<?php

namespace Sensyn\LaravelOTelSupport;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Throwable;

/**
 * 長命 worker プロセスの作業境界で OTel signal provider を flush する。
 *
 * 各計装が張る span / metric は SDK 既定の processor / reader に溜まり、export は SDK autoload が仕込む
 * shutdown hook = PHP プロセスの終了時にしか走らない。FPM は 1 リクエスト 1 プロセスなのでそれで足りるが、
 * 長命プロセスでは次の作業まで滞留し、kill されれば失われる。対象は 2 種類:
 *   - queue:work daemon: ジョブ境界 (Looping / WorkerStopping) で flush する。
 *     auto-laravel が span を完了させるのは process の post hook なので、Worker::process で発火する
 *     JobProcessed / JobFailed は採用できない。Looping はループ先頭 (= 前ジョブの span は end 済み) かつ
 *     getNextJob のブロック前に発火するので、次のジョブを待たずに直前のジョブを flush できる。
 *     最終ジョブはループに戻らないので WorkerStopping で拾う。
 *   - Octane (FrankenPHP / Swoole / RoadRunner) の HTTP worker: 1 プロセスが数百〜数千リクエストを
 *     処理するので、リクエスト終了時に flush しないと HTTP metrics が worker の recycle まで出ない。
 *     Octane の RequestTerminated でリクエスト境界を拾い、FPM と同じ「リクエスト終了時に export」に揃える。
 *
 * flush は信号横断の worker ライフサイクルの関心事なので、submodule (Metrics 等) ではなく umbrella 直下に
 * 置き OTelSupportServiceProvider が配線する。
 *
 * @internal OTelSupportServiceProvider が配線する。消費側アプリ向けの API ではない。
 */
class WorkerFlusher
{
    /**
     * Octane が HTTP リクエストの終了時に発火するイベント。
     *
     * laravel/octane を require せずクラス名の文字列で購読する (本パッケージは Octane 非依存で、
     * Octane が居ない環境ではこのイベントが発火しないだけ)。Octane は自前の listener 設定だけでなく
     * アプリの event dispatcher にも dispatch する (Laravel\Octane\DispatchesEvents::dispatchEvent が
     * $app[Illuminate\Contracts\Events\Dispatcher::class]->dispatch()) ため、通常の listen で拾える。
     */
    public const OCTANE_REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    /**
     * 購読するイベント => ハンドラメソッド名。
     *
     * インスタンスを直接持つ場合は subscribe()、container 解決を発火時まで遅らせる場合は
     * subscribeLazily() が使う。マッピングを 1 箇所に集約して両経路のズレ (片方だけ購読漏れ) を防ぐ。
     *
     * @var array<string, string>
     */
    public const HANDLERS = [
        JobProcessing::class => 'markPending',
        Looping::class => 'flushIfPending',
        WorkerStopping::class => 'flushIfPending',
        self::OCTANE_REQUEST_TERMINATED => 'flushRequest',
    ];

    private TracerProviderInterface $tracerProvider;

    private MeterProviderInterface $meterProvider;

    /** 前回 flush 以降に作業をしたか。アイドルのポーリング周回での無駄 flush を避ける。 */
    private bool $pending = false;

    public function __construct(TracerProviderInterface $tracerProvider, MeterProviderInterface $meterProvider)
    {
        $this->tracerProvider = $tracerProvider;
        $this->meterProvider = $meterProvider;
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
     * container からの解決を、イベントの初回発火まで遅らせる形で listener を登録する。
     * listener 登録自体は呼び出し時点 (provider の boot) で完了するので、購読のタイミングは
     * subscribe() と変わらない。
     *
     * [クラス名, メソッド名] 形式の listener は Laravel が発火時に container から解決する
     * (Dispatcher::createClassCallable)。おかげで
     *   - queue も Octane も使わないプロセス (素の FPM リクエスト / 一般の artisan) では WorkerFlusher が
     *     生成されず、Globals (= SDK autoload) にも触らない。
     *   - 逆に実行文脈の判定に依らず配線できる。「長命 worker か」を runningInConsole() や PHP_SAPI から
     *     判定する形にすると、Octane では以下のとおりどちらの signal でも間違える (この事実の帰結は
     *     HTTP middleware を登録する MetricsServiceProvider 側にもある):
     *       - runningInConsole(): Octane は worker の bootstrap で $_ENV['APP_RUNNING_IN_CONSOLE'] = false
     *         を置く (octane/bin/bootstrap.php。swoole / roadrunner / frankenphp の 3 経路すべてが通る)。
     *         Application::runningInConsole() はこの env を PHP_SAPI より先に見るので、Octane worker は
     *         server 実装に依らず false = 長命 worker なのに「console でない」と申告する。
     *       - PHP_SAPI: Swoole / RoadRunner の worker は artisan (octane:start) から起動した CLI プロセス
     *         なので、HTTP リクエストの処理中も 'cli' のまま。一方 FrankenPHP はバイナリに埋め込まれた
     *         SAPI で動くので 'frankenphp' になる。つまり 'cli' 判定でも文脈は決まらない。
     *
     * 解決は発火ごとに走るので、dirty フラグ ($pending) をイベント間で共有するには WorkerFlusher が
     * singleton として bind されている必要がある (OTelSupportServiceProvider::register)。同じ理由で、
     * 渡す Dispatcher はアプリの container を持つもの (app('events')) であること。container を持たない
     * Dispatcher を渡すと解決先が別 container になり、provider の contextual binding が効かない。
     */
    public static function subscribeLazily(Dispatcher $events): void
    {
        foreach (self::HANDLERS as $event => $method) {
            $events->listen($event, [self::class, $method]);
        }
    }

    /** 成功/失敗どちらのジョブでも process 冒頭で発火するので dirty フラグの起点に使う。 */
    public function markPending(): void
    {
        $this->pending = true;
    }

    /**
     * queue worker のジョブ境界 (Looping / WorkerStopping) の flush。
     *
     * Looping はループ先頭。前ジョブの span は end 済みで、getNextJob のブロック前なので即時 flush に
     * なる。最終ジョブはループに戻らず停止するので WorkerStopping で残りを flush する。
     */
    public function flushIfPending(): void
    {
        if (! $this->pending) {
            return;
        }

        // flush が成功した時だけ dirty フラグを落とす。先に落とすと、投げた flush 分の signal が
        // 次の周回で再試行されず黙って失われる。
        $this->pending = ! $this->flush();
    }

    /**
     * Octane のリクエスト境界の flush。
     *
     * リクエストごとに必ず flush する (FPM の shutdown hook による export と同じ粒度に揃える)。
     * ジョブ境界と違ってアイドル周回が無く、リクエストがあれば span / metric は必ず出ているので
     * dirty フラグの判定は不要 = ここで立ててから flush する。
     */
    public function flushRequest(): void
    {
        $this->markPending();
        $this->flushIfPending();
    }

    /**
     * 対象 provider を flush して、すべて成功したかを返す。
     *
     * 対象は **traces と metrics の 2 signal のみ**。`Globals::loggerProvider()` は意図的に触っていない:
     * このパッケージの消費側はログを stdout に出す運用で、OTLP logs exporter を使っていないため。
     * **将来 OTLP でログを出す判断をしたら、ここに logger provider を足す必要がある** (足さないと、
     * 長命 worker のログレコードがプロセス終了まで batch processor に滞留する = このクラスが解こうと
     * している問題がログで再発する)。
     *
     * @return bool 対象 provider の flush がすべて成功したか
     */
    private function flush(): bool
    {
        // trace span (auto-laravel) と metric を flush する。1 つの try で直列に呼んではいけない:
        // 先に呼んだ側が投げると後の側に到達せず、pending も落ちないので次の周回も同じ順で同じ所で
        // 落ちる = 後の provider が worker の生存中一度も flush されない状態が固定される。
        // (TracerProvider::forceFlush は実在の throw 経路を持つ: BatchSpanProcessor::flush() は
        // 直接 forceFlush された場合 $propagateResult=true で、捕まえた例外を再 throw する。)
        $tracer = $this->forceFlush($this->tracerProvider);
        $meter = $this->forceFlush($this->meterProvider);

        return $tracer && $meter;
    }

    /**
     * provider 1 つを flush する。失敗は呼び出し側に漏らさず false で返す。
     *
     * @return bool flush が例外なく完了したか
     */
    private function forceFlush(object $provider): bool
    {
        try {
            // forceFlush() は API の provider interface には無く SDK 実装にしか無い。signal 未有効時は
            // Noop (forceFlush 無し) が返るので method_exists で守る。本パッケージが open-telemetry/sdk
            // を require しない (配線は消費側の env autoload に委ねる) 設計はこのガードで成り立っている。
            //
            // ⚠️ `$provider->forceFlush()` が返す bool は**意図的に捨てている**。本メソッドが返す bool は
            // 「配送が成功したか」ではなく「例外が出ずに完了したか」であり、両者は別物である。
            //   - 配送失敗 (false) は exporter が catch して SDK ログに記録済みで、例外は投げない。
            //   - そして **false を成功判定に混ぜて pending を残しても復旧しない**: BatchSpanProcessor は
            //     forceFlush の時点でキューを消費し、export に失敗した span を再キューしないため、
            //     次の境界で再 flush しても空のキューを流すだけになる。pending が永久に true のまま
            //     以降の全境界で無意味な flush を繰り返す副作用だけが残る。
            // よって「再試行して復旧できる失敗」だけを false にする。それが下の catch (想定外の例外) である。
            if (method_exists($provider, 'forceFlush')) {
                $provider->forceFlush();
            }

            return true;
        } catch (Throwable) {
            // flush の失敗は worker に漏らさない (listener はどれもアプリ = worker 本体のコードパス上で、
            // 囲む側に catch が無い):
            //   - Looping は Worker::daemonShouldRun() から発火し、daemon() の while にも WorkCommand にも
            //     try/catch が無い。投げると artisan まで抜けて queue:work が exit 1 で死ぬ
            //     (k8s ではジョブごとに再起動 = CrashLoop 相当でスループットが崩壊する)。
            //   - WorkerStopping は 2 経路。Worker::stop() 経由では終了コードが本来の status から 1 に
            //     化けて k8s の終了理由が誤判定される。Worker::kill() 経由 (job timeout の SIGALRM
            //     handler) では dispatch の直後にある posix_kill/exit に到達できず、job のコード実行中の
            //     任意の点に着弾した例外が job 側の try/catch に食われて、timeout の強制終了そのものが
            //     無効化されうる。
            //   - Octane の RequestTerminated は ApplicationGateway が応答後に dispatch する。投げると
            //     Octane worker 側の後処理 (sandbox の破棄) に例外が漏れる。
            // 作業自体は完了済みなのでロスは無く、pending は落とさないので次の境界で再試行される。
            // ログは出さない (guard 方針は HttpServerMetrics::terminate() と同じ: Log 自体が throw 源)。
            return false;
        }
    }
}
