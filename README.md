# WordPress Configs

A collection of shared configuration files for WordPress projects. Provides base configs for PHPCS and PHPStan, Composer helpers for dependency scoping, a catalog-agnostic php-scoper base config for the WordPress ecosystem, and a transitive `roave/security-advisories` install that fails `composer install --dev` on any known CVE in the dep graph.

## Requirements

- PHP 8.5+
- Composer 2.x
- Node 26+ / npm 11+ — for the Node baselines (`node/`) only; PHP-only consumers don't need them

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

Reusable CI workflows are referenced directly from GitHub (see [Reusable CI Workflows](#reusable-ci-workflows) below). The DWS repos pin them to a commit SHA (dependabot proposes bumps); the examples in this README show `@trunk` for copy-paste brevity.

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

The bundled `phpstan.dist.neon.php` auto-discovers paths by layout:

| Layout (detected by)                               | Directories analysed                                                         | Root files                                            |
|----------------------------------------------------|------------------------------------------------------------------------------|-------------------------------------------------------|
| Plugin (`Plugin Name:` header) or library (`src/`) | `src/`, `includes/`, `models/`, `blocks/`, `templates/`, `config/`, `tests/` | `{plugin-name}.php`, `functions.php`, `uninstall.php` |
| Theme (`style.css`)                                | `inc/`, `template-parts/`, `parts/`, `patterns/`, `blocks/`, `tests/`        | `functions.php`, `index.php`                          |

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

Each declaration is either a bare `vendor/package` (a whole catalog) or an explicit `vendor/package:relative/file.php` (one specific stubs file). For a bare entry the hook reads the package's `autoload.files` plus the conventional `<package>.php`; the FQCNs of every class, interface, trait, enum, function, and constant it finds are unioned into `scoping-exclusions.json`.

A catalog the package ships but does **not** list in its `autoload.files` needs the explicit form — `vendor/package:relative/file.php` names that one secondary file:

```json
{
    "extra": {
        "scoping-stubs": [
            "php-stubs/woocommerce-stubs",
            "php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php"
        ]
    }
}
```

The Action Scheduler `as_*` functions need **no consumer declaration at all**: this repo ships `php/stubs/action-scheduler.php` and declares it via its own `extra.scoping-stubs` (see below), so the collector picks it up automatically wherever `ahegyes/wordpress-configs` is installed. The explicit `woocommerce-packages-stubs.php` entry above remains useful only for the OTHER symbols that file carries (WooCommerce packages internals) — not for `as_*` coverage.

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
| Dev mode                                                        | Generates `scoping-exclusions.json` at project root   |
| Non-dev mode                                                    | Skipped                                               |
| No `extra.scoping-stubs` declarations anywhere in the dep graph | Writes empty exclusion lists (no symbols to skip)     |
| Declared stubs package not installed                            | Skipped with a console warning, helper continues      |
| Malformed declaration in the PROJECT's own composer.json        | **Throws** — a root typo must not silently shrink the exclusion set |
| Malformed declaration in an installed package                   | Skipped with a console warning (third-party metadata the consumer cannot fix) |

**Shipped catalog — Action Scheduler:** this repo ships `php/stubs/action-scheduler.php` (the `as_*` public API) and declares it via its own `extra.scoping-stubs`, so every consumer that installs `ahegyes/wordpress-configs` excludes the `as_*` family automatically. Action Scheduler is host-provided at runtime (bundled by WooCommerce or the standalone plugin) and must never be prefixed; keeping the catalog here means the exclusion survives a consumer dropping `php-stubs/woocommerce-stubs`. The `wp-framework.inc.php` contrib partial additionally installs a scope-time guard that fails the run if a prefixed `as_*` reference ever reaches the scoped output.

The contract is: **whichever package introduces references to external (non-prefixable) symbols is the package that declares the catalog covering them.** A consumer plugin doesn't need to enumerate WP usage — the framework packages it depends on declare `php-stubs/wordpress-stubs` and the helper picks that up automatically. A consumer that integrates with WooCommerce adds `php-stubs/woocommerce-stubs` to its own `composer.json`.

**Trust model:** vendor packages can declare their own `extra.scoping-stubs`, and the helper parses + reads symbol names from those declared files. A malicious vendor could declare custom symbol names that, after merging into `scoping-exclusions.json`, weaken the consumer's scoping (those symbols stay unprefixed and may clash with WP core). This is the standard composer supply-chain trust model — only install vendor packages you trust. The helper does NOT execute anything from the parsed stubs files; it only extracts class, function, and constant declaration names via AST traversal.

**Output overrides (opt-in):** Two environment variables redirect where the exclusion JSON is written. Both are constrained to the project root — they cannot be used to write outside the project tree.

| Env var                            | Default                       | Constraint                                                        |
|------------------------------------|-------------------------------|-------------------------------------------------------------------|
| `SCOPING_EXCLUSIONS_OUTPUT_DIR`    | Project root                  | Must resolve to an existing directory **inside** the project root |
| `SCOPING_EXCLUSIONS_OUTPUT_FILE`   | `scoping-exclusions.json`     | Filename only — `/` and `\` are rejected                          |

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
        "scope-php-dependencies": "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::run",
        "scope-php-dependencies:raw": [
            "@php vendor/bin/php-scoper add-prefix --prefix='DeepWebSolutions\\YourPlugin\\Scoped' --config=./scoper.inc.php --force --quiet"
        ]
    },
    "extra": {
        "scoped-dependencies-dir": "dependencies"
    }
}
```

The scoped output directory is single-sourced from `extra.scoped-dependencies-dir`: the pipeline derives php-scoper's `--output-dir` from it and appends the flag when dispatching `scope-php-dependencies:raw`. The raw script holds only the prefix/config/flags — a raw script (or public script) carrying its own `--output-dir` (or the short `-o`) is rejected loudly, as is a raw `add-prefix` invocation left under the public `scope-php-dependencies` name (it would scope without regenerating the autoload on a manual run). The public script must reference `ScopePhpDependencies::run` (every listener is checked for that string); a listener that does not — e.g. an alias like `"@scope-php-dependencies:raw"` — is rejected with the wiring instructions.

| Hook / script                | What It Does                                                                                                              |
|------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| `preAutoloadDump`            | Ensures scoped directories/files exist before autoloader runs                                                             |
| `postAutoloadDump`           | Dispatches `scope-php-dependencies:raw` with the derived `--output-dir`, then generates `dependencies/scoper-autoload.php` if opted in (see below) |
| `run` (`composer scope-php-dependencies`) | Same pipeline for manual runs: scopes, then regenerates the autoload when `extra.scoping-prefix` is declared (the generator stays opt-in — without it a manual run scopes but skips `scoper-autoload.php`); throws if php-scoper is missing |

#### Autoload generator (opt-in)

`php/composer/GenerateScopedAutoload.php` — runs at the end of `ScopePhpDependencies::postAutoloadDump` and emits a single `dependencies/scoper-autoload.php` that registers every scoped package's `autoload.psr-4` and `autoload.classmap` entries on a dedicated Composer `ClassLoader` it creates and registers (independent of whichever loader the host registered first), and `require_once`s each `autoload.files` entry. Host plugins reference this one file via their root `autoload.files` instead of hand-declaring a PSR-4 entry per scoped package.

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

That's it — adding a new scoped package never requires editing the host `composer.json` again. The generator reads each scoped package's `composer.json` and emits the corresponding `addPsr4()` / `addClassMap()` calls and `require_once` statements at the next scoping run. The output is purely a function of the scoped tree and the prefix.

The generator reads packages from both output layouts php-scoper produces: `dependencies/<vendor>/<pkg>/` (finders spanning several vendor namespaces) and the flattened `dependencies/<pkg>/` (finders covering a single vendor namespace, whose shared `vendor/<vendor>/` segment php-scoper collapses).

| Behavior          | When                                                                                                                                                        |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Generator runs    | Both `extra.scoped-dependencies-dir` and `extra.scoping-prefix` set                                                                                         |
| Generator skipped | `extra.scoping-prefix` missing (the generator is the opt-in half; scoping alone completes)                                                                  |
| Throws            | No scoped package is found under the output dir (an empty generated autoload must never be silent), the output dir doesn't exist after a successful scoper run, a scoped package's `psr-4` key doesn't start with the declared prefix (pipeline drift), it declares `autoload.exclude-from-classmap` or `autoload.psr-0` (unsupported), or a classmap class resolves to more than one file |

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
| `exclude_files`      | Additional files to leave entirely unscoped                             |
| `patchers`           | Additional patcher callables (run after the default reference-stripper) |

**Default patcher limitation:** the reference-stripper rewrites statically-visible references to excluded symbols (direct calls, `use` statements, the string argument of `function_exists`/`defined`/`class_exists`-style calls, bare constants) and is token-aware, so prefix patterns inside comments are preserved. It does NOT reach a class name that exists only in a runtime string variable (e.g. `instanceof $dynamic`). If a scoped library relies on such dynamic patterns, add a custom callable to `patchers`.

### Editor Config

`.editorconfig` — copy to your project root or reference in your editor's settings.

| File Type                   | Indent Style | Indent Size                         |
|-----------------------------|--------------|-------------------------------------|
| `*` (default)               | Tabs         | —                                   |
| `*.yml`, `*.yaml`, `*.json` | Spaces       | 2                                   |
| `*.md`                      | Tabs         | — (trailing whitespace preserved)   |
| `*.txt`                     | Tabs         | — (CRLF line endings)               |

### Node Baselines

Shared baseline configurations for Node-ecosystem tooling — one central config per tool, extended and tweaked per project (the same central-defaults-plus-overrides pattern `@wordpress/scripts` itself uses). Live in `node/` (parallel to `php/`). Plugins extend each via `extends`-style composition.

The baselines assume the consuming plugin has installed `@wordpress/scripts` — the standard modern WP plugin stack. It brings `@wordpress/eslint-plugin` and `@wordpress/stylelint-config` transitively and declares `@playwright/test` as a peer dependency (npm installs peers automatically; stricter package managers may need it declared explicitly).

The bare `@ahegyes/wordpress-configs/node/...` require resolves through `node_modules`, not `vendor/`, so the package must also be installed on the npm side — a git devDependency pinned to a commit SHA:

```json
{
    "devDependencies": {
        "@ahegyes/wordpress-configs": "git+https://github.com/ahegyes/wordpress-configs.git#<commit-sha>"
    }
}
```

#### TypeScript

`node/tsconfig.base.json` — assumes TypeScript 6+ and states only deltas from its defaults (which already provide strict mode, `bundler` resolution, and a latest-ES target that floats with the compiler): `react-jsx` for blocks plus a few extra checks (`noImplicitReturns`, `noFallthroughCasesInSwitch`, `isolatedModules`, `noEmit`).

Create a `tsconfig.json` in your project:

```json
{
    "extends": "@ahegyes/wordpress-configs/node/tsconfig.base.json",
    "include": ["client/**/*"],
    "exclude": ["assets/**", "node_modules/**", "vendor/**"]
}
```

#### ESLint

`node/eslint.config.base.mjs` — flat-config wrapping `@wordpress/eslint-plugin`'s recommended preset plus DWS defaults, with the plugin's `test-unit` / `test-playwright` presets scoped to the DWS test layout (`**/test/**`, `tests/e2e/**`). Requires `@wordpress/eslint-plugin` v25+ and ESLint v9+ (the flat format is the only one ESLint v10 supports).

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

`node/stylelint.config.base.js` — extends `@wordpress/stylelint-config/scss` with DWS defaults, including `ignoreFiles` for generated/vendored trees. The ignores take effect through the spread pattern shown below (the globs land in your config and resolve against your project); if you load the base via `extends` instead, they are inert — declare your own or use `.stylelintignore`.

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
    use: {
        ...baseConfig.use,
        // Plugin-specific overrides go here — spread nested keys (use, webServer,
        // projects) individually, or the top-level spread drops the WP defaults.
    },
});
```

For test fixtures (admin login, block editor helpers, REST request utilities), import from `@wordpress/e2e-test-utils-playwright` in your test files — it provides extended `test`, `admin`, `editor`, `pageUtils`, and `requestUtils` fixtures designed for WordPress E2E testing.

## Dependency Scoping Workflow

`CollectScopingStubs`, `ScopePhpDependencies`, `scoper-base.inc.php`, and the autoload generator compose into one pipeline. On a dev-mode `composer install` the hooks fire in order:

1. `pre-autoload-dump` → `ScopePhpDependencies::preAutoloadDump` — pre-creates the not-yet-scoped paths under `extra.scoped-dependencies-dir` so the autoloader dump doesn't error on a fresh clone.
2. Composer dumps the autoloader.
3. `post-autoload-dump` → `CollectScopingStubs` (writes `scoping-exclusions.json`), then `ScopePhpDependencies` (dispatches `scope-php-dependencies:raw` with the `--output-dir` derived from `extra.scoped-dependencies-dir`, then the autoload generator if opted in).
4. `scope-php-dependencies:raw` → `php-scoper add-prefix` via the consumer's `scoper.inc.php`, which loads `scoper-base.inc.php`. The public `composer scope-php-dependencies` (bound to `ScopePhpDependencies::run`) executes the same full pipeline for manual runs.

**Consumer contract for framework packages:** installing any `ahegyes/wp-framework-*` package makes `extra.text-domain` mandatory in the consumer's `composer.json` — the `wp-framework.inc.php` scoper partial rewrites the framework's reserved `wp-framework-*` text domains to it at scope time (`"text-domain": false` is the explicit opt-out for non-plugin consumers with no translation catalog). The reserved literal space is enforced at scope time too: a `wp-framework-*` literal anywhere outside a plain gettext domain argument (including compound domain expressions, named-argument gettext calls, and heredoc/interpolated occurrences) fails the scope run.

Net result: your bundled deps are scoped under your prefix, while declared-catalog symbols (WordPress, WooCommerce) and `Psr\*` stay unprefixed and resolve globally at runtime. With the autoload generator opted in, host code reaches the scoped tree through the single `dependencies/scoper-autoload.php` in `autoload.files` — no per-package PSR-4 wiring.

## Reusable CI Workflows

Reusable GitHub Actions workflows live in `.github/workflows/reusable-*.yml`. Plugins call them via `workflow_call` and compose them into their own pipelines. They only trigger on `workflow_call` — this repo's own CI exercises `reusable-workflow-checks` and `reusable-codeql` through its `workflow-checks` / `codeql` thin callers. The DWS repos pin the reusables to a commit SHA (dependabot proposes bumps); the `@trunk` refs in the examples below are for copy-paste brevity.

| Workflow                              | Purpose                                          | Key Inputs                                                   |
|---------------------------------------|--------------------------------------------------|--------------------------------------------------------------|
| `reusable-php-syntax-check.yml`       | `php -l` matrix across PHP versions              | `project-path`, `php-versions[]`                             |
| `reusable-php-lint.yml`               | Named composer scripts as parallel jobs          | `project-path`, `php-version`, `scripts[]`                   |
| `reusable-scripts-styles-lint.yml`    | ESLint + Stylelint via npm scripts               | `project-path`, `node-version`                               |
| `reusable-phpunit.yml`                | PHPUnit; wp-env startup gated by `needs-wp-env`  | `project-path`, `php-version`, `wp-version`, `needs-wp-env`  |
| `reusable-playwright-e2e.yml`         | Playwright E2E + report upload on failure        | `project-path`, `plugin-slug`, `php-version`                 |
| `reusable-block-json-check.yml`       | Validates block.json against wp.org schema       | `project-path`, `node-version`                              |
| `reusable-supply-chain-audit.yml`     | `composer audit` + `npm audit` (parallel jobs)   | `project-path`, `composer-audit`, `npm-audit`, plus `*-flags` |
| `reusable-release.yml`                | Build → test built artifact → deploy to wp.org   | `plugin-slug`, `project-path`, `php-version` (no secrets)   |
| `reusable-workflow-checks.yml`        | actionlint + zizmor with a blocking SARIF gate   | — (no inputs)                                                |
| `reusable-codeql.yml`                 | CodeQL analysis across a language matrix         | `languages[]`                                                |

Each workflow's `inputs:` block (every input carries a `description:`) is the authoritative reference for its full input set and defaults — the table lists only the commonly-set ones.

**`php-versions[]` vs `php-version`:** `reusable-php-syntax-check.yml` accepts an array because matrixing across PHP versions is the whole point of syntax checking. The other reusables run a single PHP version per call — to test multiple versions, wrap the reusable in your own matrix. This asymmetry is intentional; consolidating either direction would force the wrong shape on the side that doesn't want it.

**`reusable-phpunit.yml` + `needs-wp-env`:** Defaults to `true` (the wp-env + npm ci + start/stop steps run). Set `needs-wp-env: false` for pure-unit suites that don't need a WordPress runtime — skips the Node setup and wp-env lifecycle entirely.

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
  lint-php:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-lint.yml@trunk
    with:
      scripts: '["lint:php:phpcs", "lint:php:phpstan"]'
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

concurrency:
  group: release
  cancel-in-progress: false

jobs:
  release:
    # A reusable workflow can't elevate above the caller's token; grant the GitHub Release scope here.
    permissions:
      contents: write
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-release.yml@trunk
    with:
      plugin-slug: your-plugin-slug
```

No `secrets:` block: the reusable's deploy job runs in the `wp-org-release` environment and reads `SVN_USERNAME` / `SVN_PASSWORD` from the **calling repository's** environment secrets directly (environment secrets cannot be passed through `workflow_call`). Create a `wp-org-release` environment in your plugin repo, store the two secrets there — and only there — and attach whatever deployment-protection rules you want (required reviewers, wait timers, allowed branches); a preflight step fails the deploy with instructions when they are missing.

The workflow enforces two release contracts on the consumer:

- **Main file named after the slug** — `<plugin-slug>.php` at the project root, with a `Version:` header equal to the tag's version; `readme.txt`'s `Stable tag:` must match too. Tags must be `v<major>.<minor>.<patch>` (no leading zeros); the version is taken from the tag exclusively and verified against both files, never stamped in.
- **`.wp-env.json` maps `wp-content/plugins/<plugin-slug>`** — the artifact test remaps exactly that `mappings` key at the built zip (co-mounted plugins, port, and lifecycle scripts survive the per-key merge) and pins the environment to the latest stable WordPress core, so the E2E suite runs against what actually ships on what users actually run.

## Typical Composer Scripts

Add these to your project's `composer.json` for a consistent dev workflow. Composer and npm have no script-`extends`, so these blocks are deliberate copy-paste — keep the PHP/Node floors aligned with this repo when forking them:

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

This repo's own `lint:php` adds a third tool, `composer-require-checker` (declared-dependency completeness, gated on a `composer-require-checker.json`) — add it if your package wants that check.

## Development

This repository is itself tested with PHPUnit. To work on it:

```bash
composer install
composer test           # unit suite
composer test:all       # unit + mutation (Infection)
```

Tests live in `tests/Unit/` (PSR-4 autoloaded as `DeepWebSolutions\Config\Tests\Unit\`) and use real `Composer\Composer` + `Event` instances rather than mocks.

Test fixtures live in `tests/fixtures/<test-name>/` only when the data is multi-line or shared across multiple tests. Inline test data is preferred for small, scenario-specific inputs.
