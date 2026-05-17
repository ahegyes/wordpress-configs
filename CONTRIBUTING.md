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
composer test            # PHPUnit unit suite
```

## Tests

PHPUnit tests live under `tests/Unit/`. Use real `Composer\Composer` instances rather than mocks (per existing convention) — it surfaces real composer-API regressions when you bump composer-runtime versions.

Mutation tests run via Infection on a weekly schedule (manually triggerable too). MSI ratchets up over time as new tests cover more branches.

## Reusable workflows are public API

Anything in `.github/workflows/reusable-*.yml` is consumed by external repositories via `uses: ahegyes/wordpress-configs/.github/workflows/X.yml@trunk`. Changes to inputs, outputs, or behavior are breaking. Document new inputs in the workflow's own header comment AND in `README.md`.

## composer-require-checker.json

`composer-require-checker.json` whitelists symbols used by our PHP code that come from packages this repo `require-dev`s (not `require`s). The current whitelist covers `Composer\Factory`, `Composer\Script\Event`, and `Symfony\Component\Finder\Finder` — all provided transitively by `composer/composer` in `require-dev`.

We **don't** add these to `require`: per the keyword-discipline rule, declared deps signal what we *offer*, not what we *consume*. composer-require-checker is the arbiter that catches accidental dependence on something not in `require`; the whitelist exempts the deliberately-consumed-but-not-required ones.

If you add a new whitelist entry, document the rationale in your PR description.

## Filing changes

- One PR per concern (don't bundle a Composer script change with a PHPCS ruleset bump).
- Include a one-line summary of consumer impact in the PR description, especially for reusable workflows.
- The PR template prompts for breaking-change disclosure — fill it in honestly.
