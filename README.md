# WordPress Configs

A collection of shared configuration files for WordPress projects. Provides base configs for PHPCS and PHPStan, Composer helpers for dependency scoping, a catalog-agnostic php-scoper base config for the WordPress ecosystem, and a transitive `roave/security-advisories` install that fails `composer install --dev` on any known CVE in the dep graph.

## Requirements

- PHP 8.5+
- Composer 2.x

## Installation

Add the VCS repository and require the package as a dev dependency:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ahegyes/wordpress-configs.git" }
    ],
    "require-dev": {
        "ahegyes/wordpress-configs": "dev-trunk"
    }
}
```

Reusable CI workflows are referenced via `@trunk` directly from GitHub (see [Reusable CI Workflows](#reusable-ci-workflows) below).

## What's Included

### Quality Assurance Configs

Base configuration files that your project extends. Create thin project-level config files that reference these.

#### PHPCS (WordPress Coding Standards)

`php/quality-assurance/phpcs.dist.xml` — WordPress-Extra + WordPress-Docs + PHPCompatibilityWP.

| Setting              | Value |
|----------------------|-------|
| PHP compatibility    | 8.5+  |
| WordPress minimum    | 7.0   |
| Parallel workers     | 8     |

Create a `.phpcs.xml` in your project:

```xml
<?xml version="1.0"?>
<ruleset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/squizlabs/php_codesniffer/phpcs.xsd">
    <!-- Extend the shared ruleset. -->
    <rule ref="./vendor/ahegyes/wordpress-configs/php/quality-assurance/phpcs.dist.xml"/>

    <!-- Check that the proper text domain(s) is used everywhere. -->
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="your-text-domain"/>
            </property>
        </properties>
    </rule>

    <!-- Check that the proper prefix is used everywhere. -->
    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="dws_"/>
                <element value="DeepWebSolutions\YourPlugin"/>
            </property>
        </properties>
    </rule>
</ruleset>
```

Run:

```bash
vendor/bin/phpcs --standard=./.phpcs.xml --basepath=. ./ -v       # Check
vendor/bin/phpcbf --standard=./.phpcs.xml --basepath=. ./ -v      # Auto-fix
```

#### PHPStan (Static Analysis)

`php/quality-assurance/phpstan.dist.neon` — level 8, WordPress stubs, auto-discovered paths.

| Setting                                     | Value                                           |
|---------------------------------------------|-------------------------------------------------|
| Level                                       | 8                                               |
| `treatPhpDocTypesAsCertain`                 | `false`                                         |
| `inferPrivatePropertyTypeFromConstructor`   | `true`                                          |
| WordPress stubs                             | `php-stubs/wordpress-stubs` (auto-bootstrapped) |

Create a `.phpstan.neon` in your project:

```neon
includes:
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon

parameters:
    scanDirectories:
        - vendor/wp-plugin/woocommerce  # If using WooCommerce
```

Run:

```bash
vendor/bin/phpstan analyse -c ./.phpstan.neon -v --memory-limit=1G
```

The bundled `phpstan.dist.neon.php` auto-discovers paths to analyse:

| Directories                                                          | Root Files                                                      |
|----------------------------------------------------------------------|-----------------------------------------------------------------|
| `src/`, `includes/`, `models/`, `blocks/`, `templates/`              | `{plugin-name}.php`, `functions-bootstrap.php`, `functions.php` |

**Included PHPStan extensions** (auto-installed):

| Extension                          | Purpose                                    |
|------------------------------------|--------------------------------------------|
| `phpstan-deprecation-rules`        | Detects usage of deprecated code           |
| `phpstan-strict-rules`             | Additional strict type checks              |
| `szepeviktor/phpstan-wordpress`    | WordPress function signatures and types    |
| `johnbillion/wp-compat`            | WordPress version compatibility checks     |
| `swissspidy/phpstan-no-private`    | Flags usage of private WordPress APIs      |

### Composer Helpers

#### CollectScopingStubs

`php/composer/CollectScopingStubs.php` — Composer post-autoload-dump hook that aggregates per-package stubs-catalog declarations and writes the unioned symbol set to a JSON file the [php-scoper base config](#php-scoper-base-config) consumes.

Each Composer package in the dep graph (and the consuming project itself) declares the stubs catalogs covering its scoped code's external references via `extra.scoping-stubs` in its own `composer.json`:

```json
{
    "extra": {
        "scoping-stubs": ["php-stubs/wordpress-stubs"]
    }
}
```

The hook walks `vendor/<vendor>/<package>/composer.json` plus the project root, parses every stubs file each declared package ships (via the package's own `autoload.files`, falling back to the `vendor/<vendor>/<package>/<package>.php` convention when `autoload.files` is absent), unions every class-like declaration (`class`, `interface`, `trait`, `enum`), function, and constant (top-level `const` declarations and `define()` calls) it finds, and writes `scoping-exclusions.json`. Names are recorded as FQCNs (`A\Foo` ≠ `B\Foo`) so namespaced stubs catalogs are handled correctly. Multi-file catalogs like `php-stubs/woocommerce-stubs` (which ships both `woocommerce-stubs.php` and `woocommerce-packages-stubs.php`) are fully covered via this lookup.

**Wire it in your project's `composer.json`:**

```json
{
    "scripts": {
        "post-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\CollectScopingStubs::postAutoloadDump"
        ]
    }
}
```

| When it runs                                                    | Behavior                                              |
|-----------------------------------------------------------------|-------------------------------------------------------|
| Dev mode + non-CI                                               | Generates `scoping-exclusions.json` at project root   |
| Non-dev mode                                                    | Skipped                                               |
| `CI` env var set                                                | Skipped                                               |
| No `extra.scoping-stubs` declarations anywhere in the dep graph | Writes empty exclusion lists (no symbols to skip)     |
| Declared stubs package not installed                            | Skipped with a console warning, helper continues      |

The contract is: **whichever package introduces references to external (non-prefixable) symbols is the package that declares the catalog covering them.** A consumer plugin doesn't need to enumerate WP usage — the framework packages it depends on declare `php-stubs/wordpress-stubs` and the helper picks that up automatically. A consumer that integrates with WooCommerce adds `php-stubs/woocommerce-stubs` to its own `composer.json`.

**Trust model:** vendor packages can declare their own `extra.scoping-stubs`, and the helper parses + reads symbol names from those declared files. A malicious vendor could declare custom symbol names that, after merging into `scoping-exclusions.json`, weaken the consumer's scoping (those symbols stay unprefixed and may clash with WP core). This is the standard composer supply-chain trust model — only install vendor packages you trust. The helper does NOT execute anything from the parsed stubs files; it only extracts class, function, and constant declaration names via AST traversal.

#### ScopePhpDependencies

`php/composer/ScopePhpDependencies.php` — Composer hooks for scoping third-party PHP dependencies via [php-scoper](https://github.com/humbug/php-scoper).

This is **opt-in**: it only triggers if `humbug/php-scoper` is installed in your project's dev dependencies. Intended for third-party libraries (PDF generators, HTTP clients, DI containers, etc.) that may conflict with other plugins on the same WordPress site.

**Wire it in your project's `composer.json`:**

```json
{
    "scripts": {
        "pre-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::preAutoloadDump"
        ],
        "post-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\CollectScopingStubs::postAutoloadDump",
            "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::postAutoloadDump"
        ],
        "scope-php-dependencies": [
            "@php ./vendor/bin/php-scoper add-prefix --config=scoper.inc.php --force --quiet"
        ]
    }
}
```

| Hook                 | What It Does                                                                                                              |
|----------------------|---------------------------------------------------------------------------------------------------------------------------|
| `preAutoloadDump`    | Ensures scoped directories/files exist before autoloader runs                                                             |
| `postAutoloadDump`   | Triggers the `scope-php-dependencies` script, then generates `dependencies/scoper-autoload.php` if opted in (see below)   |

#### Autoload generator (opt-in)

`php/composer/GenerateScopedAutoload.php` — runs at the end of `ScopePhpDependencies::postAutoloadDump` and emits a single `dependencies/scoper-autoload.php` that wires every scoped package's `autoload.psr-4` and `autoload.files` into the host plugin's Composer ClassLoader. Host plugins reference this one file via their root `autoload.files` instead of hand-declaring a PSR-4 entry per scoped package.

**Opt in by declaring both keys in your project's `composer.json`:**

```json
{
    "extra": {
        "scoped-dependencies-dir": "dependencies",
        "scoping-prefix": "DeepWebSolutions\\YourPlugin\\Scoped"
    }
}
```

**Wire the generated file in your `autoload`:**

```json
{
    "autoload": {
        "psr-4": {
            "DeepWebSolutions\\YourPlugin\\": "src/"
        },
        "files": [
            "dependencies/scoper-autoload.php"
        ]
    }
}
```

That's it — adding a new scoped package never requires editing the host `composer.json` again. The generator reads each scoped package's `composer.json` and emits the corresponding `addPsr4()` calls and `require_once` statements at the next scoping run. Deterministic by design: no `class_alias`, no `expose-*` machinery — the output is purely a function of the scoped tree and the prefix.

| Behavior          | When                                                                                                                                                        |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Generator runs    | Both `extra.scoped-dependencies-dir` and `extra.scoping-prefix` set                                                                                         |
| Generator skipped | Either key missing or `dependencies/` doesn't exist after scoping                                                                                           |
| Throws            | A scoped package's `psr-4` key doesn't start with the declared prefix (pipeline drift) or declares `autoload.classmap` (not yet supported by the generator) |

### php-scoper Base Config

`php/php-scoper/scoper-base.inc.php` — a catalog-agnostic base php-scoper config for the WordPress ecosystem. Returns a closure that builds a complete config with sensible defaults.

**What it handles:**

- Reads `scoping-exclusions.json` (generated by CollectScopingStubs) and excludes those classes/functions from scoping
- Excludes the `Psr\` namespace from scoping (PSR interfaces are designed for cross-plugin interop; scoping them per-plugin would create incompatible interface declarations)
- Provides a default patcher that strips the scoping prefix from any excluded-symbol references inside scoped files

**Wire it in your project's `scoper.inc.php`:**

```php
<?php declare( strict_types = 1 );

use Isolated\Symfony\Component\Finder\Finder;

$build_config = require __DIR__ . '/vendor/ahegyes/wordpress-configs/php/php-scoper/scoper-base.inc.php';

return $build_config( array(
    'finders' => array(
        Finder::create()->files()->in( 'vendor/php-di' )->name( '*.php' ),
        // Add per-library finders for whatever you want to scope...
    ),
) );
```

**Available overrides** (all optional, all merged with the defaults):

| Key                  | Effect                                                                  |
|----------------------|-------------------------------------------------------------------------|
| `project_dir`        | Override where to look for `scoping-exclusions.json` (defaults to cwd)  |
| `finders`            | Files to scope (most plugins always need to set this)                   |
| `exclude_namespaces` | Additional namespaces to leave unprefixed                               |
| `exclude_classes`    | Additional classes to leave unprefixed                                  |
| `exclude_functions`  | Additional functions to leave unprefixed                                |
| `exclude_constants`  | Additional constants to leave unprefixed                                |
| `patchers`           | Additional patcher callables (run after the default reference-stripper) |

**Default patcher limitations:** the reference-stripper handles direct calls (`\Prefix\function(`), `use Prefix\Class;` and `use Prefix\Class as Alias;` statements, `function_exists('Prefix\\function')` and `defined('Prefix\\CONST')` string arguments, bare constant refs (`\Prefix\CONST`), and any string-typed reference matching `'Prefix\\Symbol'` (covers `class_exists`, `method_exists`, `is_a`, `is_subclass_of`, `ReflectionClass`, etc.). The patcher is token-aware — comments containing the prefix pattern are preserved verbatim. It does NOT cover `instanceof` via dynamic-string variables (the class name isn't statically visible). If a scoped third-party library uses dynamic patterns the patcher can't reach, add a custom callable to `patchers`.

### Editor Config

`.editorconfig` — copy to your project root or reference in your editor's settings.

| File Type                   | Indent Style | Indent Size                         |
|-----------------------------|--------------|-------------------------------------|
| `*` (default)               | Tabs         | —                                   |
| `*.yml`, `*.yaml`, `*.json` | Spaces       | 2                                   |
| `*.md`                      | Tabs         | — (trailing whitespace preserved)   |
| `*.txt`                     | Tabs         | — (CRLF line endings)               |

### Node Baselines

Shared baseline configurations for Node-ecosystem tooling. Live in `node/` (parallel to `php/`). Plugins extend each via `extends`-style composition.

The baselines assume the consuming plugin has installed `@wordpress/scripts` (which transitively brings `@wordpress/eslint-plugin`, `@wordpress/stylelint-config`, `@playwright/test`, etc.) — the standard modern WP plugin stack.

#### TypeScript

`node/tsconfig.base.json` — modern TS base targeting ES2022 with `bundler` module resolution (matches `@wordpress/scripts`). Strict mode on, `react-jsx` for blocks.

Create a `tsconfig.json` in your project:

```json
{
    "extends": "./vendor/ahegyes/wordpress-configs/node/tsconfig.base.json",
    "include": ["client/**/*"],
    "exclude": ["assets/**", "node_modules/**", "vendor/**"]
}
```

#### ESLint

`node/eslint.config.base.mjs` — flat-config wrapping `@wordpress/eslint-plugin`'s recommended preset plus DWS defaults. Requires `@wordpress/eslint-plugin` v25+ and ESLint v9+.

Create an `eslint.config.mjs` in your project:

```js
import dwsBase from '@ahegyes/wordpress-configs/node/eslint.config.base.mjs';

export default [
    ...dwsBase,
    {
        rules: {
            // Plugin-specific overrides go here.
        },
    },
];
```

#### Stylelint

`node/stylelint.config.base.js` — extends `@wordpress/stylelint-config/scss` with DWS defaults.

Create a `stylelint.config.js` in your project:

```js
const dwsBase = require('@ahegyes/wordpress-configs/node/stylelint.config.base.js');

module.exports = {
    ...dwsBase,
    rules: {
        ...dwsBase.rules,
        // Plugin-specific overrides go here.
    },
};
```

#### Playwright

`node/playwright.config.base.js` — extends `@wordpress/scripts/config/playwright.config.js` (the canonical Playwright config from `@wordpress/scripts`), adjusting `testDir` to `tests/e2e/` to match DWS plugin layout. Inherits everything else: baseURL `http://localhost:8889`, viewport, headless Chromium, screenshots on failure, retries in CI, auto-start of wp-env, etc.

Create a `playwright.config.js` in your project:

```js
const { defineConfig } = require('@playwright/test');
const baseConfig = require('@ahegyes/wordpress-configs/node/playwright.config.base.js');

module.exports = defineConfig({
    ...baseConfig,
    // Plugin-specific overrides go here.
});
```

For test fixtures (admin login, block editor helpers, REST request utilities), import from `@wordpress/e2e-test-utils-playwright` in your test files — it provides extended `test`, `admin`, `editor`, `pageUtils`, and `requestUtils` fixtures designed for WordPress E2E testing.

## Dependency Scoping Workflow

`CollectScopingStubs`, `ScopePhpDependencies`, `scoper-base.inc.php`, and the autoload generator compose into a complete dependency-scoping pipeline:

```
┌─────────────────────────────────────────────────────────────┐
│ composer install (dev mode)                                 │
└────────────────────────┬────────────────────────────────────┘
                         │
            pre-autoload-dump fires
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│ ScopePhpDependencies::preAutoloadDump                       │
│ • Creates any missing autoload paths                        │
│   (so the upcoming dump-autoload doesn't error)             │
└────────────────────────┬────────────────────────────────────┘
                         │
            composer dumps autoloader
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│ post-autoload-dump fires                                    │
│                                                             │
│ CollectScopingStubs::postAutoloadDump                       │
│ • Walks vendor/*/composer.json + project's composer.json    │
│ • Reads every `extra.scoping-stubs` declaration             │
│ • Parses every stubs file each package ships                │
│ • Writes scoping-exclusions.json with unioned symbols       │
│                                                             │
│ ScopePhpDependencies::postAutoloadDump                      │
│ • Verifies php-scoper is installed + dev mode is on         │
│ • Dispatches the `scope-php-dependencies` script            │
│ • If opted in: generates dependencies/scoper-autoload.php   │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│ scope-php-dependencies script (you define this)             │
│ • Runs `php-scoper add-prefix --config=scoper.inc.php ...`  │
│                                                             │
│ scoper.inc.php loads php-scoper/scoper-base.inc.php         │
│ • Reads scoping-exclusions.json                             │
│ • Excludes declared symbols + Psr\* from scoping            │
│ • Strips prefix from excluded refs in scoped output         │
└─────────────────────────────────────────────────────────────┘
```

The result: your plugin's bundled deps end up scoped under your declared prefix, but every symbol from a declared catalog (e.g., WordPress functions, WC classes) and PSR interfaces remain unprefixed and resolve to their global definitions at runtime. If the autoload generator is opted in, host plugin code references the scoped tree through a single `dependencies/scoper-autoload.php` entry in `autoload.files` — no per-package PSR-4 wiring in the host `composer.json`.

## Reusable CI Workflows

Reusable GitHub Actions workflows live in `.github/workflows/reusable-*.yml`. Plugins call them via `workflow_call` and compose them into their own pipelines. They only trigger on `workflow_call` — they don't run on pushes to this repo.

| Workflow                              | Purpose                                          | Key Inputs                                            |
|---------------------------------------|--------------------------------------------------|-------------------------------------------------------|
| `reusable-php-syntax-check.yml`       | `php -l` matrix across PHP versions              | `plugin-path`, `php-versions[]`                       |
| `reusable-php-qa.yml`                 | PHPCS + PHPStan (parallel jobs)                  | `plugin-path`, `php-version`                          |
| `reusable-scripts-styles-lint.yml`    | ESLint + Stylelint via npm scripts               | `plugin-path`, `node-version`                         |
| `reusable-phpunit.yml`                | PHPUnit + wp-env startup                         | `plugin-path`, `php-version`, `wp-version`            |
| `reusable-playwright-e2e.yml`         | Playwright E2E + report upload on failure        | `plugin-path`, `plugin-slug`, `php-version`           |
| `reusable-block-json-check.yml`       | Validates block.json against wp.org schema       | `plugin-path`, `node-version`                         |
| `reusable-release.yml`                | Build → test built artifact → deploy to wp.org   | `plugin-slug`, `plugin-path`, `php-version` + secrets |

### Plugin orchestrators

Plugins typically split their CI into three concern-focused workflows that call the reusables.

**`.github/workflows/quality.yml`** — runs on every push/PR:

```yaml
name: Quality
on: [push, pull_request]

jobs:
  syntax:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-syntax-check.yml@trunk
    with:
      php-versions: '["8.5","8.6"]'
  qa:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-qa.yml@trunk
  block-json:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-block-json-check.yml@trunk
  lint-scripts-styles:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-scripts-styles-lint.yml@trunk
```

**`.github/workflows/tests.yml`** — runs on every push/PR:

```yaml
name: Tests
on: [push, pull_request]

jobs:
  phpunit:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-phpunit.yml@trunk
  e2e:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-playwright-e2e.yml@trunk
    with:
      plugin-slug: your-plugin-slug
```

**`.github/workflows/release.yml`** — runs on version tags:

```yaml
name: Release
on:
  push:
    tags: ['v*']

jobs:
  release:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-release.yml@trunk
    with:
      plugin-slug: your-plugin-slug
    secrets:
      SVN_USERNAME: ${{ secrets.WP_ORG_SVN_USERNAME }}
      SVN_PASSWORD: ${{ secrets.WP_ORG_SVN_PASSWORD }}
```

## Typical Composer Scripts

Add these to your project's `composer.json` for a consistent dev workflow:

```json
{
    "scripts": {
        "format:php": "phpcbf --standard=./.phpcs.xml --basepath=. ./ -v",
        "lint:php": ["@lint:php:phpcs", "@lint:php:phpstan"],
        "lint:php:phpcs": "phpcs --standard=./.phpcs.xml --basepath=. ./ -v",
        "lint:php:phpstan": "phpstan analyse -c ./.phpstan.neon -v --memory-limit=1G"
    }
}
```

## Development

This repository is itself tested with PHPUnit. To work on it:

```bash
composer install
composer test           # runs all tests
composer test:unit      # runs the Unit testsuite specifically
```

Tests live in `tests/Unit/` (PSR-4 autoloaded as `DeepWebSolutions\Config\Tests\Unit\`) and use real `Composer\Composer` + `Event` instances rather than mocks — closer to how the helpers are actually invoked in consuming plugins.

Coverage: declaration aggregation and multi-file stubs lookup in `CollectScopingStubs`; autoload-path creation and pipeline gates in `ScopePhpDependencies`; scoper config assembly, override merging, and patcher transformations in `scoper-base.inc.php`; the contrib scoper partials including their seam into scoper-base; and the autoload generator's output across PSR-4, `autoload.files`, and pipeline-drift detection.

Test fixtures live in `tests/fixtures/<test-name>/` only when the data is multi-line or shared across multiple tests. Inline test data is preferred for small, scenario-specific inputs.
