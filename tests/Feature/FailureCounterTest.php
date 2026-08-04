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
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as SdkMeterProvider;
use OpenTelemetry\SDK\Trace\TracerProvider;
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
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
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

        // flush 経路も健全な provider で 1 境界通す。ここを通さないと「成功した flush まで数える」
        // 退行 (forceFlush() の成功パスに increment を足す変異) が陰性対照をすり抜ける — 計装 2 種と
        // 違い、flush 経路は他のどのテストからも counter を見られていない。
        $this->flushAtOneJobBoundary(new TracerProvider, $this->meterProvider);

        $this->assertSame([], $this->failureCounts(), '正常系で失敗 counter に data point が出てはいけない。');
    }

    public function test_http_instrument_build_failure_is_counted(): void
    {
        // 構築に失敗すると以降そのプロセスの HTTP metrics は丸ごと出ない。例外もログも出ないので、
        // この counter が無いと「Noop に落ちた」ことを外から区別できない。
        new HttpServerMetrics($this->meterProviderThatFailsInstrumentBuild());

        $this->assertSame(['build|metrics.http' => 1], $this->failureCounts());
    }

    public function test_a_broken_meter_provider_cannot_report_its_own_build_failure(): void
    {
        // **stage=build が拾えるのは「provider は健全だが instrument だけ作れない」場合に限られる。**
        // 計装は同じ MeterProvider から先に FailureCounter を作る (HttpServerMetrics /
        // QueueMetrics の constructor 冒頭) ので、instrument を作れない原因が provider / meter 側に
        // あると、失敗 counter の createCounter も同じ理由で倒れて NoopCounter になり、直後の
        // increment(build, ...) はどこにも出ない。
        //
        // つまり build を数える動機として src のコメントが挙げている代表例 (provider 側の故障で
        // 計装が丸ごと Noop に落ちた) では数えられない。「build が 0 なら計装は生きている」と
        // 読めないことを固定しておく (限界は src/Metrics/README.md の盲点一覧)。

        // 対照: provider が健全なら build は数えられる。以降の陰性が「そもそも何も測れていない」
        // だけではないことの裏取りになる。
        new HttpServerMetrics($this->meterProviderThatFailsInstrumentBuild());
        $this->assertSame(['build|metrics.http' => 1], $this->failureCounts());

        // 本題: provider ごと壊れている場合は 1 件も増えない (cumulative なので値は据え置き)。
        new HttpServerMetrics($this->meterProviderThatAlwaysThrows());
        new QueueMetrics($this->meterProviderThatAlwaysThrows());

        $this->assertSame(
            ['build|metrics.http' => 1],
            $this->failureCounts(),
            '壊れた provider 側の build は、失敗 counter 自身も同じ理由で倒れるので観測できない。',
        );
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

    public function test_the_other_method_fallback_is_not_counted_as_a_failure(): void
    {
        // 「_OTHER フォールバックは失敗として数えない」は意味論の選択ではなく、この counter に張る
        // アラートの前提である。requestMethod() の catch は **未認証リモートが任意回数踏める唯一の
        // 失敗経路** (不正な method override) なので、ここで数えると
        // laravel.otel_support.failures が外部から自由に伸ばせる値になり、sum(rate(...)) > 0 の
        // ようなアラートを remote が発報させられる。将来「数え漏れの修正」として善意で足されうる
        // ため、陰性として固定する。実際 catch に increment を足す変異は、この 1 本が無いと
        // 全テストをすり抜ける。
        //
        // ヘッダ経路を使うのは、enableHttpMethodParameterOverride() (プロセスグローバル) に依らず
        // 常に読まれるため (getMethod は X-HTTP-METHOD-OVERRIDE を無条件に見る)。
        $request = Request::create('https://example.test/things', 'POST');
        $request->headers->set('X-HTTP-METHOD-OVERRIDE', 'ZZZ-ATTACKER-1');

        // 前提を固定する: この入力で getMethod() が本当に投げること。投げないなら _OTHER の catch を
        // 再現しておらず、テストが何も検証しないものに化ける。getMethod() は結果を memoize するので
        // clone に対して呼ぶ (元の request で先に呼ぶと terminate() 側で投げなくなる)。
        $thrown = null;
        try {
            (clone $request)->getMethod();
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(
            SuspiciousOperationException::class,
            $thrown,
            'この入力で getMethod() が投げることが前提 (投げないなら _OTHER 経路を再現していない)。',
        );

        // 503 = ルーティング前に応答が確定した状況。この計装が getMethod() の最初の呼び出し者になる。
        $middleware = new HttpServerMetrics($this->meterProvider);
        $response = new Response('', 503);
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);

        $metrics = $this->collectMetrics();
        $this->assertSame(
            '_OTHER',
            $this->attributesOf($metrics['http.server.request.duration'])['http.request.method'],
            'telemetry は落ちておらず、semconv 的に正しい _OTHER で記録されている必要がある。',
        );
        $this->assertSame([], $this->failureCounts(), '_OTHER は「計上を諦めた失敗」ではないので数えない。');
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

        $this->flushAtOneJobBoundary($tracerProvider, $this->meterProviderThatFailsToFlush());

        // 並びは failureCounts() の ksort 順 (metrics -> traces)。
        $this->assertSame([
            'flush|worker_flusher.metrics' => 1,
            'flush|worker_flusher.traces' => 1,
        ], $this->failureCounts());
    }

    public function test_a_trace_flush_failure_is_counted_only_under_the_traces_site(): void
    {
        // **非対称なケースが要る**。両方を失敗させるテストだけでは、site を signal に割り当てている
        // 2 引数 (WorkerFlusher::flush) を入れ替えても結果集合が変わらず、変異が生き残る。site を
        // signal 別にした唯一の理由は「片方だけ恒常的に失敗している状態を切り分ける」ことなので、
        // 対応が逆でも緑ならその投資は回帰から守られていない。しかも metrics 側は「壊れた
        // MeterProvider 自身が運ぶので届かないことがある」既知の盲点付きで、ラベルが逆だと
        // 『届かないはずの系列が見えている』という誤った調査に直結する。
        //
        // meter は本物 (flush も成功する) なので、metrics 側の系列が **出ないこと** まで完全一致で
        // 固定できる。これは「成功した flush まで数える」退行も同時に落とす。
        $tracerProvider = Mockery::mock(SdkTracerProvider::class);
        $tracerProvider->shouldReceive('forceFlush')->andThrow(new RuntimeException('trace flush exploded'));

        $this->flushAtOneJobBoundary($tracerProvider, $this->meterProvider);

        $this->assertSame(['flush|worker_flusher.traces' => 1], $this->failureCounts());
    }

    public function test_a_metric_flush_failure_is_counted_only_under_the_metrics_site(): void
    {
        // 上の裏返し。tracer 側は成功するので traces の系列が出ないことまで固定する。
        $tracerProvider = Mockery::mock(SdkTracerProvider::class);
        $tracerProvider->shouldReceive('forceFlush')->andReturn(true);

        $this->flushAtOneJobBoundary($tracerProvider, $this->meterProviderThatFailsToFlush());

        $this->assertSame(['flush|worker_flusher.metrics' => 1], $this->failureCounts());
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
     * 構築ガードが要る根拠 = 「provider を受け取れても instrument を作れるとは限らない」を
     * upstream に対して固定する。
     *
     * api の LateBindingMeterProvider は getMeter() では factory を走らせず LateBindingMeter を返し、
     * factory が走るのは **createCounter() の時点** である。つまり provider の生成が成功した後、
     * SafeGlobals の try/catch を抜けた先で初めて失敗しうる型が api に実在する。ここが遅延しなくなったら
     * 構築ガードの根拠が変わるので、その時に気付けるようにしておく。
     *
     * **この型が Globals 経由で本パッケージに来るわけではない。** SdkAutoloader が
     * createLateBindingMeterProvider() を使うのは auto-instrumentation へ渡す InstrumentationContext
     * だけで (SdkAutoloader::registerInstrumentations)、Globals に載るのは environmentBasedInitializer が
     * eager に作る実 SDK の MeterProvider である。よってこれは「api の契約上ありうる実装」に対する
     * 保険であって、現行の配線の再現ではない (現行配線の前提は FailureCounter の docblock)。
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

    /**
     * WorkerFlusher を購読させ、queue worker のジョブ境界 (JobProcessing -> Looping) を 1 回通す。
     *
     * until() の戻りも見る: Looping リスナが false を返すと daemonShouldRun() が worker を止めるので、
     * flush 経路のテストは「数えたか」と同時に「worker を止めていないか」も担保する必要がある。
     */
    private function flushAtOneJobBoundary(TracerProviderInterface $tracerProvider, MeterProviderInterface $meterProvider): void
    {
        $dispatcher = new Dispatcher;
        (new WorkerFlusher($tracerProvider, $meterProvider))->subscribe($dispatcher);

        $dispatcher->dispatch(new JobProcessing(self::CONN, Mockery::mock(Job::class)));
        $this->assertNotFalse($dispatcher->until(new Looping(self::CONN, 'default')));
    }

    /**
     * forceFlush() だけが投げる MeterProvider。
     *
     * getMeter は本物に委譲する: 失敗 counter の届け先まで壊すと、そもそも観測できない
     * (それがこの counter の構造的な限界で、src/Metrics/README.md にも明記してある)。
     */
    private function meterProviderThatFailsToFlush(): MeterProviderInterface
    {
        $real = $this->meterProvider;

        $meterProvider = Mockery::mock(SdkMeterProvider::class);
        $meterProvider->shouldReceive('getMeter')->andReturnUsing(
            fn (string $name): MeterInterface => $real->getMeter($name),
        );
        $meterProvider->shouldReceive('forceFlush')->andThrow(new RuntimeException('metric flush exploded'));

        return $meterProvider;
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

    /** getMeter() が常に投げる MeterProvider (= provider / meter 側そのものが壊れている状況)。 */
    private function meterProviderThatAlwaysThrows(): MeterProviderInterface
    {
        $meterProvider = Mockery::mock(MeterProviderInterface::class);
        $meterProvider->shouldReceive('getMeter')->andThrow(new RuntimeException('meter provider exploded'));

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
