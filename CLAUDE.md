# wordpress-configs

Shared dev tooling — base configs, scoping helpers, reusable CI workflows — for the DWS WordPress framework + plugins. MIT license. Composer name `ahegyes/wordpress-configs`. NPM name `@ahegyes/wordpress-configs`. PHP namespace `DeepWebSolutions\Config\` (kept for continuity).

Consumed via `"dev-trunk"` from all other DWS repos. **No version tags / no releases.** Latest fixes propagate immediately. Justification: low-risk dev tooling, single owner, breakage gets fixed on trunk. Reusable workflows referenced as `@trunk`.

Intentionally standalone — also consumable by non-plugin repos (themes, site builds, client work).

## Layout

```
wordpress-configs/
├── php/
│   ├── quality-assurance/
│   │   ├── phpcs.dist.xml          # WPCS + PHPCompatibilityWP, PHP 8.5+ / WP 7.0+
│   │   └── phpstan.dist.neon       # level 8 + WordPress stubs (auto-discovered via .neon.php)
│   ├── php-scoper/
│   │   ├── scoper-base.inc.php     # catalog-agnostic; reads scoping-exclusions.json
│   │   └── contrib/
│   │       ├── php-di.inc.php      # PHP-DI 7 + transitive deps
│   │       └── wp-framework.inc.php # auto-detect ahegyes/wp-framework-* packages
│   └── composer/
│       ├── CollectScopingStubs.php  # post-autoload-dump: aggregates extra.scoping-stubs → scoping-exclusions.json
│       └── ScopePhpDependencies.php # composer event handler invoking php-scoper
├── node/
│   ├── tsconfig.base.json
│   ├── eslint.config.base.mjs
│   ├── stylelint.config.base.js
│   └── playwright.config.base.js
├── tests/                           # PHPUnit tests across 3 test files (CollectScopingStubsTest, ScopePhpDependenciesTest, ScoperBaseConfigTest); fixtures in tests/fixtures/
└── .github/workflows/               # 7 reusable + codeql + tests + tests-mutation
```

## Reusable CI workflows

7 reusable workflows in `.github/workflows/reusable-*.yml` (`workflow_call` only):
`reusable-block-json-check`, `reusable-js-css-lint`, `reusable-php-qa`, `reusable-php-syntax-check`, `reusable-phpunit`, `reusable-playwright-e2e`, `reusable-release`.

Plus 3 self-running for this repo's own CI: `codeql`, `tests`, `tests-mutation`.

## Conventions

- File naming: `<tool>.dist.<ext>` — IDEs auto-recognize the trailing extension as XML/NEON/etc. (per `feedback_dist_config_naming` memory).
- PSR-12 file-header order overridden: `PSR12.Files.FileHeader.IncorrectOrder` is silenced in `phpcs.dist.xml` so consumers can write `<?php declare( strict_types=1 );` inline (per `feedback_php_file_header` memory).
- Tests use real `Composer\Composer` instances (not mocks).
