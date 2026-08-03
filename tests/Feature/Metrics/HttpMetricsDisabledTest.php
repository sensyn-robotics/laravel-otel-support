<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Foundation\Application;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\InspectsGlobalMiddleware;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * metrics.http.enabled=false (HTTP を持たない worker 専用サービス向けのフラグ) が実際に middleware の
 * 登録を止めることの担保。boot 前に値を入れる必要があるので、環境定義を上書きした専用クラスにしている。
 */
class HttpMetricsDisabledTest extends TestCase
{
    use InspectsGlobalMiddleware;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // mergeConfigFrom は top-level キーの shallow merge なので、ここで metrics を部分的に置くと
        // パッケージ既定の metrics.* 兄弟キーは消える。読み出し側が既定値付きで get しているため
        // (config('otel-support.metrics.enabled', true) 等) このテストでは問題にならない。
        // 他のテストが同じことをしてはいけない理由は base TestCase のコメント参照。
        $app['config']->set('otel-support.metrics.http.enabled', false);
    }

    public function test_middleware_is_not_registered_when_http_metrics_are_disabled(): void
    {
        $this->assertFalse($this->hasGlobalMiddleware(HttpServerMetrics::class));
    }
}
