<?php

namespace Sensyn\LaravelOTelSupport\Metrics\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Sensyn\LaravelOTelSupport\Metrics\Buckets;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP server リクエストの所要時間を OTel histogram (http.server.request.duration) に記録する
 * terminable な global middleware。flush は明示的に行わず、FPM の request 終了時に SDK autoload の
 * shutdown hook が export する (queue worker と違い HTTP は明示 flush 不要)。
 */
class HttpServerMetrics
{
    private const START_ATTRIBUTE = 'otel.http.start';

    private const CONTEXT_ATTRIBUTE = 'otel.http.context';

    private HistogramInterface $duration;

    public function __construct(MeterProviderInterface $meterProvider)
    {
        $buckets = Buckets::fromConfig('otel-support.metrics.http.buckets', Buckets::DEFAULT_HTTP);

        // MeterProvider は provider が container に bind する (Globals 依存は app 側に閉じる)。
        // meter (instrumentation scope) 名と histogram 名は計装の identity。サービス名は resource の
        // service.name で別途付くので、ここはモジュール名ベースの固定値にする (config 化しない)。
        $this->duration = $meterProvider
            ->getMeter('laravel-otel-support.metrics.http')
            ->createHistogram(
                'http.server.request.duration',
                's',
                'Duration of HTTP server requests',
                ['ExplicitBucketBoundaries' => $buckets],
            );
    }

    public function handle(Request $request, Closure $next): Response
    {
        // terminate() が別インスタンスで解決されても拾えるよう開始時刻は request に載せる。
        if (! $request->attributes->has(self::START_ATTRIBUTE)) {
            $request->attributes->set(self::START_ATTRIBUTE, microtime(true));
            // span が active なうちに context を捕まえておく。terminate() 時点では auto-laravel の
            // SERVER span は終了済みで、record に context を渡さないと exemplar filter が拾えない。
            // SpanContext は span 終了後も不変なので、後で渡しても trace/span id は乗る。
            $request->attributes->set(self::CONTEXT_ATTRIBUTE, Context::getCurrent());
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $start = $request->attributes->get(self::START_ATTRIBUTE);
        if (! is_float($start)) {
            return;
        }

        $status = $response->getStatusCode();

        $attrs = [
            'http.request.method' => $request->getMethod(),          // Required
            'url.scheme' => $request->getScheme(),                   // Required (http/https)
            'http.response.status_code' => $status,                  // Conditionally Required
        ];

        // http.route はルートがマッチした時のみ。raw path は高カーディナリティなので入れない。
        $route = $request->route();
        if ($route instanceof Route) {
            $attrs['http.route'] = '/'.ltrim($route->uri(), '/');
        }

        // network.protocol.version (Recommended, best-effort)。"HTTP/1.1" -> "1.1"。
        $protocol = $request->server->get('SERVER_PROTOCOL');
        if (is_string($protocol) && str_starts_with($protocol, 'HTTP/')) {
            $attrs['network.protocol.version'] = substr($protocol, 5);
        }

        // error.type はサーバエラー (5xx) の時のみ。値はステータスコード文字列でよい。
        if ($status >= 500) {
            $attrs['error.type'] = (string) $status;
        }

        // handle() で捕まえた span context を渡す (exemplar に trace/span id を乗せるため)。
        $context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);
        $this->duration->record(
            microtime(true) - $start,
            $attrs,
            $context instanceof ContextInterface ? $context : null,
        );
    }
}
