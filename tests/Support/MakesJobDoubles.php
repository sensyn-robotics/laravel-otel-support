<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use Illuminate\Contracts\Queue\Job;
use Mockery;

/**
 * アプリの Dispatcher 越しに queue イベントを流すための Job double。
 *
 * framework 自身が JobProcessing を購読しているため、計装が読まない API にも応える必要がある:
 * Illuminate\Log\Context\ContextServiceProvider の listener が $job->payload() を読んで
 * ログ context を復元する。素の Mockery mock だとそこで BadMethodCallException になる。
 */
trait MakesJobDoubles
{
    private function queuedJob(): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);

        return $job;
    }
}
