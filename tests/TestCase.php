<?php

namespace Sensyn\LaravelOTelSupport\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Sensyn\LaravelOTelSupport\OTelSupportServiceProvider;

/**
 * パッケージテストの base case (orchestra/testbench)。
 *
 * 計装本体は app を必要としない (テストは middleware / subscriber を直接 new する) が、
 * 計装が触るフレームワーク接点が 2 つあるので container は必要:
 * config() の get/set と、Buckets::fromConfig() 内の Log facade。
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * umbrella provider だけを列挙する。
     *
     * OTelSupportServiceProvider::register() が自分で MetricsServiceProvider を register する
     * (umbrella が子を登録する構造) ので、umbrella 1 本で Metrics 側の contextual binding /
     * singleton / middleware prepend / queue listener まで通る。子を並べても Application::register()
     * が既登録を返すので無害だが、umbrella -> 子の配線を検証しなくなるので列挙しない。
     *
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [OTelSupportServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Buckets::fromConfig() は不正値で Log::warning を出す。null driver にして
        // Testbench の skeleton にログファイルを書かせない。
        $app['config']->set('logging.default', 'null');

        // 注意: ここで otel-support.* を set しないこと。mergeConfigFrom() は top-level キーの
        // shallow merge なので、app 側に値を置くとパッケージ既定の兄弟キーが黙って消える。
        // 値を変えたいテストは boot 後にテストメソッド内で config() で set する (merge を経由しない)。
    }

    protected function setUp(): void
    {
        parent::setUp();

        // OTel の context storage はプロセスグローバル。テストごとに新しい storage にして、
        // あるテストが載せた context (HttpServerMetrics は Context::getCurrent() を捕まえる) が
        // 次のテストに漏れないようにする。
        Context::setStorage(new ContextStorage);
    }
}
