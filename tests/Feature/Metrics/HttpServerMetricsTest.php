<?php

namespace Sensyn\LaravelOTelSupport\Tests\Feature\Metrics;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Sensyn\LaravelOTelSupport\Metrics\Http\HttpServerMetrics;
use Sensyn\LaravelOTelSupport\Tests\Support\RecordsMetrics;
use Sensyn\LaravelOTelSupport\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class HttpServerMetricsTest extends TestCase
{
    use RecordsMetrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMeter();
    }

    private function runMiddleware(Request $request, Response $response): void
    {
        $middleware = new HttpServerMetrics($this->meterProvider);
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }

    public function test_records_route_template_and_attributes(): void
    {
        $request = Request::create('https://example.test/api/v1/users/5', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'api/v1/users/{user}', []));

        $this->runMiddleware($request, new Response('', 200));

        $metrics = $this->collectMetrics();
        $this->assertArrayHasKey('http.server.request.duration', $metrics);

        $attrs = $this->attributesOf($metrics['http.server.request.duration']);
        $this->assertSame('GET', $attrs['http.request.method']);
        $this->assertSame('https', $attrs['url.scheme']);
        $this->assertSame(200, $attrs['http.response.status_code']);
        // raw path (/api/v1/users/5) ではなくテンプレートが入る。
        $this->assertSame('/api/v1/users/{user}', $attrs['http.route']);
        $this->assertArrayNotHasKey('error.type', $attrs);
    }

    public function test_unmatched_route_omits_http_route(): void
    {
        // route resolver 無し = ルート未マッチ (404 等)。
        $request = Request::create('http://example.test/no/such/path', 'GET');

        $this->runMiddleware($request, new Response('', 404));

        $attrs = $this->attributesOf($this->collectMetrics()['http.server.request.duration']);
        $this->assertArrayNotHasKey('http.route', $attrs);
        $this->assertSame(404, $attrs['http.response.status_code']);
    }

    public function test_server_error_sets_error_type(): void
    {
        $request = Request::create('https://example.test/boom', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'boom', []));

        $this->runMiddleware($request, new Response('', 500));

        $attrs = $this->attributesOf($this->collectMetrics()['http.server.request.duration']);
        $this->assertSame('500', $attrs['error.type']);
    }

    public function test_invalid_buckets_do_not_throw(): void
    {
        // 不正値でも throw せず既定にフォールバックする (全 HTTP リクエストを 500 にしない)。
        config(['otel-support.metrics.http.buckets' => ['oops']]);

        $this->assertInstanceOf(HttpServerMetrics::class, new HttpServerMetrics($this->meterProvider));
    }
}
