<?php

namespace Sensyn\LaravelOTelSupport;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopCounter;
use Throwable;

/**
 * fail-open のガードが飲み込んだ失敗の **回数だけ** を counter に積む。
 *
 * このパッケージの入口はすべて fail-open で、失敗はログを出さずに握り潰す (record / flush は
 * per-request / per-job で走るので、1 件 1 行でもログ増幅 = ログストアへの DoS になる)。その結果、
 * 失敗は「telemetry が欠けている」形でしか現れず、「壊れて落ちた」と「そもそも通っていない」を
 * 区別できない。counter は集約されるのでログと違って hot path で増やしても増幅せず、回数だけなら
 * この方針を崩さずに残せる。
 *
 * **この counter 自身が例外を上げないことが全体の前提**なので、生成と記録の両方を飲む。api の
 * interface に「投げない」保証は無く、実装側には実在の throw 経路がある:
 *   - 生成: 受け取るのは api の MeterProviderInterface で、この interface に「投げない」保証は無い。
 *     Globals::meterProvider() は Context に載った provider を先に見るため実装は差し替わりうるし、
 *     api 自身が **factory の実行を createCounter() まで遅らせる型** を持っている
 *     (LateBindingMeterProvider -> LateBindingMeter::createCounter が factory を走らせる。SDK autoload は
 *     auto-instrumentation へ渡す InstrumentationContext でこれを使う)。つまり「provider を受け取れた」
 *     ことは「instrument を作れる」ことを意味せず、SafeGlobals の try/catch はこの型より外側にしか
 *     張れない。
 *     なお **SDK の初期化そのものの失敗はここには来ない**: SdkAutoloader の環境変数初期化は
 *     MeterProviderFactory を eager に走らせて実 SDK の MeterProvider を Globals に載せる
 *     (SdkAutoloader::environmentBasedInitializer)。その中で投げれば Globals::globals() が自前で飲み、
 *     noop configurator のまま NoopMeterProvider が返る。伝播するのは飲んだ後の logWarning が
 *     アプリ logger 経由で投げた場合で、それを受けるのは SafeGlobals である (同 docblock)。
 *   - 記録: add() は SDK 側で属性を組み立て、値が不正なら AttributesBuilder が logWarning する。
 *     その落ち先は既定で LoggerHolder のアプリ logger (Laravel なら Monolog) なので、logger が
 *     投げれば add() から伝播する (OTEL_PHP_LOG_DESTINATION=trigger_error なら Laravel の
 *     HandleExceptions が ErrorException に変える)。SafeGlobals の docblock が挙げている経路と同じ。
 * よって「たぶん投げない」に賭けず、両方を Throwable で受ける。ここで投げると「失敗を数える仕組み」が
 * 計装本体より広い blast radius を持つことになり本末転倒になる。
 *
 * **原理的に数えられない失敗がある。** いずれも counter の届け先そのものが壊れている場合:
 *   - SafeGlobals::meterProvider() の失敗。倒す先は api の NoopMeterProvider なので、そこに積んだ
 *     counter はどこにも出ない。
 *   - **MeterProvider / Meter 側が原因の instrument 構築失敗。** 各計装は同じ provider から
 *     「まずこの counter を」作るので、原因が provider 側にあるならこの counter も同じ理由で
 *     Noop に倒れ、直後の increment(STAGE_BUILD) はどこにも出ない。つまり stage=build が拾えるのは
 *     **provider は健全で instrument だけ作れなかった場合** (Buckets::fromConfig の Log::warning が
 *     投げた、facade root 未設定など) に限られる。「build が 0 = 計装は生きている」とは読めない。
 *   - WorkerFlusher の MeterProvider flush 失敗。回数は積まれるが、それを運ぶのは flush に失敗した
 *     その MeterProvider なので、後続の境界で flush が成功しない限り出ない。
 * 「counter が 0 である」ことは「失敗が無かった」ことの証明にはならない、という限界として
 * src/Metrics/README.md に一覧してある (traces 側の盲点も含む)。
 *
 * @internal 本パッケージの計装専用。消費側アプリ向けの API ではない。
 */
final class FailureCounter
{
    /**
     * 失敗した段階。
     *
     * - build: instrument を作れなかった (以降その計装は Noop = 記録が丸ごと落ちる)。
     * - record: data point 1 件を捨てた。
     * - flush: 境界での forceFlush に失敗した (次の境界で再試行される)。
     */
    public const STAGE_BUILD = 'build';

    public const STAGE_RECORD = 'record';

    public const STAGE_FLUSH = 'flush';

    /** 失敗した場所。flush は signal ごとに独立して走るので値も signal で分ける。 */
    public const SITE_HTTP = 'metrics.http';

    public const SITE_QUEUE = 'metrics.queue';

    public const SITE_FLUSH_TRACES = 'worker_flusher.traces';

    public const SITE_FLUSH_METRICS = 'worker_flusher.metrics';

    private CounterInterface $failures;

    public function __construct(MeterProviderInterface $meterProvider)
    {
        try {
            // meter (instrumentation scope) は計装ごとの scope ではなく umbrella 名にする。発生源は
            // site 属性が持つので、metric としては 1 本のストリームに揃える方が「どこかで失敗して
            // いないか」を 1 クエリで見られる。同名 instrument の再生成は SDK が (scope, instrument)
            // で dedup するので、計装 3 箇所がそれぞれ作っても data point は 1 系列に集まる。
            $this->failures = $meterProvider
                ->getMeter('laravel-otel-support')
                ->createCounter(
                    'laravel.otel_support.failures',
                    '{failure}',
                    'Failures contained by the fail-open guards of this package',
                );
        } catch (Throwable) {
            // 記録先が作れないなら黙って Noop に倒す (フォールバック自身が新たな throw 源にならないこと)。
            $this->failures = new NoopCounter;
        }
    }

    /**
     * 失敗を 1 件数える。呼び出し元はすべて「既に失敗を飲んだ後の catch 節」なので、**この
     * メソッドは絶対に投げてはならない** (投げれば飲んだはずの失敗がそのまま外へ出る)。
     *
     * 例外クラス名 (semconv の error.type) は付けない。ここに届く例外は SDK 内部・アプリの logger
     * (Monolog handler)・exporter の transport 由来で、**このパッケージのコードでは値の集合を
     * 縛れない**。しかも record 経路は per-request / per-job で、HTTP 側は remote が任意回数
     * 踏める入力 (HttpServerMetrics::requestMethod の docblock 参照) なので、無界のラベルを
     * 付けると http.request.method_original を落としたのと同じ cardinality 増幅を、よりによって
     * 「障害時にだけ伸びる metric」で再現することになる。例外の中身が要る調査は OTel の内部ログ
     * (OTEL_LOG_LEVEL / OTEL_PHP_LOG_DESTINATION) 側で行う。
     */
    public function increment(string $stage, string $site): void
    {
        try {
            $this->failures->add(1, [
                'laravel.otel_support.stage' => $stage,
                'laravel.otel_support.site' => $site,
            ]);
        } catch (Throwable) {
            // 生成が成功しても記録は投げうる (理由はクラスの docblock)。二重に飲む。
        }
    }
}
