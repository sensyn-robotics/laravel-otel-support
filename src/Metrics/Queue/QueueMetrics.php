<?php

namespace Sensyn\LaravelOTelSupport\Metrics\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Config;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;

/**
 * queue/messaging の metrics を記録する。
 *
 * - enqueue 数は semconv の意味と一致するので semconv 名 messaging.client.sent.messages。
 * - 終端 (retry 込みの最終 成功/失敗) は semconv の per-delivery と意味が違うので Laravel ドメイン名
 *   laravel.queue.job.*。成功/失敗は finished を error.type の有無で割る。
 */
class QueueMetrics
{
    /**
     * 購読する queue イベント => ハンドラメソッド名。
     *
     * インスタンスを直接持つ場合は subscribe()、container 解決を発火時まで遅らせる場合は
     * subscribeLazily() が使う。マッピングを 1 箇所に集約して両経路のズレ (片方だけ購読漏れ) を防ぐ。
     *
     * @var array<class-string, string>
     */
    public const HANDLERS = [
        JobQueued::class => 'recordEnqueued',
        JobProcessing::class => 'markStarted',
        JobProcessed::class => 'recordProcessed',
        JobFailed::class => 'recordFailed',
        JobExceptionOccurred::class => 'forgetStart',
    ];

    private CounterInterface $sent;

    private CounterInterface $finished;

    private HistogramInterface $duration;

    /** @var array<string, float> job key => 処理開始時刻 */
    private array $start = [];

    public function __construct(MeterProviderInterface $meterProvider)
    {
        $buckets = Buckets::fromConfig('otel-support.metrics.queue.duration_buckets', Buckets::DEFAULT_QUEUE);

        // MeterProvider は provider が container に bind する (Globals 依存は app 側に閉じる)。
        $meter = $meterProvider->getMeter('laravel-otel-support.metrics.queue');

        $this->sent = $meter->createCounter(
            'messaging.client.sent.messages',
            '{message}',
            'Number of enqueued messages',
        );
        $this->finished = $meter->createCounter(
            'laravel.queue.job.finished',
            '{job}',
            'Jobs reaching a terminal outcome (success or final failure)',
        );
        $this->duration = $meter->createHistogram(
            'laravel.queue.job.duration',
            's',
            'Duration of the terminal job attempt',
            ['ExplicitBucketBoundaries' => $buckets],
        );
    }

    /**
     * このインスタンスを listener として登録する (生成済みのインスタンスを使う経路)。
     */
    public function subscribe(Dispatcher $events): void
    {
        foreach (self::HANDLERS as $event => $method) {
            $events->listen($event, [$this, $method]);
        }
    }

    /**
     * container からの解決 (= meter + instrument 3 本の生成) を、queue イベントの初回発火まで遅らせる形で
     * listener を登録する。listener 登録自体は呼び出し時点 (provider の boot) で完了するので、購読の
     * タイミングは subscribe() と変わらない。
     *
     * [クラス名, メソッド名] 形式の listener は Laravel が発火時に container から解決する
     * (Dispatcher::createClassCallable) ため、queue イベントが飛ばないプロセス (web リクエスト /
     * queue:work 以外の artisan) では計装が生成されない。
     *
     * 解決は発火ごとに走るので、開始時刻 ($start) をイベント間で共有するには QueueMetrics が singleton
     * として bind されている必要がある (MetricsServiceProvider::register)。同じ理由で、渡す Dispatcher は
     * アプリの container を持つもの (app('events')) であること。container を持たない Dispatcher を
     * 渡すと解決先が別 container になり、MeterProvider の contextual binding が効かない。
     */
    public static function subscribeLazily(Dispatcher $events): void
    {
        foreach (self::HANDLERS as $event => $method) {
            $events->listen($event, [self::class, $method]);
        }
    }

    public function recordEnqueued(JobQueued $e): void
    {
        $this->sent->add(1, [
            'messaging.system' => $this->driver($e->connectionName),
            'messaging.destination.name' => $this->producerQueue($e),
            'messaging.operation.type' => 'send',
            'laravel.queue.job.name' => $this->producerJobName($e),
        ]);
    }

    public function markStarted(JobProcessing $e): void
    {
        if ($e->job instanceof Job) {
            $this->start[$this->key($e->job)] = microtime(true);
        }
    }

    /**
     * 終端 disposition = メッセージ単位で1回。JobProcessed は fire() が正常 return すれば発火するが、
     * その中には「自分を release して再キューした attempt」や「handle 内で fail() した attempt」も含む
     * (Worker::process)。release は終端でなく fail() は JobFailed で既に計上済みなので、どちらも
     * 成功として計上しない (isReleased/hasFailed でガード)。これを欠くと release/fail で多重計上する。
     */
    public function recordProcessed(JobProcessed $e): void
    {
        if (! $e->job instanceof Job) {
            return;
        }
        if ($e->job->isReleased() || $e->job->hasFailed()) {
            unset($this->start[$this->key($e->job)]);

            return;
        }
        $this->recordTerminal($e->connectionName, $e->job, null);
    }

    /** JobFailed は retry 尽き / 明示 fail / pre-exec の maxattempts 超過で発火 = 最終失敗。 */
    public function recordFailed(JobFailed $e): void
    {
        if ($e->job instanceof Job) {
            $this->recordTerminal($e->connectionName, $e->job, $e->exception::class);
        }
    }

    /** retry される失敗 attempt は終端を持たない。開始時刻のリーク掃除のみ (terminal カウントしない)。 */
    public function forgetStart(JobExceptionOccurred $e): void
    {
        if ($e->job instanceof Job) {
            unset($this->start[$this->key($e->job)]);
        }
    }

    private function recordTerminal(mixed $connection, Job $job, ?string $errorType): void
    {
        $key = $this->key($job);
        $begin = $this->start[$key] ?? null;
        unset($this->start[$key]);

        // sync driver の job はディスパッチ元 (HTTP リクエスト等) でインライン実行され、その所要時間は
        // HTTP metrics 側で計測される。queue の終端に混ぜると成功カウントが水増しされるので計上しない。
        $driver = $this->driver($connection);
        if ($driver === 'sync') {
            return;
        }

        // histogram にも渡す共通属性は低カーディナリティのみ (classic histogram は bucket×属性で series 増)。
        // job 名はクラス数ぶんの有界カーディナリティなので histogram にも付けてよい。
        $attrs = [
            'messaging.system' => $driver,
            'messaging.destination.name' => (string) $job->getQueue(),
            'laravel.queue.job.name' => (string) $job->resolveName(),
        ];
        // error.type (例外クラス名) は counter と histogram (duration) の両方に付ける。例外クラスは
        // 有界 cardinality で、成功/失敗でレイテンシ分布が異なるため duration を error.type で割って
        // 区別したい (semconv の duration 系も error.type を持つ)。
        if ($errorType !== null) {
            $attrs['error.type'] = $errorType; // 付けば最終失敗、無ければ成功
        }

        // retry したか (attempts>1) を低カーディナリティの bool で付ける (counter のみ。histogram には付けない)。
        // retry 率 = sum(finished{retried="true"}) / sum(finished)。整数 attempts はカーディナリティが
        // maxTries 倍になる割に用途に見合わないので持たない。
        $this->finished->add(1, $attrs + ['laravel.queue.job.retried' => (int) $job->attempts() > 1]);
        // duration は開始時刻が取れた時だけ (= JobProcessing を経た終端 attempt の処理時間)。
        if ($begin !== null) {
            $this->duration->record(microtime(true) - $begin, $attrs);
        }

        // worker での flush は umbrella の WorkerFlusher が Looping/WorkerStopping で行う (本クラスは record 専念)。
    }

    private function key(Job $job): string
    {
        return (string) ($job->uuid() ?? $job->getJobId());
    }

    private function driver(mixed $connection): string
    {
        // config() helper (illuminate/foundation) は使わない (理由は Buckets::fromConfig のコメント参照)。
        return (string) Config::get('queue.connections.'.((string) $connection).'.driver', 'unknown');
    }

    private function producerQueue(JobQueued $e): string
    {
        // JobQueued の queue 名は Laravel のバージョンで扱いが違う: 10.x のイベントは $queue を持たず、
        // 11.x 以降は $queue プロパティがある。ここでは全バージョン共通で、job が onQueue 等で
        // 自身に持っていれば取る best-effort に留める (無ければ 'default')。
        $job = $e->job;
        if (is_object($job) && isset($job->queue) && is_string($job->queue)) {
            return $job->queue;
        }

        return 'default';
    }

    private function producerJobName(JobQueued $e): string
    {
        // producer は payload 前なので resolveName が使えない。dispatch されたオブジェクトのクラス名で代替
        // (string dispatch の場合はその名前)。
        $job = $e->job;

        return is_object($job) ? $job::class : (string) $job;
    }
}
