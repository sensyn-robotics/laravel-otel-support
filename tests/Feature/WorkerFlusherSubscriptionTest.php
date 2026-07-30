<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use ArrayObject;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Sensyn\LaravelOTelSupport\Tests\Support\MakesJobDoubles;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Sensyn\LaravelOTelSupport\WorkerFlusher;

/**
 * provider が実際に使う配線 (OTelSupportServiceProvider::registerWorkerFlusher ->
 * WorkerFlusher::subscribeLazily) の担保。
 *
 * WorkerFlusherTest はインスタンスを直接 new してローカルの Dispatcher に subscribe() する経路を
 * 検証しており、本番で通る「boot で listener 登録 → 発火時にアプリの container から解決」の経路は別物。
 * この配線が落ちる/別 Dispatcher に繋がると、per-job flush が黙って消える (例外は出ない) ので、
 * ジョブ境界とリクエスト境界の両方をアプリの Dispatcher 越しに通す。
 */
class WorkerFlusherSubscriptionTest extends TestCase
{
    use MakesJobDoubles;

    private InMemoryExporter $spanExporter;

    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // scheduledDelay を大きくして onEnd の自動 export を封じる。export が起きたら forceFlush が
        // 効いた証拠になる。
        $this->spanExporter = new InMemoryExporter(new ArrayObject);
        $this->tracerProvider = new TracerProvider(new BatchSpanProcessor(
            $this->spanExporter,
            Clock::getDefault(),
            scheduledDelayMillis: 60_000,
        ));
    }

    /**
     * 既定の contextual binding は Globals (テストでは SDK autoload 無効 = Noop) なので flush を観測
     * できない。in-memory の SDK TracerProvider に差し替える (boot 後の差し替えが効くこと自体が
     * 「boot 時点では未生成」= 遅延解決の裏付けにもなる)。
     */
    private function useInMemoryTracerProvider(): void
    {
        app()->when(WorkerFlusher::class)
            ->needs(TracerProviderInterface::class)
            ->give(fn (): TracerProviderInterface => $this->tracerProvider);
    }

    private function endOneSpan(): void
    {
        $this->tracerProvider->getTracer('test')->spanBuilder('job-span')->startSpan()->end();
    }

    public function test_worker_flusher_is_not_resolved_during_boot(): void
    {
        // boot での eager 生成をしない。生成は Globals (SDK autoload) に触るので、queue も Octane も
        // 使わないプロセス (素の FPM リクエスト / 一般の artisan) では触らせない。
        $this->assertFalse(app()->resolved(WorkerFlusher::class));
    }

    public function test_worker_flusher_is_bound_as_a_singleton(): void
    {
        // 解決は発火ごとに走るので、JobProcessing で立てた dirty フラグを Looping 側で見るには
        // 同一インスタンスが返る必要がある。
        $this->assertSame(app(WorkerFlusher::class), app(WorkerFlusher::class));
    }

    public function test_every_handled_event_is_subscribed_on_the_application_dispatcher(): void
    {
        $events = app(Dispatcher::class);

        foreach (array_keys(WorkerFlusher::HANDLERS) as $event) {
            $this->assertTrue(
                $events->hasListeners($event),
                "{$event} の listener がアプリの Dispatcher に登録されている必要がある。",
            );
        }
    }

    public function test_job_boundary_flush_runs_through_the_application_dispatcher(): void
    {
        $this->useInMemoryTracerProvider();
        $events = app(Dispatcher::class);

        $events->dispatch(new JobProcessing('redis', $this->queuedJob()));
        $this->endOneSpan();
        // Looping は本番では until() (halt=true) で発火する。listener が false を返すと worker が止まる。
        $this->assertNotFalse($events->until(new Looping('redis', 'default')));

        $this->assertTrue(app()->resolved(WorkerFlusher::class), '発火時解決で WorkerFlusher が作られる必要がある。');
        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function test_worker_stopping_flush_runs_through_the_application_dispatcher(): void
    {
        $this->useInMemoryTracerProvider();
        $events = app(Dispatcher::class);

        $events->dispatch(new JobProcessing('redis', $this->queuedJob()));
        $this->endOneSpan();
        $events->dispatch(new WorkerStopping);

        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function test_octane_request_boundary_flush_runs_through_the_application_dispatcher(): void
    {
        // Octane は自前の listener 設定だけでなくアプリの event dispatcher にも dispatch するので
        // (DispatchesEvents::dispatchEvent)、クラス名の文字列購読で拾える。
        $this->useInMemoryTracerProvider();
        $events = app(Dispatcher::class);

        $this->endOneSpan();
        $events->dispatch(WorkerFlusher::OCTANE_REQUEST_TERMINATED);

        $this->assertCount(1, $this->spanExporter->getSpans());
    }
}
