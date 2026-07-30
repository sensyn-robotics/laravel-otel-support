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
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProvider;
use RuntimeException;
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

    /**
     * forceFlush が投げる TracerProvider。呼ばれた回数を $attempts に積むので到達確認ができる。
     *
     * API の provider interface に forceFlush は無く SDK 実装にしか無い (WorkerFlusher は method_exists で
     * 判定する) ため、mock も SDK の interface で作る。そうしないと method_exists が false になり、
     * flush が呼ばれない = 何も再現しないテストになる。
     */
    private function tracerProviderThatFailsToFlush(int &$attempts): SdkTracerProvider
    {
        $provider = Mockery::mock(SdkTracerProvider::class);
        $provider->shouldReceive('forceFlush')->andReturnUsing(function () use (&$attempts): bool {
            $attempts++;
            throw new RuntimeException('force flush exploded');
        });

        return $provider;
    }

    public function test_looping_flush_failure_does_not_propagate_or_halt_the_worker(): void
    {
        // Looping は Worker::daemonShouldRun() から発火し、daemon() の while にも WorkCommand にも
        // try/catch が無い。例外が漏れると queue:work が exit 1 で死ぬ (k8s ではジョブごとに再起動)。
        $attempts = 0;
        $flusher = new WorkerFlusher($this->tracerProviderThatFailsToFlush($attempts), $this->meterProvider);
        $dispatcher = new Dispatcher;
        $flusher->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));

        // until() が false を返すと daemonShouldRun() が worker を止めるので、そこも同時に見る。
        $this->assertNotFalse($dispatcher->until(new Looping('redis', 'default')));
        $this->assertSame(1, $attempts, 'flush (forceFlush) まで到達している必要がある。');
    }

    public function test_worker_stopping_flush_failure_does_not_propagate(): void
    {
        // WorkerStopping は Worker::kill() (job timeout の SIGALRM handler) からも発火し、その直後に
        // posix_kill/exit がある。例外が漏れるとプロセスが死なず、job 実行中の任意の点に着弾した例外が
        // job 側の try/catch に食われて timeout の強制終了そのものが無効化されうる。
        $attempts = 0;
        $flusher = new WorkerFlusher($this->tracerProviderThatFailsToFlush($attempts), $this->meterProvider);
        $dispatcher = new Dispatcher;
        $flusher->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $dispatcher->dispatch(new WorkerStopping);

        $this->assertSame(1, $attempts, 'flush (forceFlush) まで到達している必要がある。');
    }

    public function test_failed_flush_is_retried_on_the_next_loop(): void
    {
        // 失敗した flush は dirty フラグを落とさない = 次の周回で再試行される (先に落とすと、投げた
        // flush 分の signal が誰にも送られず黙って失われる)。成功したら落ちるので、その後のアイドル
        // 周回は no-op に戻る。
        $attempts = 0;
        $tracerProvider = Mockery::mock(SdkTracerProvider::class);
        $tracerProvider->shouldReceive('forceFlush')->andReturnUsing(function () use (&$attempts): bool {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('force flush exploded');
            }

            return true;
        });

        $flusher = new WorkerFlusher($tracerProvider, $this->meterProvider);
        $dispatcher = new Dispatcher;
        $flusher->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertSame(1, $attempts);

        // ジョブは増えていないが、前回の flush が失敗しているので再試行される。
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertSame(2, $attempts, '失敗した flush は次の Looping で再試行される必要がある。');

        // 2 回目は成功したので、以降のアイドル周回では flush しない。
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertSame(2, $attempts, 'flush 成功後のアイドル周回は no-op である必要がある。');
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
