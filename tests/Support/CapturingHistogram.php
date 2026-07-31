<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * record() の引数をそのまま保持する HistogramInterface。
 *
 * in-memory SDK (RecordsMetrics) は集計後の data point しか見せないので、**第3引数の context** が
 * 何だったかは観測できない。context は exemplar に trace_id / span_id を載せるためだけに使われ、
 * 集計値にも属性にも現れないため、渡し忘れても SDK 経由のテストは全て緑のままになる。
 * その 1 点を観測するためのフェイク。
 */
class CapturingHistogram implements HistogramInterface
{
    /** @var list<array{value: float|int, attributes: iterable<mixed>, context: ContextInterface|false|null}> */
    public array $records = [];

    /**
     * @param  iterable<mixed>  $attributes
     */
    public function record(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
    {
        $this->records[] = ['value' => $amount, 'attributes' => $attributes, 'context' => $context];
    }

    /**
     * Instrument::isEnabled()。新しめの open-telemetry/api にしか無いが、宣言しておくぶんには
     * 古い版でも「余分な public メソッド」でしかないので、CI の lowest 脚でも壊れない。
     */
    public function isEnabled(): bool
    {
        return true;
    }
}
