# Contributing

Thank you for your interest in this project.

**This repository is published for internal use at Sensyn Robotics, Inc., and we do not accept
external contributions.**

`laravel-otel-support` was extracted from our own Laravel applications. It lives on public GitHub only so
that those applications can install it with Composer — as a VCS repository, see the installation section
of [README.md](README.md) — without private-registry authentication. It is not a general-purpose
community package, and its scope and roadmap are driven entirely by the needs of our internal services.

Consequently:

- Pull requests from outside Sensyn Robotics will be closed without review.
- Feature requests and support questions are out of scope, and such issues may be closed without a
  response.
- Backward compatibility is maintained only as far as our own services require it. Breaking changes
  may ship in any release, so pin the version you depend on.

You are of course free to fork and modify the code under the terms of the
[Apache License 2.0](LICENSE).

## Reporting a security issue

Please do **not** open a public issue for security problems. Use GitHub's private vulnerability
reporting instead (repository **Security** tab → **Report a vulnerability**) so that we can triage it
privately.

---

## For Sensyn Robotics engineers

### Local setup

**Development requires PHP 8.2 or newer**, even though the package itself supports PHP 8.1.

```bash
composer install
composer check      # format check + static analysis + tests (same as CI)
```

The lock file is not committed (a library must let consumers resolve their own graph), so
`composer install` resolves the newest supported combination your PHP version allows — Laravel 12 on
PHP 8.2, Laravel 13 on PHP 8.3+, together with the newest Testbench and PHPUnit those permit. The other
supported combinations are covered by the CI matrix in
[.github/workflows/ci.yml](.github/workflows/ci.yml).

> **Warning**
> On PHP 8.1 the command above **cannot resolve**: the only framework release compatible with 8.1 is
> Laravel 10, and Composer refuses to load it because that branch is past its security window. Either
> develop on PHP 8.2+, or use the advisory escape hatch from
> [Reproducing a CI leg locally](#reproducing-a-ci-leg-locally) below (which is exactly the PHP 8.1 /
> Laravel 10 CI leg) and revert `composer.json` afterwards.

Individual commands:

| Command | What it runs |
|---|---|
| `composer test` | PHPUnit, on top of `orchestra/testbench` |
| `composer analyse` | PHPStan, level 8, no baseline |
| `composer format` | Laravel Pint (`composer format:check` for a dry run) |

### Reproducing a CI leg locally

This is also the only way to work on PHP 8.1 (see the warning above).

```bash
# Composer >= 2.10 blocks every Laravel 10 and 11 release because those branches are past their
# security window; the matrix legs need this escape hatch, scoped to laravel/framework.
composer config --json policy.advisories.ignore '["laravel/framework"]'

composer require --dev --no-update "laravel/framework:10.*"
composer update --prefer-lowest   # drop --prefer-lowest for the "highest" legs
vendor/bin/phpunit
```

Revert `composer.json` afterwards (`git checkout composer.json`) — neither the advisory policy nor the
framework pin belongs in the committed file. Only the Testbench *major* follows from the framework pin;
its floor stays in `composer.json`, which is why CI never pins `orchestra/testbench` directly.

### Ground rules

- **Runtime code may only depend on `open-telemetry/api` and `open-telemetry/context`.**
  `open-telemetry/sdk` stays in `require-dev` (the tests need in-memory exporters); SDK, exporter and
  autoload wiring is the consuming application's responsibility. Adding an SDK import to `src/` breaks
  that contract.
- **The package supports Laravel 10, 11, 12 and 13 from a single branch.** Do not use an API that does
  not exist in every supported version without guarding it — the queue event payloads in particular
  differ between Laravel 10 and 11+.
- **No `ext-*` requirements and no `config.platform` entries in `composer.json`.** The instrumentation
  needs neither, and platform overrides would break the PHP matrix.
- **Do not add a PHPStan baseline.** The package is small; fix the code instead.
- **Keep the config file merge-safe.** `mergeConfigFrom()` only shallow-merges top-level keys, so keep
  new options nested under the existing top-level keys (`metrics`, `worker_flusher`) and document them
  in the config table in [README.md](README.md).
- Every change to `src/` needs a matching test. New metrics or attributes must also be reflected in
  `src/Metrics/README.md`.
- **Documentation is written in English.** The repository is public, so `README.md`, this file and
  `src/Metrics/README.md` are English; inline comments and docblocks in `src/` stay Japanese. Do not add
  a translated copy of a document — a duplicate that drifts out of sync is worse than a single language.
- Follow [Conventional Commits](https://www.conventionalcommits.org/) for commit messages, and open
  pull requests as **draft** first.

### Repository visibility

**This repository is intentionally public, and its visibility must not be changed.** Most of our
repositories are private; this one is a deliberate exception, approved for the reason described at the
top of this file — consuming applications install it as a Composer VCS repository, which only works
without authentication while the repository is public.

Flipping it to private or internal would break `composer install` in every consuming application and in
their CI and Docker builds, and would require adding Git credential wiring to each of them. If you
believe the visibility should change, raise it with the owning team first rather than changing it.

### Releasing

Consuming applications reference this repository directly as a Composer **VCS repository**, so there is
no registry to publish to: releasing *is* pushing a Git tag. Composer lists the tags of this repository
and reads `composer.json` as it existed at the tag. Use semantic versioning and tag from `main` after CI
is green:

```bash
git tag v1.2.3
git push origin v1.2.3
```

Two consequences worth remembering:

- **Only tags are releasable.** A commit on `main` without a tag is reachable solely as `dev-main`, which
  is not something a consumer should depend on.
- **Consumers pin a caret constraint**, so any release they cannot pick up automatically needs a
  follow-up pull request in each consumer. Below 1.0 that is every minor bump (`^0.1` means
  `>=0.1.0 <0.2.0`); from 1.0 on it is every major bump.
