# laravel-otel-support

OpenTelemetry metrics instrumentation and queue-worker signal flushing for Laravel, complementing
[`opentelemetry-auto-laravel`](https://github.com/opentelemetry-php/contrib-auto-laravel) and the OTel
SDK autoloader.

> **Note**
> This package is published for internal use at Sensyn Robotics. It is provided as-is and we do not
> accept external contributions — see [CONTRIBUTING.md](CONTRIBUTING.md).

---

This is an umbrella package for the instrumentation that the official `opentelemetry-auto-laravel` plus
the SDK autoloader do *not* cover. Traces keep coming from auto-laravel; this package adds the other
signals (metrics today) and provides a `WorkerFlusher` that flushes buffered signals at job boundaries
inside long-lived queue workers.

Runtime code depends on the OTel **api and context packages only**. SDK, exporter and autoload wiring
are left to the consuming application's env, which is why `open-telemetry/sdk` is not in `require`
(the test suite pulls it in through `require-dev`).

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
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
| `worker_flusher.enabled` | `OTEL_WORKER_FLUSH_ENABLED` | `true` | `forceFlush` at queue-worker job boundaries on/off |

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

### Flags a consumer should consider turning off

The cases below are the ones where leaving a flag on is wasteful or misleading, rather than merely
inert.

- **`worker_flusher.enabled=false` — services that never run a `queue:work` daemon** (HTTP-only
  services, or services whose jobs run in another runtime / an external worker). There is nothing to
  flush there: `Looping` / `WorkerStopping` only fire under `queue:work`, so the listeners can never
  run, yet every artisan invocation still pays for wiring them — including resolving the global tracer
  and meter providers, which initialises the SDK on a console process that will never emit worker
  telemetry.

  Also consider it when **per-job synchronous export becomes a measurable cost on a high-throughput
  queue** (today the flush runs on every job; deltas mean it does not double-count).

  Be aware of the trade-off: disabling it stops the per-job flush of *both* traces and metrics and
  leaves everything to the SDK's shutdown flush at process exit, which in a long-lived daemon means
  exports are heavily delayed or lost.
- **`metrics.queue.enabled=false` — services that do not use queues, or only the `sync` driver.**
  `sync` jobs run inline in the dispatching process (an HTTP request, typically) and are deliberately
  not counted, so the queue metrics would stay empty forever.
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
- `src/WorkerFlusher.php`: `forceFlush`es the signal providers at queue-worker job boundaries
  (`Looping` / `WorkerStopping`). The class docblock explains why those flush points were chosen and
  why the class sits directly under the umbrella rather than in a submodule.

## Development

```bash
composer install
composer test        # phpunit (orchestra/testbench)
composer analyse     # phpstan (level 8)
composer format      # laravel/pint
composer check       # all three of the above (what CI runs)
```

The supported Laravel 10–13 combinations are verified by the CI matrix
([.github/workflows/ci.yml](.github/workflows/ci.yml)). On PHP 8.1 a plain `composer install` cannot
resolve — see [CONTRIBUTING.md](CONTRIBUTING.md) for that and for the rest of the development workflow.

## License

[Apache License 2.0](LICENSE). Copyright notice: [NOTICE](NOTICE).
