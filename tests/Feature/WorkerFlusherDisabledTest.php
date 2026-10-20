<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use ArrayObject;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
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
 * worker_flusher.enabled=false (per-job / per-request の同期 export がコストになった場合の緩和策として
 * README が案内するフラグ) が flush の配線を止めることの担保。
 *
 * フラグが no-op に退行すると、運用が「切ったつもり」でも forceFlush が走り続け、緩和策として案内した
 * 操作が黙って効かなくなる。
 */
class WorkerFlusherDisabledTest extends TestCase
{
    use MakesJobDoubles;

    private InMemoryExporter $spanExporter;

    private TracerProvider $tracerProvider;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('otel-support.worker_flusher.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->spanExporter = new InMemoryExporter(new ArrayObject);
        $this->tracerProvider = new TracerProvider(new BatchSpanProcessor(
            $this->spanExporter,
            Clock::getDefault(),
            scheduledDelayMillis: 60_000,
        ));

        app()->when(WorkerFlusher::class)
            ->needs(TracerProviderInterface::class)
            ->give(fn (): TracerProviderInterface => $this->tracerProvider);
    }

    public function test_flush_listeners_are_not_registered(): void
    {
        $events = app(Dispatcher::class);

        // JobProcessing / Looping は QueueMetrics も購読するので、flusher しか購読しないイベントで見る
        // (Looping で flusher が動かないことは下の test_nothing_is_flushed_at_a_job_boundary が押さえる)。
        foreach ([WorkerStopping::class, WorkerFlusher::OCTANE_REQUEST_TERMINATED] as $event) {
            $this->assertFalse(
                $events->hasListeners($event),
                "worker_flusher.enabled=false では {$event} を購読してはいけない。",
            );
        }
    }

    public function test_nothing_is_flushed_at_a_job_boundary(): void
    {
        $events = app(Dispatcher::class);

        $events->dispatch(new JobProcessing('redis', $this->queuedJob()));
        $this->tracerProvider->getTracer('test')->spanBuilder('job-span')->startSpan()->end();
        $events->until(new Looping('redis', 'default'));
        $events->dispatch(new WorkerStopping);

        // scheduledDelay を長くしてあるので、export されていれば forceFlush が走った証拠になる。
        $this->assertCount(0, $this->spanExporter->getSpans());
        $this->assertFalse(app()->resolved(WorkerFlusher::class));
    }
}
