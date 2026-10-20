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
- `../FailureCounter.php`: **not part of this module.** It sits under the umbrella because
  `WorkerFlusher` reports through it too, but the metric it emits is catalogued here with the rest — see
  [Instrumentation failures](#instrumentation-failures).

## Recorded metrics

| Name | Kind | Unit | Description |
|------|------|------|-------------|
| `http.server.request.duration` | Histogram | `s` | Duration of HTTP requests |
| `messaging.client.sent.messages` | Counter | `{message}` | Number of enqueued messages |
| `laravel.queue.job.finished` | Counter | `{job}` | Jobs reaching a terminal outcome (final success / failure, retries included) |
| `laravel.queue.job.duration` | Histogram | `s` | Duration of the terminal attempt |
| `laravel.otel_support.failures` | Counter | `{failure}` | Failures contained by the fail-open guards of this package ([details](#instrumentation-failures)) |

- Where the meaning matches semconv ([HTTP](https://opentelemetry.io/docs/specs/semconv/http/) /
  [Messaging](https://opentelemetry.io/docs/specs/semconv/messaging/)), the semconv name is used.
- Where semconv has no equivalent, the domain name `laravel.queue.*` is used instead — and
  `laravel.otel_support.*` for the package's own self-diagnostics, which no convention covers.
- Attributes also follow semconv wherever possible.
- The meter (instrumentation scope) names are `laravel-otel-support.metrics.http` and
  `laravel-otel-support.metrics.queue`; `laravel.otel_support.failures` uses the umbrella scope
  `laravel-otel-support`, because it is emitted from the whole package rather than from one
  instrumentation (the source is carried in an attribute instead).
- Per metric:
  - `messaging.client.sent.messages` carries the attribute set semconv specifies for that metric:
    `messaging.operation.name` (`send`) and `messaging.system` (both Required), plus
    `messaging.destination.name` (Conditionally Required) and the package's own
    `laravel.queue.job.name`.
    - `messaging.system` is the Laravel queue driver mapped onto semconv's well-known values, which
      today means `sqs` → `aws_sqs`. Drivers with no well-known value (`redis`, `database`,
      `beanstalkd`, …) are passed through as the custom value semconv permits. `sync` never appears,
      because those jobs are not counted at all (see below).
    - `messaging.destination.name` is the name of the queue the message was really pushed to, so
      that it joins with the consumer-side attribute on `laravel.queue.job.*` (queue backlog =
      `sum(sent) - sum(finished)` per queue; the `sqs` driver needs the normalisation described
      below for that to hold, and the `sync` driver is left out of both sides so that the
      subtraction stays meaningful). It comes from `JobQueued::$queue` on Laravel 11+, and
      falls back to the connection's default queue (`queue.connections.*.queue`) when the caller
      named no queue. On **Laravel 10 the event carries no queue name**, so the job's own
      destination (`onQueue()` / a `$queue` property) is used instead.

      That fallback has a limit worth knowing about, because the framework itself hits it. A
      destination handed to `Queue::pushOn($queue, $job)` is never written onto the job, so on
      Laravel 10 it cannot be recovered from anywhere — not the event, not the payload. Of the four
      wrappers the framework queues work in, two are pushed that way:

      | wrapper | how the destination travels | recoverable on 10 |
      |---|---|---|
      | `SendQueuedMailable` (mail) | assigned in the constructor | yes |
      | `SendQueuedNotifications` (notifications) | `NotificationSender` calls `->onQueue()` | yes |
      | `CallQueuedListener` (queued listeners) | `Dispatcher::queueHandler` calls `pushOn()` | **no** |
      | `BroadcastEvent` (broadcasts) | `BroadcastManager` calls `pushOn()` | **no** |

      So a queued listener or a broadcast event with its own `$queue` is reported by the producer
      under the connection's default queue while the consumer reports the real one, and the per-queue
      `sum(sent) - sum(finished)` splits: the default queue grows without bound and the real queue goes
      negative. **Laravel 11+ is unaffected** — the event carries the destination. The test suite pins
      the wrong value so the limit stays visible rather than being mistaken for correct behaviour.
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
  - Every queue metric carries the job's **display name** as `laravel.queue.job.name`, which is the
    job class for an ordinary job. It is not simply `get_class()`, because the framework queues
    mail, notifications, queued listeners and broadcast events wrapped in `SendQueuedMailable`,
    `SendQueuedNotifications`, `CallQueuedListener` and `BroadcastEvent`; each of those overrides
    `displayName()` to return the class it wraps, and that is what ends up in the payload. The
    producer side reproduces the framework's rule so that both sides of a message agree — otherwise
    `sum(sent) - sum(finished)` splits into a wrapper name that never finishes and an inner name that
    was never sent.
  - Every queue metric carries the queue as `messaging.destination.name`, and on the `sqs` driver
    both sides are normalised to the queue *name* so that they join:
    - The consumer side would otherwise report the **full queue URL**, because that is what Laravel
      puts on the job (`SqsQueue::pop()` hands `getQueue()`'s return value — the URL — to `SqsJob`).
      A URL is therefore reduced to its last path segment. That is the queue name semconv's own
      examples use and what other languages' SQS instrumentation emits, and it keeps the AWS
      account id (part of the URL) out of the label.
    - The producer side only knows the queue argument of the push, so it applies the connection's
      `suffix` (`queue.connections.*.suffix`) exactly as `SqsQueue` does — before the `.fifo`
      marker, and not at all when the caller passed a queue URL of its own, which SQS queues use
      verbatim.
  - `laravel.queue.job.finished`
    - carries `laravel.queue.job.retried` (bool, `attempts > 1`).
      - Retry rate is `sum(...finished{retried="true"}) / sum(...finished)`.
    - Success vs. failure is told apart by the presence of `error.type`.
  - `laravel.queue.job.duration` (histogram):
    - carries `error.type` as well (same as the counter). Latency distributions differ between success
      and failure, so the duration can be split by `error.type`.
- Jobs on the `sync` driver are counted on **neither** side — not as an enqueue and not as a terminal
  outcome. They run inline in the dispatching process (an HTTP request, typically), where
  `http.server.request.duration` already covers the time they take, and they never sit in a queue, so
  there is no backlog they could be part of: counting one of the two sides only would turn
  `sum(sent) - sum(finished)` into a backlog that grows forever on a connection that can never have one.
  A `sync` failure is thrown back to the caller synchronously, so it surfaces through the application's
  own error handling rather than through this metric.
- A job goes uncounted whenever recording it fails (a failing instrument, or a job whose payload-derived
  key cannot be read). The queue listeners run on the application's own call stack — `JobQueued` fires
  inline inside `dispatch()`, `JobProcessing` fires before `$job->fire()`, and the listener that writes
  `failed_jobs` is registered *after* ours on `JobFailed` — so the instrumentation never lets its own
  failure escape, and it never logs one either. What it does do is count the drop in
  `laravel.otel_support.failures`. See
  [the fail-open contract](../../README.md#the-instrumentation-never-breaks-the-application).

## Instrumentation failures

`laravel.otel_support.failures` is the package reporting on itself. Every entry point the framework
calls into is fail-open and **none of those paths log** — they run per request and per job, so a log
line would amplify an outage instead of reporting it. Without a counter a contained failure is
indistinguishable from "this code never ran", because both look like missing telemetry. A counter
aggregates, so it can be incremented on the same hot paths at the cost of one series.

| Attribute | Values | Meaning |
|---|---|---|
| `laravel.otel_support.stage` | `build` | An instrument could not be created. The instrumentation falls back to a no-op one, so **everything it would have recorded is gone for the rest of the process**. |
| | `record` | The recording of one event was abandoned. Usually that is a dropped data point; on the queue side it can also be the bookkeeping a later data point needs (`JobProcessing` storing the job's start time), in which case the *duration* of that job is what goes missing. |
| | `flush` | `forceFlush` at a work boundary failed. The dirty flag survives, so it is retried at the next boundary. An idle-time metrics flush is not retried; the next one runs after the interval. |
| `laravel.otel_support.site` | `metrics.http` | The HTTP middleware (`Http/HttpServerMetrics.php`). |
| | `metrics.queue` | The queue listeners (`Queue/QueueMetrics.php`). |
| | `worker_flusher.traces` / `worker_flusher.metrics` | `WorkerFlusher`, per signal — the two are flushed independently so that one failing does not skip the other, and the counter keeps that granularity. |

What the counter deliberately does **not** carry is the exception class (semconv's `error.type`). The
exceptions reaching these guards come from the SDK's internals, the application's logger (any Monolog
handler) and the exporter's transport, so the set of values is **not bounded by this package**; the
recording paths run per request, and the HTTP one is reachable by an unauthenticated client at will.
An unbounded label there would reproduce, on the one metric that only grows during an incident, exactly
the cardinality amplification `http.request.method_original` is left out to avoid. When the exception
itself is needed, turn on the SDK's own diagnostics (`OTEL_LOG_LEVEL`, `OTEL_PHP_LOG_DESTINATION`).

**A zero is not proof that nothing failed.** These cases are outside what the counter can see — the
first three because the counter's own destination is the thing that failed:

- **The global meter provider could not be resolved.** `SafeGlobals` falls back to the API's
  `NoopMeterProvider`, and a counter on a no-op provider goes nowhere.
- **An instrument could not be built because the provider or the meter is what is broken.** Each
  instrumentation builds the failure counter from that same provider before anything else, so it falls
  back to a no-op counter for the same reason, and the `build` increment that follows goes nowhere.
  **`build` therefore only ever covers a healthy provider on which one instrument could not be
  created** — an invalid bucket list whose `Log::warning` throws, a missing facade root. Do not read
  `build == 0` as "the instrumentation is alive".
- **`WorkerFlusher` failed to flush the meter provider.** The increment happens, but the only thing that
  can deliver it is that same provider, so it surfaces only if a later boundary flushes successfully.
- **The global *tracer* provider could not be resolved.** `SafeGlobals` falls back to the API's
  `NoopTracerProvider`, which has no `forceFlush` at all, so `WorkerFlusher` skips it and reports
  success. Nothing is counted under `worker_flusher.traces`, and spans simply never leave the process.
- **A request whose HTTP method cannot be determined** (a malformed override making `getMethod()` throw)
  is *not* a failure by this definition: nothing is dropped, the data point is recorded with the
  semconv-correct `_OTHER`. Count the `_OTHER` series itself if you need those. It is also the one
  guarded path an unauthenticated client can reach at will, so counting it would let a remote drive
  this counter — and any alert on it — directly.

The counter needs no switch of its own; it follows the ones already there. `metrics.enabled=false`
registers neither instrumentation, so the `metrics.*` sites cannot fire, and `worker_flusher.enabled=false`
does the same for the `worker_flusher.*` ones. Note that the *instrument* is created by each of the three
independently, so `WorkerFlusher` alone is enough to register it. With everything healthy it holds no
data points, but it is still one instrument in every collection — near-free rather than free.

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
  `WorkerStopping`), and flushes metrics once a minute while idle. The queue instruments are created at
  the first `Looping` rather than at the first job, so that the idle flushes keep their start time
  recent.

All of the flushing is behind `worker_flusher.enabled`. Creating the queue instruments at the first
`Looping` is part of the queue instrumentation, so it follows `metrics.queue.enabled` instead.
