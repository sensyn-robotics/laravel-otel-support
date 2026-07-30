<?php

namespace Sensyn\LaravelOTelSupport\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;

/**
 * Buckets::parse / normalize は config も Log も触らない純関数なので、Testbench の app を起動せず
 * PHPUnit の TestCase を直接継承する (最速の unit テストとして維持する)。
 */
class BucketsTest extends TestCase
{
    public function test_parses_csv_into_floats(): void
    {
        $this->assertSame([0.1, 0.5, 1.0], Buckets::parse('0.1, 0.5, 1', Buckets::DEFAULT_HTTP));
    }

    public function test_null_falls_back_to_default(): void
    {
        $this->assertSame(Buckets::DEFAULT_HTTP, Buckets::parse(null, Buckets::DEFAULT_HTTP));
    }

    public function test_blank_string_falls_back_to_default(): void
    {
        $this->assertSame(Buckets::DEFAULT_HTTP, Buckets::parse('   ', Buckets::DEFAULT_HTTP));
    }

    public function test_non_string_falls_back_to_default(): void
    {
        $this->assertSame(Buckets::DEFAULT_HTTP, Buckets::parse(true, Buckets::DEFAULT_HTTP));
    }

    public function test_non_numeric_token_falls_back_to_default(): void
    {
        // floatval で "oops" => 0.0 に化けさせず、default に戻す。
        $this->assertSame(Buckets::DEFAULT_HTTP, Buckets::parse('oops,1', Buckets::DEFAULT_HTTP));
    }

    public function test_non_ascending_is_sorted(): void
    {
        // 順不同の env 値は normalize でソートする。
        $this->assertSame([0.1, 0.5], Buckets::parse('0.5,0.1', Buckets::DEFAULT_HTTP));
    }

    public function test_duplicates_are_deduped(): void
    {
        $this->assertSame([0.1, 0.5], Buckets::parse('0.5,0.1,0.5', Buckets::DEFAULT_HTTP));
    }

    public function test_trailing_comma_is_skipped(): void
    {
        // 末尾カンマの空トークンはスキップして許容 (0.0 を紛れさせない)。
        $this->assertSame([0.1, 0.5], Buckets::parse('0.1,0.5,', Buckets::DEFAULT_HTTP));
    }

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
