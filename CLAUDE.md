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

**`composer check` is not the same as CI.** It covers three of the five jobs, and even those three are
narrower than they look: its `test` step runs against the one dependency set your PHP version resolves,
not the nine-leg matrix. The two jobs it leaves out are cheap, so run them before calling a change done:

```bash
# runtime-dependencies: runtime code may only depend on the OTel api and context packages.
# No match is the success case, so grep exits 1; an exit of 2 or above means the gate did not run.
grep -rnE 'OpenTelemetry\\(SDK|SemConv|Contrib)' src config

composer validate --strict --no-check-lock
```

PHP 8.2+ (the package's own floor). Reproducing the `lowest` CI leg needs an advisory escape hatch —
see [CONTRIBUTING.md](CONTRIBUTING.md#local-setup).

On a machine with no PHP, run the same commands in a container — `vendor/` is portable because these
are PHP scripts:

```bash
docker run --rm -v "$PWD":/app -w /app --user "$(id -u):$(id -g)" php:8.4-cli vendor/bin/phpunit
```

## What the usual checks will not catch

Each of these passes PHPStan and the whole test suite while being broken for a consumer.

- **An SDK import in `src/` or `config/`.** PHPStan and PHPUnit both run with `open-telemetry/sdk` in
  `vendor/`, so nothing local fails. A consumer without the SDK gets a fatal error while the provider
  registers — the application does not start. The `runtime-dependencies` grep above is the only gate,
  and it only sees literal `OpenTelemetry\SDK|SemConv|Contrib` under `src/` and `config/`: a class name
  assembled at runtime, or an SDK package promoted into `require` in `composer.json`, walks past it.
  `open-telemetry/sem-conv` is off limits for the same reason, which is why semconv names and values in
  `src/` are string literals.
- **A config read without an inline default, or with one that differs from the shipped value.**
  `mergeConfigFrom()` is a shallow merge of top-level keys, so a published application file that defines
  `metrics` replaces that whole subtree: a key nested under it is absent for every consumer that
  published before the key existed. (A new *top-level* key is safe on its own — the application file has
  no entry to override it with.) The package's own tests read the shipped file and therefore always see
  the new key, in every CI leg. Pass the default at the call site, **make it equal to the shipped
  value**, and add the option to the config table in `README.md`.
- **A changed metric name, attribute key or attribute value.** No PHP call site breaks, so every test
  stays green — but consumer dashboards, recording rules and alerts break. This is a minor bump, never
  a patch, and it includes following an upstream semconv rename.
- **An API that exists in Laravel 12 but not 10.** The package supports 10 through 13 from a single
  branch, and only two of the nine CI legs run Laravel 10 (both on PHP 8.2, `lowest` and `highest`).
  Queue event payloads in particular differ between 10 and 11+.
- **A `require-dev` floor that cannot install or run on the lowest supported PHP.** `--prefer-lowest`
  resolves the dev tools too, so a floor whose lowest matching release is broken makes `composer check`
  unreproducible on that leg. `laravel/pint` has a `^1.18.1` floor for exactly this reason.

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

## This repository is public

Everything committed here is world-readable the moment it is pushed, and rewriting history does not
unpublish it. The code was extracted from internal Laravel applications, so porting more of it is the
realistic way internal detail leaks: **queue names, hostnames, Azure resource names, internal service
and repository names, ticket IDs, and fixtures built from real data do not belong in this repository.**

This is not hypothetical — it has already been cleaned up twice, and both original commits are still
public: `4cc4232` removed an internal service name from a `ci.yml` comment, `c8efc49` removed an
internal repository name from `dependabot.yml`.

Credentials have two automated gates — GitHub secret scanning with push protection, and
[detect-secrets](https://github.com/Yelp/detect-secrets), which runs both as a pre-commit hook and as
the `Detect Secrets` workflow (the hook is bypassable with `git commit --no-verify`, the workflow is
not). Neither gate flags a hostname or a queue name, and comments in `src/` are Japanese, which lowers
the chance a reader spots one in passing. Check what you are pasting in.

A detect-secrets false positive is best silenced with an inline `pragma: allowlist secret` comment or
an `exclude` pattern in `.secrets.baseline.json`; commit the updated baseline first, or the hook still
runs against the old one.

## Things that have to move together

| When you change | Also update |
|---|---|
| a metric, attribute key or attribute value | `src/Metrics/README.md`, including the semconv version it declares |
| a config option | the inline default at the call site **and** the config table in `README.md` |
| the shipped `config/otel-support.php` | keep it free of package class references — consumers keep a published copy, and a missing class there is a boot-time fatal `config:clear` cannot fix |
| anything in `src/` | a matching test |
| an action pin in `.github/workflows/` | the trailing `# vX.Y.Z` comment — Dependabot reads it |

## Conventions

- **Documentation is English; inline comments and docblocks in `src/` are Japanese.** Never add a
  translated copy of a document.
- Anything a consumer's own files can reach is `@api` and bound by the versioning rules. Everything
  else is `@internal`, even where the framework forces it to be `public`.
- Telemetry must never break the application. Every entry point the framework calls is fail-open, so a
  failure shows up as missing telemetry, not as changed behaviour. The recording and flushing paths do
  not log; the one exception is `Buckets::fromConfig()` (`src/Metrics/Buckets.php:94`), which warns on an
  invalid bucket list — and because the HTTP middleware is constructed twice per request, a bad value
  there means a log line per request. Do not add a second one.
- [Conventional Commits](https://www.conventionalcommits.org/); open pull requests as **draft** first.
  Every pull request targeting `main` needs an approval from `@sensyn-robotics/infra` (see
  `.github/CODEOWNERS`). Note that a draft pull request does not request that review automatically —
  mark it ready, or ask for the review by hand.
