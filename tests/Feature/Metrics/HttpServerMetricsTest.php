<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Mockery;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopHistogram;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\CapturingHistogram;
use Sensyn\LaravelOTelSupport\Tests\Support\RecordsMetrics;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HttpServerMetricsTest extends TestCase
{
    use RecordsMetrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMeter();
    }

    private function runMiddleware(Request $request, Response $response): void
    {
        $middleware = new HttpServerMetrics($this->meterProvider);
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }

    /**
     * 「その入力で Symfony のアクセサが本当に投げる」ことをテスト自身で固定する。
     *
     * 投げない入力を書くと回帰テストが何も再現しないテストに化ける。検証ロジックは Symfony の major で
     * 違う (method override は 6.4/7 が preg_match('/^[A-Z]++$/D')、8 が strspn) ため、CI マトリクスの
     * Laravel 10〜13 = symfony/http-foundation 6.4〜8 のどの脚でも前提が崩れたら即失敗させる。
     *
     * clone してから呼ぶのが必須: getMethod() は結果を $method に memoize し、getScheme() は
     * ConflictingHeadersException を投げた後 $isForwardedValid を false に落とす。つまりどちらも
     * 「2 回目以降は投げない」ので、元の request で先に呼ぶと terminate() 側で再現しなくなる。
     *
     * @param  callable(Request): mixed  $accessor
     * @param  class-string<Throwable>  $expected
     */
    private function assertThrowsOnFirstCall(Request $request, callable $accessor, string $expected, string $label): void
    {
        $thrown = null;

        try {
            $accessor(clone $request);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            $expected,
            $thrown,
            "[{$label}] この入力は Symfony のアクセサが {$expected} を投げる前提。投げないなら回帰テストとして無意味なので入力を見直すこと。",
        );
    }

    public function test_records_route_template_and_attributes(): void
    {
        $request = Request::create('https://example.test/api/v1/users/5', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'api/v1/users/{user}', []));

        $this->runMiddleware($request, new Response('', 200));

        $metrics = $this->collectMetrics();
        $this->assertArrayHasKey('http.server.request.duration', $metrics);

        $attrs = $this->attributesOf($metrics['http.server.request.duration']);
        $this->assertSame('GET', $attrs['http.request.method']);
        $this->assertSame('https', $attrs['url.scheme']);
        $this->assertSame(200, $attrs['http.response.status_code']);
        // raw path (/api/v1/users/5) ではなくテンプレートが入る。
        $this->assertSame('/api/v1/users/{user}', $attrs['http.route']);
        $this->assertArrayNotHasKey('error.type', $attrs);
    }

    public function test_unmatched_route_omits_http_route(): void
    {
        // route resolver 無し = ルート未マッチ (404 等)。
        $request = Request::create('http://example.test/no/such/path', 'GET');

        $this->runMiddleware($request, new Response('', 404));

        $attrs = $this->attributesOf($this->collectMetrics()['http.server.request.duration']);
        $this->assertArrayNotHasKey('http.route', $attrs);
        $this->assertSame(404, $attrs['http.response.status_code']);
    }

    public function test_server_error_sets_error_type(): void
    {
        $request = Request::create('https://example.test/boom', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'boom', []));

        $this->runMiddleware($request, new Response('', 500));

        $attrs = $this->attributesOf($this->collectMetrics()['http.server.request.duration']);
        $this->assertSame('500', $attrs['error.type']);
    }

    public function test_unknown_methods_collapse_into_a_single_other_series(): void
    {
        // 未認証リモートは REQUEST_METHOD に任意の文字列を置ける (Symfony は raw な REQUEST_METHOD を
        // 検証せずそのまま返す)。無加工だとメソッド名ごとに data point = 時系列が増える (cardinality 増幅)。
        foreach (['ZZZ-ATTACKER-1', 'ZZZ-ATTACKER-2', 'PURGE'] as $method) {
            $this->runMiddleware(
                Request::create('https://example.test/things', $method),
                new Response('', 200),
            );
        }

        $metric = $this->collectMetrics()['http.server.request.duration'];

        // 3 種類のメソッドでも系列は 1 本 (_OTHER) に潰れる。
        $this->assertCount(1, $this->dataPoints($metric));
        $this->assertSame(3, $this->histogramCount($metric));

        $attrs = $this->attributesOf($metric);
        $this->assertSame('_OTHER', $attrs['http.request.method']);
        // semconv の http.request.method_original は付けない (付けると高カーディナリティが復活する)。
        $this->assertArrayNotHasKey('http.request.method_original', $attrs);
    }

    public function test_known_methods_are_recorded_as_is(): void
    {
        // semconv の既知メソッドは正規化せずそのまま記録する (件数は有界)。
        $known = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH'];

        foreach ($known as $method) {
            $this->runMiddleware(
                Request::create('https://example.test/things', $method),
                new Response('', 200),
            );
        }

        $recorded = [];
        foreach ($this->dataPoints($this->collectMetrics()['http.server.request.duration']) as $point) {
            $recorded[] = $point->attributes->get('http.request.method');
        }

        sort($recorded);
        $expected = $known;
        sort($expected);
        $this->assertSame($expected, $recorded);
    }

    public function test_method_override_cannot_inject_an_unknown_method(): void
    {
        // Laravel は Request::capture() で HTTP method override を有効にするので、getMethod() は POST
        // ボディの _method / X-HTTP-METHOD-OVERRIDE ヘッダの値を返す = ここからも任意のメソッド名が
        // 入り得る。正規化は getMethod() の戻り値に対して行うので、この経路も _OTHER に潰れる。
        $override = new ReflectionProperty(SymfonyRequest::class, 'httpMethodParameterOverride');
        $original = $override->getValue();
        Request::enableHttpMethodParameterOverride();

        try {
            $byParameter = Request::create('https://example.test/things', 'POST', ['_method' => 'PURGE']);

            $byHeader = Request::create('https://example.test/things', 'POST');
            $byHeader->headers->set('X-HTTP-METHOD-OVERRIDE', 'ZZZATTACKER');

            $this->runMiddleware($byParameter, new Response('', 200));
            $this->runMiddleware($byHeader, new Response('', 200));
        } finally {
            // enableHttpMethodParameterOverride() はプロセスグローバルで戻す API が無いため、
            // 他のテストへ漏らさないよう元の値に戻す。
            $override->setValue(null, $original);
        }

        $metric = $this->collectMetrics()['http.server.request.duration'];

        // override 由来の 2 リクエストも 1 系列 (_OTHER) にまとまる。
        $this->assertCount(1, $this->dataPoints($metric));
        $this->assertSame(2, $this->histogramCount($metric));
        $this->assertSame('_OTHER', $this->attributesOf($metric)['http.request.method']);
    }

    public function test_invalid_method_override_does_not_propagate_and_records_other(): void
    {
        // getMethod() は override 経路の入力を検証し、不正なら投げる。ここが厄介なのは「ルーティング前に
        // 応答が確定したリクエスト」(メンテナンスモードの 503、ValidatePostSize の 413 等) では
        // フレームワークの誰も getMethod() を呼ばないため、terminate() = この計装が最初の呼び出し者に
        // なる点。本 middleware は global stack の先頭に prepend される = terminate 連鎖の先頭なので、
        // ここで例外が漏れると後続の terminate() (Laravel 11+ の InvokeDeferredCallbacks) と
        // $app->terminate() がスキップされ、defer() 済み処理と terminating callback が黙って消える。
        $override = new ReflectionProperty(SymfonyRequest::class, 'httpMethodParameterOverride');
        $original = $override->getValue();
        Request::enableHttpMethodParameterOverride();

        try {
            // A-Z 以外を含む override 値 => SuspiciousOperationException。
            $byParameter = Request::create('https://example.test/things', 'POST', ['_method' => 'ZZZ-ATTACKER-1']);
            $this->assertThrowsOnFirstCall(
                $byParameter,
                fn (Request $r) => $r->getMethod(),
                SuspiciousOperationException::class,
                '_method=ZZZ-ATTACKER-1',
            );

            // ヘッダ経路は enableHttpMethodParameterOverride() に依らず常に見られる (getMethod は
            // X-HTTP-METHOD-OVERRIDE を無条件に読む) ので、プロセスグローバル設定に関係なく踏める。
            $byHeader = Request::create('https://example.test/things', 'POST');
            $byHeader->headers->set('X-HTTP-METHOD-OVERRIDE', 'ZZZ-ATTACKER-2');
            $this->assertThrowsOnFirstCall(
                $byHeader,
                fn (Request $r) => $r->getMethod(),
                SuspiciousOperationException::class,
                'X-HTTP-METHOD-OVERRIDE: ZZZ-ATTACKER-2',
            );

            // _method が非スカラー (_method[]=PUT) だと InputBag::get() が BadRequestException を投げる。
            // SuspiciousOperationException とは継承関係が無い別クラスなので、片方だけ catch すると穴が残る
            // = ここで両方を 1 本のテストに入れておく。
            $byArray = Request::create('https://example.test/things', 'POST', ['_method' => ['PUT']]);
            $this->assertThrowsOnFirstCall(
                $byArray,
                fn (Request $r) => $r->getMethod(),
                BadRequestException::class,
                '_method[]=PUT',
            );

            // 503 = メンテナンスモードで応答が確定したのと同じ状況。ここを抜ければ例外は伝播していない。
            foreach ([$byParameter, $byHeader, $byArray] as $request) {
                $this->runMiddleware($request, new Response('', 503));
            }
        } finally {
            // enableHttpMethodParameterOverride() はプロセスグローバルで戻す API が無いため元の値に戻す。
            $override->setValue(null, $original);
        }

        $metric = $this->collectMetrics()['http.server.request.duration'];

        // 記録される値は安全な _OTHER で、3 種類の不正入力でも系列は 1 本に潰れたまま
        // (= 例外を飲む修正で cardinality 対策が壊れていない)。
        $this->assertCount(1, $this->dataPoints($metric));
        $this->assertSame(3, $this->histogramCount($metric));

        $attrs = $this->attributesOf($metric);
        $this->assertSame('_OTHER', $attrs['http.request.method']);
        $this->assertSame(503, $attrs['http.response.status_code']);
        $this->assertArrayNotHasKey('http.request.method_original', $attrs);
    }

    public function test_conflicting_forwarded_headers_do_not_propagate(): void
    {
        // getScheme() -> isSecure() も remote 入力で投げ得る: trusted proxy 経由のとき getTrustedValues()
        // を通り、Forwarded と X-Forwarded-Proto の内容が矛盾すると ConflictingHeadersException になる
        // (Laravel 既定の TrustProxies は HEADER_FORWARDED を信頼しないので、Forwarded を信頼する設定を
        // 入れたアプリ限定の経路)。method override と同じく terminate() が最初の呼び出し者になり得る。
        // 設定は public API (アプリの TrustProxies と同じ経路) で行い、復元は static を直接書き戻す:
        // trustedHeaderSet の既定値は -1 (全ビット = 全ヘッダ信頼) で、setTrustedProxies() の phpdoc
        // (int-mask-of<Request::HEADER_*> = int<0,63>) に収まらないため、public API では元の値に戻せない。
        $proxies = new ReflectionProperty(SymfonyRequest::class, 'trustedProxies');
        $headerSet = new ReflectionProperty(SymfonyRequest::class, 'trustedHeaderSet');
        $originalProxies = $proxies->getValue();
        $originalHeaderSet = $headerSet->getValue();

        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_PROTO);

        try {
            // Request::create() の REMOTE_ADDR 既定値は 127.0.0.1 = trusted proxy 扱いになる。
            $request = Request::create('https://example.test/things', 'GET');
            $request->headers->set('Forwarded', 'proto=http');
            $request->headers->set('X-Forwarded-Proto', 'https');

            $this->assertThrowsOnFirstCall(
                $request,
                fn (Request $r) => $r->getScheme(),
                ConflictingHeadersException::class,
                'Forwarded: proto=http vs X-Forwarded-Proto: https',
            );

            $this->runMiddleware($request, new Response('', 503));
        } finally {
            // trusted proxy 設定もプロセスグローバルなので元に戻す。
            $proxies->setValue(null, $originalProxies);
            $headerSet->setValue(null, $originalHeaderSet);
        }

        // 例外は伝播しない (= アプリ側の terminate 連鎖が生き残る)。ただし url.scheme の正しい代替値は
        // 推測できない (Symfony の trusted proxy 解決を再実装したくない) ので、この 1 リクエストは
        // 計上されない = 属性を偽らず計上漏れを選ぶ、が受容した trade-off。
        $metrics = $this->collectMetrics();
        $points = array_key_exists('http.server.request.duration', $metrics)
            ? $this->dataPoints($metrics['http.server.request.duration'])
            : [];
        $this->assertSame([], $points);
    }

    public function test_instrument_failures_do_not_propagate(): void
    {
        // 属性収集だけでなく record() 側 (SDK / exporter の想定外エラー) も terminate 連鎖に漏らさない。
        $recorded = false;
        $histogram = Mockery::mock(HistogramInterface::class);
        $histogram->shouldReceive('record')->andReturnUsing(function () use (&$recorded): void {
            $recorded = true;
            throw new RuntimeException('instrument exploded');
        });

        $meter = Mockery::mock(MeterInterface::class);
        $meter->shouldReceive('createHistogram')->andReturn($histogram);

        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturn($meter);

        $middleware = new HttpServerMetrics($meterProvider);
        $request = Request::create('https://example.test/things', 'GET');
        $response = new Response('', 200);

        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);

        $this->assertTrue($recorded, 'terminate() は record() まで到達している必要がある (到達前に return していないこと)。');
    }

    public function test_duration_is_recorded_in_seconds(): void
    {
        // 単位 (s) は公開している契約で、値はその単位で記録されている必要がある。秒とミリ秒の
        // 取り違えは OTel/Prometheus の定番事故で、しかも属性・件数のテストでは一切検知できない
        // (semconv の bucket 境界は秒なので、1000 倍ずれると全観測が +Inf bucket に落ちる)。
        $request = Request::create('https://example.test/things', 'GET');
        $response = new Response('', 200);
        $middleware = new HttpServerMetrics($this->meterProvider);

        $middleware->handle($request, fn () => $response);
        usleep(5_000);
        $middleware->terminate($request, $response);

        $metric = $this->collectMetrics()['http.server.request.duration'];
        $this->assertSame('s', $metric->unit);

        // 5ms スリープを挟んだ 1 リクエストぶん。秒なら [0.005, 1) に収まり、ミリ秒 (1000 倍) なら 5 以上、
        // 単位を取り違えた縮小方向なら 0.005 未満になる。
        $sum = $this->histogramSum($metric);
        $this->assertGreaterThanOrEqual(0.005, $sum, '記録値が秒として小さすぎる (単位の取り違え?)。');
        $this->assertLessThan(1.0, $sum, '記録値が秒として大きすぎる (ミリ秒で記録している?)。');
    }

    public function test_configured_buckets_reach_the_histogram(): void
    {
        // 設定した境界が instrument の advisory (ExplicitBucketBoundaries) まで届かないと、SDK 既定の
        // 境界 ([0, 5, 10, ..., 1000]) に黙って落ちる = OTEL_METRICS_HTTP_BUCKETS が無視される。
        // config キー名の誤りもここで落ちる。
        config(['otel-support.metrics.http.buckets' => [0.25, 0.75]]);

        $this->runMiddleware(Request::create('https://example.test/things', 'GET'), new Response('', 200));

        $this->assertSame([0.25, 0.75], $this->histogramBounds($this->collectMetrics()['http.server.request.duration']));
    }

    public function test_default_buckets_are_the_semconv_recommendation(): void
    {
        // config を触らない既定経路。同梱 config の既定値が histogram まで届くこと。
        $this->runMiddleware(Request::create('https://example.test/things', 'GET'), new Response('', 200));

        $this->assertSame(
            Buckets::DEFAULT_HTTP,
            $this->histogramBounds($this->collectMetrics()['http.server.request.duration']),
        );
    }

    public function test_invalid_buckets_do_not_throw(): void
    {
        // 不正値でも throw せず既定にフォールバックする (全 HTTP リクエストを 500 にしない)。
        config(['otel-support.metrics.http.buckets' => ['oops']]);

        $this->assertInstanceOf(HttpServerMetrics::class, new HttpServerMetrics($this->meterProvider));

        // 「throw しない」だけでは instrument が丸ごと Noop に落ちる退行と区別できないので、
        // 既定の境界で動き続けていることまで見る。
        $this->runMiddleware(Request::create('https://example.test/things', 'GET'), new Response('', 200));
        $this->assertSame(
            Buckets::DEFAULT_HTTP,
            $this->histogramBounds($this->collectMetrics()['http.server.request.duration']),
        );
    }

    /**
     * 属性集合を **丸ごと** 固定する。
     *
     * 個別キーの assertSame だけでは「属性の追加」が一切落ちない。ここに url.path や user id のような
     * 高カーディナリティ属性を足す変更が入ると、bucket 14 本の explicit histogram では系列が一気に増え、
     * このクラス自身が requestMethod() のコメントで警告している「未認証リモートからメトリクスストアを
     * 膨らませる」経路が成立する。完全一致にしておけば追加も削除も必ず落ちる。
     */
    public function test_attribute_set_is_pinned_for_a_matched_route(): void
    {
        $request = Request::create('https://example.test/api/v1/users/5', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'api/v1/users/{user}', []));

        $this->runMiddleware($request, new Response('', 200));

        $this->assertSame([
            'http.request.method' => 'GET',
            'url.scheme' => 'https',
            'http.response.status_code' => 200,
            'http.route' => '/api/v1/users/{user}',
            'network.protocol.version' => '1.1',
        ], $this->attributesOf($this->collectMetrics()['http.server.request.duration']));
    }

    public function test_attribute_set_is_pinned_for_an_unmatched_route(): void
    {
        // ルート未マッチ (404) では http.route が無く、4xx なので error.type も付かない。
        $this->runMiddleware(Request::create('http://example.test/no/such/path', 'GET'), new Response('', 404));

        $this->assertSame([
            'http.request.method' => 'GET',
            'url.scheme' => 'http',
            'http.response.status_code' => 404,
            'network.protocol.version' => '1.1',
        ], $this->attributesOf($this->collectMetrics()['http.server.request.duration']));
    }

    /**
     * error.type の境界。499/500 を取り違えると、error.type の有無で success/error を割っている
     * ダッシュボードとアラートが 4xx をまとめてサーバエラーとして扱う。
     */
    #[DataProvider('errorTypeBoundaries')]
    public function test_error_type_is_attached_only_to_server_errors(int $status, ?string $expected): void
    {
        $this->runMiddleware(Request::create('https://example.test/things', 'GET'), new Response('', $status));

        $attrs = $this->attributesOf($this->collectMetrics()['http.server.request.duration']);

        if ($expected === null) {
            $this->assertArrayNotHasKey('error.type', $attrs);
        } else {
            $this->assertSame($expected, $attrs['error.type']);
        }
    }

    /**
     * @return iterable<string, array{int, string|null}>
     */
    public static function errorTypeBoundaries(): iterable
    {
        yield '400 は付かない' => [400, null];
        yield '499 は付かない' => [499, null];
        yield '500 ちょうどで付く' => [500, '500'];
        yield '503 も付く' => [503, '503'];
    }

    /**
     * SERVER_PROTOCOL は web サーバがリクエストラインから素通しする値で、前段が正規化しない構成では
     * 表記揺れがそのまま届く。既知値でなければ属性を付けない (semconv の SHOULD NOT に従う)。
     */
    #[DataProvider('protocolVersions')]
    public function test_network_protocol_version_is_allowlisted(string $serverProtocol, ?string $expected): void
    {
        $request = Request::create('https://example.test/things', 'GET');
        $request->server->set('SERVER_PROTOCOL', $serverProtocol);

        $this->runMiddleware($request, new Response('', 200));

        $attrs = $this->attributesOf($this->collectMetrics()['http.server.request.duration']);

        if ($expected === null) {
            $this->assertArrayNotHasKey('network.protocol.version', $attrs);
        } else {
            $this->assertSame($expected, $attrs['network.protocol.version']);
        }
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function protocolVersions(): iterable
    {
        yield 'HTTP/1.1' => ['HTTP/1.1', '1.1'];
        yield 'HTTP/1.0' => ['HTTP/1.0', '1.0'];
        yield 'HTTP/2' => ['HTTP/2', '2'];
        yield 'HTTP/3' => ['HTTP/3', '3'];
        // 表記揺れ・詰め物・非 HTTP スキーム。属性ごと落ちること。
        yield '桁を詰めた表記揺れ' => ['HTTP/1.000', null];
        yield '長い任意文字列' => ['HTTP/'.str_repeat('9', 64), null];
        yield 'HTTP/ 以外' => ['ICY 200 OK', null];
        yield '空のバージョン' => ['HTTP/', null];
    }

    /**
     * handle() を経ずに terminate() だけが走る経路 (Kernel::handle() が bootstrap 例外を握り潰して
     * pipeline を実行しなかった場合。Kernel::terminate() は global middleware の terminate を常に呼ぶ)。
     *
     * ガードが消えると `microtime(true) - null` ≒ 1.7e9 秒が cumulative histogram の sum に入り、
     * そのプロセスが生きている間ずっと平均・分位のダッシュボードが壊れる。エラーは出ない。
     */
    public function test_terminate_without_handle_records_nothing(): void
    {
        $request = Request::create('https://example.test/things', 'GET');
        $middleware = new HttpServerMetrics($this->meterProvider);

        $middleware->terminate($request, new Response('', 200));

        $metrics = $this->collectMetrics();
        $points = array_key_exists('http.server.request.duration', $metrics)
            ? $this->dataPoints($metrics['http.server.request.duration'])
            : [];
        $this->assertSame([], $points, 'handle() を経ていない terminate() は data point を作ってはいけない。');
    }

    /**
     * handle() 時点の Context が terminate() の record() まで運ばれること。
     *
     * これは exemplar (レイテンシ外れ値から trace への導線) の前提だが、context は集計値にも属性にも
     * 現れないため、渡し忘れても SDK 経由のテストは全て緑になる。record() の第3引数を直接見る。
     */
    public function test_span_context_captured_in_handle_reaches_record(): void
    {
        $histogram = new CapturingHistogram;

        $meter = Mockery::mock(MeterInterface::class);
        $meter->shouldReceive('createHistogram')->andReturn($histogram);
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturn($meter);

        $middleware = new HttpServerMetrics($meterProvider);
        $request = Request::create('https://example.test/things', 'GET');
        $response = new Response('', 200);

        // handle() の間だけ有効な context に目印を入れる。terminate() 時点では既に detach 済みなので、
        // 「その場で Context::getCurrent() を読む」実装ではこの目印は取れない = handle() で捕まえた
        // context が運ばれていることの証明になる。
        $key = Context::createKey('laravel-otel-support.test');
        $scope = Context::getCurrent()->with($key, 'marker')->activate();
        try {
            $middleware->handle($request, fn () => $response);
        } finally {
            $scope->detach();
        }

        $this->assertNull(Context::getCurrent()->get($key), 'detach 後に目印が残っていては前提が崩れる。');

        $middleware->terminate($request, $response);

        $this->assertCount(1, $histogram->records);
        $context = $histogram->records[0]['context'];
        $this->assertInstanceOf(ContextInterface::class, $context);
        $this->assertSame('marker', $context->get($key), 'handle() 時点の context が record() に渡っていない (exemplar が無言で死ぬ)。');
    }

    public function test_meter_resolution_failure_does_not_propagate_from_the_constructor(): void
    {
        // constructor は terminate() より着弾範囲が広い。この middleware は 1 リクエストで 2 回構築され
        // (pipeline = Pipeline::carry の make / terminate = Kernel::terminateMiddleware の make)、singleton は
        // 失敗した構築をキャッシュしないため、投げると全リクエストが 500 になった上に terminate 連鎖も
        // 途切れる (= terminate() の catch が避けている事象を constructor が引き起こす)。
        $attempts = 0;
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(function () use (&$attempts): MeterInterface {
            $attempts++;
            throw new RuntimeException('meter provider exploded');
        });

        $this->assertMiddlewareIsHarmless($meterProvider);
        $this->assertSame(1, $attempts, 'constructor は getMeter() まで到達している必要がある。');
    }

    public function test_instrument_creation_failure_does_not_propagate_from_the_constructor(): void
    {
        // getMeter() は通っても createHistogram() (advisory の解釈など) で投げる余地は残る。
        $attempts = 0;
        $meter = Mockery::mock(MeterInterface::class);
        $meter->shouldReceive('createHistogram')->andReturnUsing(function () use (&$attempts): HistogramInterface {
            $attempts++;
            throw new RuntimeException('instrument creation exploded');
        });

        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturn($meter);

        $this->assertMiddlewareIsHarmless($meterProvider);
        $this->assertSame(1, $attempts, 'constructor は createHistogram() まで到達している必要がある。');
    }

    /**
     * 壊れた MeterProvider でも構築 → handle() → terminate() が例外を出さず、histogram には Noop が
     * 入っていることを確認する。
     *
     * Noop であることまで見るのは terminate() が Throwable を飲むため: フォールバックの代入を落とすと
     * 「未初期化プロパティへのアクセス = Error」が terminate() の catch に飲まれて黙るので、
     * 「例外が出ないこと」だけではガードの半分が抜けても気付けない。
     */
    private function assertMiddlewareIsHarmless(MeterProviderInterface $meterProvider): void
    {
        $middleware = new HttpServerMetrics($meterProvider);

        $duration = new ReflectionProperty(HttpServerMetrics::class, 'duration');
        $this->assertInstanceOf(
            NoopHistogram::class,
            $duration->getValue($middleware),
            'instrument の構築に失敗したら Noop instrument に倒す必要がある (未初期化のままだと記録時に Error)。',
        );

        $request = Request::create('https://example.test/things', 'GET');
        $response = new Response('', 200);

        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }
}
