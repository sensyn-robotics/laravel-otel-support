<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\FailsGlobalMeterProvider;
use Sensyn\LaravelOTelSupport\Tests\Support\FakeQueuedJob;
use Sensyn\LaravelOTelSupport\Tests\Support\InspectsGlobalMiddleware;
use Sensyn\LaravelOTelSupport\Tests\Support\MakesQueueEvents;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * 計装の「引数解決」層のガード。
 *
 * MeterProvider は MetricsServiceProvider の contextual binding が Globals から取る。この factory は
 * container が constructor 引数を解決する時点 = 計装の constructor 本体に入る前に走るので、計装側の
 * try/catch では絶対に捕まえられない。ここを守らないと telemetry の初期化失敗がそのままアプリの
 * 障害になる (全リクエスト 500 / terminate 連鎖の断裂 / dispatch 元への伝播)。
 *
 * 本番の解決経路 (middleware の make と listener の遅延解決) を通す必要があるので、計装を直接 new する
 * のではなく HTTP リクエストと event dispatch から検証する。
 */
class MeterProviderFallbackTest extends TestCase
{
    use FailsGlobalMeterProvider;
    use InspectsGlobalMiddleware;
    use MakesQueueEvents;

    private const ROUTE = '/otel-support/meter-provider-fallback';

    public function test_http_request_survives_a_failing_meter_provider_resolution(): void
    {
        // この middleware が global stack に載っていなければ、以下のリクエストは計装を通らず
        // 「素通りしただけ」になる。前提をここで固定する。
        $this->assertTrue(
            $this->hasGlobalMiddleware(HttpServerMetrics::class),
            'HttpServerMetrics が global stack に無いと、このテストは計装を通らないので無意味になる。',
        );

        Route::get(self::ROUTE, static fn (): string => 'ok');

        $this->withFailingGlobalMeterProvider(function (): void {
            // 1 リクエストで解決は 2 回走る: pipeline (Illuminate\Pipeline\Pipeline::carry の make) と
            // terminate (Kernel::terminateMiddleware の make)。Laravel のテストヘルパは handle() の後に
            // kernel->terminate() も呼ぶので、両方の着弾点をこの 1 本で通る。
            $this->get(self::ROUTE)->assertOk();
        });
    }

    public function test_queue_event_survives_a_failing_meter_provider_resolution(): void
    {
        $this->withFailingGlobalMeterProvider(function (): void {
            // listener は [クラス名, メソッド名] で登録されており、発火時に container が解決する
            // (Dispatcher::createClassCallable)。つまり解決の失敗は dispatch 元のコードパスに着弾する。
            app(Dispatcher::class)->dispatch(
                $this->jobQueued('otel-test', 'id-1', new FakeQueuedJob),
            );
        });

        // 解決自体は起きていること。listener が未登録で dispatch が素通りしただけなら、ガードの有無に
        // 関係なく例外は出ない = 上の検証が無意味になる。
        $this->assertTrue(app()->resolved(QueueMetrics::class));
    }
}
