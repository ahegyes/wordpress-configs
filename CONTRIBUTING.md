# Contributing

## Consumption model

wordpress-configs is consumed via `dev-trunk` only — no version tags or releases are published. Every change to `trunk` propagates to downstream consumers' next `composer update` on the PHP side. This repo's internal third-party action refs are SHA-pinned and dependabot-tracked; reusable GitHub Actions workflows are consumed through SHA-pinned `uses:` refs tracked by dependabot, which carries `trunk` changes into consumers without mutable refs.

Practical consequences:
- Treat `trunk` as if it were a tagged release. Don't merge anything to `trunk` that you wouldn't tag.
- Breaking changes to Composer script signatures or ruleset behavior are immediately felt by every PHP consumer; reusable workflow changes are felt when consumers accept dependabot SHA bumps. Changes to the credentialed `deploy` path in `reusable-release.yml` are reviewed in consumers, never automerged.

## Development setup

Requires PHP 8.5+, Composer 2.x, and — for the Node baselines — Node 26+ / npm 11+.

```bash
composer install         # PHP deps + PHPUnit
npm install              # Node configs' peer deps for local linting
composer test            # PHPUnit unit suite
```

## Before opening a PR

CI runs more than `composer test`: `quality.yml` runs the PHP-lint, PHP-syntax, PHPCS-dist-smoke, ESLint, Node-config-smoke, supply-chain, and block.json-schema gates, while `tests.yml` runs the PHPUnit suite. The commands below mirror those with a local equivalent:

```bash
composer lint:php        # phpcs + phpstan + composer-require-checker
composer test            # unit suite
composer audit --abandoned=report
npm run lint:scripts     # ESLint over node/ + the config-smoke harness
npm run lint:config      # load-check the eslint / stylelint / tsconfig / playwright baselines
npm audit --omit=dev --audit-level=high
```

The scoping pipeline (`CollectScopingStubs` → `ScopePhpDependencies` → `scoper-base.inc.php` → autoload generator) only runs in a *consumer's* dev install; this repo tests each piece in isolation, so there's no build step to run here.

## Tests

PHPUnit tests live under `tests/Unit/`. Use real `Composer\Composer` instances rather than mocks — it surfaces real composer-API regressions when you bump composer-runtime versions.

Mutation tests run via Infection on a weekly schedule (manually triggerable too). MSI ratchets up over time as new tests cover more branches.

## Reusable workflows are public API

Anything in `.github/workflows/reusable-*.yml` is consumed by external repositories via SHA-pinned refs such as `uses: ahegyes/wordpress-configs/.github/workflows/X.yml@<sha>`, with dependabot tracking new `trunk` commits. Changes to inputs, outputs, or behavior are breaking. Build-side reusable bumps may be automerged by consumer policy; changes to the credentialed `deploy` path in `reusable-release.yml` are reviewed, never automerged in consumers. Give every input a `description:` in the workflow's `inputs:` block — that's the authoritative reference; add it to the README table only if it's commonly set.

## composer-require-checker.json

`composer-require-checker.json` whitelists symbols our PHP code uses that come from packages this repo `require-dev`s (not `require`s) — chiefly the `Composer\*` API provided transitively by `composer/composer`. The file itself is the authoritative list.

We **don't** add these to `require`: `require` is reserved for what the package offers its consumers, not what its own tooling consumes. composer-require-checker catches accidental dependence on something not in `require`; the whitelist exempts the deliberately-consumed-but-not-required ones.

If you add a new whitelist entry, document the rationale in your PR description.

## Filing changes

- One PR per concern (don't bundle a Composer script change with a PHPCS ruleset bump).
- Include a one-line summary of consumer impact in the PR description, especially for reusable workflows.
- The PR template prompts for breaking-change disclosure — fill it in honestly.
