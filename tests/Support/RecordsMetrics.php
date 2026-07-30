<?php

namespace Sensyn\LaravelOTelSupport\Tests\Support;

use ArrayObject;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Assert;

/**
 * テスト用の in-memory MeterProvider を組み、記録された metrics を取り出すヘルパ。
 *
 * data point の取り出しは型ごとに分けている (counterValue / histogramCount)。Metric::$data は
 * DataInterface で dataPoints を宣言せず、Sum の data point (NumberDataPoint) と Histogram の
 * data point (HistogramDataPoint) は別クラスなので、instanceof で絞らないと静的解析 (level 8) が通らない。
 */
trait RecordsMetrics
{
    private InMemoryExporter $exporter;

    private MeterProviderInterface $meterProvider;

    private function bootMeter(): void
    {
        // cumulative にする。delta だと collectMetrics の forceFlush で 1 回読むと次が空になり最後勝ちで
        // 消えるが、cumulative なら毎回 running total が返るので、flush 回数に依らず安定して total を
        // 検証できる (本番では WorkerFlusher が worker でジョブ境界ごとに flush する)。
        $this->exporter = new InMemoryExporter(new ArrayObject, Temporality::CUMULATIVE);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader(new ExportingReader($this->exporter))
            ->build();
    }

    /**
     * flush して記録済み metrics を name => Metric で返す。
     *
     * @return array<string, Metric>
     */
    private function collectMetrics(): array
    {
        $this->meterProvider->forceFlush();

        $byName = [];
        foreach ($this->exporter->collect(true) as $metric) {
            $byName[$metric->name] = $metric;
        }

        return $byName;
    }

    /**
     * Metric の data point 配列を返す (Sum=NumberDataPoint / Histogram=HistogramDataPoint)。
     *
     * @return list<NumberDataPoint|HistogramDataPoint>
     */
    private function dataPoints(Metric $metric): array
    {
        $data = $metric->data;
        $points = $data instanceof Sum || $data instanceof Histogram || $data instanceof Gauge
            ? $data->dataPoints
            : [];

        $list = [];
        foreach ($points as $point) {
            $list[] = $point;
        }

        return $list;
    }

    /** counter (Sum) の先頭 data point の値を返す。 */
    private function counterValue(Metric $metric): float|int
    {
        $point = $this->firstDataPoint($metric);
        if (! $point instanceof NumberDataPoint) {
            Assert::fail(sprintf('Metric "%s" has no NumberDataPoint (not a counter?).', $metric->name));
        }

        return $point->value;
    }

    /** histogram の先頭 data point の記録件数を返す。 */
    private function histogramCount(Metric $metric): int
    {
        return $this->histogramPoint($metric)->count;
    }

    /**
     * histogram の先頭 data point の記録値の合計を返す。
     *
     * 件数や属性だけを見るテストは「値そのもの」の退行 (秒とミリ秒の取り違え等、桁が 1000 倍ずれる
     * OTel/Prometheus の定番事故) を検知できないので、記録値を読む口を用意する。
     */
    private function histogramSum(Metric $metric): float|int
    {
        return $this->histogramPoint($metric)->sum;
    }

    /**
     * histogram の先頭 data point の bucket 境界を返す。
     *
     * 設定値が instrument の advisory (ExplicitBucketBoundaries) まで届いているかは SDK 側の集計結果に
     * しか現れない (届かなければ SDK 既定の [0, 5, 10, ..., 1000] に黙って落ちる)。
     *
     * @return list<float|int>
     */
    private function histogramBounds(Metric $metric): array
    {
        return $this->histogramPoint($metric)->explicitBounds;
    }

    private function histogramPoint(Metric $metric): HistogramDataPoint
    {
        $point = $this->firstDataPoint($metric);
        if (! $point instanceof HistogramDataPoint) {
            Assert::fail(sprintf('Metric "%s" has no HistogramDataPoint (not a histogram?).', $metric->name));
        }

        return $point;
    }

    /**
     * 単一 data point を前提にその属性を連想配列で返す。
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Metric $metric): array
    {
        /** @var array<string, mixed> */
        return $this->firstDataPoint($metric)->attributes->toArray();
    }

    private function firstDataPoint(Metric $metric): NumberDataPoint|HistogramDataPoint
    {
        $points = $this->dataPoints($metric);
        if ($points === []) {
            Assert::fail(sprintf('Metric "%s" has no data points.', $metric->name));
        }

        return $points[0];
    }
}
