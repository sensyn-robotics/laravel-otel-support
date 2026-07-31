# CLAUDE.md

Guidance for Claude Code (claude.ai/code) when working in this repository.

## Read this first

[CONTRIBUTING.md](CONTRIBUTING.md) is the source of truth for the ground rules, the versioning policy
and the release process. This file does not restate them — a second copy would drift. It covers only
what is easy to get wrong here and what the obvious checks do **not** catch.

`laravel-otel-support` is a Laravel package that adds the OpenTelemetry signals
`opentelemetry-auto-laravel` and the SDK autoloader do not cover (metrics today), plus a `WorkerFlusher`
that flushes at the work boundaries of long-lived processes. [README.md](README.md) has the layout,
the config table and the fail-open design; [src/Metrics/README.md](src/Metrics/README.md) has the
recorded metrics and their attributes.

## Commands

```bash
composer install
composer check       # format:check + analyse + test
```

**`composer check` is not the same as CI.** It runs three of the five CI jobs. The two it leaves out
are cheap and both have caught real regressions, so run them before you call a change done:

```bash
# runtime-dependencies: runtime code may only depend on the OTel api and context packages.
# No match is the success case, so grep exits 1; an exit of 2 or above means the gate did not run.
grep -rnE 'OpenTelemetry\\(SDK|SemConv|Contrib)' src config

composer validate --strict --no-check-lock
```

Development requires PHP 8.2+, even though the package supports 8.1: on 8.1 a plain `composer install`
cannot resolve. See [CONTRIBUTING.md](CONTRIBUTING.md#local-setup) for the escape hatch and for
reproducing a specific CI leg.

## What the usual checks will not catch

Each of these passes PHPStan and the whole test suite while being broken for a consumer.

- **An SDK import in `src/` or `config/`.** PHPStan and PHPUnit both run with `open-telemetry/sdk` in
  `vendor/`, so nothing local fails. A consumer without the SDK gets a fatal error while the provider
  registers — the application does not start. The `runtime-dependencies` grep above is the only gate.
  `open-telemetry/sem-conv` is off limits for the same reason, which is why semconv names and values in
  `src/` are string literals.
- **A config read without an inline default.** `mergeConfigFrom()` is a shallow merge of top-level
  keys, so an application that published the config file before an option existed has no entry for it.
  The package's own tests use the shipped file and therefore always see the new key. Pass the default at
  the call site and add the option to the config table in `README.md`.
- **A changed metric name, attribute key or attribute value.** No PHP call site breaks, so every test
  stays green — but consumer dashboards, recording rules and alerts break. This is a minor bump, never
  a patch, and it includes following an upstream semconv rename.
- **An API that exists in Laravel 12 but not 10.** The package supports 10 through 13 from a single
  branch, and only one CI leg runs Laravel 10. Queue event payloads in particular differ between 10 and
  11+.
- **A `require-dev` floor that cannot install or run on PHP 8.1.** `--prefer-lowest` resolves the dev
  tools too, so a floor whose lowest matching release needs a newer PHP makes the `lowest` leg
  unresolvable. `laravel/pint` is pinned between two such walls; `.github/dependabot.yml` documents why.

## Fixed decisions — do not re-propose these

- **Distribution is a Composer VCS repository, and only that.** There is no registry: releasing is
  pushing a `vX.Y.Z` Git tag from `main`. Do not add Packagist or any other registry step, including
  "just to reserve the name".
- **The repository is intentionally public and its visibility must not be changed.** Most repositories
  in this organisation are private or internal; this one is a deliberate, approved exception, because
  consuming applications install it without Composer credentials. See
  [CONTRIBUTING.md](CONTRIBUTING.md#repository-visibility).
- **No PHPStan baseline.** The package is small — fix the code.
- **No `ext-*` requirements and no `config.platform` entries** in `composer.json`.

## Things that have to move together

| When you change | Also update |
|---|---|
| a metric, attribute key or attribute value | `src/Metrics/README.md`, including the semconv version it declares |
| a config option | the inline default at the call site **and** the config table in `README.md` |
| a `Buckets` member used by `config/otel-support.php` | treat as breaking — consumers keep a published copy of that file |
| anything in `src/` | a matching test |
| an action pin in `.github/workflows/` | the trailing `# vX.Y.Z` comment — Dependabot reads it |

## Conventions

- **Documentation is English; inline comments and docblocks in `src/` are Japanese.** Never add a
  translated copy of a document.
- Anything a consumer's own files can reach is `@api` and bound by the versioning rules. Everything
  else is `@internal`, even where the framework forces it to be `public`.
- Telemetry must never break the application. Every entry point the framework calls is fail-open, and
  the per-request and per-job paths do not log — a failure shows up as missing telemetry, not as changed
  behaviour.
- [Conventional Commits](https://www.conventionalcommits.org/); open pull requests as **draft** first.
  Every pull request needs an approval from `@sensyn-robotics/infra` (see `.github/CODEOWNERS`).
