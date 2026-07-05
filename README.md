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

#### Consumer prerequisites

A consuming project's root `composer.json` must declare `minimum-stability: dev` with `prefer-stable: true`, because this package requires `roave/security-advisories: dev-latest` as a deliberate solver-time CVE gate on every dev install and `phpcompatibility/phpcompatibility-wp: ^3@alpha`. Composer honours stability flags only in the root package.

The root package must also allow `dealerdirect/phpcodesniffer-composer-installer` and `phpstan/extension-installer`; they register the PHPCS standards and discover PHPStan extensions, and a non-interactive install fails when they are not allowed.

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true,
            "phpstan/extension-installer": true
        }
    }
}
```

Reusable CI workflows are referenced directly from GitHub (see [Reusable CI Workflows](#reusable-ci-workflows) below). The DWS repos pin every reusable, including `reusable-release.yml`, to a commit SHA and dependabot proposes bumps; `@trunk` in this README's examples is shorthand for "replace this with a commit SHA pin."

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

WordPress 7.0 stubs resolve out of the box: this package rides `szepeviktor/phpstan-wordpress` at `2.x-dev`, whose `php-stubs/wordpress-stubs: >=6.6.2` constraint admits 7.x stubs (the tagged v2.0.3 still caps below 7.0 — revert to `^2` at the next release). A consumer whose own dependencies cap the stubs (`php-stubs/woocommerce-stubs` does) restores 7.0 analysis with a root-`require-dev` inline alias — root-only, a dependency-declared alias is ignored by the solver — bumping the exact 7.x version as new stubs ship:

```json
{
    "require-dev": {
        "php-stubs/wordpress-stubs": "7.0.0 as 6.9999.0"
    }
}
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

`php/composer/CollectScopingStubs.php` — Composer post-autoload-dump hook that aggregates per-package stubs declarations and writes the unioned symbol set to a JSON file the [php-scoper base config](#php-scoper-base-config) consumes.

Each Composer package in the dep graph (and the consuming project itself) declares the stubs packages or files covering its scoped code's external references via `extra.scoping-stubs` in its own `composer.json`:

```json
{
    "extra": {
        "scoping-stubs": ["php-stubs/wordpress-stubs"]
    }
}
```

Each declaration is either a bare `vendor/package` (the package's stubs files) or an explicit `vendor/package:relative/file.php` (one specific stubs file). For a bare entry the hook reads the package's `autoload.files` plus the conventional `<package>.php`; the FQCNs of every class, interface, trait, enum, function, and constant it finds are unioned into `scoping-exclusions.json`.

A stubs file the package ships but does **not** list in its `autoload.files` needs the explicit form — `vendor/package:relative/file.php` names that one secondary file:

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

The Action Scheduler `as_*` functions need **no consumer declaration at all**: `scoper-base.inc.php` excludes the family with a built-in `/^as_/` `exclude-functions` regex, so they stay unprefixed even when no stubs file declares them. The explicit `woocommerce-packages-stubs.php` entry above remains useful only for the other symbols that file carries (WooCommerce packages internals) — not for `as_*` coverage.

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
| No `extra.scoping-stubs` declarations anywhere in the dep graph | Writes empty exact-symbol exclusion lists (`as_*` stays covered by scoper-base's regex) |
| Declared stubs package not installed                            | Skipped with a console warning, helper continues      |
| Malformed declaration in the PROJECT's own composer.json        | **Throws** — a root typo must not silently shrink the exclusion set |
| Malformed declaration in an installed package                   | Skipped with a console warning (third-party metadata the consumer cannot fix) |

**Action Scheduler exclusion:** `scoper-base.inc.php` excludes the `as_*` public API with a `/^as_/` `exclude-functions` regex, so every consumer that uses the base config keeps the family unprefixed without declaring stubs. Action Scheduler is host-provided at runtime (bundled by WooCommerce or the standalone plugin) and must never be prefixed; the regex survives a consumer dropping `php-stubs/woocommerce-stubs` and covers functions added upstream. The `wp-framework.inc.php` contrib partial additionally installs a scope-time guard that fails the run if a prefixed `as_*` reference ever reaches the scoped output.

The contract is: **whichever package introduces references to external (non-prefixable) symbols is the package that declares the stubs covering them.** A consumer plugin doesn't need to enumerate WP usage — the framework packages it depends on declare `php-stubs/wordpress-stubs` and the helper picks that up automatically. A consumer that integrates with WooCommerce adds `php-stubs/woocommerce-stubs` to its own `composer.json`.

**Trust model:** vendor packages can declare their own `extra.scoping-stubs`, and the helper parses + reads symbol names from those declared files. A malicious vendor could declare custom symbol names that, after merging into `scoping-exclusions.json`, weaken the consumer's scoping (those symbols stay unprefixed and may clash with WP core). This is the standard composer supply-chain trust model — only install vendor packages you trust. The helper does NOT execute anything from the parsed stubs files; it only extracts class, function, and constant declaration names via AST traversal.

**Output overrides (opt-in):** Two environment variables redirect where the exclusion JSON is written. Both are constrained to the project root — they cannot be used to write outside the project tree.

| Env var                            | Default                       | Constraint                                                        |
|------------------------------------|-------------------------------|-------------------------------------------------------------------|
| `SCOPING_EXCLUSIONS_OUTPUT_DIR`    | Project root                  | Must resolve to an existing directory **inside** the project root |
| `SCOPING_EXCLUSIONS_OUTPUT_FILE`   | `scoping-exclusions.json`     | Filename only — `/` and `\` are rejected                          |

#### ScopePhpDependencies

`php/composer/ScopePhpDependencies.php` — Composer hooks for scoping third-party PHP dependencies via [php-scoper](https://github.com/humbug/php-scoper).

This is **opt-in**: it only triggers if `humbug/php-scoper` is installed in your project's dev dependencies and `extra.scoped-dependencies-dir` is declared. The `wordpress-configs` package has php-scoper in its own `require-dev`, but Composer does not install a dependency's dev dependencies into consumers; the pipeline only runs when `humbug/php-scoper` is installed in the consumer project, so add it to your own `require-dev`. Intended for third-party libraries (PDF generators, HTTP clients, DI containers, etc.) that may conflict with other plugins on the same WordPress site.

**Wire it in your project's `composer.json`:**

```json
{
    "require-dev": {
        "humbug/php-scoper": "^0.18"
    },
    "scripts": {
        "pre-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::preAutoloadDump"
        ],
        "post-autoload-dump": [
            "DeepWebSolutions\\Config\\Composer\\CollectScopingStubs::postAutoloadDump",
            "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::postAutoloadDump"
        ],
        "scope-php-dependencies": "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::run"
    },
    "extra": {
        "scoped-dependencies-dir": "dependencies",
        "scoping-prefix": "DeepWebSolutions\\YourPlugin\\Scoped",
        "text-domain": "your-text-domain",
        "scoping-flags": [
            "--ansi"
        ]
    }
}
```

`extra.scoping-flags` is optional; omit it when no extra php-scoper flags are needed. The pipeline owns `--output-dir`/`-o`, `--prefix`, and `--config`, so those flags are rejected if they appear in `extra.scoping-flags`.

`extra.text-domain` is required when the consumer installs any `ahegyes/wp-framework-*` package; see the framework consumer contract below. Set it to your plugin's own text domain — the same value as your plugin header's `Text Domain` (conventionally the plugin slug) — so the rewritten framework strings resolve against the catalog your plugin actually loads; a value that disagrees with the header leaves those strings untranslatable. Use `"text-domain": false` only for non-plugin consumers with no translation catalog.

The pipeline constructs this command itself:

```bash
PHP_BINARY vendor/bin/php-scoper add-prefix \
  --prefix=<extra.scoping-prefix> \
  --config=<project>/scoper.inc.php \
  --output-dir=<project>/<extra.scoped-dependencies-dir> \
  --force \
  --quiet \
  [extra.scoping-flags...]
```

`extra.scoping-prefix` is required whenever scoping runs and must be a non-empty PHP namespace prefix. `scoper.inc.php` must exist at the project root; missing config throws because php-scoper without a config would scope the whole current working directory.

| Hook / script                | What It Does                                                                                                              |
|------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| `preAutoloadDump`            | Ensures scoped directories/files exist before autoloader runs                                                             |
| `postAutoloadDump`           | In dev mode, when php-scoper is installed and `extra.scoped-dependencies-dir` is declared, runs php-scoper and generates `dependencies/scoper-autoload.php` |
| `run` (`composer scope-php-dependencies`) | Same pipeline for manual runs; throws if php-scoper is missing or required scoping config is incomplete |

#### Autoload generator

`php/composer/Internal/GenerateScopedAutoload.php` — runs after every successful scope and emits a single `dependencies/scoper-autoload.php` that registers every scoped package's `autoload.psr-4` and `autoload.classmap` entries on a dedicated Composer `ClassLoader` it creates and registers (independent of whichever loader the host registered first), and `require_once`s each `autoload.files` entry. Consumers reference this one file via their root `autoload.files` instead of hand-declaring a PSR-4 entry per scoped package.

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

That's it — adding a new scoped package never requires editing the host `composer.json` again. The generator reads each scoped package's `composer.json` and emits the corresponding `addPsr4()` / `addClassMap()` calls and `require_once` statements at the next scoping run. The output is purely a function of the scoped tree.

The generator reads packages from both output layouts php-scoper produces: `dependencies/<vendor>/<pkg>/` (finders spanning several vendor namespaces) and the flattened `dependencies/<pkg>/` (finders covering a single vendor namespace, whose shared `vendor/<vendor>/` segment php-scoper collapses).

| Behavior          | When                                                                                                                                                        |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Generator runs    | Every successful scoping run                                                                                                                                |
| Throws            | No scoped package is found under the output dir (an empty generated autoload must never be silent), a scoped package declares `autoload.exclude-from-classmap` or `autoload.psr-0` (unsupported), or a classmap class resolves to more than one file |

### php-scoper Base Config

`php/php-scoper/scoper-base.inc.php` — a catalog-agnostic base php-scoper config for the WordPress ecosystem. Returns a closure that builds a complete config with sensible defaults.

**What it handles:**

- Reads `scoping-exclusions.json` (generated by CollectScopingStubs) and excludes those classes/functions/constants from scoping
- Excludes Action Scheduler's `as_*` function family by regex because it is host-provided and must stay unprefixed
- Excludes the `Psr\` namespace from scoping (PSR interfaces are designed for cross-plugin interop; scoping them per-plugin would create incompatible interface declarations)
- Provides a default patcher that strips the scoping prefix from any excluded-symbol references inside scoped files

**Wire it in your project's `scoper.inc.php`:**

```php
<?php declare( strict_types = 1 );

$vendor_dir = __DIR__ . '/vendor';

$build_config = require $vendor_dir . '/ahegyes/wordpress-configs/php/php-scoper/scoper-base.inc.php';
$php_di = ( require $vendor_dir . '/ahegyes/wordpress-configs/php/php-scoper/contrib/php-di.inc.php' )( $vendor_dir );
$wp_framework = ( require $vendor_dir . '/ahegyes/wordpress-configs/php/php-scoper/contrib/wp-framework.inc.php' )( $vendor_dir, __DIR__ );

return $build_config(
    array(
        'finders'       => array_merge(
            $php_di['finders'],
            $wp_framework['finders'],
        ),
        'exclude_files' => $php_di['exclude_files'],
        'patchers'      => $wp_framework['patchers'],
    )
);
```

The framework partial returns both `finders` and `patchers`; forward both into `scoper-base.inc.php`. The patchers rewrite reserved framework text domains to the consumer's `extra.text-domain` and guard against prefixed Action Scheduler references.

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

### Distribution Ignore

`.distignore` — copy to your project root and extend with project-specific source-only paths before using `reusable-release.yml`. The release workflow requires the file to exist so `wp dist-archive` has explicit exclusions for tests, package-manager manifests, CI, IDE files, and source-only tooling.

### Editor Config

`.editorconfig` — copy to your project root or reference in your editor's settings.

| File Type                   | Indent Style | Indent Size                         |
|-----------------------------|--------------|-------------------------------------|
| `*` (default)               | Tabs         | —                                   |
| `*.yml`, `*.yaml`, `*.json` | Spaces       | 2                                   |
| `*.md`                      | Tabs         | — (trailing whitespace preserved)   |
| `*.txt`                     | Tabs         | — (CRLF line endings)               |

### Node Baselines

Shared baseline configurations for Node-ecosystem tooling — one central config per tool, extended and tweaked per project (the same central-defaults-plus-overrides pattern `@wordpress/scripts` itself uses). Live in `node/` (parallel to `php/`). Plugins compose each baseline using the tool-specific pattern shown below.

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

`node/eslint.config.base.mjs` — flat-config wrapping `@wordpress/eslint-plugin`'s recommended preset, with the plugin's `test-unit` / `test-playwright` presets scoped to project test globs (`**/test/**`, `**/*.test.*`, `tests/e2e/**`). Requires `@wordpress/eslint-plugin` v25+ and ESLint v9+ (the flat format is the only one ESLint v10 supports).

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

`node/stylelint.config.base.js` — extends `@wordpress/stylelint-config/scss` and adds `ignoreFiles` for generated/vendored trees. Do not load this base via `extends`: Stylelint ignores the `ignoreFiles` property of an extended config entirely. Use the spread pattern shown below; the globs then live in the consumer's root config and resolve against the consumer project.

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
3. `post-autoload-dump` → `CollectScopingStubs` (writes `scoping-exclusions.json`), then `ScopePhpDependencies` (runs php-scoper with `--prefix` from `extra.scoping-prefix`, `--config=<project>/scoper.inc.php`, `--output-dir` from `extra.scoped-dependencies-dir`, and optional `extra.scoping-flags`, then runs the autoload generator).
4. `composer scope-php-dependencies` (bound to `ScopePhpDependencies::run`) executes the same full pipeline for manual runs.

**Consumer contract for framework packages:** installing any `ahegyes/wp-framework-*` package makes `extra.text-domain` mandatory in the consumer's `composer.json` — the `wp-framework.inc.php` scoper partial rewrites the framework's reserved `wp-framework-*` text domains to it at scope time (`"text-domain": false` is the explicit opt-out for non-plugin consumers with no translation catalog). The reserved literal space is enforced at scope time too: a `wp-framework-*` literal anywhere outside a plain gettext domain argument (including compound domain expressions, named-argument gettext calls, and heredoc/interpolated occurrences) fails the scope run.

Net result: your bundled deps are scoped under your prefix, while declared external symbols (WordPress, WooCommerce) and `Psr\*` stay unprefixed and resolve globally at runtime. Consumer code reaches the scoped tree through the single `dependencies/scoper-autoload.php` in `autoload.files` — no per-package PSR-4 wiring.

## Reusable CI Workflows

Reusable GitHub Actions workflows live in `.github/workflows/reusable-*.yml`. Plugins call them via `workflow_call` and compose them into their own pipelines. They only trigger on `workflow_call` — this repo's own CI exercises `reusable-workflow-checks` and `reusable-codeql` through its `workflow-checks` / `codeql` thin callers. The DWS repos pin every reusable, including `reusable-release.yml`, to a commit SHA and dependabot proposes bumps; the `@trunk` refs in the examples below are shorthand for "replace this with a commit SHA pin."

| Workflow                              | Purpose                                          | Key Inputs                                                   |
|---------------------------------------|--------------------------------------------------|--------------------------------------------------------------|
| `reusable-php-syntax-check.yml`       | `php -l` matrix across PHP versions              | `project-path`, `php-versions[]`                             |
| `reusable-php-lint.yml`               | Named composer scripts as parallel jobs          | `project-path`, `php-version`, `scripts[]`                   |
| `reusable-scripts-styles-lint.yml`    | ESLint + Stylelint via npm scripts               | `project-path`, `node-version`                               |
| `reusable-phpunit.yml`                | PHPUnit; wp-env startup gated by `needs-wp-env`  | `project-path`, `php-version`, `wp-version`, `needs-wp-env`  |
| `reusable-playwright-e2e.yml`         | Playwright E2E + report upload on failure        | `project-path`, `artifact-slug`, `php-version`               |
| `reusable-block-json-check.yml`       | Validates block.json against wp.org schema       | `project-path`, `node-version`                              |
| `reusable-supply-chain-audit.yml`     | `composer audit` + `npm audit` (parallel jobs)   | `project-path`, `composer-audit`, `npm-audit`, plus `*-flags` |
| `reusable-release.yml`                | Build zip/assets → verify/test zip → deploy verified artifacts to wp.org | `plugin-slug`, `project-path`, `scoped-dependencies-dir`, `php-version`, `requires-scoped-dependencies`, `plugin-check`, `generate-pot`, `pot-domain` (no secrets) |
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
      artifact-slug: your-artifact-slug
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
    # Pin release like the other reusables; dependabot bumps this SHA.
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-release.yml@<sha>
    with:
      plugin-slug: your-plugin-slug
```

No `secrets:` block: the reusable's deploy job runs in the `wp-org-release` environment and reads `SVN_USERNAME` / `SVN_PASSWORD` from the **calling repository's** environment secrets directly (environment secrets cannot be passed through `workflow_call`). Create a `wp-org-release` environment in your plugin repo, store the two secrets there — and only there — and attach whatever deployment-protection rules you want (required reviewers, wait timers, allowed branches); a preflight step fails the deploy with instructions when they are missing.

The reusable is three jobs: `build` (read-only, no environment, no secrets) builds the zip and `.wordpress-org` assets artifact, asserts archive contents, runs Plugin Check, and outputs the version plus zip SHA-256; `test` (read-only, no environment, no secrets) checks out the source E2E harness, verifies the zip digest, mounts the extracted zip in wp-env, and runs Playwright; `deploy` (tag-only, `wp-org-release`, `contents: write`) downloads the zip/assets artifacts, verifies the digest again, fresh-extracts `./publish/<plugin-slug>`, publishes to SVN, and attaches the same zip to the GitHub Release. The SVN credentials only exist in `deploy`, which performs no checkout or dependency install.

The workflow enforces two release contracts on the consumer:

- **Main file named after the slug** — `<plugin-slug>.php` at the project root, with a `Version:` header equal to the tag's version; `readme.txt`'s `Stable tag:` must match too. Tags must be `v<major>.<minor>.<patch>` (no leading zeros); the version is taken from the tag exclusively and verified against both files, never stamped in.
- **`.wp-env.json` maps `wp-content/plugins/<plugin-slug>`** — the artifact test remaps exactly that `mappings` key at the built zip (co-mounted plugins, port, and lifecycle scripts survive the per-key merge) and pins the environment to the latest stable WordPress core, so the E2E suite runs against what actually ships on what users actually run.

`scoped-dependencies-dir` defaults to `dependencies`. `requires-scoped-dependencies` defaults to `true` and asserts `<scoped-dependencies-dir>/scoper-autoload.php` plus scoped package files in the archive and mounted test artifact; set it to `false` for plugins that do not run the scoping pipeline. `plugin-check` defaults to `true` and runs WordPress Plugin Check against the built zip's extracted tree. `generate-pot` defaults to `true`: release regenerates `languages/<domain>.pot` before archive creation, so the shipped zip always carries a current catalog — set it to `false` only for plugins with no translatable strings. The domain derives from `plugin-slug` (the wp.org convention Plugin Check enforces); `pot-domain` overrides it and accepts lowercase letters, numbers, underscores, and hyphens. When scoped dependencies are required and the scoped tree carries strings under the consumer domain, the step warns if the generated POT has no scoped-source references.

## Typical Composer Scripts

Add these to your project's `composer.json` for a consistent dev workflow. Composer and npm have no script-`extends`, so these blocks are deliberate copy-paste — keep the PHP/Node floors aligned with this repo when forking them:

```json
{
    "scripts": {
        "format:php": "phpcbf --standard=./.phpcs.xml --basepath=. ./ -v",
        "i18n:make-pot": "wp i18n make-pot . languages/your-text-domain.pot",
        "lint:php": ["@lint:php:phpcs", "@lint:php:phpstan"],
        "lint:php:phpcs": "phpcs --standard=./.phpcs.xml --basepath=. ./ -v",
        "lint:php:phpstan": "phpstan analyse -c ./.phpstan.neon -v --memory-limit=1G"
    }
}
```

This repo's own `lint:php` adds a third tool, `composer-require-checker` (declared-dependency completeness, gated on a `composer-require-checker.json`) — add it if your package wants that check.

Release regenerates the POT on every tag build; the script stays useful for local catalog refreshes.

## Development

This repository is itself tested with PHPUnit. To work on it:

```bash
composer install
composer test           # unit suite
composer test:all       # unit + mutation (Infection)
```

Tests live in `tests/Unit/` (PSR-4 autoloaded as `DeepWebSolutions\Config\Tests\Unit\`) and use real `Composer\Composer` + `Event` instances rather than mocks.

Test fixtures live in `tests/fixtures/<test-name>/` only when the data is multi-line or shared across multiple tests. Inline test data is preferred for small, scenario-specific inputs.
