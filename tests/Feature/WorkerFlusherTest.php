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
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProvider;
use ReflectionProperty;
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

    /**
     * export された metric の名前一覧を返す。
     *
     * 「metrics が flush された」の検証に collect() の非空判定を使ってはいけない: WorkerFlusher の
     * constructor は失敗が 1 件も無くても FailureCounter を作るので、instrument
     * laravel.otel_support.failures が MeterProvider に登録され、**data point 0 件の空メトリクスとして
     * 毎回 export に載る**。つまり非空判定はアプリ側のメトリクスが 1 件も出ていなくても成立してしまい、
     * flush 経路が壊れても落ちないアサーションになる。名前で絞って初めて境界の flush を検証できる。
     *
     * @return list<string>
     */
    private function exportedMetricNames(): array
    {
        $names = [];
        foreach ($this->metricExporter->collect(true) as $metric) {
            $names[] = $metric->name;
        }

        return $names;
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

        // forceFlush で ExportingReader が export しなければアプリ側の instrument は出てこない。
        $this->assertContains('jobs', $this->exportedMetricNames());
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

    public function test_octane_request_terminated_flushes_without_a_job(): void
    {
        // Octane の HTTP worker は 1 プロセスで数百〜数千リクエストを処理するので、リクエスト境界で
        // flush しないと HTTP metrics が worker の recycle まで出ない。ジョブ境界と違って
        // JobProcessing (dirty フラグの起点) は飛ばないため、この経路は無条件に flush する必要がある。
        $dispatcher = $this->subscribedDispatcher();
        $this->endOneSpan();
        $this->meterProvider->getMeter('test')->createCounter('requests')->add(1);

        $dispatcher->dispatch(WorkerFlusher::OCTANE_REQUEST_TERMINATED);

        $this->assertCount(1, $this->spanExporter->getSpans());
        $this->assertContains('requests', $this->exportedMetricNames());
    }

    public function test_meter_is_flushed_even_when_the_tracer_flush_fails(): void
    {
        // provider ごとに独立して flush する必要がある。1 つの try で直列に呼ぶと、tracer が投げた時点で
        // meter に到達せず pending も落ちないので、次の周回も同じ順で同じ所で落ちる = meter が worker の
        // 生存中一度も flush されない状態が固定される (TracerProvider::forceFlush は実在の throw 経路を
        // 持つ: BatchSpanProcessor::flush は直接 forceFlush されると捕まえた例外を再 throw する)。
        $attempts = 0;
        $flusher = new WorkerFlusher($this->tracerProviderThatFailsToFlush($attempts), $this->meterProvider);
        $dispatcher = new Dispatcher;
        $flusher->subscribe($dispatcher);
        $this->meterProvider->getMeter('test')->createCounter('jobs')->add(1);

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $dispatcher->until(new Looping('redis', 'default'));

        $this->assertSame(1, $attempts, 'tracer の forceFlush まで到達している必要がある。');
        $this->assertContains(
            'jobs',
            $this->exportedMetricNames(),
            'tracer の flush 失敗で meter の flush が飛んではいけない。',
        );
    }

    public function test_api_noop_providers_are_skipped_by_the_force_flush_guard(): void
    {
        // 消費側が SDK autoload を切っている / 一部の signal だけ有効にしている構成では Globals が
        // api の Noop provider を返す。forceFlush は SDK 実装にしか無いので method_exists のガードが
        // 要る。ここが前提: Noop に forceFlush が生えていたらこのテストは何も再現しない。
        $tracerProvider = new NoopTracerProvider;
        $meterProvider = new NoopMeterProvider;
        $this->assertHasNoForceFlush($tracerProvider);
        $this->assertHasNoForceFlush($meterProvider);

        $flusher = new WorkerFlusher($tracerProvider, $meterProvider);
        $dispatcher = new Dispatcher;
        $flusher->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $this->assertNotFalse($dispatcher->until(new Looping('redis', 'default')));

        // ガードを外すと "Call to undefined method" が forceFlush() の catch に飲まれ、失敗扱いになって
        // pending が落ちない (= 毎周回同じ Error を踏み続け、flush が永久に成立しない)。
        $this->assertFalse(
            $this->pendingOf($flusher),
            'Noop provider に対する flush は成功として扱う必要がある (毎周回の再試行にしてはいけない)。',
        );
    }

    /**
     * Octane のイベント名を **リテラルで** 固定する。
     *
     * このイベントはクラス名の文字列で購読している (laravel/octane を依存に持たないため)。他のテストは
     * すべて定数 OCTANE_REQUEST_TERMINATED 経由で listen と dispatch を行うので、リテラルが何であっても
     * 両者が一致してしまい、値そのものが間違っていることを検知できない。
     *
     * リテラルが実クラス名とズレると、Octane worker では誰も RequestTerminated を購読していない状態に
     * なり、per-request の forceFlush が完全に止まる。例外もログも出ないので、症状は「Octane の
     * サービスだけ span/metric が worker recycle まで遅れる」という判別困難な形でしか出ない。
     */
    public function test_octane_event_name_matches_the_upstream_class(): void
    {
        $this->assertSame(
            'Laravel\Octane\Events\RequestTerminated',
            WorkerFlusher::OCTANE_REQUEST_TERMINATED,
        );
    }

    /**
     * 引数を object で受けるのは静的解析のため: 具象の Noop クラスを直接渡すと PHPStan が
     * 「常に false」と判定して落ちる (= 前提が静的にも成り立っていることの裏返し)。
     */
    private function assertHasNoForceFlush(object $provider): void
    {
        $this->assertFalse(
            method_exists($provider, 'forceFlush'),
            'api の Noop provider に forceFlush が無いことが前提 (生えているならガードのテストは無意味)。',
        );
    }

    private function pendingOf(WorkerFlusher $flusher): bool
    {
        return (bool) (new ReflectionProperty(WorkerFlusher::class, 'pending'))->getValue($flusher);
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

    public function test_idle_loop_flushes_only_metrics_once_the_interval_has_passed(): void
    {
        $clock = new TestClock;
        $dispatcher = new Dispatcher;
        (new WorkerFlusher($this->tracerProvider, $this->meterProvider, $clock))->subscribe($dispatcher);

        $this->endOneSpan();
        $this->meterProvider->getMeter('test')->createCounter('jobs')->add(1);

        $clock->advanceSeconds(59);
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertNotContains('jobs', $this->exportedMetricNames());

        $clock->advanceSeconds(1);
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertContains('jobs', $this->exportedMetricNames());
        // traces は作業が無ければ溜まらない前提なので、アイドルの flush では触らない。
        $this->assertCount(0, $this->spanExporter->getSpans());
    }

    public function test_job_flush_restarts_the_idle_interval(): void
    {
        $clock = new TestClock;
        $dispatcher = new Dispatcher;
        (new WorkerFlusher($this->tracerProvider, $this->meterProvider, $clock))->subscribe($dispatcher);
        $counter = $this->meterProvider->getMeter('test')->createCounter('jobs');

        $clock->advanceSeconds(50);
        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $dispatcher->until(new Looping('redis', 'default'));
        $this->exportedMetricNames();

        // 起動からは 60 秒を超えたが、ジョブ境界の flush からはまだ 50 秒。
        $counter->add(1);
        $clock->advanceSeconds(50);
        $dispatcher->until(new Looping('redis', 'default'));
        $this->assertNotContains('jobs', $this->exportedMetricNames());
    }

    public function test_idle_flush_keeps_the_start_of_a_new_series_recent(): void
    {
        // 狙いは export 先で系列の先頭に 0 を補えるようにすること: delta の data point の start は前回 collect
        // した時刻なので、アイドル中の flush があれば、久しぶりのジョブで初めて出た系列の start がそこまでしか遡らない。
        $clock = new TestClock;
        $exporter = new MetricInMemoryExporter(temporality: Temporality::DELTA);
        $meterProvider = (new MeterProviderBuilder)
            ->addReader(new ExportingReader($exporter))
            ->setClock($clock)
            ->build();
        $dispatcher = new Dispatcher;
        (new WorkerFlusher($this->tracerProvider, $meterProvider, $clock))->subscribe($dispatcher);
        $counter = $meterProvider->getMeter('test')->createCounter('jobs');

        $clock->advanceSeconds(60);
        $dispatcher->until(new Looping('redis', 'default'));
        $idleFlushedAt = $clock->now();

        $clock->advanceSeconds(30);
        $dispatcher->dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));
        $counter->add(1, ['outcome' => 'first-seen']);
        $dispatcher->until(new Looping('redis', 'default'));

        $starts = [];
        foreach ($exporter->collect(true) as $metric) {
            if ($metric->name === 'jobs') {
                $this->assertInstanceOf(Sum::class, $metric->data);
                foreach ($metric->data->dataPoints as $point) {
                    $starts[] = $point->startTimestamp;
                }
            }
        }
        $this->assertSame([$idleFlushedAt], $starts);
    }
}
