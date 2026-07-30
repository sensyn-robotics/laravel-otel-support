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
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\FakeQueuedJob;
use Sensyn\LaravelOTelSupport\Tests\Support\MakesQueueEvents;
use Sensyn\LaravelOTelSupport\Tests\Support\RecordsMetrics;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use stdClass;

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

        // 宛先を明示した push。11+ はイベント側、10 は job 側から同じ 'emails' を読む。
        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob, 'emails'));

        $metrics = $this->collectMetrics();
        $this->assertArrayHasKey('messaging.client.sent.messages', $metrics);

        $sent = $metrics['messaging.client.sent.messages'];
        $this->assertSame(1, $this->counterValue($sent));
        // semconv の messaging.client.sent.messages の属性表どおり: Required の messaging.operation.name /
        // messaging.system が付き、この metric の属性ではない messaging.operation.type は付かない。
        $this->assertSame([
            'messaging.system' => 'redis',
            'messaging.destination.name' => 'emails',
            'messaging.operation.name' => 'send',
            'laravel.queue.job.name' => FakeQueuedJob::class,
        ], $this->attributesOf($sent));
        $this->assertSame('{message}', $metrics['messaging.client.sent.messages']->unit);
    }

    public function test_sqs_driver_is_reported_with_the_semconv_system_value(): void
    {
        // semconv の well-known 値が存在する driver は素通しにできない (MUST)。他言語 auto-instrumentation は
        // 同じブローカーに aws_sqs を出すので、'sqs' のままだと同一 metric が 2 系列に分裂する。
        config(['queue.connections.otel-sqs.driver' => 'sqs']);
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch($this->jobQueued('otel-sqs', 'id-1', new FakeQueuedJob, 'emails'));
        $dispatcher->dispatch(new JobProcessing('otel-sqs', $job));
        $dispatcher->dispatch(new JobProcessed('otel-sqs', $job));

        $metrics = $this->collectMetrics();
        $this->assertSame('aws_sqs', $this->attributesOf($metrics['messaging.client.sent.messages'])['messaging.system']);
        // 終端側 (finished / duration) も同じ写像を通す。
        $this->assertSame('aws_sqs', $this->attributesOf($metrics['laravel.queue.job.finished'])['messaging.system']);
        $this->assertSame('aws_sqs', $this->attributesOf($metrics['laravel.queue.job.duration'])['messaging.system']);
    }

    public function test_sqs_queue_url_is_reported_as_the_queue_name_on_both_sides(): void
    {
        // sqs の consumer 側は Job::getQueue() がフルのキュー URL を返す (SqsQueue::pop が SqsJob に渡す
        // のは getQueue() の戻り = URL)。producer 側は push の宛先 = キュー名なので、URL のままだと
        // 系列が分かれてキュー単位の結合 (sum(sent) - sum(finished)) が成立しない。
        config(['queue.connections.otel-sqs.driver' => 'sqs']);
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(
            FakeQueuedJob::class,
            'https://sqs.ap-northeast-1.amazonaws.com/123456789012/emails',
        );

        $dispatcher->dispatch($this->jobQueued('otel-sqs', 'id-1', new FakeQueuedJob, 'emails'));
        $dispatcher->dispatch(new JobProcessing('otel-sqs', $job));
        $dispatcher->dispatch(new JobProcessed('otel-sqs', $job));

        $metrics = $this->collectMetrics();
        $terminal = $this->attributesOf($metrics['laravel.queue.job.finished'])['messaging.destination.name'];

        $this->assertSame('emails', $terminal, 'URL ではなくキュー名 (URL の最終セグメント) を出す。');
        $this->assertSame('emails', $this->attributesOf($metrics['laravel.queue.job.duration'])['messaging.destination.name']);
        $this->assertSame(
            $this->attributesOf($metrics['messaging.client.sent.messages'])['messaging.destination.name'],
            $terminal,
            'producer と consumer で値が違うとキュー単位で結合できない。',
        );
    }

    public function test_sqs_producer_applies_the_connection_suffix(): void
    {
        // suffix 付き connection (1 つの AWS アカウントを環境間で共有する構成) では、実キュー名が
        // 'emails-staging' になる (SqsQueue::suffixQueue)。producer が suffix を落とすと、consumer 側
        // (実キュー URL 由来) と結合できない。
        config([
            'queue.connections.otel-sqs.driver' => 'sqs',
            'queue.connections.otel-sqs.suffix' => '-staging',
        ]);
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(
            FakeQueuedJob::class,
            'https://sqs.ap-northeast-1.amazonaws.com/123456789012/emails-staging',
        );

        $dispatcher->dispatch($this->jobQueued('otel-sqs', 'id-1', new FakeQueuedJob, 'emails'));
        $dispatcher->dispatch(new JobProcessing('otel-sqs', $job));
        $dispatcher->dispatch(new JobProcessed('otel-sqs', $job));

        $metrics = $this->collectMetrics();
        $this->assertSame(
            'emails-staging',
            $this->attributesOf($metrics['messaging.client.sent.messages'])['messaging.destination.name'],
        );
        $this->assertSame(
            'emails-staging',
            $this->attributesOf($metrics['laravel.queue.job.finished'])['messaging.destination.name'],
        );
    }

    public function test_sqs_producer_puts_the_suffix_before_the_fifo_marker(): void
    {
        // FIFO キューは名前が '.fifo' で終わる必要があるので、suffix はその前に入る
        // (SqsQueue::suffixQueue と同じ規則。'orders.fifo-staging' は実在しないキュー名になる)。
        config([
            'queue.connections.otel-sqs.driver' => 'sqs',
            'queue.connections.otel-sqs.suffix' => '-staging',
        ]);
        $dispatcher = $this->subscribedDispatcher();

        // Laravel 10 (イベントが宛先を持たない版) でも同じ宛先を読めるよう、job 側にも持たせる。
        $job = new stdClass;
        $job->queue = 'orders.fifo';
        $dispatcher->dispatch($this->jobQueued('otel-sqs', 'id-1', $job, 'orders.fifo'));

        $attrs = $this->attributesOf($this->collectMetrics()['messaging.client.sent.messages']);
        $this->assertSame('orders-staging.fifo', $attrs['messaging.destination.name']);
    }

    public function test_sqs_producer_does_not_suffix_a_queue_url_given_by_the_caller(): void
    {
        // SqsQueue::getQueue は URL 形式の宛先をそのまま使う (prefix も suffix も付けない)。producer 側も
        // 同じ条件にしないと consumer 側 (その URL 由来) とずれる。
        config([
            'queue.connections.otel-sqs.driver' => 'sqs',
            'queue.connections.otel-sqs.suffix' => '-staging',
        ]);
        $dispatcher = $this->subscribedDispatcher();

        $url = 'https://sqs.ap-northeast-1.amazonaws.com/123456789012/emails';
        $job = new stdClass;
        $job->queue = $url;
        $dispatcher->dispatch($this->jobQueued('otel-sqs', 'id-1', $job, $url));

        $attrs = $this->attributesOf($this->collectMetrics()['messaging.client.sent.messages']);
        $this->assertSame('emails', $attrs['messaging.destination.name']);
    }

    public function test_driver_without_a_semconv_value_is_passed_through(): void
    {
        // well-known 値が無い driver (redis / database / beanstalkd) はカスタム値として driver 名を出す。
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob, 'emails'));

        $attrs = $this->attributesOf($this->collectMetrics()['messaging.client.sent.messages']);
        $this->assertSame('redis', $attrs['messaging.system']);
    }

    public function test_enqueue_without_an_explicit_queue_uses_the_connection_default(): void
    {
        // onQueue() 等でキューを指定しない dispatch。実際の宛先は connection 既定のキューなので、
        // 'default' 固定にすると consumer 側 ($job->getQueue()) と結合できなくなる。
        config(['queue.connections.'.self::CONN.'.queue' => 'app-jobs']);
        $dispatcher = $this->subscribedDispatcher();

        // 宛先を持たない job (FakeQueuedJob は $queue を持つので使えない) を dispatch する。
        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new stdClass));

        $attrs = $this->attributesOf($this->collectMetrics()['messaging.client.sent.messages']);
        $this->assertSame('app-jobs', $attrs['messaging.destination.name']);
    }

    public function test_enqueue_falls_back_to_default_when_the_connection_has_no_queue(): void
    {
        // 既定キュー名が config に無い connection (テストの既定状態) では 'default' に倒す。
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new stdClass));

        $attrs = $this->attributesOf($this->collectMetrics()['messaging.client.sent.messages']);
        $this->assertSame('default', $attrs['messaging.destination.name']);
    }

    public function test_enqueue_prefers_the_queue_carried_by_the_event(): void
    {
        // Queue::pushOn('high', $job) 相当: 呼び出し側の指定が job 自身の宛先 (FakeQueuedJob::$queue =
        // 'emails') を上書きする。Laravel 11+ はイベントが実際の宛先 'high' を持つのでそれを使い、
        // イベントに宛先が無い 10 では job 側の 'emails' が唯一の手掛かりになる。
        config(['queue.connections.'.self::CONN.'.queue' => 'app-jobs']);
        $dispatcher = $this->subscribedDispatcher();

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob, 'high'));

        $attrs = $this->attributesOf($this->collectMetrics()['messaging.client.sent.messages']);
        $this->assertSame(
            $this->jobQueuedCarriesQueue() ? 'high' : 'emails',
            $attrs['messaging.destination.name'],
            'イベントが宛先を持つ版ではイベント側を優先する必要がある (job 側の値では push 先とずれる)。',
        );
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

    public function test_terminal_duration_is_recorded_in_seconds(): void
    {
        // 単位 (s / {job}) は公開している契約で、値はその単位で記録されている必要がある。属性と件数の
        // テストでは桁が 1000 倍ずれる退行 (秒とミリ秒の取り違え) を一切検知できない。
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        usleep(5_000);
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $metrics = $this->collectMetrics();
        $this->assertSame('{job}', $metrics['laravel.queue.job.finished']->unit);
        $this->assertSame('s', $metrics['laravel.queue.job.duration']->unit);

        $sum = $this->histogramSum($metrics['laravel.queue.job.duration']);
        $this->assertGreaterThanOrEqual(0.005, $sum, '記録値が秒として小さすぎる (単位の取り違え?)。');
        $this->assertLessThan(1.0, $sum, '記録値が秒として大きすぎる (ミリ秒で記録している?)。');
    }

    public function test_configured_duration_buckets_reach_the_histogram(): void
    {
        // 設定した境界が instrument の advisory まで届かないと SDK 既定の境界に黙って落ちる
        // (= OTEL_METRICS_QUEUE_DURATION_BUCKETS が無視される)。config キー名の誤りもここで落ちる。
        config(['otel-support.metrics.queue.duration_buckets' => [0.25, 0.75]]);
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $this->assertSame([0.25, 0.75], $this->histogramBounds($this->collectMetrics()['laravel.queue.job.duration']));
    }

    public function test_default_duration_buckets_are_the_semconv_recommendation(): void
    {
        $dispatcher = $this->subscribedDispatcher();
        $job = $this->consumerJob(FakeQueuedJob::class);

        $dispatcher->dispatch(new JobProcessing(self::CONN, $job));
        $dispatcher->dispatch(new JobProcessed(self::CONN, $job));

        $this->assertSame(
            Buckets::DEFAULT_QUEUE,
            $this->histogramBounds($this->collectMetrics()['laravel.queue.job.duration']),
        );
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
