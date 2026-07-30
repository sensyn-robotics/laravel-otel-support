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
    private function jobQueued(string $connection, string $id, object $job, string $payload = '{}'): JobQueued
    {
        // Laravel 11+ はイベント自身が queue 名を持つ。job 側の宛先 (FakeQueuedJob::$queue) と
        // 同じ値を渡し、どちらの分岐でも producer の宛先属性が同じになるようにする。
        $queue = isset($job->queue) && is_string($job->queue) ? $job->queue : null;

        $class = new ReflectionClass(JobQueued::class);
        $arguments = ($class->getConstructor()?->getNumberOfParameters() ?? 0) >= 6
            ? [$connection, $queue, $id, $job, $payload, null]
            : [$connection, $id, $job, $payload];

        return $class->newInstanceArgs($arguments);
    }
}
