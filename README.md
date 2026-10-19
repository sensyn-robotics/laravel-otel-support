# laravel-otel-support

OpenTelemetry metrics instrumentation and queue-worker signal flushing for Laravel, complementing
[`opentelemetry-auto-laravel`](https://github.com/opentelemetry-php/contrib-auto-laravel) and the OTel
SDK autoloader.

> **Warning**
> This package is published for internal use at Sensyn Robotics. It is provided as-is and we do not
> accept external contributions — see [CONTRIBUTING.md](CONTRIBUTING.md).

---

This is an umbrella package for the instrumentation that the official `opentelemetry-auto-laravel` plus
the SDK autoloader do *not* cover. Traces keep coming from auto-laravel; this package adds the other
signals (metrics today) and provides a `WorkerFlusher` that flushes buffered signals at the work
boundaries of long-lived processes — `queue:work` jobs and Octane HTTP requests — where the SDK's
process-exit flush comes far too late.

Runtime code depends on the OTel **api and context packages only**. SDK, exporter and autoload wiring
are left to the consuming application's env, which is why `open-telemetry/sdk` is not in `require`
(the test suite pulls it in through `require-dev`).

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| Laravel | 10.x / 11.x / 12.x / 13.x |
| OpenTelemetry | `open-telemetry/api` ^1.9 / `open-telemetry/context` ^1.4 |

## Installation

This package is **not published to a public Composer registry**. Composer reaches it through a VCS
`repositories` entry pointing at this Git repository, so a bare `composer require` cannot find it. Add
both the repository and the constraint to the consuming application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sensyn-robotics/laravel-otel-support"
        }
    ],
    "require": {
        "sensyn-robotics/laravel-otel-support": "^0.1"
    }
}
```

```bash
composer update sensyn-robotics/laravel-otel-support
```

The same thing from the command line:

```bash
composer config repositories.laravel-otel-support vcs https://github.com/sensyn-robotics/laravel-otel-support
composer require "sensyn-robotics/laravel-otel-support:^0.1"
```

How the constraint is resolved:

- **Versions come from Git tags.** Releases are tagged `vX.Y.Z` on `main`; Composer lists those tags
  and reads `composer.json` as it existed at the tag. `^0.1` therefore means `>=0.1.0 <0.2.0`, so a
  `0.2.0` release needs a constraint bump in every consumer (pre-1.0 caret constraints do not cross the
  minor boundary). If a constraint does not resolve, check that a matching tag exists — there is no
  registry to fall back on.
- **`^0.1` never picks up a breaking change.** Below 1.0 the minor position is treated as the major, so
  breaking changes — including changes to metric names, attribute keys or attribute values, which break
  dashboards without breaking any PHP call — only ship in `0.2.0`, `0.3.0`, … A patch release
  (`0.1.1`, …) is always backward compatible and safe to pick up in an unrelated `composer update`.
  The full rules are in [CONTRIBUTING.md](CONTRIBUTING.md#versioning-and-compatibility).
- An untagged commit can be required as `dev-main`, but keep that to local experiments: the constraint
  follows a moving branch, so any `composer update` silently advances it.
- The repository is public, so no Composer credentials are needed in CI or in Docker builds. Composer
  does call the GitHub API to list tags and download archives; if a build hits the unauthenticated rate
  limit, give it a token (for example
  `COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'`).

### Registration

Nothing to register by hand. Composer package auto-discovery (`extra.laravel.providers`) registers
`Sensyn\LaravelOTelSupport\OTelSupportServiceProvider`, so no entry is needed in `config/app.php`
(or `bootstrap/providers.php` on Laravel 11+). The umbrella provider registers the Metrics submodule
provider and wires `WorkerFlusher`.

Middleware needs no registration either: the Metrics provider's `boot()` prepends it to the front of
the global middleware stack, so the application's `Http/Kernel.php` / `bootstrap/app.php` stay
untouched.

## Configuration

Defaults work as they are, and the settings a deployment realistically changes all have env vars, so
**prefer env overrides** and publish the config file only when you really need to.

```bash
php artisan vendor:publish --tag=otel-support-config
```

> **Warning**
> If you do publish, copy the shipped file and change only what you need. The provider merges with
> `mergeConfigFrom()`, which is a **shallow merge of top-level keys**: an application
> `config/otel-support.php` that defines only part of `metrics` silently drops the package defaults for
> the sibling keys (`metrics.http`, `metrics.queue`, …).

| Config key | Env | Default | Description |
|---|---|---|---|
| `metrics.enabled` | `OTEL_METRICS_INSTRUMENTATION_ENABLED` | `true` | Master switch for the metrics module. `false` registers neither the middleware nor the queue listeners |
| `metrics.http.enabled` | – | `true` | HTTP metrics instrumentation on/off |
| `metrics.http.buckets` | `OTEL_METRICS_HTTP_BUCKETS` | semconv recommendation | Bucket boundaries of the HTTP duration histogram (seconds, comma-separated) |
| `metrics.queue.enabled` | – | `true` | Queue metrics instrumentation on/off |
| `metrics.queue.duration_buckets` | `OTEL_METRICS_QUEUE_DURATION_BUCKETS` | semconv recommendation | Bucket boundaries of the job duration histogram (seconds, comma-separated) |
| `worker_flusher.enabled` | `OTEL_WORKER_FLUSH_ENABLED` | `true` | `forceFlush` at the work boundaries of long-lived processes (queue-worker jobs, Octane requests), plus a once-a-minute metrics flush while a queue worker is idle, on/off |

Bucket lists may be unordered and contain duplicates (they are sorted and de-duplicated), and invalid
values (empty, non-numeric) fall back to the default.

### Without the OTel SDK the package is inert

`WorkerFlusher` and the instrumentation resolve their providers through `OpenTelemetry\API\Globals`.
When the SDK autoloader is not running — `OTEL_PHP_AUTOLOAD_ENABLED` unset or false, or
`open-telemetry/sdk` not installed at all — those calls return the API's **`NoopMeterProvider` /
`NoopTracerProvider`**: instruments are no-ops, nothing is buffered, and `forceFlush()` is skipped
because the Noop providers do not implement it (`WorkerFlusher` guards with `method_exists()`).

Installing this package on a service that has no OTel wiring yet is therefore harmless, and the same
holds when only some signals are exported. **If the only thing missing is an enabled exporter, leave
the flags at their default `true`.**

### The instrumentation never breaks the application

Telemetry is not worth an outage, so every entry point the framework calls into is fail-open: the
instrumentation contains its own failures and gives up on the measurement rather than letting an
exception reach the application.

- **Building the instruments** — an invalid bucket list, a logger that throws while reporting it, a
  failing `Globals` initialisation — falls back to the API's Noop instruments. This is handled where
  the meter provider is *resolved* as well as inside the constructors, because the container resolves
  the constructor argument before the constructor body runs: the middleware is resolved twice per
  request (in the pipeline and again in `Kernel::terminateMiddleware`) and, while the singleton binding
  means a *successful* construction happens once, **a failed one is not cached** — so the second resolve
  runs the constructor again. The queue listeners are resolved on every event. A failure there would
  otherwise mean a 500 for every request, a truncated `terminate` chain (`defer()` work and terminating
  callbacks silently dropped), or an exception surfacing in whatever called `dispatch()`.
- **Recording** — the queue listeners and the middleware's `terminate()` — drops the data point.
- **Flushing** — `WorkerFlusher` keeps the worker process alive, and the dirty flag survives the
  failure, so the flush is retried on the next `Looping`.

None of the recording and flushing paths log. The logger is a failure source of its own, and those paths
run per request and per job, so a log line would amplify the problem instead of reporting it. **A failure
therefore shows up as missing telemetry, never as changed application behaviour**; when data goes
missing, look for the cause in the OTel SDK's own diagnostics rather than the application log.

There is exactly one exception, and it is a configuration error rather than a runtime failure: an
invalid bucket list logs a single `warning` naming the config key, from where the instrument is built.
The middleware is a singleton, so that is one line per request under FPM and one line per worker under
Octane — still a steady stream if the value is left wrong. Treat it as something to fix, not to filter.

Not logging is not the same as saying nothing, though. What each guard leaves behind is a *count*:
`laravel.otel_support.failures`, incremented with the stage (`build` / `record` / `flush`) and the site
that contained the failure. A counter aggregates, so unlike a log line it can be written on those same
hot paths without amplifying an incident. It carries no exception class, and there are cases it cannot
see by construction (a broken meter provider cannot deliver a metric about itself), so **a zero on it is
not proof that nothing failed**. The attribute values and the blind spots are listed in
[src/Metrics/README.md](src/Metrics/README.md#instrumentation-failures).

Wiring is fail-open too. The provider only *registers listeners* during `boot()`; `WorkerFlusher` itself
is built the first time one of those events fires, and the global tracer and meter providers are
resolved through the same guarded factory as the instrumentation. A broken `Globals` therefore cannot
turn a telemetry misconfiguration into a failing `artisan migrate` or a dead `queue:work`.

### Flags a consumer should consider turning off

The cases below are the ones where leaving a flag on is wasteful or misleading, rather than merely
inert.

- **`worker_flusher.enabled=false` — services that run neither a `queue:work` daemon nor Octane.**
  Under plain FPM there is nothing to flush, because the SDK's shutdown hook already exports at the end
  of every request. The flush boundaries themselves (`Looping`, `WorkerStopping`, Octane's
  `RequestTerminated`) can never fire there, so leaving the flag on costs a few listener entries at boot
  — plus, in an application that dispatches `sync` jobs, building the flusher once to set a dirty flag
  that nothing ever acts on.

  Also consider it when **synchronous export per unit of work becomes a measurable cost** — a
  high-throughput queue, or a high-RPS Octane service (today the flush runs on every job and every
  Octane request; deltas mean it does not double-count). An idle `queue:work` worker also flushes
  metrics once a minute, so that the start time of a series first seen after a long idle stretch stays
  recent. Some OTLP backends fill in a zero sample at the start time only when it is within 5 minutes of
  the first sample. This covers the instruments that already exist at the time of the flush, which is
  why the package's queue instruments are created at the first loop; an application's own instrument
  created lazily inside a job is not covered. It also covers queue workers only: an idle Octane worker
  has no loop event to flush on, and a job that runs for several minutes still pushes its first sample
  past that window. While the collector is unreachable, each idle flush blocks the loop for the
  exporter's timeout and retries, as the flush after a job already does.

  Note that traces and metrics are the only signals this flushes. The **logs signal is deliberately
  out of scope**, because these services log to stdout rather than through an OTLP exporter — if that
  ever changes, the logger provider has to be added here, or a long-lived worker's log records sit in
  the batch processor until the process exits.

  Be aware of the trade-off: disabling it stops the flush of *both* traces and metrics and leaves
  everything to the SDK's shutdown flush at process exit. In a long-lived daemon or an Octane worker
  that means exports are heavily delayed, and lost outright if the process is SIGKILLed.
- **`metrics.queue.enabled=false` — services that do not use queues, or only the `sync` driver.**
  `sync` jobs run inline in the dispatching process (an HTTP request, typically) and are deliberately
  counted on neither side — not as an enqueue and not as a terminal outcome — so the queue metrics would
  stay empty forever.
- **`metrics.http.enabled=false`** — worker-only services with no HTTP surface.
- **`metrics.enabled=false`** — services that should not emit the metrics signal at all (traces only).

### Export wiring

This package only records and flushes; it does not touch export wiring. OTLP endpoint, protocol,
temporality and friends are configured through the OTel SDK's own env vars
(`OTEL_PHP_AUTOLOAD_ENABLED`, `OTEL_EXPORTER_OTLP_*`, `OTEL_METRICS_EXPORTER`, …). The SDK, the
exporter and the auto-instrumentation (`open-telemetry/sdk`, `open-telemetry/exporter-otlp`,
`open-telemetry/opentelemetry-auto-laravel`, …) are **required by the consuming application**.

## Layout

- `src/OTelSupportServiceProvider.php`: umbrella provider. Merges and publishes the config once,
  registers the submodule providers and wires `WorkerFlusher`.
- `config/otel-support.php`: config shared by the submodules (key `otel-support`), with `metrics.*` and
  `worker_flusher.*` nested underneath.
- `src/Metrics/`: HTTP and queue metrics instrumentation. See
  [src/Metrics/README.md](src/Metrics/README.md) for the recorded metrics, their attributes and the
  naming rationale.
- `src/WorkerFlusher.php`: `forceFlush`es the signal providers at the work boundaries of long-lived
  processes — queue-worker jobs (`Looping` / `WorkerStopping`) and Octane requests
  (`RequestTerminated`, subscribed by class name so that `laravel/octane` stays out of the dependency
  list). The class docblock explains why those flush points were chosen and why the class sits directly
  under the umbrella rather than in a submodule.
- `src/SafeGlobals.php`: resolves the global tracer / meter provider and falls back to the API's Noop
  providers if `Globals` throws. Both providers inject through it, which is what keeps a telemetry
  misconfiguration out of the application's own code paths.
- `src/FailureCounter.php`: counts what the fail-open guards swallow (`laravel.otel_support.failures`).
  It sits under the umbrella rather than in `src/Metrics/` because the instrumentation *and*
  `WorkerFlusher` report through it. Its own construction and recording are guarded as well — the
  class docblock lists the upstream paths on which creating or writing a counter really can throw.

## Development

```bash
composer install
composer test        # phpunit (orchestra/testbench)
composer analyse     # phpstan (level 8)
composer format      # laravel/pint
composer check       # all three of the above (three of the five CI jobs)
```

The supported Laravel 10–13 combinations are verified by the CI matrix
([.github/workflows/ci.yml](.github/workflows/ci.yml)). On PHP 8.1 a plain `composer install` cannot
resolve — see [CONTRIBUTING.md](CONTRIBUTING.md) for that and for the rest of the development workflow.

## License

[Apache License 2.0](LICENSE). Copyright notice: [NOTICE](NOTICE).
