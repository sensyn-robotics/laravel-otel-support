<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobQueued;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\InspectsGlobalMiddleware;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * metrics.queue.enabled=false (キューを使わない / sync driver だけのサービス向けのフラグ) が queue
 * listener の登録だけを止めることの担保。boot 前に値を入れる必要があるので専用クラスにしている。
 */
class QueueMetricsDisabledTest extends TestCase
{
    use InspectsGlobalMiddleware;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('otel-support.metrics.queue.enabled', false);
    }

    public function test_queue_listeners_are_not_registered(): void
    {
        $this->assertFalse(app(Dispatcher::class)->hasListeners(JobQueued::class));
    }

    public function test_http_metrics_are_unaffected(): void
    {
        // このフラグの効果範囲は queue だけ。HTTP 側まで止めてしまう退行を防ぐ。
        $this->assertTrue($this->hasGlobalMiddleware(HttpServerMetrics::class));
    }
}
