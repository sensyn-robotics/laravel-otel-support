<?php

namespace Sensyn\LaravelOTelSupport\Metrics\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HTTP server リクエストの所要時間を OTel histogram (http.server.request.duration) に記録する
 * terminable な global middleware。
 *
 * flush は行わない (本クラスは record 専念)。FPM は 1 リクエスト 1 プロセスなので request 終了時に
 * SDK autoload の shutdown hook が export するが、Octane の worker ではプロセスが数百〜数千リクエストを
 * 生きるため、そこは umbrella の WorkerFlusher が Octane の RequestTerminated で flush する。
 *
 * @internal MetricsServiceProvider が global middleware として登録する。消費側アプリ向けの API ではない。
 */
class HttpServerMetrics
{
    private const START_ATTRIBUTE = 'otel.http.start';

    private const CONTEXT_ATTRIBUTE = 'otel.http.context';

    /**
     * semconv が "known" と定める HTTP メソッド (RFC 9110 の 8 種 + PATCH)。これ以外は _OTHER に潰す。
     * https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-request-method
     *
     * @var list<string>
     */
    private const KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH'];

    /** 未知メソッドの正規化先 (semconv 規定値)。 */
    private const OTHER_METHOD = '_OTHER';

    /**
     * network.protocol.version として認める値。HTTP のバージョン番号は事実上この閉じた集合で、
     * semconv の例示値もこれに揃っている。メソッドと違って未知値の受け皿 (_OTHER) は規定が無いため、
     * 外れた場合は属性を付けない (semconv: "If the actual protocol version is not known, this
     * attribute SHOULD NOT be set")。
     *
     * @var list<string>
     */
    private const KNOWN_PROTOCOL_VERSIONS = ['0.9', '1.0', '1.1', '2', '2.0', '3', '3.0'];

    private HistogramInterface $duration;

    public function __construct(MeterProviderInterface $meterProvider)
    {
        try {
            $buckets = Buckets::fromConfig('otel-support.metrics.http.buckets', Buckets::DEFAULT_HTTP);

            // MeterProvider は provider が container に bind する (Globals 依存は app 側に閉じる)。
            // meter (instrumentation scope) 名と histogram 名は計装の identity。サービス名は resource の
            // service.name で別途付くので、ここはモジュール名ベースの固定値にする (config 化しない)。
            $this->duration = $meterProvider
                ->getMeter('laravel-otel-support.metrics.http')
                ->createHistogram(
                    'http.server.request.duration',
                    's',
                    'Duration of HTTP server requests',
                    ['ExplicitBucketBoundaries' => $buckets],
                );
        } catch (Throwable) {
            // 構築の失敗も terminate() と同じくアプリに漏らさない。この middleware は 1 リクエストで 2 回
            // 構築される (request 経路 = global stack 先頭の pipe である Pipeline::carry() の make()、
            // terminate 経路 = Kernel::terminateMiddleware() の make()) 上に、singleton は失敗した構築を
            // キャッシュしないため、投げると全リクエストが 500 になり、さらに terminate 連鎖が途切れて
            // defer() 済み処理と terminating callback が黙って消える (terminate() が避けている事象そのもの)。
            //
            // 現実的なトリガは SDK ではなく config 側: Buckets::fromConfig() が不正値で出す Log::warning
            // (channel 未定義 / handler が書き込み不能 / カスタム handler の throw) と facade root 未設定。
            //
            // フォールバックは config も logger も触らない Noop instrument にする (フォールバック自身が
            // 新たな throw 源にならないこと)。プロパティは非 null のまま埋まるので record 側は変更不要。
            // ログは出さない (terminate() と同じ理由: Log 自体が throw 源で、hot path では増幅する)。
            $this->duration = (new NoopMeter)->createHistogram('http.server.request.duration');
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        // terminate() が別インスタンスで解決されても拾えるよう開始時刻は request に載せる。
        if (! $request->attributes->has(self::START_ATTRIBUTE)) {
            $request->attributes->set(self::START_ATTRIBUTE, microtime(true));
            // span が active なうちに context を捕まえておく。terminate() 時点では auto-laravel の
            // SERVER span は終了済みで、record に context を渡さないと exemplar filter が拾えない。
            // SpanContext は span 終了後も不変なので、後で渡しても trace/span id は乗る。
            $request->attributes->set(self::CONTEXT_ATTRIBUTE, Context::getCurrent());
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $start = $request->attributes->get(self::START_ATTRIBUTE);
        if (! is_float($start)) {
            return;
        }

        $elapsed = microtime(true) - $start;

        try {
            $this->record($elapsed, $request, $response);
        } catch (Throwable) {
            // 属性収集と record は telemetry 側の関心事なので、例外はアプリに漏らさない。terminate() は
            // 応答確定後に走るが、この middleware は global stack の先頭に prepend されている (=
            // Kernel::terminateMiddleware の先頭) ため、ここで投げると後続 middleware の terminate()
            // (Laravel 11+ の InvokeDeferredCallbacks) と $app->terminate() がスキップされ、defer() 済み
            // 処理と terminating callback が黙って消える。
            //
            // 既知の remote 起因経路 (不正な method override) は requestMethod() 側で _OTHER に倒して
            // data point を残す。こちらが受け止めるのは正しい代替値を推測できない残りのケース:
            //   - getScheme(): trusted proxy 設定 (HEADER_FORWARDED を信頼) 下で Forwarded と
            //     X-Forwarded-Proto が矛盾すると ConflictingHeadersException を投げる。
            //   - record(): SDK / view / exemplar 側の想定外エラー。
            // これらは url.scheme を偽るより 1 リクエスト分の計上漏れを選ぶ (計装の失敗は
            // telemetry の欠損として出る方が、アプリの挙動を変えるより望ましい)。
            //
            // ログは出さない: 上記のうち少なくとも scheme 経路は remote が任意回数踏めるので、
            // 1 リクエスト 1 行でもログ増幅 (ログストアへの DoS) になる。
        }
    }

    /**
     * 属性を組み立てて histogram に記録する。request からの属性取得は remote 入力に触るので、
     * ここで投げた例外は terminate() が飲む (アプリの terminate 連鎖に漏らさない)。
     */
    private function record(float $elapsed, Request $request, Response $response): void
    {
        $status = $response->getStatusCode();

        $attrs = [
            'http.request.method' => $this->requestMethod($request), // Required (未知メソッドは _OTHER)
            'url.scheme' => $request->getScheme(),                   // Required (http/https)
            'http.response.status_code' => $status,                  // Conditionally Required
        ];

        // http.route はルートがマッチした時のみ。raw path は高カーディナリティなので入れない。
        $route = $request->route();
        if ($route instanceof Route) {
            $attrs['http.route'] = '/'.ltrim($route->uri(), '/');
        }

        // network.protocol.version (Recommended, best-effort)。"HTTP/1.1" -> "1.1"。
        //
        // SERVER_PROTOCOL は web サーバがリクエストラインの HTTP-version をそのまま渡す値で
        // (nginx の fastcgi_param SERVER_PROTOCOL $server_protocol)、前段が正規化しない構成では
        // "HTTP/1.00" のような表記揺れがそのまま属性値になる。それは http.request.method 側が
        // requestMethod() で既知値 + _OTHER に閉じて防いでいるのと同じ攻撃面 (下のコメント参照) なので、
        // ここも既知値でしか属性を付けない。semconv も "If the actual protocol version is not known,
        // this attribute SHOULD NOT be set" と規定しているので、外れ値は _OTHER に倒さず属性ごと落とす。
        $protocol = $request->server->get('SERVER_PROTOCOL');
        if (is_string($protocol) && str_starts_with($protocol, 'HTTP/')) {
            $version = substr($protocol, 5);
            if (in_array($version, self::KNOWN_PROTOCOL_VERSIONS, true)) {
                $attrs['network.protocol.version'] = $version;
            }
        }

        // error.type はサーバエラー (5xx) の時のみ。値はステータスコード文字列でよい。
        if ($status >= 500) {
            $attrs['error.type'] = (string) $status;
        }

        // handle() で捕まえた span context を渡す (exemplar に trace/span id を乗せるため)。
        $context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);
        $this->duration->record(
            $elapsed,
            $attrs,
            $context instanceof ContextInterface ? $context : null,
        );
    }

    /**
     * http.request.method を semconv の既知メソッドに正規化する (未知は _OTHER)。
     * getMethod() 自体が投げるケース (不正な method override) も _OTHER に倒す (理由は catch 節)。
     *
     * getMethod() の戻り値はリモートから任意の文字列にできる。Symfony は raw な REQUEST_METHOD を
     * 検証せず strtoupper して返すだけで (例: "ZZZ-ATTACKER-1" がそのまま通る)、POST の場合は
     * Laravel が Request::capture() で有効化する method override 経由で _method パラメータ /
     * X-HTTP-METHOD-OVERRIDE ヘッダの値 (^[A-Z]+$ を満たす任意値) も返す。
     * 無加工で属性に入れると、未認証リモートから http.server.request.duration の時系列を無限に
     * 増やせる (cardinality 増幅 = メトリクスストアへの DoS)。正規化は「両経路が合流した後の
     * getMethod() の戻り値」に対して行い、raw / override どちらも塞ぐ。
     *
     * semconv は元の値を http.request.method_original に持つ規定だが、それを付けると高カーディナリティが
     * そのまま復活して対策の意味が無くなるため付けない (未知メソッドの実値が必要な調査はアクセスログ側で行う)。
     */
    private function requestMethod(Request $request): string
    {
        try {
            // Symfony は既に大文字化して返すが、Request 実装差に依らないよう明示的に揃える
            // (semconv の既知メソッドは大文字表記のみ)。
            $method = strtoupper($request->getMethod());
        } catch (Throwable) {
            // getMethod() は override 経路の入力を検証しており、不正なら投げる (Symfony
            // Request::getMethod):
            //   - A-Z 以外を含む override 値 ("ZZZ-ATTACKER-1" 等) => SuspiciousOperationException
            //   - _method が配列 ("_method[]=PUT") => InputBag::get() の BadRequestException
            // 2 つは RequestExceptionInterface を実装する別クラスで継承関係も無いため、
            // 片方だけ catch すると穴が残る。よって Throwable で受ける。
            //
            // 重要なのは「terminate() が getMethod() の最初の呼び出し者になり得る」こと。ルーティング前に
            // 応答が確定したリクエスト (メンテナンスモードの 503、ValidatePostSize の 413 等) では
            // フレームワークが誰も getMethod() を呼ばないため、検証エラーはこの計装から初めて飛ぶ。
            // 逆に、通常経路 (ルーティングが走る) では routing が先に呼んで例外はそこで処理され、
            // getMethod() は結果を memoize するので terminate() では投げない。
            //
            // ここで投げると terminate 連鎖が途切れてアプリの動作 (defer() / terminating callback) を壊す
            // ため、投げずに未知メソッド扱い = _OTHER に倒す。検証を通らない override は定義上「既知の
            // メソッドではない」ので、semconv 的にも _OTHER が正しい値であり、cardinality も 1 系列に
            // 収まったままになる。
            //
            // getRealMethod() (生の REQUEST_METHOD = この経路では必ず POST) には倒さない: 不正な override を
            // 正常な POST 系列に混ぜて見えなくする上に、戻り値が strtoupper(mixed) 依存で新たな例外源に
            // なり得る。ログも出さない (未認証リモートが任意回数踏める入力なのでログ増幅になる)。
            return self::OTHER_METHOD;
        }

        return in_array($method, self::KNOWN_METHODS, true) ? $method : self::OTHER_METHOD;
    }
}
