<?php

use Sensyn\LaravelOTelSupport\Metrics\Buckets;

return [
    'metrics' => [
        /*
        |----------------------------------------------------------------------
        | OpenTelemetry メトリクス関連機能の有効化
        |----------------------------------------------------------------------
        |
        | false にすると metrics モジュールは一切有効化しない (middleware も登録しない)。
        | MeterProvider が Noop でも record/flush は無害なので、metrics exporter が
        | 未有効でもこのフラグは true のままでよい。
        |
        */
        'enabled' => env('OTEL_METRICS_INSTRUMENTATION_ENABLED', true),

        'http' => [
            // http のメトリクス計装を有効にする。
            'enabled' => true,

            // explicit-bucket (秒)。既定は semconv 推奨値 (Buckets::DEFAULT_HTTP)。
            // プロダクトの実分布・SLO に合わせて env OTEL_METRICS_HTTP_BUCKETS (カンマ区切り) で上書き可。
            // env が空・非数値なら Buckets::parse() が既定にフォールバックする。
            'buckets' => Buckets::parse(env('OTEL_METRICS_HTTP_BUCKETS'), Buckets::DEFAULT_HTTP),
        ],

        'queue' => [
            // queue worker のメトリクス計装を有効にする。
            'enabled' => true,

            // job duration の explicit-bucket (秒)。既定は semconv (messaging) 推奨値 (Buckets::DEFAULT_QUEUE)。
            // job が秒〜分かかるなら env OTEL_METRICS_QUEUE_DURATION_BUCKETS (カンマ区切り) で上書きする。
            // env が空・非数値なら Buckets::parse() が既定にフォールバックする。
            'duration_buckets' => Buckets::parse(env('OTEL_METRICS_QUEUE_DURATION_BUCKETS'), Buckets::DEFAULT_QUEUE),
        ],
    ],

    'worker_flusher' => [
        /*
        |----------------------------------------------------------------------
        | queue worker のジョブ境界での flush
        |----------------------------------------------------------------------
        |
        | queue:work daemon は長命プロセスで、span / metric は SDK の processor / reader に溜まり
        | process 終了時の shutdown flush まで送られない。true にするとジョブ完了ごとに provider を
        | forceFlush する。provider が Noop でも flush は無害なので、exporter 未有効でも true のままでよい。
        |
        | false にすると trace・metrics 両方の per-job flush が止まる (QueueMetrics は自前 flush しない)。
        | process 終了時の SDK shutdown flush 任せになり、長命 daemon では export が大幅に遅延/欠落しうるので、
        | 基本 true。flush のオーバーヘッドが問題になった場合のみ切る。
        |
        */
        'enabled' => env('OTEL_WORKER_FLUSH_ENABLED', true),
    ],
];
