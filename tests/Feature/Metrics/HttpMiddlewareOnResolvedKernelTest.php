<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\InspectsGlobalMiddleware;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

/**
 * middleware 登録の「HTTP kernel が boot より前に解決済み」分岐 (= 本番の HTTP 経路) の担保。
 *
 * MetricsServiceProvider::registerHttpMiddleware() は 2 分岐あり、既定の Testbench 環境では常に
 * afterResolving 側 (console 経路) しか通らない。本番の FPM / Octane では kernel が provider の boot より
 * 前に解決される (public/index.php の make / Application::handleRequest、Octane の ApplicationFactory) ため、
 * boot 時点で即 prepend する側が使われる。
 *
 * 分岐を切り替えるには boot より前に kernel を解決しておく必要がある。Testbench は
 * defineEnvironment() を「provider の register 後・boot 前」に呼び (CreatesApplication::
 * resolveApplicationBootstrappers)、global middleware を差し替える afterResolving フックはその前に
 * 登録済みなので、ここで make すると本番と同じ順序になる。
 *
 * このテストは即 prepend 分岐が無いと必ず落ちる: container の afterResolving は「登録より前に解決済みの
 * インスタンス」には遡って走らないので、boot で登録する afterResolving 側の分岐は発火しない。
 */
class HttpMiddlewareOnResolvedKernelTest extends TestCase
{
    use InspectsGlobalMiddleware;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make(Kernel::class);
    }

    public function test_middleware_is_prepended_when_the_kernel_is_already_resolved(): void
    {
        $this->assertTrue(app()->resolved(Kernel::class), 'この検証は kernel が boot 前に解決済みである前提。');
        $this->assertTrue($this->hasGlobalMiddleware(HttpServerMetrics::class));
    }
}
