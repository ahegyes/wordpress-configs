# wordpress-configs

Shared dev tooling — base configs, scoping helpers, reusable CI workflows — for the DWS WordPress framework + plugins. MIT license. Composer name `ahegyes/wordpress-configs`. NPM name `@ahegyes/wordpress-configs`. PHP namespace `DeepWebSolutions\Config\` (kept for continuity).

Composer consumption is `"dev-trunk"` from all other DWS repos. **No version tags / no releases.** Latest Composer-side fixes propagate immediately. Justification: low-risk dev tooling, single owner, breakage gets fixed on trunk. Reusable CI workflows are consumed at immutable SHA pins by the DWS repos (dependabot proposes bumps), `reusable-release.yml` included — the credentialed release workflow is SHA-pinned like every other reusable (see SECURITY.md).

Intentionally standalone — also consumable by non-plugin repos (themes, site builds, client work).

## Layout

```
wordpress-configs/
├── php/
│   ├── quality-assurance/           # SHARED configs — consumed by other repos
│   │   ├── phpcs.dist.xml           # WPCS + PHPCompatibilityWP, PHP 8.5+ / WP 7.0+
│   │   └── phpstan.dist.neon        # level 8 + WordPress stubs (auto-discovered via .neon.php)
│   ├── php-scoper/
│   │   ├── scoper-base.inc.php      # catalog-agnostic; reads scoping-exclusions.json; excludes as_* by regex
│   │   └── contrib/
│   │       ├── php-di.inc.php       # PHP-DI 7 + transitive deps
│   │       └── wp-framework.inc.php # auto-detect ahegyes/wp-framework-* packages; rewrites reserved wp-framework-* literal domains from extra.text-domain; fails reserved-literal and prefixed as_* residues
│   └── composer/
│       ├── CollectScopingStubs.php  # post-autoload-dump: aggregates extra.scoping-stubs → scoping-exclusions.json (classes, functions, constants); malformed ROOT declaration throws, malformed installed-package declaration warns
│       ├── ScopePhpDependencies.php # full pipeline: builds and runs php-scoper from extra.scoped-dependencies-dir + extra.scoping-prefix + optional extra.scoping-flags; owns --output-dir/-o, --prefix, and --config; requires project-root scoper.inc.php; regenerates scoped autoload after every successful scope
│       └── Internal/
│           ├── GenerateScopedAutoload.php # emits dependencies/scoper-autoload.php from the scoped tree (three layouts: single package directly at dependencies/, flattened <pkg>/, nested <vendor>/<pkg>/); throws when the scan finds no packages
│           └── StubSymbolCollector.php    # AST visitor for scoping-stubs symbol harvesting
├── node/
│   ├── tsconfig.base.json
│   ├── eslint.config.base.mjs
│   ├── stylelint.config.base.js
│   └── playwright.config.base.js
├── tests/                           # PHPUnit unit tests (tests/Unit/); fixtures in tests/fixtures/
├── phpcs.dist.xml                   # SELF-lint — extends shared with WP-runtime exclusions (this repo's PHP is Composer-time tooling)
├── phpstan.dist.neon                # SELF-lint — includes shared, declares `paths: [php, tests]`
├── composer-require-checker.json    # whitelists Composer\* + Symfony\Finder (provided by composer/composer in require-dev)
└── .github/workflows/               # 10 reusable + 5 self-CI (codeql, tests, tests-mutation, quality, workflow-checks)
```

## Reusable CI workflows

10 reusable workflows in `.github/workflows/reusable-*.yml` (`workflow_call` only):
`reusable-block-json-check`, `reusable-scripts-styles-lint`, `reusable-php-lint`, `reusable-php-syntax-check`, `reusable-phpunit`, `reusable-playwright-e2e`, `reusable-supply-chain-audit`, `reusable-release`, `reusable-workflow-checks`, `reusable-codeql`.

Plus 5 self-running for this repo's own CI: `codeql`, `tests`, `tests-mutation`, `quality`, `workflow-checks`. `tests` dogfoods `reusable-phpunit` as a unit-only matrix (`needs-wp-env: false`). `quality` dogfoods this repo's own reusables — `reusable-php-lint` (phpcs, phpstan, composer-require-checker as parallel jobs), `reusable-php-syntax-check` (scoped to `php/`, since `tests/fixtures/` carries intentional `php -l` redeclaration failures), `reusable-supply-chain-audit`, `reusable-scripts-styles-lint` (lint:scripts; styles disabled), and `reusable-block-json-check` (against the throwaway `tests/fixtures/block-json` fixture, since this tooling repo ships no real blocks). `workflow-checks` and `codeql` are thin callers of `reusable-workflow-checks` (actionlint for workflow YAML correctness + zizmor for workflow security; uploads SARIF → Security tab and fails the job on findings) and `reusable-codeql` (this repo passes `languages: '["actions", "javascript-typescript"]'`).

Dogfooding scope: `reusable-php-lint`, `reusable-scripts-styles-lint`, `reusable-supply-chain-audit`, `reusable-php-syntax-check`, `reusable-block-json-check` (against a throwaway fixture), `reusable-phpunit` (unit-only via `needs-wp-env: false`), `reusable-workflow-checks`, and `reusable-codeql` are run against this repo in self-CI. The other two — `reusable-playwright-e2e` and `reusable-release` — are plugin-shaped with no meaningful target here, so they are validated by actionlint + zizmor static checks only, not behaviorally exercised.

## Conventions

- File naming: `<tool>.dist.<ext>` — IDEs auto-recognize the trailing extension as XML/NEON/etc.
- PSR-12 file-header order overridden: `PSR12.Files.FileHeader.IncorrectOrder` is silenced in the shared `php/quality-assurance/phpcs.dist.xml` so consumers can write `<?php declare( strict_types=1 );` inline.
- `lint:php` chains 3 tools: `phpcs` + `phpstan` + `composer-require-checker`. Symbols used from transitive deps are whitelisted in `composer-require-checker.json`, not added to `require`.
- `--ignore-platform-req=php+` is applied to Composer installs/updates in scripts, this repo's CI, and the reusable workflows — only the PHP upper bound is ignored so future-PHP installs stay possible; extension requirements and PHP floor checks stay live.
- Self-lint is `phpcs.dist.xml` at root (extends shared with WP-runtime sniff exclusions for this repo's tooling code) — NOT the consumer-facing `php/quality-assurance/phpcs.dist.xml`.
- `CollectScopingStubs` writes the exclusion JSON via temp-file + `rename` (atomic on same filesystem) so a concurrent reader can't observe a partial write.
- Tests use real `Composer\Composer` instances (not mocks).
