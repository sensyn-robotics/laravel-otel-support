# laravel-otel-support / Metrics

The module that instruments OpenTelemetry metrics by hand. Traces stay with
`opentelemetry-auto-laravel`; only metrics are emitted over OTLP by this module (a separate signal, so
the two do not conflict).

It is a submodule under the `Sensyn\LaravelOTelSupport` umbrella, whose `OTelSupportServiceProvider`
takes care of merging and publishing the config in one place (`metrics.*` in
[../../config/otel-support.php](../../config/otel-support.php)).

## Layout

- `Http/HttpServerMetrics.php`: global terminable middleware. Records the duration of HTTP requests in a
  histogram.
- `Queue/QueueMetrics.php`: subscribes to the queue events and records the number of enqueued messages
  plus the terminal outcome (success / failure) and duration of jobs.
- `MetricsServiceProvider.php`: middleware registration and queue listener subscription (it only reads
  `otel-support.metrics.*`).
- `../../config/otel-support.php`: buckets and the other settings, under `metrics.*`.

## Recorded metrics

| Name | Kind | Unit | Description |
|------|------|------|-------------|
| `http.server.request.duration` | Histogram | `s` | Duration of HTTP requests |
| `messaging.client.sent.messages` | Counter | `{message}` | Number of enqueued messages |
| `laravel.queue.job.finished` | Counter | `{job}` | Jobs reaching a terminal outcome (final success / failure, retries included) |
| `laravel.queue.job.duration` | Histogram | `s` | Duration of the terminal attempt |

- Where the meaning matches semconv ([HTTP](https://opentelemetry.io/docs/specs/semconv/http/) /
  [Messaging](https://opentelemetry.io/docs/specs/semconv/messaging/)), the semconv name is used.
- Where semconv has no equivalent, the domain name `laravel.queue.*` is used instead.
- Attributes also follow semconv wherever possible.
- The meter (instrumentation scope) names are `laravel-otel-support.metrics.http` and
  `laravel-otel-support.metrics.queue`.
- Per metric:
  - `http.server.request.duration`
    - carries `http.route` (the route template) only when routing matched; the raw path is never used.
    - `http.request.method` only ever holds a semconv known method or `_OTHER`, so the number of series
      stays bounded no matter what a client sends. Requests whose method cannot be determined at all (a
      malformed override such as `_method=FOO-BAR` makes Symfony's `getMethod()` throw) are recorded as
      `_OTHER` too. `http.request.method_original` is deliberately omitted: it would bring the unbounded
      cardinality straight back.
    - A request goes uncounted in the rare case where collecting the rest of its attributes fails - for
      instance `getScheme()` raising `ConflictingHeadersException` when a trusted `Forwarded` header
      contradicts `X-Forwarded-Proto`. The instrumentation never lets its own failure escape into the
      application's `terminate` chain, and it does not invent an attribute value either.
  - Every queue metric carries the job class as `laravel.queue.job.name`.
  - `laravel.queue.job.finished`
    - carries `laravel.queue.job.retried` (bool, `attempts > 1`).
      - Retry rate is `sum(...finished{retried="true"}) / sum(...finished)`.
    - Success vs. failure is told apart by the presence of `error.type`.
  - `laravel.queue.job.duration` (histogram):
    - carries `error.type` as well (same as the counter). Latency distributions differ between success
      and failure, so the duration can be split by `error.type`.
- Jobs on the `sync` driver are not counted: they run inline in the dispatching process (an HTTP
  request, typically).

## Registration

No registration work on the consuming side. Composer package auto-discovery
(`extra.laravel.providers`) registers the umbrella
`Sensyn\LaravelOTelSupport\OTelSupportServiceProvider`, which in turn registers this module's
`MetricsServiceProvider` (for the installation steps see [../../README.md](../../README.md)).

The middleware is registered too: the Metrics provider's `boot()` prepends it to the front of the global
stack, so the consumer's middleware definitions (`bootstrap/app.php` on Laravel 11+, `Http/Kernel.php`
on Laravel 10) are never edited.

## Configuration (`metrics.*` in [`../../config/otel-support.php`](../../config/otel-support.php))

- `metrics.enabled`: master switch for the metrics module (`OTEL_METRICS_INSTRUMENTATION_ENABLED`).
- `metrics.http.enabled` / `metrics.queue.enabled`: on/off per instrumentation.
- `metrics.http.buckets` / `metrics.queue.duration_buckets`: histogram bucket boundaries (seconds). The
  defaults are the semconv recommendation (identical for HTTP and messaging); to match a product's own
  distribution or SLO, override them through env (`OTEL_METRICS_HTTP_BUCKETS` /
  `OTEL_METRICS_QUEUE_DURATION_BUCKETS`). Unordered lists and duplicates are normalised by sorting and
  de-duplication, and invalid values (empty / non-numeric) fall back to the default.

Export itself (OTLP endpoint, protocol, temporality) is configured through the OTel SDK's env vars
(`OTEL_EXPORTER_OTLP_*`, `OTEL_METRICS_EXPORTER`, `OTEL_PHP_AUTOLOAD_ENABLED`). This module only records;
it does not touch export wiring.

## About flushing

- **HTTP (FPM)**: no explicit flush. The shutdown hook installed by the SDK autoloader exports at the end
  of the request (the same path traces take).
- **Queue worker (the `queue:work` CLI daemon)**: the shutdown hook only runs when the process exits, so
  the umbrella's `WorkerFlusher` calls `forceFlush` on the meter provider at job boundaries (`Looping` /
  `WorkerStopping`); `QueueMetrics` sticks to recording.
