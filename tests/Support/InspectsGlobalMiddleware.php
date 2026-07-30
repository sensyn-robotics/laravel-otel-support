<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\Assert;

/**
 * global middleware stack を検査するヘルパ。
 *
 * hasMiddleware() は Kernel contract には無く Foundation の HTTP kernel 実装が持つ
 * (Laravel 10〜13 で存在)。Testbench の HTTP kernel はこれを継承するので、具象型に絞って呼ぶ。
 */
trait InspectsGlobalMiddleware
{
    /**
     * @param  class-string  $middleware
     */
    private function hasGlobalMiddleware(string $middleware): bool
    {
        $kernel = app(KernelContract::class);

        if (! $kernel instanceof Kernel) {
            Assert::fail(sprintf(
                'The HTTP kernel is expected to extend %s, got %s.',
                Kernel::class,
                get_debug_type($kernel),
            ));
        }

        return $kernel->hasMiddleware($middleware);
    }
}
