<?php

namespace Sensyn\LaravelOTelSupport;

use Illuminate\Queue\Worker;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Trace\Span;
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
 * Worker::daemon の実行中だけ active span を invalid な span に差し替え、その間の span を独立した trace にする。
 * baggage など span 以外の context の値は残す。境界は ext-opentelemetry の hook で取る。post hook は例外で
 * 抜けた時も走るので、command span は従来どおり command の終了時に閉じる。auto-laravel 自体が
 * ext-opentelemetry を要るので、ext が無い環境ではこの問題も起きない。
 *
 * @internal OTelSupportServiceProvider が配線する。消費側アプリ向けの API ではない。
 */
class WorkerTraceContext
{
    /** ext の hook はプロセスに残るので、app を作り直しても (テスト等) 1 回だけ掛ける。 */
    private static bool $registered = false;

    /**
     * daemon ごとに attach した scope (attach に失敗した回は null)。pre と post の対応を stack で持つ。
     *
     * @var list<ScopeInterface|null>
     */
    private array $scopes = [];

    /**
     * プロセスで 1 回だけ hook を掛ける。ext の hook は外せないので、一度掛けたら後の app で無効にしても残る。
     */
    public static function registerOnce(HookManagerInterface $hooks): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        try {
            (new self)->register($hooks);
        } catch (Throwable) {
            // 失敗は数えない。掛けられなかった時は span が command span の下に積まれる従来の形に戻るだけで、
            // telemetry は欠けない。provider の boot はアプリの起動経路なので外には漏らさない。
        }
    }

    public function register(HookManagerInterface $hooks): void
    {
        $hooks->hook(
            Worker::class,
            'daemon',
            function (): void {
                $this->enter();
            },
            function (): void {
                $this->leave();
            },
        );
    }

    public function enter(): void
    {
        try {
            $this->scopes[] = Context::storage()->attach(Span::getInvalid()->storeInContext(Context::getCurrent()));
        } catch (Throwable) {
            // 失敗は数えない。切り離せなかった時は span が command span の下に積まれる従来の形に戻るだけで、
            // telemetry は欠けない。hook は worker の起動経路上にあるので外には漏らさない。
            $this->scopes[] = null;
        }
    }

    /**
     * 自分の scope が一番上にある時だけ外す。
     *
     * daemon の中で開いた span の scope は、それぞれの post hook が先に外しているので、通常は一番上にある。
     * ジョブ側のコードが scope を外し忘れていた場合、command span の終了処理は一番上の scope を外すので、
     * ここで何をしても command span は閉じない。その時は触らずに置く。
     */
    public function leave(): void
    {
        $scope = array_pop($this->scopes);
        if ($scope === null) {
            return;
        }

        try {
            if (Context::storage()->scope() === $scope) {
                $scope->detach();
            }
        } catch (Throwable) {
            // 失敗は数えない。外せなかった時に起きるのは command span が閉じないことだけで、今回の変更前の
            // queue:work でも SIGKILL で終われば同じ。hook は worker の停止経路上にあるので外には漏らさない。
        }
    }
}
