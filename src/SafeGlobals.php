<?php

namespace Sensyn\LaravelOTelSupport;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Throwable;

/**
 * Globals (SDK autoload) から signal provider を取り、失敗したら api の Noop provider に倒す。
 *
 * provider の contextual binding factory から使う。この factory は container が対象クラスの constructor
 * 引数を解決する時点、つまり constructor 本体に入る前に走るので、対象クラス側の try/catch では絶対に
 * 捕まえられない。ここを守らない限り「telemetry の初期化失敗」をアプリから隔離できず、着弾点は解決を
 * 要求した側と同じになる:
 *   - HTTP request: global stack 先頭の pipe = Pipeline::carry() の make() -> 全リクエストが 500。
 *   - HTTP terminate: Kernel::terminateMiddleware() の make() -> terminate 連鎖が途切れ、後続
 *     middleware の terminate() と $app->terminate() (defer() / terminating callback) が消える。
 *   - queue / Octane: listener の遅延解決 (Dispatcher::createClassCallable) = dispatch 元 / worker /
 *     リクエスト終了処理のコードパス。
 * singleton は「構築に失敗したインスタンス」をキャッシュしないので、一度失敗すると以後ずっと投げ続ける。
 *
 * Globals::globals() は initializer の例外を自前で catch するが、その内部 logWarning は LoggerHolder
 * 経由でアプリの logger に落ちるため、logger 側が投げればここまで伝播する (他に assert(isset(...)) の
 * AssertionError、Configurator::activate()/detach())。
 *
 * Noop フォールバックは api 側の実装なので SDK 非依存の契約を破らない。ログは出さない:
 * HttpServerMetrics::terminate() と同じ理由で、Log 自体が新たな throw 源になる。記録と flush は黙って
 * 落ちる = 計装の失敗は telemetry の欠損として観測される (アプリの挙動は変えない)。
 *
 * @internal 本パッケージの provider 専用。消費側アプリ向けの API ではない。
 */
final class SafeGlobals
{
    public static function tracerProvider(): TracerProviderInterface
    {
        try {
            return Globals::tracerProvider();
        } catch (Throwable) {
            return new NoopTracerProvider;
        }
    }

    public static function meterProvider(): MeterProviderInterface
    {
        try {
            return Globals::meterProvider();
        } catch (Throwable) {
            return new NoopMeterProvider;
        }
    }
}
