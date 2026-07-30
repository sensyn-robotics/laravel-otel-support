<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use RuntimeException;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\FakeQueuedJob;
use Sensyn\LaravelOTelSupport\Tests\Support\MakesQueueEvents;
use Sensyn\LaravelOTelSupport\Tests\Support\RecordsMetrics;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

class QueueMetricsTest extends TestCase
{
    use MakesQueueEvents;
    use RecordsMetrics;

    private const CONN = 'otel-test';

    protected function setUp(): void
    {
        parent::setUp();
        // driver は config から引く。テスト用 connection を固定。
        config(['queue.connections.'.self::CONN.'.driver' => 'redis']);
        $this->bootMeter();
    }

    private function subscribedDispatcher(): Dispatcher
    {
        $dispatcher = new Dispatcher;
        (new QueueMetrics($this->meterProvider))->subscribe($dispatcher);

        return $dispatcher;
    }

    private function consumerJob(
        string $name,
        string $queue = 'example-queue',
        int $attempts = 1,
        bool $released = false,
        bool $failed = false,
    ): Job {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn('uuid-1');
        $job->shouldReceive('getJobId')->andReturn('jobid-1');
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldReceive('resolveName')->andReturn($name);
        $job->shouldReceive('isReleased')->andReturn($released);
        $job->shouldReceive('hasFailed')->andReturn($failed);

        return $job;
    }

    public function test_enqueue_records_sent_counter(): void
    {
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob));

        $metrics = $this->collectMetrics();
        $this->assertArrayHasKey('messaging.client.sent.messages', $metrics);

        $sent = $metrics['messaging.client.sent.messages'];
        $this->assertSame(1, $this->counterValue($sent));
        $this->assertSame([
            'messaging.system' => 'redis',
            'messaging.destination.name' => 'emails',
            'messaging.operation.type' => 'send',
            'laravel.queue.job.name' => FakeQueuedJob::class,
        ], $this->attributesOf($sent));
    }

    public function test_successful_terminal_has_no_error_type_and_records_duration(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $metrics = $this->collectMetrics();

        $finished = $metrics['laravel.queue.job.finished'];
        $this->assertSame(1, $this->counterValue($finished));
        $attrs = $this->attributesOf($finished);
        $this->assertArrayNotHasKey('error.type', $attrs);
        $this->assertSame(FakeQueuedJob::class, $attrs['laravel.queue.job.name']);
        $this->assertSame('example-queue', $attrs['messaging.destination.name']);
        $this->assertFalse($attrs['laravel.queue.job.retried']); // attempts=1

        // JobProcessing を経たので duration が記録される。
        $this->assertArrayHasKey('laravel.queue.job.duration', $metrics);
        $this->assertSame(1, $this->histogramCount($metrics['laravel.queue.job.duration']));
    }

    public function test_failed_terminal_sets_error_type(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));

        $metrics = $this->collectMetrics();

        $attrs = $this->attributesOf($metrics['laravel.queue.job.finished']);
        $this->assertSame(RuntimeException::class, $attrs['error.type']);
        $this->assertSame(1, $this->counterValue($metrics['laravel.queue.job.finished']));
    }

    public function test_failure_without_processing_counts_terminal_but_no_duration(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        // pre-exec の maxattempts 超過など JobProcessing 無しの最終失敗。
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));

        $metrics = $this->collectMetrics();

        $this->assertSame(1, $this->counterValue($metrics['laravel.queue.job.finished']));
        // 開始時刻が無いので duration は記録されない (data point 0 件)。
        $duration = $metrics['laravel.queue.job.duration'] ?? null;
        $this->assertTrue($duration === null || $this->dataPoints($duration) === []);
    }

    public function test_retried_flag_is_on_counter_only_not_histogram(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class, attempts: 3);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $metrics = $this->collectMetrics();

        // attempts=3 なので retried=true。finished にのみ付き、histogram には付けない。
        $this->assertTrue($this->attributesOf($metrics['laravel.queue.job.finished'])['laravel.queue.job.retried']);
        $this->assertArrayNotHasKey('laravel.queue.job.retried', $this->attributesOf($metrics['laravel.queue.job.duration']));
    }

    public function test_terminal_is_counted_once(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $metrics = $this->collectMetrics();
        // 1 メッセージ 1 終端。data point は 1 つ・値も 1。
        $this->assertCount(1, $this->dataPoints($metrics['laravel.queue.job.finished']));
        $this->assertSame(1, $this->counterValue($metrics['laravel.queue.job.finished']));
    }

    public function test_released_job_is_not_counted_as_finished(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        // job が自分を release した attempt: JobProcessed は撃たれるが isReleased()=true。
        $job = $this->consumerJob(FakeQueuedJob::class, released: true);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $metrics = $this->collectMetrics();
        $finished = $metrics['laravel.queue.job.finished'] ?? null;
        $this->assertTrue($finished === null || $this->dataPoints($finished) === []);
    }

    public function test_fail_inside_handle_is_not_double_counted(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        // handle 内で fail() した attempt: JobFailed (失敗計上) の後 JobProcessed が hasFailed()=true で撃たれる。
        $job = $this->consumerJob(FakeQueuedJob::class, failed: true);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $metrics = $this->collectMetrics();
        // 失敗 1 件のみ (JobProcessed 側は計上しない)。
        $this->assertCount(1, $this->dataPoints($metrics['laravel.queue.job.finished']));
        $this->assertSame(RuntimeException::class, $this->attributesOf($metrics['laravel.queue.job.finished'])['error.type']);
    }

    public function test_sync_driver_is_not_counted(): void
    {
        // sync は HTTP 側で計測されるので queue の終端には計上しない (成功カウントの水増し回避)。
        config(['queue.connections.otel-sync.driver' => 'sync']);
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing('otel-sync', $job));
        $dispatcher->dispatch(new JobProcessed('otel-sync', $job));

        $metrics = $this->collectMetrics();
        $finished = $metrics['laravel.queue.job.finished'] ?? null;
        $this->assertTrue($finished === null || $this->dataPoints($finished) === []);
    }

    public function test_invalid_buckets_do_not_throw(): void
    {
        // 不正値でも throw せず既定にフォールバックする (telemetry でアプリを落とさない)。
        config(['otel-support.metrics.queue.duration_buckets' => ['oops']]);

        $this->assertInstanceOf(QueueMetrics::class, new QueueMetrics($this->meterProvider));
    }
}
