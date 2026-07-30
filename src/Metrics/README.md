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
  - `messaging.client.sent.messages` carries the attribute set semconv specifies for that metric:
    `messaging.operation.name` (`send`) and `messaging.system` (both Required), plus
    `messaging.destination.name` (Conditionally Required) and the package's own
    `laravel.queue.job.name`. `messaging.operation.type` is deliberately **not** emitted: it is not
    part of this metric's attribute table (it belongs to the spans and to
    `messaging.client.operation.duration`), and adding it would make the series diverge from the
    same metric emitted by other languages' instrumentation.
    - `messaging.system` is the Laravel queue driver mapped onto semconv's well-known values, which
      today means `sqs` → `aws_sqs`. Drivers with no well-known value (`redis`, `database`,
      `beanstalkd`, `sync`, …) are passed through as the custom value semconv permits.
    - `messaging.destination.name` is the queue the message was really pushed to, so that it joins
      with the consumer-side attribute on `laravel.queue.job.*` (queue backlog =
      `sum(sent) - sum(finished)` per queue). It comes from `JobQueued::$queue` on Laravel 11+, and
      falls back to the connection's default queue (`queue.connections.*.queue`) when the caller
      named no queue. On **Laravel 10 the event carries no queue name**, so the job's own
      destination (`onQueue()` / a `$queue` property) is used instead and a `Queue::pushOn()` that
      contradicts it cannot be seen.
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
- A job goes uncounted whenever recording it fails (a failing instrument, or a job whose payload-derived
  key cannot be read). The queue listeners run on the application's own call stack — `JobQueued` fires
  inline inside `dispatch()`, `JobProcessing` fires before `$job->fire()`, and the listener that writes
  `failed_jobs` is registered *after* ours on `JobFailed` — so the instrumentation never lets its own
  failure escape, and it never logs one either. See
  [the fail-open contract](../../README.md#the-instrumentation-never-breaks-the-application).

## Semantic conventions

**The names, attribute keys and attribute values above follow OpenTelemetry semantic conventions
`1.38.0`.** That version is the reference point for judging whether a change is breaking; without it
pinned in writing, the metric contract would sit outside this package's versioning.

The values are written as plain string literals in `src/`. `open-telemetry/sem-conv` is a dependency of
`open-telemetry/sdk`, which this package may not require at runtime (see
[CONTRIBUTING.md](../../CONTRIBUTING.md)), so the constants are not available to us — when the pinned
version moves, the literals have to be updated by hand together with this section.

Policy for following upstream:

- The messaging conventions are still **Development** stability, so upstream renames of keys or values
  are expected. Adopting one is a **breaking change for the consumer's dashboards and alert rules**
  even though no PHP API changes, so it ships in a minor bump (a major from 1.0 on) and never in a
  patch release — see the versioning rules in [CONTRIBUTING.md](../../CONTRIBUTING.md).
- Where semconv offers no equivalent, the `laravel.*` names are ours and are versioned the same way.

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

Neither instrumentation flushes; both only record. Flushing belongs to the umbrella's `WorkerFlusher`,
because the shutdown hook installed by the SDK autoloader runs when the **PHP process** exits — which is
the end of the request under FPM, but only the end of the worker's life in a long-lived process.

- **HTTP under FPM**: no explicit flush needed. The shutdown hook exports at the end of the request (the
  same path traces take).
- **HTTP under Octane** (FrankenPHP / Swoole / RoadRunner): one process serves hundreds or thousands of
  requests, so `WorkerFlusher` flushes at Octane's `RequestTerminated` to keep the FPM behaviour
  ("export at the end of the request"). Without it `http.server.request.duration` would sit in the
  meter provider until the worker recycles, and be lost entirely if the worker is SIGKILLed.
- **Queue worker (the `queue:work` daemon)**: `WorkerFlusher` flushes at job boundaries (`Looping` /
  `WorkerStopping`).

All of it is behind `worker_flusher.enabled`.
