<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

/**
 * queue テスト用の job stub。
 *
 * producer (JobQueued) では get_class が job 名、public $queue が宛先になる。
 * consumer (JobProcessing/JobProcessed/JobFailed) 側でも Job::resolveName() が返す
 * 「アプリの job クラス名」の代役としてこのクラス名を使う (テストに実在アプリのクラス名を持ち込まない)。
 */
class FakeQueuedJob
{
    public string $queue = 'emails';
}
