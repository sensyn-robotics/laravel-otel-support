<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use RuntimeException;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\FakeQueuedJob;
use Sensyn\LaravelOTelSupport\Tests\Support\MakesQueueEvents;
use Sensyn\LaravelOTelSupport\Tests\Support\RecordsMetrics;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * provider が実際に使う購読経路 (QueueMetrics::subscribeLazily) の担保。
 *
 * QueueMetricsTest はインスタンスを直接 new して subscribe() する経路を検証しており、本番で通る
 * 「boot で listener 登録 → 発火時に container から解決」の経路は別物なので、ここで押さえる。
 * この経路が壊れると queue の metrics が黙って記録されなくなる (例外は出ない)。
 */
class QueueMetricsSubscriptionTest extends TestCase
{
    use MakesQueueEvents;
    use RecordsMetrics;

    public function test_queue_metrics_is_not_resolved_during_boot(): void
    {
        // boot での eager 生成 (meter + instrument 3 本) をしない。queue イベントが飛ばない
        // web リクエスト / queue:work 以外の artisan では計装を作らない。
        $this->assertFalse(app()->resolved(QueueMetrics::class));
    }

    public function test_queue_metrics_is_bound_as_a_singleton(): void
    {
        // 解決は発火ごとに走るので、JobProcessing で記録した開始時刻を JobProcessed 側で使うには
        // 同一インスタンスが返る必要がある。
        $this->assertSame(app(QueueMetrics::class), app(QueueMetrics::class));
    }

    public function test_every_handled_event_is_subscribed_on_the_application_dispatcher(): void
    {
        // 本番の購読は subscribeLazily 経路だけ。ここで全イベントを見ないと、HANDLERS の一部しか
        // 登録しない退行 (= JobProcessing/JobProcessed/JobFailed 系が黙って記録されなくなる) を
        // 検知できない (behaviour のテストは subscribe() 経路を通っているため素通りする)。
        $events = app(Dispatcher::class);

        foreach (array_keys(QueueMetrics::HANDLERS) as $event) {
            $this->assertTrue(
                $events->hasListeners($event),
                "{$event} の listener がアプリの Dispatcher に登録されている必要がある。",
            );
        }
    }

    public function test_terminal_outcome_is_recorded_through_the_lazily_resolved_subscriber(): void
    {
        // JobProcessing -> JobProcessed の相関 (開始時刻の引き継ぎ) は、遅延解決のたびに同一インスタンスが
        // 返る = singleton であることに依存する唯一の挙動。本番経路で通しておく。
        $this->bootMeter();
        config(['queue.connections.otel-test.driver' => 'redis']);
        app()->when(QueueMetrics::class)
            ->needs(MeterProviderInterface::class)
            ->give(fn (): MeterProviderInterface => $this->meterProvider);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn('uuid-1');
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldReceive('getQueue')->andReturn('example-queue');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('resolveName')->andReturn(FakeQueuedJob::class);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);

        $events = app(Dispatcher::class);
        $events->dispatch(new JobProcessing('otel-test', $job));
        $events->dispatch(new JobProcessed('otel-test', $job));

        $metrics = $this->collectMetrics();
        $this->assertSame(1, $this->counterValue($metrics['laravel.queue.job.finished']));
        // duration は JobProcessing で記録した開始時刻が引き継がれた時だけ出る。
        $this->assertSame(1, $this->histogramCount($metrics['laravel.queue.job.duration']));
    }

    public function test_queue_event_is_recorded_through_the_lazily_resolved_subscriber(): void
    {
        $this->bootMeter();

        // 既定の contextual binding は Globals (SDK autoload 無効 = Noop) なので記録を観測できない。
        // in-memory の SDK MeterProvider に差し替える。boot 後に差し替えが効くこと自体が
        // 「boot 時点では未生成」= 遅延解決の裏付けにもなる。
        app()->when(QueueMetrics::class)
            ->needs(MeterProviderInterface::class)
            ->give(fn (): MeterProviderInterface => $this->meterProvider);

        app(Dispatcher::class)->dispatch(
            $this->jobQueued('otel-test', 'id-1', new FakeQueuedJob),
        );

        $this->assertTrue(app()->resolved(QueueMetrics::class));
        $this->assertArrayHasKey('messaging.client.sent.messages', $this->collectMetrics());
    }

    public function test_constructor_failure_does_not_propagate_through_the_lazily_resolved_listener(): void
    {
        // 構築は発火時に container が行う (Dispatcher::createClassCallable) ため、失敗はイベントの発火点 =
        // アプリの dispatch() / worker のコードパスに着弾する。handler 本体に try/catch を足しても
        // Dispatcher が本体呼び出しの前に make() するので救えない。直接 new する検証ではこの穴を見逃す
        // ので、必ず listener 経由で確かめる。
        $attempts = 0;
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(function () use (&$attempts): MeterInterface {
            $attempts++;
            throw new RuntimeException('meter provider exploded');
        });

        app()->when(QueueMetrics::class)
            ->needs(MeterProviderInterface::class)
            ->give(fn (): MeterProviderInterface => $meterProvider);

        app(Dispatcher::class)->dispatch(
            $this->jobQueued('otel-test', 'id-1', new FakeQueuedJob),
        );

        $this->assertSame(1, $attempts, '発火時解決で constructor が走り getMeter() まで到達している必要がある。');
        $this->assertTrue(app()->resolved(QueueMetrics::class));
    }
}
