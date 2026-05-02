# Contributing

## Consumption model

wordpress-configs is consumed via `dev-trunk` only — no version tags or releases are published. Every change to `trunk` propagates to downstream consumers' next `composer update` (PHP) or git fetch (reusable GitHub Actions workflows referenced as `@trunk`).

Practical consequences:
- Treat `trunk` as if it were a tagged release. Don't merge anything to `trunk` that you wouldn't tag.
- Breaking changes to reusable workflow inputs, Composer script signatures, or ruleset behavior are immediately felt by every consumer. Consider backwards-compatibility carefully.

## Development setup

```bash
composer install         # PHP deps + PHPUnit
npm install              # Node configs' peer deps for local linting
composer test            # PHPUnit (27 tests across CollectScopingStubs, ScopePhpDependencies, scoper-base)
```

## Tests

PHPUnit tests live under `tests/Unit/`. Use real `Composer\Composer` instances rather than mocks (per existing convention) — it surfaces real composer-API regressions when you bump composer-runtime versions.

Mutation tests run via Infection on a weekly schedule (manually triggerable too). MSI ratchets up over time as new tests cover more branches.

## Reusable workflows are public API

Anything in `.github/workflows/reusable-*.yml` is consumed by external repositories via `uses: ahegyes/wordpress-configs/.github/workflows/X.yml@trunk`. Changes to inputs, outputs, or behavior are breaking. Document new inputs in the workflow's own header comment AND in `README.md`.

## Filing changes

- One PR per concern (don't bundle a Composer script change with a PHPCS ruleset bump).
- Include a one-line summary of consumer impact in the PR description, especially for reusable workflows.
- The PR template prompts for breaking-change disclosure — fill it in honestly.
