<?php

namespace Sensyn\LaravelOTelSupport;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Sensyn\LaravelOTelSupport\Metrics\MetricsServiceProvider;

/**
 * OTel 補完計装 (auto-laravel + SDK autoload の穴埋め) をまとめる umbrella provider。
 *
 * - config は本 provider が 1 回だけ merge / publish し、子 (Metrics) は otel-support.* を読むだけにする。
 * - 計装本体はサブモジュール配下に閉じ、OTel api のみに依存する (SDK / exporter の配線は消費側アプリの env autoload に任せる)。
 * - サブモジュール横断の worker flush は WorkerFlusher が担い、本 provider が配線する (理由は WorkerFlusher を参照)。
 *
 * @api Composer の package auto-discovery (extra.laravel.providers) が登録する公開 provider。
 */
class OTelSupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // config の merge は register() で行う (boot より前に値が揃う = Laravel の作法)。
        // 子 provider の boot() が otel-support.* を読む時点では merge 済み。
        $this->mergeConfigFrom(__DIR__.'/../config/otel-support.php', 'otel-support');

        $this->app->register(MetricsServiceProvider::class);

        // WorkerFlusher は provider interface (api) のみに依存し、Globals (SDK autoload) への依存は
        // この contextual binding に閉じる。アプリ全体には bind せず、解決時にだけ注入する。
        // Globals が投げたら Noop provider に倒す (理由は SafeGlobals): 解決は listener の発火時に走るので、
        // 素で呼ぶと telemetry の初期化失敗が worker / dispatch 元のコードパスに着弾する。
        $this->app->when(WorkerFlusher::class)
            ->needs(TracerProviderInterface::class)
            ->give(static fn (): TracerProviderInterface => SafeGlobals::tracerProvider());
        $this->app->when(WorkerFlusher::class)
            ->needs(MeterProviderInterface::class)
            ->give(static fn (): MeterProviderInterface => SafeGlobals::meterProvider());

        // listener は発火時に container から解決される (WorkerFlusher::subscribeLazily) ので、dirty
        // フラグ ($pending) をイベント間で共有するには毎回の解決で同一インスタンスが返る必要がある。
        $this->app->singleton(WorkerFlusher::class);
    }

    public function boot(): void
    {
        // 消費側で config を上書きする際の正規手段 (vendor:publish --tag=otel-support-config)。
        // 既定値のままで動くので publish は必須でない。
        //
        // config_path() / config() は illuminate/foundation の helper で、どの illuminate/* コンポーネントにも
        // 含まれない (laravel/framework を require しない限り宣言できない依存になる)。本パッケージは
        // illuminate/contracts + illuminate/support だけで動くよう、Application 契約のパス API と
        // container 経由の config repository を使う。
        $this->publishes([
            __DIR__.'/../config/otel-support.php' => $this->app->configPath('otel-support.php'),
        ], 'otel-support-config');

        if ($this->app->make(ConfigRepository::class)->get('otel-support.worker_flusher.enabled', true)) {
            $this->registerWorkerFlusher();
        }
    }

    private function registerWorkerFlusher(): void
    {
        // listener 登録だけを行い、WorkerFlusher 本体の生成 (= Globals 経由の provider 解決) は購読した
        // イベントの初回発火まで遅らせる。SAPI で分岐しない理由と遅延解決の効果は subscribeLazily 参照。
        WorkerFlusher::subscribeLazily($this->app->make(Dispatcher::class));
    }
}
