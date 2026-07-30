<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use Closure;
use Mockery;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\LoggerHolder;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Globals::meterProvider() が投げる状態を作って作業を実行するヘルパ。
 *
 * MetricsServiceProvider の contextual binding は container が計装の constructor 引数を解決する時点で
 * Globals::meterProvider() を呼ぶ = 計装の constructor 本体に入る前なので、そこが投げると計装側の
 * try/catch では捕まえられない。この経路を再現するには Globals を「まだ初期化されていない」状態に
 * 戻す必要がある (一度初期化されると initializer は二度と走らない)。
 *
 * 投げさせ方は監査が挙げた実在の経路: Globals::globals() は initializer の例外を自前で catch して
 * logWarning するが (vendor/open-telemetry/api/Globals.php)、そのログは LoggerHolder 経由でアプリの
 * logger に落ちるので、logger 側が投げると Globals::meterProvider() から伝播する。zend.assertions に
 * 依存する assert(isset(...)) 経路は使わない (CI の脚によって無効化され得る)。
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
        $originalLogger = LoggerHolder::get();

        // 1. 未初期化に戻す。初期化済みだと meterProvider() はキャッシュを返すだけで initializer を通らない。
        $globals->setValue(null, null);

        // 2. 失敗する initializer。これ単体では投げない (Globals が catch して logWarning する)。
        Globals::registerInitializer(
            static fn (Configurator $configurator): Configurator => throw new RuntimeException('initializer exploded'),
        );

        // 3. その logWarning の落ち先を投げる logger にする。LogWriter は初回に生成されてキャッシュされる
        //    ので、差し替えを効かせるには Logging::reset() でキャッシュを捨てて factory に拾わせる。
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->andThrow(new RuntimeException('logger exploded'));
        LoggerHolder::set($logger);
        Logging::reset();

        try {
            $this->assertGlobalMeterProviderThrows();
            $work();
        } finally {
            LoggerHolder::set($originalLogger);
            Logging::reset();
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
            RuntimeException::class,
            $thrown,
            'Globals::meterProvider() が投げる前提。投げないなら OTel API 側の初期化経路が変わっているので、'
                .'この状態づくり (initializer + 内部ログの落ち先) を見直すこと。',
        );
    }
}
