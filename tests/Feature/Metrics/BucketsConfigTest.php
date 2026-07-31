<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Sensyn\LaravelOTelSupport\Tests\TestCase;

class BucketsConfigTest extends TestCase
{
    public function test_returns_config_when_valid(): void
    {
        config(['otel-test.buckets' => [0.1, 0.5, 1.0]]);
        $this->assertSame([0.1, 0.5, 1.0], Buckets::fromConfig('otel-test.buckets', [9.0]));
    }

    public function test_normalizes_descending_config(): void
    {
        config(['otel-test.buckets' => [0.5, 0.1]]);
        $this->assertSame([0.1, 0.5], Buckets::fromConfig('otel-test.buckets', [1.0, 2.0]));
    }

    public function test_falls_back_to_default_when_empty(): void
    {
        config(['otel-test.buckets' => []]);
        $this->assertSame([1.0, 2.0], Buckets::fromConfig('otel-test.buckets', [1.0, 2.0]));
    }

    public function test_falls_back_to_default_when_non_numeric(): void
    {
        config(['otel-test.buckets' => ['oops']]);
        $this->assertSame([1.0, 2.0], Buckets::fromConfig('otel-test.buckets', [1.0, 2.0]));
    }

    /*
    |--------------------------------------------------------------------------
    | カンマ区切り文字列 (同梱 config が env の生値を素通しした形)
    |--------------------------------------------------------------------------
    |
    | これが本番で実際に通る経路である。同梱 config は `env('OTEL_METRICS_HTTP_BUCKETS')` を
    | そのまま置くだけなので、env が設定されていれば fromConfig が受け取るのは **文字列**になる。
    | 配列を受け取るのは、消費側が publish した config に直接書いた場合だけ。
    */

    public function test_parses_a_csv_string_into_floats(): void
    {
        config(['otel-test.buckets' => '0.1, 0.5, 1']);
        $this->assertSame([0.1, 0.5, 1.0], Buckets::fromConfig('otel-test.buckets', [9.0]));
    }

    public function test_csv_string_is_sorted_and_deduped(): void
    {
        // env は人が書くので順不同・重複が入る。SDK は境界の順序を検証しないため、
        // 素通しすると histogram が静かに壊れる。
        config(['otel-test.buckets' => '0.5,0.1,0.5']);
        $this->assertSame([0.1, 0.5], Buckets::fromConfig('otel-test.buckets', [9.0]));
    }

    public function test_trailing_comma_in_a_csv_string_is_skipped(): void
    {
        // 末尾カンマの空トークンは無視する (0.0 を境界に紛れ込ませない)。
        config(['otel-test.buckets' => '0.1,0.5,']);
        $this->assertSame([0.1, 0.5], Buckets::fromConfig('otel-test.buckets', [9.0]));
    }

    public function test_csv_string_with_a_non_numeric_token_falls_back_to_default(): void
    {
        // floatval なら "oops" が 0.0 に化けて境界を壊すので、既定に戻す。
        config(['otel-test.buckets' => 'oops,1']);
        $this->assertSame([1.0, 2.0], Buckets::fromConfig('otel-test.buckets', [1.0, 2.0]));
    }

    public function test_blank_csv_string_falls_back_to_default(): void
    {
        config(['otel-test.buckets' => '   ']);
        $this->assertSame([1.0, 2.0], Buckets::fromConfig('otel-test.buckets', [1.0, 2.0]));
    }

    public function test_unset_config_falls_back_to_default_without_warning(): void
    {
        // 同梱 config は env 未設定なら null を置く。これは異常ではないので警告を出さない
        // (出すと、既定のバケットで動いている全サービスが毎リクエスト 1 行を吐く)。
        config(['otel-test.buckets' => null]);
        $this->assertSame([1.0, 2.0], Buckets::fromConfig('otel-test.buckets', [1.0, 2.0]));
    }
}
