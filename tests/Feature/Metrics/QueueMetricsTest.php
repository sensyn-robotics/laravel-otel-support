<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopCounter;
use OpenTelemetry\API\Metrics\Noop\NoopHistogram;
use ReflectionProperty;
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

    public function test_instrument_failures_do_not_propagate_from_any_listener(): void
    {
        // queue の listener はアプリ本来の処理と同じコールスタックで走る。JobQueued は push 直後に
        // インライン発火して dispatch() の呼び出し元まで伝播し (トランザクション / afterCommit /
        // Bus::batch が壊れる)、JobProcessing は $job->fire() より前なので未実行の job が release や
        // 偽 failure になる。ここで例外を漏らさないことを 5 イベント全部で固定する。
        $calls = [];
        $dispatcher = new Dispatcher;
        (new QueueMetrics($this->providerWithFailingInstruments($calls)))->subscribe($dispatcher);
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob));
        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));
        $dispatcher->dispatch(new JobExceptionOccurred(self::CONN, $job, new RuntimeException('boom')));

        // instrument に到達した 3 件 (enqueue / 成功終端 / 失敗終端)。markStarted と forgetStart は
        // instrument に触らないので、そちらの入口は job key 経路のテストで見る。
        $this->assertSame([
            'messaging.client.sent.messages',
            'laravel.queue.job.finished',
            'laravel.queue.job.finished',
        ], $calls, 'listener が instrument の呼び出しまで到達している必要がある (到達しないなら無意味なテスト)。');
    }

    public function test_duration_failure_after_a_successful_counter_does_not_propagate(): void
    {
        // 終端記録の途中で失敗するケース (counter は成功、histogram が投げる)。部分的な失敗でも
        // listener の外に漏らさない。
        $calls = [];
        $counter = Mockery::mock(CounterInterface::class);
        $counter->shouldReceive('add')->andReturnUsing(function () use (&$calls): void {
            $calls[] = 'add';
        });
        $histogram = Mockery::mock(HistogramInterface::class);
        $histogram->shouldReceive('record')->andReturnUsing(function () use (&$calls): void {
            $calls[] = 'record';
            throw new RuntimeException('histogram exploded');
        });
        $meter = Mockery::mock(MeterInterface::class);
        $meter->shouldReceive('createCounter')->andReturn($counter);
        $meter->shouldReceive('createHistogram')->andReturn($histogram);
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturn($meter);

        $dispatcher = new Dispatcher;
        (new QueueMetrics($meterProvider))->subscribe($dispatcher);
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $this->assertSame(['add', 'record'], $calls, 'counter の後 histogram まで到達している必要がある。');
    }

    public function test_job_key_failures_do_not_propagate_from_any_listener(): void
    {
        // instrument に触らない入口 (markStarted / forgetStart) も含めて守れていること。key() は
        // payload 由来の uuid()/getJobId() を読むので、同じキューに書ける内部 producer が細工した
        // payload だと job 実装側で壊れる (生 body が JSON でなければ payload() が null になり
        // ['uuid'] へのアクセスが warning -> Laravel の HandleExceptions が ErrorException にする)。
        $attempts = 0;
        $job = $this->jobWithFailingKey($attempts);
        $dispatcher = new Dispatcher;
        (new QueueMetrics($this->meterProvider))->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));
        $dispatcher->dispatch(new JobExceptionOccurred(self::CONN, $job, new RuntimeException('boom')));

        $this->assertSame(4, $attempts, '4 イベントすべてが key() まで到達している必要がある。');
    }

    public function test_later_listeners_still_run_when_recording_fails(): void
    {
        // listener の登録順は「provider の boot (本計装) → コマンドの handle」。JobFailed の後ろには
        // WorkCommand::listenForEvents() が登録する logFailedJob (failed_jobs への insert) が並ぶので、
        // ここで投げると Dispatcher が後続 listener を呼ばず、payload がキューからも failed_jobs からも
        // 消えて queue:retry すらできなくなる (JobProcessed 側でも worker の success 出力や
        // アプリの後処理が同様に飛ぶ)。
        $calls = [];
        $dispatcher = new Dispatcher;
        (new QueueMetrics($this->providerWithFailingInstruments($calls)))->subscribe($dispatcher);

        $ran = [];
        $dispatcher->listen(JobFailed::class, function () use (&$ran): void {
            $ran[] = JobFailed::class;
        });
        $dispatcher->listen(JobProcessed::class, function () use (&$ran): void {
            $ran[] = JobProcessed::class;
        });

        $job = $this->consumerJob(FakeQueuedJob::class);
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $this->assertSame([JobFailed::class, JobProcessed::class], $ran);
        $this->assertSame(
            ['laravel.queue.job.finished', 'laravel.queue.job.finished'],
            $calls,
            '計装が実際に投げる所まで到達している必要がある (到達しなければ後続が走るのは当たり前)。',
        );
    }

    public function test_constructor_failure_falls_back_to_noop_instruments(): void
    {
        $attempts = 0;
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(function () use (&$attempts): MeterInterface {
            $attempts++;
            throw new RuntimeException('meter provider exploded');
        });

        $metrics = new QueueMetrics($meterProvider);
        $this->assertSame(1, $attempts, 'constructor は getMeter() まで到達している必要がある。');

        // Noop であることまで見る: フォールバックの代入だけを落とすと「未初期化プロパティ = Error」が
        // guard() に飲まれて黙るので、「例外が出ない」だけではガードの半分が抜けても気付けない。
        $expected = ['sent' => NoopCounter::class, 'finished' => NoopCounter::class, 'duration' => NoopHistogram::class];
        foreach ($expected as $property => $class) {
            $this->assertInstanceOf(
                $class,
                (new ReflectionProperty(QueueMetrics::class, $property))->getValue($metrics),
                "instrument の構築に失敗したら {$property} は Noop instrument に倒す必要がある。",
            );
        }

        // フォールバック済みのインスタンスで 5 イベントすべてが無害に通る。
        $dispatcher = new Dispatcher;
        $metrics->subscribe($dispatcher);
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob));
        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));
        $dispatcher->dispatch(new JobFailed(self::CONN, $job, new RuntimeException('boom')));
        $dispatcher->dispatch(new JobExceptionOccurred(self::CONN, $job, new RuntimeException('boom')));
    }

    /**
     * instrument が「呼ばれた記録を残してから投げる」MeterProvider。到達確認ができるので、テストが
     * 「計装に届いていないだけ」= vacuous にならない。
     *
     * @param  list<string>  $calls  記録に失敗した instrument 名が積まれる
     */
    private function providerWithFailingInstruments(array &$calls): MeterProviderInterface
    {
        $failWith = static function (string $name) use (&$calls): Closure {
            return static function () use ($name, &$calls): void {
                $calls[] = $name;
                throw new RuntimeException($name.' exploded');
            };
        };

        $meter = Mockery::mock(MeterInterface::class);
        $meter->shouldReceive('createCounter')->andReturnUsing(function (string $name) use ($failWith): CounterInterface {
            $counter = Mockery::mock(CounterInterface::class);
            $counter->shouldReceive('add')->andReturnUsing($failWith($name));

            return $counter;
        });
        $meter->shouldReceive('createHistogram')->andReturnUsing(function (string $name) use ($failWith): HistogramInterface {
            $histogram = Mockery::mock(HistogramInterface::class);
            $histogram->shouldReceive('record')->andReturnUsing($failWith($name));

            return $histogram;
        });

        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturn($meter);

        return $meterProvider;
    }

    /** key() の元になる payload 由来の値の取得が失敗する job double ($attempts に到達回数を積む)。 */
    private function jobWithFailingKey(int &$attempts): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturnUsing(function () use (&$attempts): ?string {
            $attempts++;
            throw new RuntimeException('job payload is not decodable');
        });
        // 終端判定は key() より先に見られるので、そこは正常値を返す。
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);

        return $job;
    }
}
