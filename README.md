# WordPress Configs

A collection of shared configuration files for WordPress projects. Provides base configs for PHPCS and PHPStan, a php-scoper pipeline that prefixes the packages a plugin bundles and their dependencies, and a transitive `roave/security-advisories` install that fails a dev-mode `composer install` on any known CVE in the dep graph.

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

Reusable CI workflows are referenced directly from GitHub (see [Reusable CI Workflows](#reusable-ci-workflows) below). The DWS repos pin every reusable, including `reusable-release.yml`, to a commit SHA and dependabot proposes bumps; replace `<sha>` in this README's examples with a full commit SHA.

## What's Included

### Quality Assurance Configs

Base configuration files that your project extends. Create thin project-level config files that reference these.

#### PHPCS (WordPress Coding Standards)

Two profiles share `php/quality-assurance/phpcs.base.dist.xml`, which runs the full `WordPress` standard and PHPCompatibilityWP:

| Profile | File | Lints |
| --- | --- | --- |
| Production | `php/quality-assurance/phpcs.dist.xml` | Everything except `tests/` |
| Tests | `php/quality-assurance/phpcs.tests.dist.xml` | `tests/`, without the docblock and WordPress-runtime sniffs |

Both profiles skip `bin/`, `vendor/`, `vendor-prefixed/`, `node_modules/` and generated files. The patterns match case-sensitively, so a `src/Vendor/` source directory is still linted. An `index.php` is linted like any other file, except that its file comment may be a `//` line, so a silence-is-golden placeholder stays clean.

Create a `phpcs.dist.xml` in your project:

```xml
<?xml version="1.0"?>
<ruleset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/squizlabs/php_codesniffer/phpcs.xsd">
    <!-- Extend the shared production profile. -->
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

Create a `phpcs.tests.dist.xml` beside it for `tests/`:

```xml
<?xml version="1.0"?>
<ruleset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/squizlabs/php_codesniffer/phpcs.xsd">
    <!-- Extend the shared tests profile. -->
    <rule ref="./vendor/ahegyes/wordpress-configs/php/quality-assurance/phpcs.tests.dist.xml"/>

    <!-- Check that the proper text domain(s) is used everywhere. -->
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="your-text-domain"/>
            </property>
        </properties>
    </rule>
</ruleset>
```

Keep the leading `./` in each `ref`. PHPCS does not treat a bare `vendor/…` reference as a path, so any exclude pattern, severity or property inside that `<rule>` element is silently ignored.

The profiles set the PHP and WordPress floors, and an included ruleset's `<config>` wins over the including ruleset's own. A project with lower floors passes them on the command line instead:

```sh
vendor/bin/phpcs --standard=./phpcs.dist.xml --runtime-set testVersion 8.3- --runtime-set minimum_wp_version 6.8 --basepath=. ./ -v
```

Lint production code:

```sh
vendor/bin/phpcs --standard=./phpcs.dist.xml --basepath=. ./ -v
```

Lint the tests:

```sh
vendor/bin/phpcs --standard=./phpcs.tests.dist.xml --basepath=. ./tests -v
```

Fix production code automatically:

```sh
vendor/bin/phpcbf --standard=./phpcs.dist.xml --basepath=. ./ -v
```

Fix the tests automatically:

```sh
vendor/bin/phpcbf --standard=./phpcs.tests.dist.xml --basepath=. ./tests -v
```

#### PHPStan (Static Analysis)

`php/quality-assurance/phpstan.dist.neon` is the shared profile. It includes `phpstan.base.dist.neon` and adds the minimum WordPress version that `johnbillion/wp-compat` checks calls against:

| File | Holds |
| --- | --- |
| `phpstan.base.dist.neon` | The rules that need no WordPress: the level, the missing `#[\Override]` check, the strict-rules settings and the nested vendor exclusions |
| `phpstan.dist.neon` | The base, plus the minimum WordPress version |
| `phpstan.dist.neon.php` | Opt-in discovery of a plugin's conventional paths |

Code under a nested `vendor/` or `vendor-prefixed/` directory is scanned but not analyzed, so the code that uses it still resolves. This package requires `php-stubs/wordpress-stubs`, so a consumer does not require it itself. A project that scans or declares `php-stubs/woocommerce-stubs`, such as a WooCommerce extension, requires it itself.

Create a `phpstan.dist.neon` in your project:

```neon
includes:
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon

parameters:
    paths:
        - my-plugin.php
        - src
    scanFiles:
        - vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php # For a WooCommerce extension.
```

A project with a different minimum WordPress version sets `WPCompat.requiresAtLeast` in its own `parameters`, which win over the profile's.

Run:

```sh
vendor/bin/phpstan analyse -v --memory-limit=1G
```

To add a plugin's conventional paths without listing them, include `phpstan.dist.neon.php` as well. It adds `functions-bootstrap.php`, `functions.php`, `uninstall.php`, `src/`, `includes/`, `models/`, `blocks/` and `templates/` when they exist in the directory PHPStan runs from, and scans `vendor-prefixed/` there. The plugin's entry file is not among them, so list it under `paths`:

```neon
includes:
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon.php

parameters:
    paths:
        - my-plugin.php
```

To analyze code with no WordPress loaded, include `phpstan.base.dist.neon` alone and list `szepeviktor/phpstan-wordpress`, `johnbillion/wp-compat` and `swissspidy/phpstan-no-private` under `extra.phpstan/extension-installer.ignore` in the root `composer.json`, so a WordPress function is an unknown symbol. A WordPress config in the same repository then includes those extensions itself, because PHPStan rejects the minimum WordPress version when wp-compat is not loaded:

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon
    - vendor/johnbillion/wp-compat/extension.neon
    - vendor/swissspidy/phpstan-no-private/rules.neon
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon
```

**Included PHPStan extensions** (auto-installed):

| Extension                          | Purpose                                    |
|------------------------------------|--------------------------------------------|
| `phpstan-deprecation-rules`        | Detects usage of deprecated code           |
| `phpstan-strict-rules`             | Additional strict type checks              |
| `szepeviktor/phpstan-wordpress`    | WordPress function signatures and types    |
| `johnbillion/wp-compat`            | WordPress version compatibility checks     |
| `swissspidy/phpstan-no-private`    | Flags usage of private WordPress APIs      |

### Dependency scoping

`php/php-scoper/scoper-base.inc.php` and `php/composer/ScopePhpDependencies.php` prefix the packages a WordPress plugin bundles with the plugin's own namespace, so plugins that bundle different versions of the same library run side by side.

Require php-scoper in the plugin itself, because Composer does not install this package's dev requirements:

```json
{
  "require-dev": {
    "humbug/php-scoper": "^0.18"
  }
}
```

Declare the scoping prefix, load the generated autoloader, and scope after every development install:

```json
{
  "autoload": {
    "files": [
      "vendor-prefixed/scoper-autoload.php"
    ]
  },
  "scripts": {
    "post-autoload-dump": [
      "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::postAutoloadDump"
    ]
  },
  "extra": {
    "scoping-prefix": "YourVendor\\YourPlugin\\Scoped"
  }
}
```

Create `scoper.inc.php` at the project root and name the packages to scope:

```php
<?php declare( strict_types=1 );

return ( require __DIR__ . '/vendor/ahegyes/wordpress-configs/php/php-scoper/scoper-base.inc.php' )( __DIR__, 'dompdf/dompdf', 'php-di/php-di' );
```

A name is a package name or an `fnmatch()` pattern, such as `'your-vendor/your-library-*'`. The runtime dependencies of each named package follow from Composer's `installed.json`, through `require` and never `require-dev`, so naming a library scopes what it needs. A pattern matches every installed package, development tools included, so keep it as narrow as the packages it means. A name that starts with `!` keeps the packages it matches unscoped, such as a polyfill whose global functions must stay reachable (`'!symfony/polyfill-php80'`), and the symbols they declare stay global too. Keep the scoped packages in `require-dev`, so a production install never loads their unprefixed copies, and require an unscoped package in `require`, so a production install still ships it.

A scope run:

- copies every file of each scoped package to `vendor-prefixed/<vendor>/<package>/`, after emptying `vendor-prefixed/` and refusing one that is a symbolic link;
- leaves global the WordPress symbols of `sniccowp/php-scoper-wordpress-excludes`, Action Scheduler (`as_*` and `ActionScheduler*`), every symbol of the stubs files the project or a scoped package declares under `extra.scoping-stubs`, and their namespaces;
- prefixes the strings that start with a scoped package's own namespace, which libraries use to build class names;
- applies the recipe of each scoped package that has one in `php/php-scoper/recipes/<vendor>/<package>.inc.php`;
- rewrites the text domain a scoped package declares under `extra.scoping-text-domain` to the project's `extra.text-domain`;
- fails, naming each one, when the scoped code references a prefixed name it does not declare: a host symbol no stubs file declares, a dependency outside the scoped packages, or a string php-scoper took for a class name;
- writes `vendor-prefixed/scoper-autoload.php`, a class map over each scoped package's `psr-4`, `psr-0` and `classmap` paths that honors `exclude-from-classmap`, followed by its `files` entries.

The run also fails when `extra.scoping-prefix` is missing or empty, when a name matches no installed package, when a scoped package requires one that is not installed, when a declared stubs file is missing, when a scoped package declares a text domain and the project declares none, when a declared text domain survives outside a string literal of its own, and when a recipe's target is gone.

The project or a scoped package declares the stubs files of the host symbols the scoped code calls, each as `./path/to/file.php` relative to its `composer.json`, as `vendor/package` for that package's `<package>.php`, or as `vendor/package:path/to/file.php`:

```json
{
  "extra": {
    "scoping-stubs": [
      "php-stubs/woocommerce-stubs",
      "php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php",
      "./stubs/host-plugin.php"
    ]
  }
}
```

A package whose strings take the host plugin's text domain declares the domain it uses, and the project declares its own:

```json
{
  "extra": {
    "scoping-text-domain": "your-library"
  }
}
```

`scoper.inc.php` returns an ordinary php-scoper config, so a project can add exclusions or patchers before returning it. Write namespace exclusions as anchored regular expressions, such as `'/^YourVendor\\\\Name(?:\\\\|$)/i'`, because php-scoper matches a plain namespace string anywhere in a name.

A recipe returns php-scoper settings for one package, with paths relative to it: `exclude-files`, anchored `exclude-namespaces`, and `replacements`, a map of search to replace strings per file of the scoped code, in which `{prefix}` stands for the scoping prefix. A replacement whose search string is missing fails the run, so a library update that invalidates a recipe surfaces when scoping.

`postAutoloadDump` scopes only in development mode, so a production install keeps the scoped output it ships; `composer dump-autoload` scopes again by hand.

### Distribution Ignore

`.distignore` — copy to your project root and extend with project-specific source-only paths before using `reusable-release.yml`. The release workflow requires the file to exist so `wp dist-archive` has explicit exclusions for tests, package-manager manifests, CI, IDE files, source-only tooling such as every `phpcs*.xml*` ruleset, and secret material (`.env*`, root-anchored `*.pem`/`*.key`, `id_rsa*`, `.npmrc`, `auth.json`).

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

The package declares the tools behind the baselines as `>=` peer dependencies, floored at the versions it is tested against: `@wordpress/scripts`, `@wordpress/eslint-plugin`, `@wordpress/stylelint-config`, `@wordpress/postcss-plugins-preset` and `@playwright/test`. npm installs missing peers automatically; stricter package managers need them declared explicitly.

The bare `@ahegyes/wordpress-configs/node/...` require resolves through `node_modules`, not `vendor/`, so the package must also be installed on the npm side — a git devDependency pinned to a commit SHA:

```json
{
    "devDependencies": {
        "@ahegyes/wordpress-configs": "git+https://github.com/ahegyes/wordpress-configs.git#<commit-sha>"
    }
}
```

#### TypeScript

`node/tsconfig.base.json` — assumes TypeScript 6+ and states only deltas from its defaults (which already provide strict mode, `bundler` resolution, and a latest-ES target that floats with the compiler): `react-jsx` for blocks plus a few extra checks (`noImplicitReturns`, `noFallthroughCasesInSwitch`, `isolatedModules`, `resolveJsonModule`, `skipLibCheck`, `noEmit`).

Create a `tsconfig.json` in your project:

```json
{
    "extends": "@ahegyes/wordpress-configs/node/tsconfig.base.json",
    "include": ["client/**/*"],
    "exclude": ["assets/**", "node_modules/**", "vendor/**"]
}
```

#### ESLint

`node/eslint.config.base.mjs` — flat-config wrapping `@wordpress/eslint-plugin`'s recommended preset, with the plugin's `test-unit` / `test-playwright` presets scoped to project test globs (`**/test/**`, `**/*.test.*` script files, `tests/e2e/**`). The `*.test.*` glob names the script extensions, so test snapshots and JSON fixtures are not linted as scripts.

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

`node/stylelint.config.base.js` — extends `@wordpress/stylelint-config/scss`, adds `ignoreFiles` for generated/vendored trees, reports `stylelint-disable` comments that are needless, lack a `-- reason` or name a rule that does not apply, and turns off `selector-class-pattern` so BEM-style `__element` classes pass. Do not load this base via `extends`: Stylelint ignores the `ignoreFiles` property of an extended config entirely. Use the spread pattern shown below; the globs then live in the consumer's root config and resolve against the consumer project.

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

#### PostCSS

`node/postcss.config.base.js` — a factory over `@wordpress/postcss-plugins-preset` that adds the `cssnano` minifier to production builds: the chain `@wordpress/scripts` uses when a project has no PostCSS config, which any project config switches off. A project that needs another PostCSS plugin appends it to this chain and keeps the minifier.

Create a `postcss.config.js` in your project:

```js
module.exports = require('@ahegyes/wordpress-configs/node/postcss.config.base.js');
```

#### Playwright

`node/playwright.config.base.js` — a factory that takes the project's wp-env `port` and returns `@wordpress/scripts/config/playwright.config.js` (the canonical Playwright config from `@wordpress/scripts`) with `testDir` set to `tests/e2e/` and artifacts under `tests/.cache/artifacts/`. Its `webServer` is the `@wordpress/scripts` one on that port, which runs `npm run wp-env start`; a project that starts wp-env through its own script spreads `webServer` and sets `command`. It sets `WP_BASE_URL` and `WP_ARTIFACTS_PATH` before loading the `@wordpress/scripts` config, which reads them only once; an exported value wins. Inherits everything else: viewport, headless Chromium, screenshots on failure, retries in CI.

Create a `playwright.config.js` in your project, passing the `port` from `.wp-env.json`:

```js
const { defineConfig } = require('@playwright/test');
const baseConfig = require('@ahegyes/wordpress-configs/node/playwright.config.base.js')({ port: 8811 });

module.exports = defineConfig({
    ...baseConfig,
    use: {
        ...baseConfig.use,
        // Plugin-specific overrides go here — spread nested keys (use, webServer,
        // projects) individually, or the top-level spread drops the WP defaults.
    },
});
```

For test fixtures (admin login, block editor helpers, REST request utilities), import from `@wordpress/e2e-test-utils-playwright` in your test files — it provides extended `test`, `admin`, `editor`, `pageUtils`, and `requestUtils` fixtures designed for WordPress E2E testing. For accessibility scans, add `@axe-core/playwright` to the project's devDependencies and import `AxeBuilder` from it.

## Reusable CI Workflows

Reusable GitHub Actions workflows live in `.github/workflows/reusable-*.yml`. Plugins call them via `workflow_call` and compose them into their own pipelines. They only trigger on `workflow_call` — this repo's own CI exercises `reusable-workflow-checks` and `reusable-codeql` through its `workflow-checks` / `codeql` thin callers. The DWS repos pin every reusable, including `reusable-release.yml`, to a commit SHA and dependabot proposes bumps; replace `<sha>` in the examples below with a full commit SHA.

| Workflow                              | Purpose                                          | Key Inputs                                                   |
|---------------------------------------|--------------------------------------------------|--------------------------------------------------------------|
| `reusable-php-syntax-check.yml`       | `php -l` matrix across PHP versions              | `project-path`, `php-versions[]`, `paths[]`                  |
| `reusable-php-lint.yml`               | `composer validate --strict` and named composer scripts, each a parallel job | `project-path`, `php-version`, `scripts[]`                   |
| `reusable-scripts-styles-lint.yml`    | Named npm scripts, such as ESLint and Stylelint, each a parallel job | `project-path`, `scripts[]`                    |
| `reusable-phpunit.yml`                | PHPUnit; wp-env startup gated by `needs-wp-env`  | `project-path`, `php-version`, `wp-version`, `needs-wp-env`, `multisite` |
| `reusable-playwright-e2e.yml`         | Playwright E2E + report upload on failure        | `project-path`, `artifact-slug`, `php-version`               |
| `reusable-block-json-check.yml`       | Validates block.json against wp.org schema       | `project-path`                                               |
| `reusable-plugin-check.yml`           | WordPress Plugin Check against a built plugin directory | `artifact`, `plugin-slug`, `php-version`, `wp-org`     |
| `reusable-supply-chain-audit.yml`     | `composer audit` and `npm audit` of the committed lockfiles (parallel jobs) | `project-path`, `composer-audit`, `npm-audit`, `fail-on-findings`, plus `*-flags` |
| `reusable-release.yml`                | Build the zip, test it in wp-env, verify provenance, and publish a GitHub release, with optional deployment to wp.org | `plugin-slug`, `project-path`, `entry-file`, `php-version`, `wp-env-config-file`, `wp-org`, `required-workflows`, `publish` (no secrets) |
| `reusable-workflow-checks.yml`        | actionlint + zizmor with a blocking SARIF gate   | — (no inputs)                                                |
| `reusable-codeql.yml`                 | CodeQL analysis across a language matrix         | `languages[]`                                                |

Each workflow's `inputs:` block (every input carries a `description:`) is the authoritative reference for its full input set and defaults — the table lists only the commonly-set ones.

**`paths[]`:** narrows the check to the files that must parse on an older PHP, such as by-path files loaded before a PHP version gate: `php-versions: '["7.4"]'` with `paths: '["uninstall.php", "vendor-prefixed/ahegyes/wp-framework-bootstrap"]'`. A path list that matches no PHP file fails the check instead of passing it empty.

**`php-versions[]` vs `php-version`:** `reusable-php-syntax-check.yml` accepts an array because matrixing across PHP versions is the whole point of syntax checking. The other reusables run a single PHP version per call — to test multiple versions, wrap the reusable in your own matrix. This asymmetry is intentional; consolidating either direction would force the wrong shape on the side that doesn't want it.

**`reusable-phpunit.yml` and `needs-wp-env`:** Defaults to `true`: the workflow runs `npm ci` and starts and stops the project's own `@wordpress/env`, so the consumer commits a `package-lock.json` that declares it, and the locked version is the one that runs. `reusable-playwright-e2e.yml` and the release test do the same. Set `needs-wp-env: false` for pure-unit suites that don't need a WordPress runtime — skips the Node setup and wp-env lifecycle entirely. `multisite: true` starts the environment as a network by merging `"multisite": true` into the config's override file, because wp-env reads that setting only from a config file.

**Node and PHP versions:** `reusable-scripts-styles-lint.yml`, `reusable-phpunit.yml` and `reusable-playwright-e2e.yml` install the Node version the project's `package.json` declares, in `engines.node` or in the `volta` or `devEngines` field `actions/setup-node` reads, and fail when it declares none. `reusable-phpunit.yml` and `reusable-playwright-e2e.yml` also pass `php-version` to wp-env as `WP_ENV_PHP_VERSION`, which wins over the `phpVersion` of its config files.

**`reusable-supply-chain-audit.yml` and `fail-on-findings`:** `false` turns advisories and abandoned packages into warnings. A package on a Composer filter list whose policy fails the audit, such as the malware list, still fails it, and the error names it.

**`reusable-plugin-check.yml`:** checks a built plugin directory, uploaded as an artifact earlier in the calling workflow, with WordPress Plugin Check in a fresh wp-env, after activating the plugin on `php-version`. The default GitHub profile skips `plugin_updater` and `plugin_readme`, which report two by-design traits of a GitHub-distributed build, its `Update URI` header and its missing `readme.txt`; `wp-org: true` runs the full set. Plugin Check skips `vendor-prefixed/`, so scoped dependencies are checked at their source instead. The job fails on any error, while warnings only annotate. The action installs the newest Plugin Check release from wp.org, not a pinned one.

### Plugin orchestrators

Plugins typically split their CI into concern-focused workflows that call the reusables.

**`.github/workflows/quality.yml`** — runs on every push/PR:

```yaml
name: Quality
on: [push, pull_request]

jobs:
  syntax:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-syntax-check.yml@<sha>
    with:
      php-versions: '["8.5","8.6"]'
  lint-php:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-lint.yml@<sha>
    with:
      scripts: '["lint:php:phpcs", "lint:php:phpcs:tests", "lint:php:phpstan"]'
  block-json:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-block-json-check.yml@<sha>
  lint-scripts-styles:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-scripts-styles-lint.yml@<sha>
    with:
      scripts: '["lint:scripts", "lint:styles"]'
```

**`.github/workflows/tests.yml`** — runs on every push/PR:

```yaml
name: Tests
on: [push, pull_request]

jobs:
  phpunit:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-phpunit.yml@<sha>
  e2e:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-playwright-e2e.yml@<sha>
    with:
      artifact-slug: your-artifact-slug
```

**`.github/workflows/workflow-checks.yml`** — lints and security-scans the workflows themselves:

```yaml
name: Workflow Checks
on:
  push:
    branches: [trunk]
  pull_request:
    paths: ['.github/workflows/**']

jobs:
  checks:
    # A reusable workflow can't elevate above the caller's token — granting less than the called
    # jobs declare fails the call at startup (a startup_failure, invisible in PR checks).
    permissions:
      contents: read
      security-events: write
      actions: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-workflow-checks.yml@<sha>
  codeql:
    permissions:
      contents: read
      security-events: write
      actions: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-codeql.yml@<sha>
    with:
      languages: '["actions", "javascript-typescript"]'
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
    # A reusable workflow can't elevate above the caller's token; grant the scopes its jobs declare here.
    permissions:
      actions: read
      contents: write
    # Pin release like the other reusables; dependabot bumps this SHA.
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-release.yml@<sha>
    with:
      plugin-slug: your-plugin-slug
```

The reusable runs five jobs:

- `build` (read-only, no secrets) checks the release metadata, installs Composer and npm dependencies on the Node version `package.json` declares, regenerates the POT, prunes to production dependencies, builds the zip, asserts its contents, runs Plugin Check, and outputs the version and the zip's SHA-256.
- `test` (read-only, no secrets) mounts the extracted zip in the project's own wp-env, activates it with `wp plugin activate`, requests the home page, and runs `npm run test:e2e` when the project defines it, after installing Chromium when `@playwright/test` is installed.
- `provenance` (`actions: read`) requires a successful push run of every file in `required-workflows`, by default `.github/workflows/quality.yml` and `.github/workflows/tests.yml`, on the released commit, so a release reuses the suites proven there; tag a commit once they pass. It runs only when `publish` is on.
- `release` (`contents: write`) verifies the zip's digest and creates the GitHub release with the zip attached. Its notes are the version's section of `CHANGELOG.md` when that file exists, and GitHub's generated notes otherwise.
- `wp-org` runs only with `wp-org: true`, after `release`. It deploys the same zip and the `.wordpress-org` assets to wp.org SVN from the `wp-org-release` environment, the only job that holds the SVN credentials.

`publish: false` skips `provenance`, `release` and `wp-org` and runs `build` and `test` on any ref, which exercises the release path without a tag.

No `secrets:` block: the `wp-org` job reads `SVN_USERNAME` / `SVN_PASSWORD` from the **calling repository's** `wp-org-release` environment secrets directly (environment secrets cannot be passed through `workflow_call`). Create that environment in your plugin repo, store the two secrets there — and only there — and attach whatever deployment-protection rules you want (required reviewers, wait timers, allowed branches); a preflight step fails the deploy with instructions when they are missing.

The `build` job fails when:

- the tag is not `v<major>.<minor>.<patch>` (no leading zeros), or `publish` is on without a tag;
- the entry file's `Version:` header differs from the tag, or from the `Stable tag:` of `readme.txt` when that file exists; a wp.org release requires `readme.txt`;
- the entry file's `Text Domain:` header, or `extra.text-domain` in `composer.json` when declared, differs from `plugin-slug`;
- `vendor-prefixed/` carries strings in the plugin's text domain but the regenerated POT has none of them;
- `Requires PHP` is below the PHP floor of a shipped package, the highest lower bound of the `require.php` constraints of the production packages in `vendor/` and the scoped packages in `vendor-prefixed/`;
- `Requires at least` is below the `extra.requires-wp` version a shipped package declares, or `Requires Plugins` lacks a slug listed in a shipped package's `extra.requires-plugins`;
- the archive misses the entry file, `readme.txt` for a wp.org release, or, when `composer.json` declares `extra.scoping-prefix`, `vendor-prefixed/scoper-autoload.php` and the files of every scoped package;
- the archive holds a development or secret file, such as a `phpcs*.xml*` ruleset, `composer.json` or `.env`.

A package declares the WordPress version and the plugins it needs in its own `composer.json`:

```json
{
  "extra": {
    "requires-wp": "7.1",
    "requires-plugins": ["woocommerce"]
  }
}
```

The entry file is `<plugin-slug>.php` at the project root unless `entry-file` names another file there. The version is taken from the tag and verified against the files, never stamped in. The artifact test needs the wp-env config, `.wp-env.json` or `wp-env-config-file`, to map `wp-content/plugins/<plugin-slug>` to the plugin source: it remaps exactly that `mappings` key at the built zip (co-mounted plugins, port, and lifecycle scripts survive the per-key merge) and pins the environment to the latest stable WordPress core, so the E2E suite runs against what actually ships on what users actually run.

`plugin-check` defaults to `true`; a GitHub release skips `plugin_updater` and `plugin_readme`, as `reusable-plugin-check.yml` does, and `wp-org: true` runs the full set. `generate-pot` defaults to `true`: release regenerates `languages/<plugin-slug>.pot` before archive creation, so the shipped zip always carries a current catalog — set it to `false` only for plugins with no translatable strings.

## Typical Composer Scripts

Add these to your project's `composer.json` for a consistent dev workflow. Composer and npm have no script-`extends`, so these blocks are deliberate copy-paste — keep the PHP/Node floors aligned with this repo when forking them:

```json
{
    "scripts": {
        "format:php": "phpcbf --standard=./phpcs.dist.xml --basepath=. ./ -v",
        "format:php:tests": "phpcbf --standard=./phpcs.tests.dist.xml --basepath=. ./tests -v",
        "i18n:make-pot": "wp i18n make-pot . languages/your-text-domain.pot",
        "lint:php": ["@lint:php:phpcs", "@lint:php:phpcs:tests", "@lint:php:phpstan"],
        "lint:php:phpcs": "phpcs --standard=./phpcs.dist.xml --basepath=. ./ -v",
        "lint:php:phpcs:tests": "phpcs --standard=./phpcs.tests.dist.xml --basepath=. ./tests -v",
        "lint:php:phpstan": "phpstan analyse -c ./.phpstan.neon -v --memory-limit=1G"
    }
}
```

Release regenerates the POT on every tag build; the script stays useful for local catalog refreshes.

## Scripts Contract

The reusable workflows above name these composer/npm scripts as their interface. A consumer's
`composer.json` / `package.json` scripts must use these names for the corresponding workflow to
find and run them.

### Default names (input-overridable)

The default value of a `workflow_call` input; matching it avoids an unnecessary `with:` entry.

| Script | Ecosystem | Workflow | Input |
| --- | --- | --- | --- |
| `test` | composer | `reusable-phpunit.yml` | `composer-script` |
| `build` | npm | `reusable-playwright-e2e.yml`; also run directly (`npm run build --if-present`) by `reusable-release.yml` | `build-script` |
| `test:e2e` | npm | `reusable-playwright-e2e.yml`; also run directly (`npm run test:e2e --if-present`) by `reusable-release.yml` against the built zip | `playwright-script` |

### Convention (consumer-declared, not enforced by the workflow)

`reusable-php-lint.yml` takes a required `scripts[]` array of composer script names and runs each
as its own matrix job; the workflow names none of them itself. The established convention — see
[Typical Composer Scripts](#typical-composer-scripts) above — is `lint:php:phpcs`,
`lint:php:phpcs:tests` and `lint:php:phpstan`, passed as
`'["lint:php:phpcs", "lint:php:phpcs:tests", "lint:php:phpstan"]'`.

`reusable-scripts-styles-lint.yml` takes a required `scripts[]` array of npm script names the same
way. The convention is `lint:scripts` for ESLint and `lint:styles` for Stylelint, passed as
`'["lint:scripts", "lint:styles"]'`.

`reusable-release.yml` regenerates the POT and builds the archive; it names no composer or npm
changelog script, so no changelog script is part of this contract.

## Development

This repository is itself tested with PHPUnit. To work on it:

```bash
composer install
composer test           # unit suite
composer test:all       # unit + mutation (Infection)
```

Tests live in `tests/Unit/` (PSR-4 autoloaded as `DeepWebSolutions\Config\Tests\Unit\`) and run the real tools, PHPCS, PHPStan and php-scoper, on fixtures copied to a temporary directory.

Test fixtures live in `tests/fixtures/<test-name>/` only when the data is multi-line or shared across multiple tests. Inline test data is preferred for small, scenario-specific inputs.
