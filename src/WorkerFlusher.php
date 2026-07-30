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
 * queue worker のジョブ境界で OTel signal provider を flush する。
 *
 * 各計装が張る span / metric は SDK 既定の processor / reader に溜まり、
 * 長命な worker では process 終了時の shutdown flush まで送られない (ジョブが疎だと次のジョブまで滞留、kill されれば失われる)。
 * そのため終端ごとに provider を明示 forceFlush する。flush は信号横断の worker ライフサイクルの関心事なので、
 * submodule (Metrics 等) ではなく umbrella 直下に置き OTelSupportServiceProvider が配線する。
 *
 * flush は Looping / WorkerStopping で行う。
 * auto-laravel が span を完了させるのは process の post hook のため、Worker::process で発火する JobProcessed / JobFailed は採用できない。
 * Looping はループ先頭 (= 前ジョブの span は end 済み) かつ getNextJob のブロック前に発火するので、次のジョブを待たずに直前のジョブを flush 可能。
 * 最終ジョブはループに戻らないので WorkerStopping で拾う。
 */
class WorkerFlusher
{
    private TracerProviderInterface $tracerProvider;

    private MeterProviderInterface $meterProvider;

    /** 前回 flush 以降にジョブを処理したか。アイドルのポーリング周回での無駄 flush を避ける。 */
    private bool $pending = false;

    public function __construct(TracerProviderInterface $tracerProvider, MeterProviderInterface $meterProvider)
    {
        $this->tracerProvider = $tracerProvider;
        $this->meterProvider = $meterProvider;
    }

    public function subscribe(Dispatcher $events): void
    {
        // 成功/失敗どちらのジョブでも process 冒頭で発火するので dirty フラグの起点に使う。
        $events->listen(JobProcessing::class, function (): void {
            $this->pending = true;
        });

        // ループ先頭。前ジョブの span は end 済みで、getNextJob のブロック前なので即時 flush になる。
        $events->listen(Looping::class, function (): void {
            $this->flushIfPending();
        });

        // 最終ジョブはループに戻らず停止するので、ここで残りを flush する。
        $events->listen(WorkerStopping::class, function (): void {
            $this->flushIfPending();
        });
    }

    private function flushIfPending(): void
    {
        if (! $this->pending) {
            return;
        }

        try {
            $this->flush();
            // flush が成功した時だけ dirty フラグを落とす。先に落とすと、投げた flush 分の signal が
            // 次の周回で再試行されず黙って失われる。
            $this->pending = false;
        } catch (Throwable) {
            // flush の失敗は worker に漏らさない (Looping / WorkerStopping の listener はどちらも
            // アプリ = worker 本体のコードパス上で、囲む側に catch が無い):
            //   - Looping は Worker::daemonShouldRun() から発火し、daemon() の while にも WorkCommand にも
            //     try/catch が無い。投げると artisan まで抜けて queue:work が exit 1 で死ぬ
            //     (k8s ではジョブごとに再起動 = CrashLoop 相当でスループットが崩壊する)。
            //   - WorkerStopping は 2 経路。Worker::stop() 経由では終了コードが本来の status から 1 に
            //     化けて k8s の終了理由が誤判定される。Worker::kill() 経由 (job timeout の SIGALRM
            //     handler) では dispatch の直後にある posix_kill/exit に到達できず、job のコード実行中の
            //     任意の点に着弾した例外が job 側の try/catch に食われて、timeout の強制終了そのものが
            //     無効化されうる。
            // ジョブ自体は完了済みなのでロスは無く、pending は落とさないので次の Looping で再試行される。
            // ログは出さない (guard 方針は HttpServerMetrics::terminate() と同じ: Log 自体が throw 源)。
        }
    }

    private function flush(): void
    {
        // trace span (auto-laravel) と metric を一括 flush する。
        $this->forceFlush($this->tracerProvider);
        $this->forceFlush($this->meterProvider);
    }

    private function forceFlush(object $provider): void
    {
        // forceFlush() は API の provider interface には無く SDK 実装にしか無い。signal 未有効時は Noop
        // (forceFlush 無し) が返るので method_exists で守る。本パッケージが open-telemetry/sdk を require
        // しない (配線は消費側の env autoload に委ねる) 設計はこのガードで成り立っている。
        //
        // transport/export の失敗はここでは扱わない: 配送失敗は exporter が catch して SDK ログに記録し、
        // transport が閉じていれば false が返るだけで、例外は投げない。worker はそのまま処理を続ければよく、
        // 戻り値も見ない。残るのは processor / transport 層や SDK 内部ログの想定外エラーで、それは
        // flushIfPending() が飲む (worker プロセスを落とさないため)。
        if (method_exists($provider, 'forceFlush')) {
            $provider->forceFlush();
        }
    }
}
