# wordpress-configs

Shared dev tooling — base configs, scoping helpers, reusable CI workflows — for the DWS WordPress framework + plugins. MIT license. Composer name `ahegyes/wordpress-configs`. NPM name `@ahegyes/wordpress-configs`. PHP namespace `DeepWebSolutions\Config\` (kept for continuity).

Composer consumption is `"dev-trunk"` from all other DWS repos. **No version tags / no releases.** Latest Composer-side fixes propagate immediately. Justification: low-risk dev tooling, single owner, breakage gets fixed on trunk. Reusable CI workflows are consumed at immutable SHA pins by the DWS repos (dependabot proposes bumps), `reusable-release.yml` included — the credentialed release workflow is SHA-pinned like every other reusable (see SECURITY.md).

Intentionally standalone — also consumable by non-plugin repos (themes, site builds, client work).

## Layout

```
wordpress-configs/
├── php/
│   ├── quality-assurance/           # SHARED configs — consumed by other repos
│   │   ├── phpcs.base.dist.xml      # shared rules: full WordPress standard and PHPCompatibilityWP, with the PHP and WordPress floors
│   │   ├── phpcs.dist.xml           # production profile: the base with tests/ excluded
│   │   ├── phpcs.tests.dist.xml     # tests profile: base minus docblock and WP-runtime sniffs
│   │   ├── phpstan.base.dist.neon   # shared rules that need no WordPress and the nested-vendor exclusions
│   │   ├── phpstan.dist.neon        # PHPStan profile: the base plus the minimum WordPress version
│   │   └── phpstan.dist.neon.php    # opt-in discovery of a plugin's conventional paths
│   ├── php-scoper/
│   │   ├── scoper-base.inc.php      # the php-scoper config: the named packages, host-symbol exclusions, recipes, and the string and text-domain patchers
│   │   └── recipes/                 # per-library fixes, as <vendor>/<package>.inc.php, applied when that package is scoped
│   └── composer/
│       ├── CollectScopingStubs.php  # the WordPress (sniccowp), Action Scheduler, extra.scoping-stubs and dropped-package symbols scoping leaves global
│       ├── ScopedPackages.php       # the named packages and their runtime dependencies, read from Composer's installed.json
│       ├── ScopePhpDependencies.php # postAutoloadDump and scope(): runs php-scoper into vendor-prefixed/, checks every prefixed name is declared, writes the class-map autoloader
│       └── Internal/
│           ├── ScopedSymbolCollector.php # AST visitor behind the declared-name check
│           └── StubSymbolCollector.php   # AST visitor that harvests stubs symbols
├── node/
│   ├── tsconfig.base.json
│   ├── eslint.config.base.mjs
│   ├── stylelint.config.base.js
│   ├── postcss.config.base.js
│   └── playwright.config.base.js
├── tests/                           # PHPUnit unit tests (tests/Unit/); fixtures in tests/fixtures/
├── phpcs.dist.xml                   # SELF-lint — extends the production profile with WP-runtime exclusions (this repo's PHP is Composer-time tooling)
├── phpcs.tests.dist.xml             # SELF-lint for tests/ — extends the tests profile
├── phpstan.dist.neon                # SELF-lint — includes the shared profile, declares `paths: [php, tests]`
└── .github/
    ├── zizmor.yml                   # disables zizmor's self-repository audit until actionlint accepts $/
    └── workflows/                   # 11 reusable and 5 self-CI (codeql, tests, tests-mutation, quality, workflow-checks)
```

## Reusable CI workflows

11 reusable workflows in `.github/workflows/reusable-*.yml` (`workflow_call` only):
`reusable-block-json-check`, `reusable-scripts-styles-lint`, `reusable-php-lint`, `reusable-php-syntax-check`, `reusable-phpunit`, `reusable-playwright-e2e`, `reusable-plugin-check`, `reusable-supply-chain-audit`, `reusable-release`, `reusable-workflow-checks`, `reusable-codeql`.

Plus 5 self-running for this repo's own CI: `codeql`, `tests`, `tests-mutation`, `quality`, `workflow-checks`. `tests` dogfoods `reusable-phpunit` as a unit-only matrix (`needs-wp-env: false`). `quality` dogfoods this repo's own reusables — `reusable-php-lint` (composer validate, both PHPCS profiles and PHPStan as parallel jobs), `reusable-php-syntax-check` (over the whole repository, and over the `tests/fixtures/dws-build-fixture` build on the oldest PHP that by-path files must parse on), `reusable-plugin-check` (the GitHub profile over the same build, uploaded as an artifact), `reusable-supply-chain-audit`, `reusable-scripts-styles-lint` (lint:scripts; styles disabled), and `reusable-block-json-check` (against the throwaway `tests/fixtures/block-json` fixture, since this tooling repo ships no real blocks). `workflow-checks` and `codeql` are thin callers of `reusable-workflow-checks` (actionlint for workflow YAML correctness and zizmor for workflow security; uploads SARIF → Security tab and fails the job on findings) and `reusable-codeql` (this repo passes `languages: '["actions", "javascript-typescript"]'`).

Dogfooding scope: `reusable-php-lint`, `reusable-scripts-styles-lint`, `reusable-supply-chain-audit`, `reusable-php-syntax-check`, `reusable-block-json-check` (against a throwaway fixture), `reusable-plugin-check` (against the build fixture), `reusable-phpunit` (unit-only via `needs-wp-env: false`), `reusable-workflow-checks`, and `reusable-codeql` are run against this repo in self-CI. The other two — `reusable-playwright-e2e` and `reusable-release` — are plugin-shaped with no meaningful target here, so they are validated by actionlint and zizmor static checks only, not behaviorally exercised.

## Conventions

- File naming: `<tool>.dist.<ext>` — IDEs auto-recognize the trailing extension as XML/NEON/etc.
- PSR-12 file-header order overridden: `PSR12.Files.FileHeader.IncorrectOrder` is silenced in the shared `php/quality-assurance/phpcs.base.dist.xml` so consumers can write `<?php declare( strict_types=1 );` inline.
- `lint:php` runs `phpcs` with the production and tests profiles and `phpstan`.
- `--ignore-platform-req=php+` is applied to Composer installs/updates in scripts, this repo's CI, and the reusable workflows — only the PHP upper bound is ignored so future-PHP installs stay possible; extension requirements and PHP floor checks stay live.
- Self-lint is `phpcs.dist.xml` and `phpcs.tests.dist.xml` at root (extending the shared profiles with this repo's exclusions) — NOT the consumer-facing `php/quality-assurance/` profiles.
- Tests run the real tools (PHPCS, PHPStan, php-scoper) on fixtures copied to a temporary directory, not mocks.
