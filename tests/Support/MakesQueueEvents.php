<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use Illuminate\Queue\Events\JobQueued;
use ReflectionClass;

/**
 * Laravel メジャー間で signature が違う queue イベントを組み立てるファクトリ。
 *
 * JobQueued のコンストラクタは Laravel 10 が ($connectionName, $id, $job, $payload = null) の 4 引数、
 * Laravel 11+ は ($connectionName, $queue, $id, $job, $payload, $delay) の 6 引数 (全て必須) で、
 * どちらの版でも直接 new すると他方で壊れる (実行時 ArgumentCountError / 静的解析エラー)。
 * 引数数を reflection で見て newInstanceArgs する形にして、10〜13 を 1 本のテストで通す。
 *
 * 他の queue イベント (JobProcessing / JobProcessed / JobFailed / JobExceptionOccurred / Looping /
 * WorkerStopping) は 10〜13 で呼び出し互換なので、素の new で足りる。
 */
trait MakesQueueEvents
{
    /**
     * $queue は Laravel 11+ の JobQueued::$queue にそのまま入る (= push の宛先引数。呼び出し側が
     * キューを指定しなければ本番でも null)。job 自身が持つ宛先 ($job->queue) から導出しないのが重要:
     * 導出すると 10 の分岐と 11+ の分岐に同じ値しか流れず、producer が「イベントの宛先」と
     * 「job の宛先」のどちらを読んでいるのか構造的に判別できないテストになる。
     */
    private function jobQueued(
        string $connection,
        string $id,
        object|string $job,
        ?string $queue = null,
        string $payload = '{}',
    ): JobQueued {
        $class = new ReflectionClass(JobQueued::class);
        $arguments = $this->jobQueuedCarriesQueue()
            ? [$connection, $queue, $id, $job, $payload, null]
            : [$connection, $id, $job, $payload];

        return $class->newInstanceArgs($arguments);
    }

    /** 走っている Laravel の JobQueued がイベント自身に宛先キュー名を持つか (11+ = true / 10 = false)。 */
    private function jobQueuedCarriesQueue(): bool
    {
        return ((new ReflectionClass(JobQueued::class))->getConstructor()?->getNumberOfParameters() ?? 0) >= 6;
    }
}
