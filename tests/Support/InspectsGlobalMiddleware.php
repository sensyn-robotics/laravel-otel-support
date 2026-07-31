<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\Assert;
use ReflectionProperty;

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
        return $this->foundationKernel()->hasMiddleware($middleware);
    }

    /**
     * global middleware の並びを返す。
     *
     * hasMiddleware() は「stack に居るか」しか答えないので、prepend が push に退行しても検知できない。
     * 位置は挙動を左右する: 末尾に付くと、先行 middleware が短絡応答するリクエスト (メンテナンス
     * モードの 503 / CORS プリフライト / 413) では handle() が走らず terminate() が即 return するため、
     * **障害時にちょうど HTTP メトリクスが欠測する**。並びは protected プロパティにしか無い。
     *
     * @return list<string>
     */
    private function globalMiddlewareStack(): array
    {
        $stack = (new ReflectionProperty(Kernel::class, 'middleware'))->getValue($this->foundationKernel());

        if (! is_array($stack)) {
            Assert::fail('The HTTP kernel is expected to keep its global middleware in an array.');
        }

        $list = [];
        foreach ($stack as $middleware) {
            $list[] = (string) $middleware;
        }

        return $list;
    }

    private function foundationKernel(): Kernel
    {
        $kernel = app(KernelContract::class);

        if (! $kernel instanceof Kernel) {
            Assert::fail(sprintf(
                'The HTTP kernel is expected to extend %s, got %s.',
                Kernel::class,
                get_debug_type($kernel),
            ));
        }

        return $kernel;
    }
}
