<?php

namespace Sensyn\LaravelOTelSupport\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;

/**
 * Buckets::normalize は config も Log も触らない純関数なので、Testbench の app を起動せず
 * PHPUnit の TestCase を直接継承する (最速の unit テストとして維持する)。
 *
 * カンマ区切り文字列のパースは private (parseCsv) なので、ここではなく実行時の唯一の入口である
 * fromConfig() 越しに BucketsConfigTest で検証する。テストのためだけに public にはしない。
 */
class BucketsTest extends TestCase
{
    public function test_normalize_sorts_and_dedupes(): void
    {
        $this->assertSame([0.1, 0.5, 1.0, 2.5], Buckets::normalize([2.5, 0.1, 0.5, 1.0, 0.5]));
    }

    public function test_normalize_leaves_valid_list_unchanged(): void
    {
        $this->assertSame(Buckets::DEFAULT_HTTP, Buckets::normalize(Buckets::DEFAULT_HTTP));
    }

    public function test_normalize_rejects_empty(): void
    {
        $this->assertNull(Buckets::normalize([]));
    }

    public function test_normalize_rejects_non_numeric(): void
    {
        $this->assertNull(Buckets::normalize([0.1, 'oops']));
    }
}
