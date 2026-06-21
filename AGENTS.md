# wordpress-configs

Shared dev tooling — base configs, scoping helpers, reusable CI workflows — for the DWS WordPress framework + plugins. MIT license. Composer name `ahegyes/wordpress-configs`. NPM name `@ahegyes/wordpress-configs`. PHP namespace `DeepWebSolutions\Config\` (kept for continuity).

Consumed via `"dev-trunk"` from all other DWS repos. **No version tags / no releases.** Latest fixes propagate immediately. Justification: low-risk dev tooling, single owner, breakage gets fixed on trunk. Reusable workflows referenced as `@trunk`.

Intentionally standalone — also consumable by non-plugin repos (themes, site builds, client work).

## Layout

```
wordpress-configs/
├── php/
│   ├── quality-assurance/           # SHARED configs — consumed by other repos
│   │   ├── phpcs.dist.xml           # WPCS + PHPCompatibilityWP, PHP 8.5+ / WP 7.0+
│   │   └── phpstan.dist.neon        # level 8 + WordPress stubs (auto-discovered via .neon.php)
│   ├── php-scoper/
│   │   ├── scoper-base.inc.php      # catalog-agnostic; reads scoping-exclusions.json; token-aware patcher
│   │   └── contrib/
│   │       ├── php-di.inc.php       # PHP-DI 7 + transitive deps
│   │       └── wp-framework.inc.php # auto-detect ahegyes/wp-framework-* packages
│   └── composer/
│       ├── CollectScopingStubs.php  # post-autoload-dump: aggregates extra.scoping-stubs → scoping-exclusions.json (classes, functions, constants)
│       └── ScopePhpDependencies.php # composer event handler invoking php-scoper
├── node/
│   ├── tsconfig.base.json
│   ├── eslint.config.base.mjs
│   ├── stylelint.config.base.js
│   └── playwright.config.base.js
├── tests/                           # PHPUnit tests across 3 test files (CollectScopingStubsTest, ScopePhpDependenciesTest, ScoperBaseConfigTest); fixtures in tests/fixtures/
├── phpcs.dist.xml                   # SELF-lint — extends shared with WP-runtime exclusions (this repo's PHP is Composer-time tooling)
├── phpstan.dist.neon                # SELF-lint — includes shared, declares `paths: [php, tests]`
├── composer-require-checker.json    # whitelists Composer\* + Symfony\Finder (provided by composer/composer in require-dev)
└── .github/workflows/               # 7 reusable + 5 self-CI (codeql, tests, tests-mutation, quality, workflow-checks)
```

## Reusable CI workflows

7 reusable workflows in `.github/workflows/reusable-*.yml` (`workflow_call` only):
`reusable-block-json-check`, `reusable-scripts-styles-lint`, `reusable-php-qa`, `reusable-php-syntax-check`, `reusable-phpunit`, `reusable-playwright-e2e`, `reusable-release`.

Plus 5 self-running for this repo's own CI: `codeql`, `tests`, `tests-mutation`, `quality`, `workflow-checks`. `quality` reuses this repo's own `reusable-php-qa.yml` plus runs composer-require-checker + lint:scripts as parallel jobs. `workflow-checks` runs actionlint (workflow YAML correctness) + zizmor (workflow security, SARIF → Security tab) on workflow changes.

## Conventions

- File naming: `<tool>.dist.<ext>` — IDEs auto-recognize the trailing extension as XML/NEON/etc.
- PSR-12 file-header order overridden: `PSR12.Files.FileHeader.IncorrectOrder` is silenced in `phpcs.dist.xml` so consumers can write `<?php declare( strict_types=1 );` inline.
- `lint:php` chains 3 tools: `phpcs` + `phpstan` + `composer-require-checker`. Symbols used from transitive deps are whitelisted in `composer-require-checker.json`, not added to `require`.
- Self-lint is `phpcs.dist.xml` at root (extends shared with WP-runtime sniff exclusions for this repo's tooling code) — NOT the consumer-facing `php/quality-assurance/phpcs.dist.xml`.
- The php-scoper patcher in `scoper-base.inc.php` is token-aware — comments containing the prefix pattern are preserved verbatim. Boundary-safe (uses `preg_replace` with negative lookahead) so `Foo` excluded ≠ `FooBar` over-caught.
- `CollectScopingStubs` writes the exclusion JSON via temp-file + `rename` (atomic on same filesystem) so a concurrent reader can't observe a partial write.
- Tests use real `Composer\Composer` instances (not mocks).
