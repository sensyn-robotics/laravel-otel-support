<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobQueued;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\InspectsGlobalMiddleware;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * metrics.enabled=false (metrics シグナルを一切出さないサービス向けのマスタースイッチ) が middleware と
 * queue listener の両方の登録を止めることの担保。
 *
 * フラグが no-op に退行すると、運用が「切ったつもり」でも計装が動き続ける (README が緩和策として
 * 案内している操作が効かない) ため、フラグごとに false 側の効果を固定する。
 */
class MetricsDisabledTest extends TestCase
{
    use InspectsGlobalMiddleware;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 読み出し側が既定値付きで get しているため、この部分的な set で兄弟キーが消えても問題にならない
        // (mergeConfigFrom の shallow merge については base TestCase のコメント参照)。
        $app['config']->set('otel-support.metrics.enabled', false);
    }

    public function test_http_middleware_is_not_registered(): void
    {
        $this->assertFalse($this->hasGlobalMiddleware(HttpServerMetrics::class));
    }

    public function test_queue_listeners_are_not_registered(): void
    {
        $this->assertFalse(app(Dispatcher::class)->hasListeners(JobQueued::class));
    }
}
