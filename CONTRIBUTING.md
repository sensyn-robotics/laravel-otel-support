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
- The scope of what stays backward compatible is decided by what our own services need. What *is*
  covered follows the versioning rules below, so a caret constraint is safe to leave in place.

You are of course free to fork and modify the code under the terms of the
[Apache License 2.0](LICENSE).

## Versioning and compatibility

Releases follow semantic versioning with the usual Composer reading of the pre-1.0 range: **below 1.0
the minor position acts as the major.**

- Breaking changes ship in a minor bump (`0.1.x` → `0.2.0`), and in a major bump once 1.0 is out.
- Patch releases (`0.1.0` → `0.1.1`) are backward compatible. `^0.1` in a consumer therefore never
  picks up a breaking change through an unrelated `composer update`.

One surface counts as breaking beyond the obvious PHP signatures, because it breaks a consumer without
breaking any call site:

- **Metric names, attribute keys and attribute values.** They are the contract that the consumers'
  dashboards, recording rules and alerts are written against. This includes adopting an upstream rename
  in the OpenTelemetry semantic conventions: the version this package targets is declared in
  [src/Metrics/README.md](src/Metrics/README.md), and following a rename is a minor bump, never a patch.

  *Changed*, not *added*. A **new metric** breaks nothing a consumer already queries, so it ships in a
  patch — consumers on `^0.1` get it without a pull request, which is the point of putting it there.
  A new **attribute on an existing metric** is not additive in the same way: it changes the identity of
  that metric's series, which reads as a counter reset and breaks any query matching on the exact label
  set, so treat it like a rename.

## Reporting a security issue

Please do **not** open a public issue for security problems. Use GitHub's private vulnerability
reporting instead (repository **Security** tab → **Report a vulnerability**) so that we can triage it
privately.

---

## For Sensyn Robotics engineers

### Local setup

**PHP 8.2 or newer**, matching the package's own floor.

```bash
composer install
composer check      # format check + static analysis + tests (three of the five CI jobs)
```

Install the secret-scanning hook as well — once per clone:

```bash
brew install pre-commit detect-secrets   # or the equivalent for your platform
pre-commit install
```

This runs [detect-secrets](https://github.com/Yelp/detect-secrets) against `.secrets.baseline.json`
before every commit. Setting up
[sensyn-git-global-hook](https://github.com/sensyn-robotics/sensyn-git-global-hook) instead enables it
for every repository at once. The same scan runs in CI, because `git commit --no-verify` walks past the
hook — and this repository being public means a secret is readable the moment it is pushed.

If the hook reports something that is not a secret, prefer an inline `pragma: allowlist secret` comment
or an `exclude` pattern in `.secrets.baseline.json` over auditing the value into the baseline; commit the
updated baseline before the commit that needs it, or the hook runs against the old file.

The lock file is not committed (a library must let consumers resolve their own graph), so
`composer install` resolves the newest supported combination your PHP version allows — Laravel 12 on
PHP 8.2, Laravel 13 on PHP 8.3+, together with the newest Testbench and PHPUnit those permit. The other
supported combinations are covered by the CI matrix in
[.github/workflows/ci.yml](.github/workflows/ci.yml).

> **Note**
> `--prefer-lowest` (the `lowest` CI leg) additionally needs the advisory escape hatch from
> [Reproducing a CI leg locally](#reproducing-a-ci-leg-locally) below, because Composer refuses to load
> the Laravel 10 releases it resolves.

Individual commands:

| Command | What it runs |
|---|---|
| `composer test` | PHPUnit, on top of `orchestra/testbench` |
| `composer analyse` | PHPStan, level 8, no baseline |
| `composer format` | Laravel Pint (`composer format:check` for a dry run) |

### Reproducing a CI leg locally

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
  that contract — a consumer without the SDK installed then gets a fatal error while the provider
  registers. Nothing else catches this (PHPStan and the tests both run with the SDK in `vendor/`), so CI
  has a dedicated `runtime-dependencies` job that greps `src/` and `config/` for the `OpenTelemetry\SDK`,
  `OpenTelemetry\SemConv` and `OpenTelemetry\Contrib` namespaces. `open-telemetry/sem-conv` is off limits
  for the same reason — it is a dependency of the SDK — which is why the semconv names and values are
  string literals.
- **The package supports Laravel 10, 11, 12 and 13 from a single branch.** Do not use an API that does
  not exist in every supported version without guarding it — the queue event payloads in particular
  differ between Laravel 10 and 11+.
- **No `ext-*` requirements and no `config.platform` entries in `composer.json`.** The instrumentation
  needs neither, and platform overrides would break the PHP matrix.
- **A `require-dev` floor has to install *and* run on the lowest supported PHP.** `--prefer-lowest`
  resolves the dev tools as well, so a floor whose lowest matching release needs a newer PHP makes the
  `lowest` leg unresolvable, and a floor whose lowest matching release is broken makes `composer check`
  unreproducible there. `laravel/pint` is the live example of the second case: `1.18.0` cannot run at
  all (`Call to undefined method PhpCsFixer\Config::setParallelConfig()`, fixed in `1.18.1`), hence the
  `^1.18.1` floor.
- **Do not add a PHPStan baseline.** The package is small; fix the code instead.
- **Every config read needs an inline default, and that default must equal the shipped value.**
  `mergeConfigFrom()` is a shallow merge of top-level keys
  (`$config->set($key, array_merge(require $path, $config->get($key, [])))`), so an application file that
  defines `metrics` replaces our whole `metrics` subtree. A new key nested under an existing top-level key
  is therefore **absent** for every consumer that published the config before it was added, and the
  package's own tests cannot see it because they use the shipped file. Passing the default at the call
  site (`$config->get('otel-support.metrics.…', true)`, `Buckets::fromConfig($key, $default)`) is what
  makes new options work without a re-publish; a new **top-level** key is safe on its own, since the
  application file has no entry to override it with. Document new options in the config table in
  [README.md](README.md).
- **Mark the public surface.** Anything a consumer's own files can reach — today `OTelSupportServiceProvider`
  and the `Buckets` members used by the published config — is `@api` and covered by the versioning rules
  above. Everything else is `@internal`, even though the framework requires it to be `public`.
- Every change to `src/` needs a matching test. New metrics or attributes must also be reflected in
  `src/Metrics/README.md`, including the semconv version that section declares.
- **Documentation is written in English.** The repository is public, so `README.md`, this file,
  `src/Metrics/README.md` and `CLAUDE.md` are English; inline comments and docblocks in `src/` stay
  Japanese. Do not add a translated copy of a document — a duplicate that drifts out of sync is worse
  than a single language.
- Follow [Conventional Commits](https://www.conventionalcommits.org/) for commit messages, and open
  pull requests as **draft** first. Every pull request targeting the default branch needs an approval
  from the `@sensyn-robotics/infra` team: that branch requires a code-owner review and
  `.github/CODEOWNERS` assigns the whole tree to the team. A draft pull request does not request that
  review automatically — mark it ready for review, or ask for the review by hand.

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
and reads `composer.json` as it existed at the tag.

**Tag `origin/main` explicitly — never your local `HEAD`.** A pull request is squashed on merge, so the
commit you have checked out is not the commit that landed. Tagging `HEAD` from a feature branch puts the
release on a commit that is not reachable from `main`.

```bash
git fetch origin
git tag -a v1.2.3 origin/main -m "v1.2.3"

# The tag must be on the commit that is actually the tip of main.
git rev-parse v1.2.3^{commit}
gh api repos/sensyn-robotics/laravel-otel-support/commits/main --jq .sha

git push origin v1.2.3
```

Check that CI is green on `main` before tagging: the tag itself does not run CI (`ci.yml` triggers on
branches, not tags).

#### If you tag the wrong commit

The `release tags` ruleset restricts creation, deletion and force-updates of every tag, with repository
admins on the bypass list. So an admin can delete and re-push a tag, and anyone else cannot.

Deleting is only the right move if **nobody has resolved the tag yet**. Composer caches a dist archive
per version and ref, and a consumer that already ran `composer update` has the old contents pinned in
its lock file. Moving a tag under them changes what the version means without changing what they
install — the two silently diverge. Once a release may have been consumed, **do not reuse the number**:
release the fix as the next patch version instead.

Two more consequences worth remembering:

- **Only tags are releasable.** A commit on `main` without a tag is reachable solely as `dev-main`, which
  is not something a consumer should depend on.
- **Consumers pin a caret constraint**, so any release they cannot pick up automatically needs a
  follow-up pull request in each consumer. Below 1.0 that is every minor bump (`^0.1` means
  `>=0.1.0 <0.2.0`); from 1.0 on it is every major bump.
