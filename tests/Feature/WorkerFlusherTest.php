<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use ArrayObject;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Mockery;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Sensyn\LaravelOTelSupport\WorkerFlusher;

/**
 * 本番 Worker のイベント発火の形に合わせる: Looping は $events->until() (halt=true)、JobProcessing /
 * WorkerStopping は dispatch()。Looping リスナが false を返すと daemonShouldRun() が止まる仕様なので、
 * until() 経路で flush が走り worker を止めないことをここで担保する。
 */
class WorkerFlusherTest extends TestCase
{
    private InMemoryExporter $spanExporter;

    private TracerProvider $tracerProvider;

    private MetricInMemoryExporter $metricExporter;

    private MeterProviderInterface $meterProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // scheduledDelay を大きくして onEnd の自動 export を封じる。export が起きたら forceFlush が
        // 効いた証拠になる (worker でジョブ完了ごとに flush する挙動を再現)。
        $this->spanExporter = new InMemoryExporter(new ArrayObject);
        $this->tracerProvider = new TracerProvider(new BatchSpanProcessor(
            $this->spanExporter,
            Clock::getDefault(),
            scheduledDelayMillis: 60_000,
        ));

        // metric は ExportingReader 経由で、forceFlush 時にだけ in-memory exporter へ出る。
        $this->metricExporter = new MetricInMemoryExporter;
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader(new ExportingReader($this->metricExporter))
            ->build();
    }

    private function subscribedDispatcher(): Dispatcher
    {
        $dispatcher = new Dispatcher;
        (new WorkerFlusher($this->tracerProvider, $this->meterProvider))->subscribe($dispatcher);

        return $dispatcher;
    }

    private function endOneSpan(): void
    {
        $this->tracerProvider->getTracer('test')->spanBuilder('job-span')->startSpan()->end();
    }

    public function test_looping_flushes_span_after_a_job(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $this->endOneSpan();
        $dispatcher->until(new Looping('redis', 'default'));

        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function test_looping_flushes_metrics_after_a_job(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $this->meterProvider->getMeter('test')->createCounter('jobs')->add(1);

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $dispatcher->until(new Looping('redis', 'default'));

        // forceFlush で ExportingReader が export しなければ exporter は空のまま。
        $this->assertNotEmpty($this->metricExporter->collect(true));
    }

    public function test_looping_listener_does_not_halt_the_worker(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $this->endOneSpan();

        // until() が false を返すと daemonShouldRun() が worker を止める。flush listener は void を返す
        // ので、flush が走っても false にならない (= worker を止めない) ことを担保する。
        $this->assertNotFalse($dispatcher->until(new Looping('redis', 'default')));
    }

    public function test_looping_does_not_flush_when_no_job_processed(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        // JobProcessing 無し = アイドルのポーリング周回。dirty フラグで flush しない。
        $this->endOneSpan();
        $this->meterProvider->getMeter('test')->createCounter('jobs')->add(1);
        $dispatcher->until(new Looping('redis', 'default'));

        $this->assertCount(0, $this->spanExporter->getSpans());
        $this->assertEmpty($this->metricExporter->collect(true));
    }

    public function test_worker_stopping_flushes_final_job(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $this->endOneSpan();
        $dispatcher->dispatch(new WorkerStopping);

        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function test_pending_resets_so_idle_loop_after_flush_is_a_noop(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $this->endOneSpan();
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertCount(1, $this->spanExporter->getSpans());

        // 次の周回はジョブ無し。新しい span を作っても flush されない (pending=false に戻っている)。
        $this->endOneSpan();
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertCount(1, $this->spanExporter->getSpans());
    }
}
