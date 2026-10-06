# wordpress-configs

Shared PHPCS, PHPStan and php-scoper configurations, Node tool baselines and reusable GitHub Actions workflows for WordPress plugins, themes and sites.

## Requirements

- The PHP version `composer.json` requires, and Composer 2.
- For the Node baselines only, the Node and npm versions `package.json` declares under `engines`.

## Installation

Add the VCS repository and require the package as a development dependency:

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

The root `composer.json` also declares `minimum-stability: dev` with `prefer-stable: true`, because this package requires `roave/security-advisories: dev-latest`, which fails a development install on any known advisory in the dependency graph, and `phpcompatibility/phpcompatibility-wp: ^3@alpha`. Composer honors stability flags only in the root package. The root package also allows the two Composer plugins that register the PHPCS standards and the PHPStan extensions; a non-interactive install fails without them:

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

The Node baselines resolve through `node_modules`, so a project that uses them also installs the package with npm, as a Git dependency pinned to a commit SHA:

```json
{
  "devDependencies": {
    "@ahegyes/wordpress-configs": "git+https://github.com/ahegyes/wordpress-configs.git#<commit-sha>"
  }
}
```

## PHPCS

Two profiles share `php/quality-assurance/phpcs.base.dist.xml`, which runs the full `WordPress` standard and PHPCompatibilityWP:

| Profile | File | Lints |
| --- | --- | --- |
| Production | `php/quality-assurance/phpcs.dist.xml` | Everything except `tests/` |
| Tests | `php/quality-assurance/phpcs.tests.dist.xml` | `tests/`, without the docblock and WordPress-runtime sniffs |

Both profiles skip `bin/`, `vendor/`, `vendor-prefixed/`, `node_modules/` and generated files. The patterns match case-sensitively, so a `src/Vendor/` source directory is still linted. An `index.php` is linted like any other file, except that its file comment may be a `//` line, so a silence-is-golden placeholder stays clean.

Create a `phpcs.dist.xml` that extends the production profile and names the project's text domain and prefixes:

```xml
<?xml version="1.0"?>
<ruleset name="Project">
    <rule ref="./vendor/ahegyes/wordpress-configs/php/quality-assurance/phpcs.dist.xml"/>

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="your-text-domain"/>
            </property>
        </properties>
    </rule>

    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="yourprefix_"/>
                <element value="YourVendor\YourPlugin"/>
            </property>
        </properties>
    </rule>
</ruleset>
```

Create a `phpcs.tests.dist.xml` beside it that extends the tests profile, `php/quality-assurance/phpcs.tests.dist.xml`, the same way. Keep the leading `./` in each `ref`. [The scripts contract](docs/scripts-contract.md#phpcs) gives the two lint scripts and the two ways to lower the PHP and WordPress floors the profiles set.

## PHPStan

`php/quality-assurance/phpstan.dist.neon` is the shared profile:

| File | Holds |
| --- | --- |
| `phpstan.base.dist.neon` | The rules that need no WordPress: the level, the missing `#[\Override]` check, the strict-rules settings and the nested vendor exclusions |
| `phpstan.dist.neon` | The base, plus the minimum WordPress version that `johnbillion/wp-compat` checks calls against |
| `phpstan.dist.neon.php` | Opt-in discovery of a plugin's conventional paths |

Code under a nested `vendor/` or `vendor-prefixed/` directory is scanned but not analyzed, so the code that uses it still resolves. This package requires `php-stubs/wordpress-stubs`; a project that scans or declares `php-stubs/woocommerce-stubs`, such as a WooCommerce extension, requires that itself.

Create a `phpstan.dist.neon`:

```neon
includes:
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon

parameters:
    paths:
        - your-plugin.php
        - src
```

To add a plugin's conventional paths without listing them, include `phpstan.dist.neon.php` as well. It adds `functions-bootstrap.php`, `functions.php`, `uninstall.php`, `src/`, `includes/`, `models/`, `blocks/` and `templates/` when they exist in the directory PHPStan runs from, and scans `vendor-prefixed/` there; the entry file still goes under `paths`.

To analyze code with no WordPress loaded, include `phpstan.base.dist.neon` alone and list `szepeviktor/phpstan-wordpress`, `johnbillion/wp-compat` and `swissspidy/phpstan-no-private` under `extra.phpstan/extension-installer.ignore` in the root `composer.json`, so a WordPress function is an unknown symbol. A WordPress config in the same repository then includes those extensions itself, because PHPStan rejects the minimum WordPress version when wp-compat is not loaded:

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon
    - vendor/johnbillion/wp-compat/extension.neon
    - vendor/swissspidy/phpstan-no-private/rules.neon
    - vendor/ahegyes/wordpress-configs/php/quality-assurance/phpstan.dist.neon
```

The package installs `phpstan-deprecation-rules`, `phpstan-strict-rules`, `szepeviktor/phpstan-wordpress` (WordPress signatures and types), `johnbillion/wp-compat` (WordPress version compatibility) and `swissspidy/phpstan-no-private` (private WordPress APIs).

## Dependency scoping

`php/php-scoper/scoper-base.inc.php` and `php/composer/ScopePhpDependencies.php` prefix the packages a WordPress plugin bundles with the plugin's own namespace, so plugins that bundle different versions of the same library run side by side.

Require php-scoper in the plugin itself, because Composer does not install this package's development requirements:

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

## Node baselines

`node/` holds one baseline per tool, which a project extends and overrides. The package declares the tools behind them as `>=` peer dependencies, floored at the versions it is tested against: `@wordpress/scripts`, `@wordpress/eslint-plugin`, `@wordpress/stylelint-config`, `@wordpress/postcss-plugins-preset` and `@playwright/test`.

| Baseline | Holds |
| --- | --- |
| `node/tsconfig.base.json` | The deltas from TypeScript's defaults: `react-jsx`, `noImplicitReturns`, `noFallthroughCasesInSwitch`, `isolatedModules`, `resolveJsonModule`, `skipLibCheck` and `noEmit` |
| `node/eslint.config.base.mjs` | `@wordpress/eslint-plugin`'s recommended flat config, with its unit-test and Playwright presets scoped to test globs |
| `node/stylelint.config.base.js` | `@wordpress/stylelint-config/scss` with generated trees ignored, `selector-class-pattern` off, and reports on needless, descriptionless and invalid-scope disable comments |
| `node/postcss.config.base.js` | `@wordpress/postcss-plugins-preset` plus the production minifier `@wordpress/scripts` uses when a project has no PostCSS config |
| `node/playwright.config.base.js` | A factory over the `@wordpress/scripts` Playwright config, taking the project's wp-env `port` |

TypeScript extends the base by path:

```json
{
  "extends": "@ahegyes/wordpress-configs/node/tsconfig.base.json",
  "include": ["client/**/*"]
}
```

ESLint spreads the base:

```js
import baseConfig from '@ahegyes/wordpress-configs/node/eslint.config.base.mjs';

export default [ ...baseConfig ];
```

Stylelint spreads the base and its rules, because Stylelint ignores the `ignoreFiles` of a config loaded through `extends`:

```js
const baseConfig = require( '@ahegyes/wordpress-configs/node/stylelint.config.base.js' );

module.exports = { ...baseConfig, rules: { ...baseConfig.rules } };
```

PostCSS re-exports the base; a project that needs another plugin appends it to the chain and keeps the minifier:

```js
module.exports = require( '@ahegyes/wordpress-configs/node/postcss.config.base.js' );
```

Playwright calls the factory with the port from the wp-env config and spreads nested keys individually, or the top-level spread drops the WordPress defaults:

```js
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@ahegyes/wordpress-configs/node/playwright.config.base.js' )( { port: 8888 } );

module.exports = defineConfig( { ...baseConfig, use: { ...baseConfig.use } } );
```

The Playwright factory sets `testDir` to `tests/e2e/` and artifacts under `tests/.cache/artifacts/`, and returns the `@wordpress/scripts` `webServer` on the given port, which runs `npm run wp-env start`; a project that starts wp-env through its own script spreads `webServer` and sets `command`. For WordPress fixtures, import from `@wordpress/e2e-test-utils-playwright`; for accessibility scans, add `@axe-core/playwright` to the project's own devDependencies.

## Reusable workflows

`.github/workflows/reusable-*.yml` holds reusable workflows a project calls with `workflow_call`, pinned to a commit SHA:

| Workflow | Runs |
| --- | --- |
| `reusable-block-json-check.yml` | Validates every `block.json` against the WordPress schema |
| `reusable-codeql.yml` | CodeQL analysis per language |
| `reusable-php-lint.yml` | `composer validate --strict` and the named Composer scripts, each a parallel job |
| `reusable-php-syntax-check.yml` | `php -l` across PHP versions over chosen paths |
| `reusable-phpunit.yml` | A Composer test script, optionally inside the project's wp-env |
| `reusable-playwright-e2e.yml` | The Playwright suite against the project's wp-env |
| `reusable-plugin-check.yml` | WordPress Plugin Check against a built plugin directory |
| `reusable-release.yml` | Builds, tests and publishes a plugin release on GitHub, with an opt-in wp.org deploy |
| `reusable-scripts-styles-lint.yml` | The named npm scripts, each a parallel job |
| `reusable-supply-chain-audit.yml` | `composer audit` and `npm audit` of the committed lockfiles |
| `reusable-workflow-checks.yml` | actionlint and zizmor over the workflows |

[The workflow reference](docs/workflows.md) gives every input, the permissions a caller grants, and an example call. [The scripts contract](docs/scripts-contract.md) names the scripts and files the workflows expect.

## Distribution and editor files

`.distignore` is the baseline of `wp dist-archive` exclusions: copy it to the project root and extend it with project-specific source paths. `reusable-release.yml` requires the file. It excludes tests, package-manager manifests, CI and IDE files, development configs such as every `phpcs*.xml*` ruleset, and secret material (`.env*`, root-anchored `*.pem` and `*.key`, `id_rsa*`, `.npmrc`, `auth.json`).

`.editorconfig` follows WordPress core: tabs by default, two spaces for YAML and JSON, trailing whitespace kept in Markdown, and CRLF line endings for `*.txt`.

## Development

Install the dependencies:

```sh
composer install
```

Run the unit suite:

```sh
composer test
```

Run the PHP lint and the unit suite together:

```sh
composer quality-check
```

[The tests guide](tests/README.md) describes the test tiers, the opt-in mutation run and the fixtures, and [CONTRIBUTING.md](CONTRIBUTING.md) covers the checks to run before a pull request.
