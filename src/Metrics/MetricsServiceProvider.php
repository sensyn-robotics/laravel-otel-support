<?php

namespace Sensyn\LaravelOTelSupport\Metrics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;

/**
 * OTel metrics 計装の登録。
 *
 * - 計装コードはこのモジュール (Sensyn\LaravelOTelSupport\Metrics) 配下に閉じ、OTel api のみに依存する
 *   (SDK / exporter の配線は消費側アプリ = env autoload に任せる)。
 * - config の merge / publish は umbrella の OTelSupportServiceProvider が担う。本 provider は
 *   otel-support.metrics.* を読むだけ。
 * - middleware は消費側の middleware 定義を編集させず、boot() の prependMiddleware で登録する。
 */
class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 計装クラスは MeterProviderInterface (api) のみに依存し、Globals (SDK autoload) への
        // 依存はこの contextual binding に閉じる。アプリ全体には bind せず、consumer (middleware /
        // QueueMetrics) の解決時にだけ注入する。
        $this->app->when([HttpServerMetrics::class, QueueMetrics::class])
            ->needs(MeterProviderInterface::class)
            ->give(static fn (): MeterProviderInterface => Globals::meterProvider());

        // HttpServerMetrics は global middleware で、pipeline と Kernel::terminateMiddleware で
        // リクエストごとに2回 make される。singleton にして meter/histogram 解決を1回に抑える。
        $this->app->singleton(HttpServerMetrics::class);

        // QueueMetrics も singleton。listener は「発火時に container から解決する」形で登録する
        // (boot の QueueMetrics::subscribeLazily) ため、JobProcessing で記録した開始時刻を
        // JobProcessed / JobFailed 側で使うには、毎回の解決で同一インスタンスが返る必要がある。
        $this->app->singleton(QueueMetrics::class);
    }

    public function boot(): void
    {
        // config() / config_path() は illuminate/foundation の helper で、どの illuminate/* コンポーネントにも
        // 含まれない (laravel/framework を require しない限り宣言できない依存になる)。本パッケージは
        // illuminate/contracts + illuminate/support だけで動くよう、container 経由で config repository を引く
        // (framework 自身の ServiceProvider::mergeConfigFrom も同じ手段を使っている)。
        $config = $this->app->make(ConfigRepository::class);

        if (! $config->get('otel-support.metrics.enabled', true)) {
            return;
        }

        if ($config->get('otel-support.metrics.http.enabled', true)) {
            $this->registerHttpMiddleware();
        }

        if ($config->get('otel-support.metrics.queue.enabled', true)) {
            // listener 登録はここ (boot) で行い、QueueMetrics 本体の生成 (meter + instrument 3 本) は
            // queue イベントの初回発火まで遅らせる。全 HTTP リクエスト / 全 artisan 実行で計装を
            // eager 生成しないため。購読タイミング自体は変わらない。
            QueueMetrics::subscribeLazily($this->app->make(Dispatcher::class));
        }
    }

    private function registerHttpMiddleware(): void
    {
        // global stack の先頭に prepend して後続 middleware 込みのほぼ全リクエスト時間を取る。
        // 消費側に登録作業を要求しないため、Laravel 11+ 推奨の bootstrap/app.php withMiddleware() ではなく
        // HTTP kernel の prependMiddleware() を使う (Laravel 10 の Http/Kernel.php も編集不要になる)。
        //
        // ただし boot() で無条件に make すると、HTTP kernel を使わない artisan (queue:work 等) でも
        // HTTP kernel を1つ生成してしまう。かわりに「既に解決済みか」で分岐する:
        //   - HTTP リクエスト経路: kernel は boot より前に解決済み = 即 prepend (public/index.php の make /
        //     Application::handleRequest、Octane も ApplicationFactory::getBootstrappers が bootstrapWith の
        //     前に HTTP kernel を make する)。
        //   - console 経路: 未解決なので解決フックに預け、実際に HTTP kernel が作られた時だけ prepend。
        // runningInConsole() で分岐しないのは、Octane では HTTP リクエスト処理中も PHP_SAPI=cli で
        // true になり、HTTP metrics が丸ごと無効化されてしまうため。
        if ($this->app->resolved(Kernel::class)) {
            $this->prependMiddleware($this->app->make(Kernel::class));

            return;
        }

        // resolving ではなく afterResolving に預ける。kernel の生成直後に global middleware 配列を
        // 差し替える afterResolving フック (Testbench の setGlobalMiddleware がまさにそれ) があると、
        // resolving で prepend した分は黙って消える。afterResolving なら先に登録済みの差し替えより
        // 後に走るので prepend が残る。
        $this->app->afterResolving(Kernel::class, function (mixed $kernel): void {
            $this->prependMiddleware($kernel);
        });
    }

    private function prependMiddleware(mixed $kernel): void
    {
        // prependMiddleware は重複登録を回避する。console kernel など HTTP kernel 以外の実装では
        // メソッドが存在しないので method_exists で守る。
        //
        // is_object() は静的解析用のガード: Container::make() が呼び出しクラスに解決される generics
        // を持つのは Laravel 11 以降で、10 系の docblock は mixed のままなので、そこでは
        // method_exists() の第1引数許容型 (object|class-string) までしか絞られない。実行時は常に
        // Kernel 実装 (object) なので挙動は変わらない。
        if (is_object($kernel) && method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(HttpServerMetrics::class);
        }
    }
}
