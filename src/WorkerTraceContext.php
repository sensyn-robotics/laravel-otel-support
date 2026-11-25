<?php

namespace Sensyn\LaravelOTelSupport;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * queue:work daemon の span を、プロセス全体を覆う command span から切り離す。
 *
 * auto-laravel は Illuminate\Console\Command::execute を hook して `Command <name>` span を開き、context に
 * attach する (OTEL_PHP_TRACE_CLI_ENABLED に依らない)。queue:work ではこの span がプロセス終了まで閉じないので、
 * 自前の親を持たない span (getNextJob の receive span、export の HTTP 送信など) がすべてその子になり、
 * 1 本の trace がプロセスの寿命だけ伸び続ける。backend の trace サイズ上限を超えると以降の span は捨てられる。
 * ジョブの process span は payload に trace context があればそこから親を取るが、無いジョブ (span の無い所から
 * dispatch したもの) は current context を親にするので、これも command span の下に入っていた。
 *
 * 最初の Looping で root context を attach し、以降の span を独立した trace にする。WorkerStopping で外し、
 * command span は従来どおり command の終了時に閉じる。
 *
 * @internal OTelSupportServiceProvider が配線する。消費側アプリ向けの API ではない。
 */
class WorkerTraceContext
{
    private ?ScopeInterface $scope = null;

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Looping::class, [$this, 'detachFromCommand']);
        $events->listen(WorkerStopping::class, [$this, 'restore']);
    }

    /**
     * Looping はループ先頭で発火するので、ジョブの span の scope は外れていて、上に残っているのは command 側だけ。
     */
    public function detachFromCommand(): void
    {
        if ($this->scope !== null) {
            return;
        }

        try {
            $this->scope = Context::storage()->attach(Context::getRoot());
        } catch (Throwable) {
            // 失敗は数えない。切り離せなかった時は span が command span の下に積まれる従来の形に戻るだけで、
            // telemetry は欠けない。listener は worker のループ上にあるので外には漏らさない。
        }
    }

    /**
     * 自分の scope が一番上にある時だけ外す。
     *
     * WorkerStopping は job timeout (Worker::kill) からも発火し、その時はジョブの span の scope が上にある。
     * 順序を崩して外すとジョブ側の span の終了処理が別の scope を外すことになる。kill はこの直後にプロセスを
     * 終えるので、外さずに置いておいてよい。
     */
    public function restore(): void
    {
        $scope = $this->scope;
        if ($scope === null) {
            return;
        }

        try {
            if (Context::storage()->scope() === $scope) {
                $scope->detach();
                $this->scope = null;
            }
        } catch (Throwable) {
            // 失敗は数えない。外せなかった時に起きるのは command span が閉じないことだけで、今回の変更前の
            // queue:work でも SIGKILL で終われば同じ。listener は worker の停止処理上にあるので外には漏らさない。
        }
    }
}
