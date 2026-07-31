<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use Illuminate\Support\Arr;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;
use Sensyn\LaravelOTelSupport\OTelSupportServiceProvider;
use Sensyn\LaravelOTelSupport\Tests\Support\InspectsGlobalMiddleware;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Sensyn\LaravelOTelSupport\WorkerFlusher;

/**
 * パッケージ化して初めて意味を持つ層 (provider の配線) のテスト。移設元 (アプリ内モジュール) には無い。
 *
 * base TestCase が列挙するのは umbrella provider 1 本だけなので、ここが通れば
 * umbrella -> MetricsServiceProvider -> (config merge / contextual binding / middleware / listener)
 * の配線が繋がっていることになる。
 */
class OTelSupportServiceProviderTest extends TestCase
{
    use InspectsGlobalMiddleware;

    public function test_package_config_is_merged(): void
    {
        // 消費側が config を publish しなくても既定値で動くこと (mergeConfigFrom の担保)。
        $this->assertTrue(config('otel-support.metrics.enabled'));
        $this->assertTrue(config('otel-support.metrics.http.enabled'));
        $this->assertTrue(config('otel-support.metrics.queue.enabled'));
        $this->assertTrue(config('otel-support.worker_flusher.enabled'));

        // バケットは env の生値なので未設定なら null。既定への解決は同梱 config ではなく実行時の
        // 読み口が担う (同梱 config からパッケージのクラス参照を外したため。理由は config の冒頭)。
        $this->assertNull(config('otel-support.metrics.http.buckets'));
        $this->assertNull(config('otel-support.metrics.queue.duration_buckets'));
        $this->assertSame(
            Buckets::DEFAULT_HTTP,
            Buckets::fromConfig('otel-support.metrics.http.buckets', Buckets::DEFAULT_HTTP),
        );
        $this->assertSame(
            Buckets::DEFAULT_QUEUE,
            Buckets::fromConfig('otel-support.metrics.queue.duration_buckets', Buckets::DEFAULT_QUEUE),
        );
    }

    public function test_http_middleware_is_prepended_to_the_global_stack(): void
    {
        // 消費側は Kernel.php / bootstrap/app.php を触らない。provider の boot() が prepend する。
        $this->assertTrue($this->hasGlobalMiddleware(HttpServerMetrics::class));

        // stack に居るだけでは足りない。**先頭** であることが計測区間と欠測の有無を決める
        // (理由は globalMiddlewareStack() の docblock)。prepend が push に退行するとここで落ちる。
        $stack = $this->globalMiddlewareStack();
        $this->assertNotSame([], $stack, '検査対象の stack が空では、先頭であることの検査が意味を持たない。');
        $this->assertSame(
            HttpServerMetrics::class,
            $stack[0],
            'HTTP 計装は global middleware の先頭でなければならない (末尾だと短絡応答するリクエストが丸ごと欠測する)。',
        );
    }

    public function test_middleware_is_resolved_as_a_singleton(): void
    {
        // global middleware はリクエストごとに pipeline と terminateMiddleware で 2 回 make される。
        // singleton でないと meter / histogram の解決が毎回走る。
        $this->assertSame(app(HttpServerMetrics::class), app(HttpServerMetrics::class));
    }

    public function test_instrumentation_resolves_without_the_sdk_autoloader(): void
    {
        // MeterProvider / TracerProvider は Globals 経由の contextual binding。SDK autoload 無効 =
        // Noop provider でも解決でき、例外にならないこと (消費側が metrics 未有効でも壊れない)。
        $this->assertInstanceOf(QueueMetrics::class, app(QueueMetrics::class));
        $this->assertInstanceOf(WorkerFlusher::class, app(WorkerFlusher::class));
    }

    public function test_composer_declares_the_provider_for_auto_discovery(): void
    {
        // extra.laravel.providers による auto-discovery 自体は Testbench では検証できない
        // (getPackageProviders が明示登録してしまう)。宣言値が実クラスと一致することで代替する。
        $path = dirname(__DIR__, 2).'/composer.json';
        $this->assertFileExists($path);

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            [OTelSupportServiceProvider::class],
            Arr::get($manifest, 'extra.laravel.providers'),
        );
    }
}
