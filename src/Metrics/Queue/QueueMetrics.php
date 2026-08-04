<?php

namespace Sensyn\LaravelOTelSupport\Metrics\Queue;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use Sensyn\LaravelOTelSupport\FailureCounter;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Throwable;

/**
 * queue/messaging の metrics を記録する。
 *
 * - enqueue 数は semconv の意味と一致するので semconv 名 messaging.client.sent.messages。
 * - 終端 (retry 込みの最終 成功/失敗) は semconv の per-delivery と意味が違うので Laravel ドメイン名
 *   laravel.queue.job.*。成功/失敗は finished を error.type の有無で割る。
 *
 * 準拠する semconv のバージョンと属性値の更新方針は src/Metrics/README.md を参照 (属性値は直書き:
 * open-telemetry/sem-conv は SDK 側の依存なので runtime から require できない)。
 *
 * @internal MetricsServiceProvider が listener として登録する。消費側アプリ向けの API ではない。
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

    /**
     * Laravel の queue driver 名 => semconv の well-known な messaging.system 値。
     *
     * semconv は messaging.system を open enum と定め「well-known 値のどれかが当てはまるならその値を
     * 使う (MUST)、当てはまらなければカスタム値でよい (MAY)」としている。Laravel の driver 名のうち
     * well-known 値を持つのは sqs だけで (1.38.0 の well-known 値は activemq / aws.sns / aws_sqs /
     * eventgrid / eventhubs / servicebus / gcp_pubsub / jms / kafka / pulsar / rabbitmq / rocketmq)、
     * redis / database / beanstalkd には対応値が無いので driver 名をそのままカスタム値として出す
     * (sync も対応値を持たないが、そもそも計上しないので値が出ることは無い。理由は isCounted())。
     * 逆に kafka / rabbitmq 等の名前を持つサードパーティ driver は素通しで well-known 値に一致する。
     *
     * 値を直書きするのは open-telemetry/sem-conv が SDK 側の依存で、「runtime は api / context のみ」の
     * 契約では require できないため (準拠バージョンと更新方針は src/Metrics/README.md)。
     *
     * @var array<string, string>
     */
    private const SEMCONV_SYSTEM = [
        'sqs' => 'aws_sqs',
    ];

    private CounterInterface $sent;

    private CounterInterface $finished;

    private HistogramInterface $duration;

    private FailureCounter $failures;

    /** @var array<string, float> job key => 処理開始時刻 */
    private array $start = [];

    public function __construct(MeterProviderInterface $meterProvider)
    {
        // 失敗 counter を先に作る (この後の構築が失敗したら、その回数をこれで数えるため)。生成も
        // 記録も FailureCounter 側が飲むので、ここを try で囲む必要は無い。
        $this->failures = new FailureCounter($meterProvider);

        try {
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
        } catch (Throwable) {
            // 構築の失敗も listener と同じく飲む (理由は guard())。ここは subscribeLazily による遅延解決
            // なので、構築は「queue イベントの初回発火点」= アプリの dispatch() 内 (JobQueued) か worker の
            // JobProcessing で走る。つまり投げれば着弾点はその発火点そのもので、guard() が守っている
            // blast radius (dispatch() 呼び出し元への伝播 / 未実行 job の release・偽 failure) を
            // そのまま引き起こす。しかも singleton は失敗した構築をキャッシュしないため、以後の全
            // イベントで投げ続ける。handler 本体の try/catch では救えない (Dispatcher が本体呼び出しの
            // 前に make() する) ので、ここで受ける必要がある。
            //
            // 現実的なトリガは config 側: Buckets::fromConfig() が不正値で出す Log::warning と
            // facade root 未設定。フォールバックは config も logger も触らない Noop instrument にする
            // (フォールバック自身が新たな throw 源にならないこと)。
            $noop = new NoopMeter;
            $this->sent = $noop->createCounter('messaging.client.sent.messages');
            $this->finished = $noop->createCounter('laravel.queue.job.finished');
            $this->duration = $noop->createHistogram('laravel.queue.job.duration');
            // 代わりに回数だけ残す。ここに落ちると以降このプロセスの queue metrics は丸ごと出なくなるが、
            // 例外もログも出ないため、数えないと「計装が黙って Noop に落ちた」ことを外から区別できない。
            $this->failures->increment(FailureCounter::STAGE_BUILD, FailureCounter::SITE_QUEUE);
        }
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
        $this->guard(function () use ($e): void {
            $this->doRecordEnqueued($e);
        });
    }

    public function markStarted(JobProcessing $e): void
    {
        $this->guard(function () use ($e): void {
            $this->doMarkStarted($e);
        });
    }

    public function recordProcessed(JobProcessed $e): void
    {
        $this->guard(function () use ($e): void {
            $this->doRecordProcessed($e);
        });
    }

    public function recordFailed(JobFailed $e): void
    {
        $this->guard(function () use ($e): void {
            $this->doRecordFailed($e);
        });
    }

    public function forgetStart(JobExceptionOccurred $e): void
    {
        $this->guard(function () use ($e): void {
            $this->doForgetStart($e);
        });
    }

    /**
     * Laravel が呼ぶ listener の入口で計装の例外を封じ込める (HttpServerMetrics::terminate() と同じ形:
     * public な入口で try/catch(Throwable) → 実処理は private に委譲 → ログは出さない)。
     *
     * queue イベントの listener はアプリ本来の処理と同じコールスタックで走り、囲む側に try/catch が
     * 無い、あるいは catch が job の失敗として扱うため、ここで投げると計装と無関係な壊れ方をする:
     *   - JobQueued は Queue::enqueueUsing() の tap() 内 = push 直後にインラインで発火し、Bus\Dispatcher に
     *     catch が無いので dispatch() の呼び出し元まで伝播する。トランザクション内なら「broker には
     *     残っているのに参照先の行はロールバックされた孤児 job」、afterCommit 経路なら「commit 済みなのに
     *     DB::transaction() が throw + 残りの afterCommit callback が全部落ちる」、Bus::batch なら
     *     「途中まで push した batch が永久に完了しない」。
     *   - JobProcessing は $job->fire() より前 (Worker::process) なので、投げると job が未実行のまま
     *     release / 偽 failure になる。maxTries=0 なら永久 release ループ = poison pill でキューが止まり、
     *     worker 自身は健全に見えるサイレント障害になる。
     *   - JobProcessed / JobFailed / JobExceptionOccurred では、登録順で後ろにいる listener が全部
     *     スキップされる。特に JobFailed の後ろには WorkCommand::listenForEvents() が handle() 時に
     *     (= provider boot で登録した本 listener より後に) 登録する logFailedJob がいるため、投げると
     *     failed_jobs への insert に到達せず、payload がキューからも failed_jobs からも消えて
     *     queue:retry もできなくなる。さらに元の job 例外が計装の例外に差し替わって報告される。
     *
     * 計装の失敗は telemetry の欠損として出す方がアプリの挙動を変えるより望ましいので、記録を諦める。
     * ログは出さない: (a) Log::warning 自体が新たな throw 源 (Monolog の channel 未定義 / 書き込み不能)、
     * (b) poison pill 化した job が無限に release されるとログも無限に増える。可観測性が必要になったら
     * OTel 内部ログ (LogsMessagesTrait) 側に寄せる。かわりに回数だけ counter に積む (counter は
     * 集約されるので、(b) の無限 release でも系列は 1 本のまま = ログのようには増幅しない)。
     *
     * @param  Closure(): void  $work
     */
    private function guard(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // 意図的に飲む (理由は上の docblock)。5 メソッドに catch をコピペせずここ 1 箇所に集約する。
            $this->failures->increment(FailureCounter::STAGE_RECORD, FailureCounter::SITE_QUEUE);
        }
    }

    /**
     * semconv の messaging.client.sent.messages の属性表に合わせる:
     * Required = {messaging.operation.name, messaging.system}、Conditionally Required に
     * messaging.destination.name。messaging.operation.type は**この metric の属性ではない**
     * (span と messaging.client.operation.duration 側の属性) ので付けない。付けると他言語 SDK の
     * 同名 metric とラベル集合が食い違い、同じ系列が分裂する。
     *
     * messaging.operation.name は「system 固有の操作名」で send / schedule / enqueue 等が例示値。
     * Laravel の enqueue は 'send' とする。
     */
    private function doRecordEnqueued(JobQueued $e): void
    {
        $driver = $this->driver($e->connectionName);

        // 終端側 (recordTerminal) とまったく同じ判定を通す。判定を 2 箇所に散らすと、片側だけ数える
        // 非対称 (= backlog の計算式が壊れる) が再発する。
        if (! $this->isCounted($driver)) {
            return;
        }

        $this->sent->add(1, [
            'messaging.system' => $this->toSemconvSystem($driver),
            'messaging.destination.name' => $this->producerDestination($e, $driver),
            'messaging.operation.name' => 'send',
            'laravel.queue.job.name' => $this->producerJobName($e),
        ]);
    }

    private function doMarkStarted(JobProcessing $e): void
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
    private function doRecordProcessed(JobProcessed $e): void
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
    private function doRecordFailed(JobFailed $e): void
    {
        if ($e->job instanceof Job) {
            $this->recordTerminal($e->connectionName, $e->job, $e->exception::class);
        }
    }

    /** retry される失敗 attempt は終端を持たない。開始時刻のリーク掃除のみ (terminal カウントしない)。 */
    private function doForgetStart(JobExceptionOccurred $e): void
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

        // 計上対象外の driver (sync) は終端を出さない。判定と理由は isCounted() に集約 (producer 側の
        // doRecordEnqueued も同じ判定を通す)。開始時刻の掃除より後に置くこと (上の unset を飛ばすと
        // sync connection の分だけ $start がリークする)。
        $driver = $this->driver($connection);
        if (! $this->isCounted($driver)) {
            return;
        }

        // histogram にも渡す共通属性は低カーディナリティのみ (classic histogram は bucket×属性で series 増)。
        // job 名はクラス数ぶんの有界カーディナリティなので histogram にも付けてよい。
        $attrs = [
            'messaging.system' => $this->toSemconvSystem($driver),
            'messaging.destination.name' => $this->destinationName((string) $job->getQueue()),
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

    /**
     * その driver の job を計上するか。producer (doRecordEnqueued) と consumer (recordTerminal) の
     * **両方**がこの 1 箇所を通る。
     *
     * sync driver の job はキューに入らず、ディスパッチ元 (HTTP リクエスト等) でインライン実行される。
     * 所要時間は HTTP metrics 側が既に計測しているので queue の終端に混ぜると成功カウントが水増しされ、
     * そもそもキューが無いので backlog という概念も存在しない。したがって片側だけ数えると、doc が示す
     * backlog = sum(sent) - sum(finished) が sync だけの connection で「増え続けるバックログ」に見える
     * (sent だけが伸びる)。判定を両側で共有するのは、この非対称を構造的に起こさないため。
     * sync の失敗は呼び出し元へ同期的に throw される (SyncQueue::handleException) ので、可視化は
     * アプリ側のエラーハンドリングと trace が担う。
     *
     * なお Laravel 10〜13 の SyncQueue::push() は enqueueUsing() を通らない = JobQueued を発火しないため、
     * producer 側の判定は現状フレームワーク経由では到達しない。それでも両側に置くのは (a) 「sync は
     * 数えない」という公開契約 (src/Metrics/README.md) を実装で固定するため、(b) driver 名 'sync' で
     * 登録された独自 connector や upstream の変更で発火し始めた時に、黙って片側だけ数える状態に
     * 戻らないようにするため。
     */
    private function isCounted(string $driver): bool
    {
        return $driver !== 'sync';
    }

    private function toSemconvSystem(string $driver): string
    {
        return self::SEMCONV_SYSTEM[$driver] ?? $driver;
    }

    private function driver(mixed $connection): string
    {
        // config() helper (illuminate/foundation) は使わない (理由は Buckets::fromConfig のコメント参照)。
        return (string) Config::get('queue.connections.'.((string) $connection).'.driver', 'unknown');
    }

    /**
     * messaging.destination.name の値 = キュー名。producer / consumer 双方がこれを通す。
     *
     * sqs では consumer 側の Job::getQueue() がキュー名ではなく**フルのキュー URL** を返す
     * (SqsQueue::pop が 'QueueUrl' => $queue = $this->getQueue($queue) と代入した URL をそのまま
     * SqsJob に渡すため)。URL には prefix = エンドポイントと AWS アカウント ID が含まれるので、
     * producer 側の値 (push の宛先引数 = キュー名) とは絶対に一致せず、結合が成立しない。
     * URL なら最終セグメント (= キュー名) に落として揃える:
     *   - semconv の messaging.destination.name は例示値も 'MyQueue' 等の名前で、OTel の他言語計装も
     *     同じ (java の SqsAttributesGetter は queueUrl の最終セグメントを使う)。URL のままだと
     *     同じブローカーに対して他言語計装と別系列になる。
     *   - label に AWS アカウント ID を出さずに済む。
     *
     * URL 判定は SqsQueue::getQueue 自身と同じ filter_var で行う (URL でない値には触らないので、
     * '/' を含むだけの redis / database のキュー名は壊れない)。
     */
    private function destinationName(string $queue): string
    {
        if (filter_var($queue, FILTER_VALIDATE_URL) === false) {
            return $queue;
        }

        $name = Str::afterLast($queue, '/');

        // '.../' で終わる (= 名前が空) 異常な URL では、空文字を出すより元の値を残す方が追える。
        return $name !== '' ? $name : $queue;
    }

    /**
     * producer 側の messaging.destination.name。consumer 側 (recordTerminal の Job::getQueue()) と
     * 同じキュー名になることが要件 = 両者を結合してキュー単位のバックログを見られること。
     */
    private function producerDestination(JobQueued $e, string $driver): string
    {
        $queue = $this->producerQueue($e);

        // 呼び出し側が URL を渡した場合、SqsQueue::getQueue はそれを素通しして suffix を付けない。
        // 同じ条件 (URL でない時だけ suffix) にしないと consumer 側とずれる。
        if ($driver === 'sqs' && filter_var($queue, FILTER_VALIDATE_URL) === false) {
            $queue = $this->withSqsSuffix($queue, $e->connectionName);
        }

        return $this->destinationName($queue);
    }

    /**
     * sqs の実キュー名は connection の suffix 付き (queue.connections.*.suffix。AWS アカウントを環境間で
     * 共有する時に使う)。consumer 側は実キュー URL からその名前を読むので、push の宛先引数しか知らない
     * producer 側では SqsQueue::suffixQueue と同じ規則を適用して揃える (.fifo キューは FIFO の
     * マーカーを保つため suffix を '.fifo' の前に入れる)。
     *
     * 付与は Str::finish なので、宛先に既に suffix が付いている場合は二重に付かない (framework も同じ)。
     */
    private function withSqsSuffix(string $queue, mixed $connection): string
    {
        $suffix = Config::get('queue.connections.'.((string) $connection).'.suffix', '');
        if (! is_string($suffix) || $suffix === '') {
            return $queue;
        }

        if (str_ends_with($queue, '.fifo')) {
            return Str::finish(Str::beforeLast($queue, '.fifo'), $suffix).'.fifo';
        }

        return Str::finish($queue, $suffix);
    }

    /**
     * push の宛先引数 (正規化前)。producerDestination() が suffix 付与と URL の正規化を担う。
     *
     * JobQueued の扱いは Laravel のバージョンで違う: 11.x 以降のイベントは push した宛先そのものを
     * $queue に持ち (Queue::enqueueUsing に渡された引数。呼び出し側がキューを指定しなければ null)、
     * 10.x のイベントは $queue を持たない。プロパティの有無で分岐する。
     */
    private function producerQueue(JobQueued $e): string
    {
        // property_exists() / $e->queue の直接アクセスは、Laravel 10 と 11+ のどちらかで静的解析が
        // 壊れる (10 では未定義プロパティ、11+ では常に真の条件)。public プロパティの一覧で見る。
        $properties = get_object_vars($e);

        if (array_key_exists('queue', $properties)) {
            // Laravel 11+: イベントが宛先を持つ。job 側の $queue より優先する (Queue::pushOn('high', $job)
            // のように呼び出し側の指定が job 自身の宛先を上書きするケースでも、実際の宛先はこちら)。
            $queue = $properties['queue'];

            return is_string($queue) && $queue !== '' ? $queue : $this->defaultQueue($e->connectionName);
        }

        // Laravel 10: イベントに宛先が無いので job 自身が onQueue 等で持つ宛先を読む。
        //
        // ⚠️ **これは Laravel 10 では常に正しいわけではない。** 宛先は job のプロパティに書かれる場合と、
        // `Queue::pushOn($queue, $job)` の引数として渡されるだけの場合があり、後者はイベントからも
        // payload からも復元できない。framework 自身が wrapper 4 系統のうち 2 つで後者を使う:
        //
        //   | wrapper                   | 宛先の渡り方                                    |
        //   |---------------------------|-------------------------------------------------|
        //   | SendQueuedMailable        | ctor が $this->queue に代入            -> 読める |
        //   | SendQueuedNotifications   | NotificationSender が ->onQueue()      -> 読める |
        //   | CallQueuedListener        | Dispatcher::queueHandler が pushOn()   -> 読めない |
        //   | BroadcastEvent            | BroadcastManager が pushOn()           -> 読めない |
        //
        // 読めない 2 つでは producer が connection 既定キューを報告し、consumer は実キューを報告するため、
        // キュー単位の sum(sent) - sum(finished) が割れる (既定キュー側が増え続け、実キュー側が負に見える)。
        // Laravel 11+ はイベント自身が宛先を持つので影響しない。限界は src/Metrics/README.md に明記し、
        // QueueMetricsTest の `queued listener / broadcast` ケースで既知の誤り値として pin してある。
        $job = $e->job;
        if (is_object($job) && isset($job->queue) && is_string($job->queue)) {
            return $job->queue;
        }

        // 宛先が指定されていない push は connection 既定のキューに入る。'default' 固定にすると、
        // 既定キュー名が 'default' でない connection (queue.connections.*.queue) で consumer 側と
        // 食い違い、実在しないキューの系列だけが伸びる。
        return $this->defaultQueue($e->connectionName);
    }

    private function defaultQueue(mixed $connection): string
    {
        $queue = Config::get('queue.connections.'.((string) $connection).'.queue', 'default');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    /**
     * producer 側の job 名。**consumer 側 (recordTerminal の resolveName) と同じ値になること**が要件で、
     * ここが割れると sum(sent) - sum(finished) を job 名で slice したときにバックログが 2 本に分裂する。
     *
     * producer には payload がまだ無いので resolveName は使えない。代わりに、framework が payload の
     * displayName を作るときと同じ規則をここで再現する:
     *
     *   - オブジェクト: Queue::getDisplayName() = `method_exists($job,'displayName') ? $job->displayName() : get_class($job)`
     *   - 文字列:       Queue::createStringPayload() = `explode('@', $job)[0]`
     *
     * 単純なクラス名では駄目な理由は、framework 自身が displayName() を override する wrapper を
     * 挟むためで、Mail (SendQueuedMailable)・Notification (SendQueuedNotifications)・queued listener
     * (CallQueuedListener)・broadcast (BroadcastEvent) の 4 系統が該当する。`Mail::to()->queue()` では
     * producer が見るのは wrapper の SendQueuedMailable、consumer が見るのは中身の Mailable になる。
     * この分岐は Laravel 10〜13 で同一なのでバージョン差の心配は無い ($e->payload を読む案もあるが、
     * イベントのプロパティ構成に依存するぶん producerQueue() と同じ get_object_vars 分岐が要る)。
     */
    private function producerJobName(JobQueued $e): string
    {
        $job = $e->job;

        if (is_object($job)) {
            return method_exists($job, 'displayName') ? (string) $job->displayName() : $job::class;
        }

        // 'SendEmail@handle' のような Class@method 指定は、payload では method 部が落ちる。
        return explode('@', (string) $job)[0];
    }
}
