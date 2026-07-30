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
}
