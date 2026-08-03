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
    /**
     * 宛先キュー。**`null` を既定にしているのは実物に合わせるため。**
     *
     * framework の 4 wrapper のうち、job 自身に宛先が書かれるのは `SendQueuedMailable` (ctor で代入) と
     * `SendQueuedNotifications` (`NotificationSender` が `->onQueue()`) だけで、`CallQueuedListener` と
     * `BroadcastEvent` は `Queue::pushOn($queue, $job)` の引数として渡されるだけなので **job には残らない**。
     * ここに値を入れておくと、Laravel 10 で producer が宛先を復元できないケースがテストから消える。
     */
    public ?string $queue = null;

    public function __construct(public readonly string $inner = FakeQueuedJob::class) {}

    public function displayName(): string
    {
        return $this->inner;
    }
}
