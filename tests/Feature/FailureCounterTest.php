<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Mockery;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\LateBindingMeter;
use OpenTelemetry\API\Metrics\LateBindingMeterProvider;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopCounter;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as SdkMeterProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProvider;
use ReflectionProperty;
use RuntimeException;
use Sensyn\LaravelOTelSupport\FailureCounter;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Metrics\Queue\QueueMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\FakeQueuedJob;
use Sensyn\LaravelOTelSupport\Tests\Support\MakesQueueEvents;
use Sensyn\LaravelOTelSupport\Tests\Support\RecordsMetrics;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Sensyn\LaravelOTelSupport\WorkerFlusher;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * fail-open のガードが飲んだ失敗が counter に出ること、そしてその counter 自身が壊れても
 * アプリに何も漏らさないこと。
 *
 * 失敗経路はログを出さない設計なので、ここが唯一の観測点になる。逆に言うと「counter が増えない」
 * 退行は他のどのテストでも落ちない (計装は元々黙って失敗するのが正しい挙動なので) ため、経路ごとに
 * 1 本ずつ固定する。
 */
class FailureCounterTest extends TestCase
{
    use MakesQueueEvents;
    use RecordsMetrics;

    private const METRIC = 'laravel.otel_support.failures';

    private const CONN = 'otel-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.connections.'.self::CONN.'.driver' => 'redis']);
        $this->bootMeter();
    }

    public function test_healthy_instrumentation_records_no_failures(): void
    {
        // 陰性対照。これが無いと「常に増える」実装 (= 失敗の有無と無関係な counter) を検知できない。
        $middleware = new HttpServerMetrics($this->meterProvider);
        $request = Request::create('https://example.test/things', 'GET');
        $response = new Response('', 200);
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);

        $dispatcher = new Dispatcher;
        (new QueueMetrics($this->meterProvider))->subscribe($dispatcher);
        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob));

        $this->assertSame([], $this->failureCounts(), '正常系で失敗 counter に data point が出てはいけない。');
    }

    public function test_http_instrument_build_failure_is_counted(): void
    {
        // 構築に失敗すると以降そのプロセスの HTTP metrics は丸ごと出ない。例外もログも出ないので、
        // この counter が無いと「Noop に落ちた」ことを外から区別できない。
        new HttpServerMetrics($this->meterProviderThatFailsInstrumentBuild());

        $this->assertSame(['build|metrics.http' => 1], $this->failureCounts());
    }

    public function test_http_record_failure_is_counted(): void
    {
        $middleware = new HttpServerMetrics($this->meterProviderThatFailsRecording());
        $request = Request::create('https://example.test/things', 'GET');
        $response = new Response('', 200);

        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);

        $this->assertSame(['record|metrics.http' => 1], $this->failureCounts());
    }

    public function test_queue_instrument_build_failure_is_counted(): void
    {
        new QueueMetrics($this->meterProviderThatFailsInstrumentBuild());

        $this->assertSame(['build|metrics.queue' => 1], $this->failureCounts());
    }

    public function test_queue_record_failure_is_counted_per_event(): void
    {
        $dispatcher = new Dispatcher;
        (new QueueMetrics($this->meterProviderThatFailsRecording()))->subscribe($dispatcher);

        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-1', new FakeQueuedJob));
        $dispatcher->dispatch($this->jobQueued(self::CONN, 'id-2', new FakeQueuedJob));

        // 1 件ごとに 1 ずつ積む (data point 1 本にまとまる = ログと違って hot path でも増幅しない)。
        $this->assertSame(['record|metrics.queue' => 2], $this->failureCounts());
    }

    public function test_flush_failures_are_counted_per_signal(): void
    {
        // flush は signal ごとに独立して走る (片方の失敗で他方を落とさない) ので、失敗もその粒度で
        // 数える。片方だけ永続的に失敗している状態を site で切り分けられることが目的。
        $tracerProvider = Mockery::mock(SdkTracerProvider::class);
        $tracerProvider->shouldReceive('forceFlush')->andThrow(new RuntimeException('trace flush exploded'));

        // getMeter は本物に委譲する: 失敗 counter の届け先が壊れていると、そもそも観測できない
        // (それがこの counter の構造的な限界で、README にも明記してある)。
        $real = $this->meterProvider;
        $meterProvider = Mockery::mock(SdkMeterProvider::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(
            fn (string $name): MeterInterface => $real->getMeter($name),
        );
        $meterProvider->shouldReceive('forceFlush')->andThrow(new RuntimeException('metric flush exploded'));

        $dispatcher = new Dispatcher;
        (new WorkerFlusher($tracerProvider, $meterProvider))->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing(self::CONN, Mockery::mock(Job::class)));
        $this->assertNotFalse($dispatcher->until(new Looping(self::CONN, 'default')));

        // 並びは failureCounts() の ksort 順 (metrics -> traces)。
        $this->assertSame([
            'flush|worker_flusher.metrics' => 1,
            'flush|worker_flusher.traces' => 1,
        ], $this->failureCounts());
    }

    public function test_metric_identity_and_attribute_set_are_pinned(): void
    {
        // 名前・単位・scope・属性集合は消費側のダッシュボードとアラートが書かれる契約なので、
        // 変更が黙って通らないよう丸ごと固定する (属性の追加も削除もここで落ちる)。
        new HttpServerMetrics($this->meterProviderThatFailsInstrumentBuild());

        $metrics = $this->collectMetrics();
        $this->assertArrayHasKey(self::METRIC, $metrics);

        $metric = $metrics[self::METRIC];
        $this->assertSame('{failure}', $metric->unit);
        $this->assertSame('laravel-otel-support', $metric->instrumentationScope->getName());
        $this->assertSame([
            'laravel.otel_support.stage' => 'build',
            'laravel.otel_support.site' => 'metrics.http',
        ], $this->attributesOf($metric));
    }

    public function test_exception_class_is_not_used_as_an_attribute(): void
    {
        // error.type (例外クラス名) は付けない。ここに届く例外の集合はこのパッケージでは縛れず
        // (SDK 内部 / アプリの logger / exporter の transport 由来)、record 経路は per-request なので
        // 無界ラベルは cardinality 増幅になる。例外クラスごとに系列が割れないことを固定する。
        $middleware = new HttpServerMetrics($this->meterProviderThatFailsRecording());

        foreach (['https://example.test/a', 'https://example.test/b'] as $url) {
            $request = Request::create($url, 'GET');
            $response = new Response('', 200);
            $middleware->handle($request, fn () => $response);
            $middleware->terminate($request, $response);
        }

        $metric = $this->collectMetrics()[self::METRIC];
        $this->assertCount(1, $this->dataPoints($metric));
        $this->assertArrayNotHasKey('error.type', $this->attributesOf($metric));
    }

    public function test_counter_creation_failure_falls_back_to_a_noop_counter(): void
    {
        // 記録先が作れない場合。Noop まで見るのは、代入を落とすと「未初期化プロパティ = Error」が
        // increment() の catch に飲まれて黙るため (「投げない」だけではガードの半分が抜けても気付けない)。
        $attempts = 0;
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(function () use (&$attempts): MeterInterface {
            $attempts++;
            throw new RuntimeException('meter provider exploded');
        });

        $failures = new FailureCounter($meterProvider);
        $this->assertSame(1, $attempts, 'constructor は getMeter() まで到達している必要がある。');
        $this->assertInstanceOf(NoopCounter::class, $this->counterOf($failures));

        // フォールバック済みのインスタンスでも記録は無害に通る。
        $failures->increment(FailureCounter::STAGE_RECORD, FailureCounter::SITE_HTTP);
    }

    public function test_counter_recording_failure_is_swallowed(): void
    {
        // 生成が成功しても add() は投げうる。呼び出し元はすべて「既に失敗を飲んだ後の catch 節」なので、
        // ここで漏らすと飲んだはずの失敗がそのままアプリに出る。
        $attempts = 0;
        $counter = Mockery::mock(CounterInterface::class);
        $counter->shouldReceive('add')->andReturnUsing(function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('counter exploded');
        });

        $meter = Mockery::mock(MeterInterface::class);
        $meter->shouldReceive('createCounter')->andReturn($counter);
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturn($meter);

        (new FailureCounter($meterProvider))->increment(FailureCounter::STAGE_FLUSH, FailureCounter::SITE_FLUSH_TRACES);

        $this->assertSame(1, $attempts, 'increment() は add() まで到達している必要がある (到達しないなら無意味なテスト)。');
    }

    /**
     * 二重ガードが要る根拠を upstream に対して固定する。
     *
     * SDK autoload が Globals に載せる MeterProvider は LateBindingMeterProvider で、getMeter() は
     * factory を走らせずに LateBindingMeter を返す。factory (= Globals の初期化) が走るのは
     * createCounter() の時点なので、初期化の失敗は SafeGlobals の try/catch を抜けた後、計装の
     * constructor の中で飛んでくる。ここが遅延しなくなったら FailureCounter の構築ガードの根拠が
     * 変わるので、その時に気付けるようにしておく。
     */
    public function test_a_late_binding_meter_provider_defers_a_creation_failure_to_the_instrument(): void
    {
        $provider = new LateBindingMeterProvider(
            static fn (): MeterProviderInterface => throw new RuntimeException('late initialisation exploded'),
        );

        // getMeter() 自体は投げない。
        $meter = $provider->getMeter('laravel-otel-support');
        $this->assertInstanceOf(LateBindingMeter::class, $meter);

        $thrown = null;
        try {
            $meter->createCounter('laravel.otel_support.failures');
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(
            RuntimeException::class,
            $thrown,
            'createCounter() が factory を走らせる前提。走らせないなら構築ガードの根拠を見直すこと。',
        );

        // その経路も FailureCounter は飲む。
        $failures = new FailureCounter($provider);
        $this->assertInstanceOf(NoopCounter::class, $this->counterOf($failures));
        $failures->increment(FailureCounter::STAGE_BUILD, FailureCounter::SITE_HTTP);
    }

    private function counterOf(FailureCounter $failures): CounterInterface
    {
        /** @var CounterInterface */
        return (new ReflectionProperty(FailureCounter::class, 'failures'))->getValue($failures);
    }

    /**
     * 失敗 counter の data point を "stage|site" => 値 で返す (未記録なら空配列)。
     *
     * @return array<string, float|int>
     */
    private function failureCounts(): array
    {
        $metrics = $this->collectMetrics();
        if (! array_key_exists(self::METRIC, $metrics)) {
            return [];
        }

        $counts = [];
        foreach ($this->dataPoints($metrics[self::METRIC]) as $point) {
            $this->assertInstanceOf(NumberDataPoint::class, $point, '失敗 counter は Sum である必要がある。');
            $attributes = $point->attributes->toArray();
            $stage = $attributes['laravel.otel_support.stage'] ?? null;
            $site = $attributes['laravel.otel_support.site'] ?? null;
            $key = (is_string($stage) ? $stage : '?').'|'.(is_string($site) ? $site : '?');
            $counts[$key] = $point->value;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * 失敗 counter 以外の instrument の **生成** を失敗させる MeterProvider。
     *
     * 失敗 counter だけは本物に委譲する: そこも壊すと「数えていない」のか「数えたが届いていない」のか
     * 区別できず、経路ごとの検証にならない (両方壊れた場合の挙動は QueueMetricsTest 側で固定済み)。
     */
    private function meterProviderThatFailsInstrumentBuild(): MeterProviderInterface
    {
        $real = $this->meterProvider;

        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(function (string $scope) use ($real): MeterInterface {
            $meter = Mockery::mock(MeterInterface::class);
            $meter->shouldReceive('createCounter')->andReturnUsing(
                /** @param array<string, mixed> $advisory */
                function (string $name, ?string $unit = null, ?string $description = null, array $advisory = []) use ($real, $scope): CounterInterface {
                    return $real->getMeter($scope)->createCounter($name, $unit, $description, $advisory);
                },
            );
            // 計装 3 種のうち HTTP は histogram だけ、queue は counter 2 本の後に histogram を作るので、
            // histogram で投げれば両方の構築失敗を同じ double で再現できる。
            $meter->shouldReceive('createHistogram')->andThrow(new RuntimeException('histogram creation exploded'));

            return $meter;
        });

        return $meterProvider;
    }

    /** 失敗 counter 以外の instrument の **記録** を失敗させる MeterProvider (生成は成功する)。 */
    private function meterProviderThatFailsRecording(): MeterProviderInterface
    {
        $real = $this->meterProvider;

        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(function (string $scope) use ($real): MeterInterface {
            $meter = Mockery::mock(MeterInterface::class);
            $meter->shouldReceive('createCounter')->andReturnUsing(
                /** @param array<string, mixed> $advisory */
                function (string $name, ?string $unit = null, ?string $description = null, array $advisory = []) use ($real, $scope): CounterInterface {
                    if ($name === self::METRIC) {
                        return $real->getMeter($scope)->createCounter($name, $unit, $description, $advisory);
                    }

                    $counter = Mockery::mock(CounterInterface::class);
                    $counter->shouldReceive('add')->andThrow(new RuntimeException($name.' exploded'));

                    return $counter;
                },
            );
            $meter->shouldReceive('createHistogram')->andReturnUsing(function (string $name): HistogramInterface {
                $histogram = Mockery::mock(HistogramInterface::class);
                $histogram->shouldReceive('record')->andThrow(new RuntimeException($name.' exploded'));

                return $histogram;
            });

            return $meter;
        });

        return $meterProvider;
    }
}
