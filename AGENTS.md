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
│   │       └── wp-framework.inc.php # auto-detect ahegyes/wp-framework-* packages; requires extra.text-domain (false = explicit opt-out); plain-argument rule: rewrites a wp-framework-* literal only when it is the single plain constant string at a gettext call's DOMAIN argument position (per-function index map); throws on any other reserved literal (decoded — b-prefix/escape obfuscation caught): out-of-position, compound domain expressions with a reserved participant, named-argument gettext calls, and any wp-framework- occurrence in heredoc/nowdoc/interpolated strings; always-on guard throws on a prefixed as_* residue (name tokens, string refs, encapsed fragments)
│   ├── stubs/
│   │   └── action-scheduler.php     # parse-only as_* catalog, self-declared via this repo's extra.scoping-stubs — the exclusion survives a consumer dropping php-stubs/woocommerce-stubs
│   └── composer/
│       ├── CollectScopingStubs.php  # post-autoload-dump: aggregates extra.scoping-stubs → scoping-exclusions.json (classes, functions, constants); malformed ROOT declaration throws, malformed installed-package declaration warns
│       ├── ScopePhpDependencies.php # full pipeline: dispatches the consumer's scope-php-dependencies:raw with --output-dir derived from extra.scoped-dependencies-dir (single source; a stray --output-dir/-o flag throws), then regenerates the scoped autoload; every scope-php-dependencies listener must reference ScopePhpDependencies::run (a missing binding or a non-matching listener throws)
│       └── GenerateScopedAutoload.php # emits dependencies/scoper-autoload.php from the scoped tree (nested <vendor>/<pkg>/ and flattened <pkg>/ layouts); throws when the scan finds no packages
├── node/
│   ├── tsconfig.base.json
│   ├── eslint.config.base.mjs
│   ├── stylelint.config.base.js
│   └── playwright.config.base.js
├── tests/                           # PHPUnit unit tests (tests/Unit/); fixtures in tests/fixtures/
├── phpcs.dist.xml                   # SELF-lint — extends shared with WP-runtime exclusions (this repo's PHP is Composer-time tooling)
├── phpstan.dist.neon                # SELF-lint — includes shared, declares `paths: [php, tests]`
├── composer-require-checker.json    # whitelists Composer\* + Symfony\Finder (provided by composer/composer in require-dev)
└── .github/workflows/               # 8 reusable + 5 self-CI (codeql, tests, tests-mutation, quality, workflow-checks)
```

## Reusable CI workflows

8 reusable workflows in `.github/workflows/reusable-*.yml` (`workflow_call` only):
`reusable-block-json-check`, `reusable-scripts-styles-lint`, `reusable-php-lint`, `reusable-php-syntax-check`, `reusable-phpunit`, `reusable-playwright-e2e`, `reusable-supply-chain-audit`, `reusable-release`.

Plus 5 self-running for this repo's own CI: `codeql`, `tests`, `tests-mutation`, `quality`, `workflow-checks`. `quality` dogfoods this repo's own reusables — `reusable-php-lint` (phpcs, phpstan, composer-require-checker as parallel jobs), `reusable-php-syntax-check` (scoped to `php/`, since `tests/fixtures/` carries intentional `php -l` redeclaration failures), `reusable-supply-chain-audit`, and `reusable-scripts-styles-lint` (lint:scripts; styles disabled). `workflow-checks` runs actionlint (workflow YAML correctness) + zizmor (workflow security; uploads SARIF → Security tab and fails the job on findings) on workflow changes.

Dogfooding scope: `reusable-php-lint`, `reusable-scripts-styles-lint`, `reusable-supply-chain-audit`, and `reusable-php-syntax-check` are run against this repo in self-CI. The other four — `reusable-block-json-check`, `reusable-phpunit`, `reusable-playwright-e2e`, and `reusable-release` — are plugin-shaped with no meaningful target here, so they are validated by actionlint + zizmor static checks only, not behaviorally exercised.

## Conventions

- File naming: `<tool>.dist.<ext>` — IDEs auto-recognize the trailing extension as XML/NEON/etc.
- PSR-12 file-header order overridden: `PSR12.Files.FileHeader.IncorrectOrder` is silenced in `phpcs.dist.xml` so consumers can write `<?php declare( strict_types=1 );` inline.
- `lint:php` chains 3 tools: `phpcs` + `phpstan` + `composer-require-checker`. Symbols used from transitive deps are whitelisted in `composer-require-checker.json`, not added to `require`.
- `--ignore-platform-reqs` is applied to every Composer install/update — the composer scripts, this repo's CI, and the reusable workflows. Platform compatibility is a runtime concern the consuming plugin/theme gates via its version headers (graceful degradation), not a Composer hard-exit at install time.
- Self-lint is `phpcs.dist.xml` at root (extends shared with WP-runtime sniff exclusions for this repo's tooling code) — NOT the consumer-facing `php/quality-assurance/phpcs.dist.xml`.
- The php-scoper patcher in `scoper-base.inc.php` is token-aware — comments containing the prefix pattern are preserved verbatim. Boundary-safe (uses `preg_replace` with negative lookahead) so `Foo` excluded ≠ `FooBar` over-caught.
- `CollectScopingStubs` writes the exclusion JSON via temp-file + `rename` (atomic on same filesystem) so a concurrent reader can't observe a partial write.
- Tests use real `Composer\Composer` instances (not mocks).
