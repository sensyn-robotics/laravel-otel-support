<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

/**
 * `displayName()` を持つ job stub。
 *
 * framework は Mail (SendQueuedMailable)・Notification (SendQueuedNotifications)・queued listener
 * (CallQueuedListener)・broadcast (BroadcastEvent) の 4 系統で、実際の job を wrapper クラスに包んで
 * push する。その wrapper は `displayName()` を override して **中身のクラス名** を返すので、
 * payload の displayName — つまり consumer 側の `Job::resolveName()` が返す値 — は wrapper ではなく
 * 中身になる。producer が単に `get_class($job)` を見ると、同じ 1 件のメッセージが producer と
 * consumer で別の `laravel.queue.job.name` になる。
 *
 * このスタブはその構造だけを再現する (実在のアプリクラス名はテストに持ち込まない)。
 */
class FakeWrappedQueuedJob
{
    public string $queue = 'emails';

    public function __construct(public readonly string $inner = FakeQueuedJob::class) {}

    public function displayName(): string
    {
        return $this->inner;
    }
}
