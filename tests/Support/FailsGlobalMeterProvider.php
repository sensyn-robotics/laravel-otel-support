<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use Closure;
use Error;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use PHPUnit\Framework\Assert;
use ReflectionProperty;
use Throwable;

/**
 * Globals::meterProvider() が投げる状態を作って作業を実行するヘルパ。
 *
 * MetricsServiceProvider の contextual binding は container が計装の constructor 引数を解決する時点で
 * Globals::meterProvider() を呼ぶ = 計装の constructor 本体に入る前なので、そこが投げると計装側の
 * try/catch では捕まえられない。この経路を再現するには Globals を「まだ初期化されていない」状態に
 * 戻す必要がある (一度初期化されると initializer は二度と走らない)。
 *
 * 投げさせ方: Globals::globals() は initializer の例外は catch するが、戻り値は検査せずに
 * storeInContext() を呼ぶ (vendor/open-telemetry/api/Globals.php)。Configurator を返さない initializer を
 * 登録すると、その Error が catch の外から Globals::meterProvider() まで伝播する。
 * initializer の例外を logWarning させて logger 側で投げさせる経路は、api 1.12 でログの書き込みが
 * 例外を飲むようになって再現できない。zend.assertions に依存する assert(isset(...)) 経路も使わない
 * (CI の脚によって無効化され得る)。
 *
 * Globals はプロセスグローバルな static なので、状態は reflection で退避して finally で必ず戻す
 * (Globals::reset() は初期化済み provider だけでなく登録済み initializer も捨ててしまい、元に戻せない)。
 */
trait FailsGlobalMeterProvider
{
    /**
     * @param  Closure(): void  $work
     */
    private function withFailingGlobalMeterProvider(Closure $work): void
    {
        $globals = new ReflectionProperty(Globals::class, 'globals');
        $initializers = new ReflectionProperty(Globals::class, 'initializers');
        $originalGlobals = $globals->getValue();
        $originalInitializers = $initializers->getValue();

        // 1. 未初期化に戻す。初期化済みだと meterProvider() はキャッシュを返すだけで initializer を通らない。
        $globals->setValue(null, null);

        // 2. Configurator を返さない initializer。registerInitializer() は api 1.12 から引数の型が PHPDoc で
        //    Closure(Configurator): Configurator になり、わざと違反する closure を渡せないので reflection で積む。
        $initializers->setValue(null, [static fn (Configurator $configurator): mixed => null]);

        try {
            $this->assertGlobalMeterProviderThrows();
            $work();
        } finally {
            $initializers->setValue(null, $originalInitializers);
            $globals->setValue(null, $originalGlobals);
        }
    }

    /**
     * 「作った状態で Globals::meterProvider() が本当に投げる」ことをテスト自身で固定する。
     *
     * ここが投げないなら、以降の検証は「壊れていない依存で普通に動いた」だけを見ていることになり、
     * ガードを外しても落ちない = 回帰テストとして無意味になる。
     */
    private function assertGlobalMeterProviderThrows(): void
    {
        $thrown = null;

        try {
            Globals::meterProvider();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        Assert::assertInstanceOf(
            Error::class,
            $thrown,
            'Globals::meterProvider() が投げる前提。投げないなら OTel API 側の初期化経路が変わっているので、'
                .'この状態づくり (Configurator を返さない initializer) を見直すこと。',
        );
    }
}
