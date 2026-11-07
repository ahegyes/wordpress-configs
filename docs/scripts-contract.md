# Scripts contract

This document names the Composer and npm scripts and the project files that the reusable workflows and shared configs read, and shows how a project wires its PHPCS and PHPStan scripts, including how it lowers the PHP and WordPress floors.

## Fixed names

A workflow or a shared config runs these scripts by name, and no input changes the name.

| Script | Ecosystem | Read by | When |
| --- | --- | --- | --- |
| `build` | npm | `reusable-release.yml` | Runs as `npm run build --if-present` before the archive is built. |
| `test:e2e` | npm | `reusable-release.yml` | Runs as `npm run test:e2e --if-present` against the built zip. |
| `wp-env` | npm | `node/playwright.config.base.js`, through the `@wordpress/scripts` Playwright config it spreads | Runs as `npm run wp-env start`, Playwright's web server, unless the project sets `webServer.command`. |

## Default names

These names are the default of a `workflow_call` input; matching them avoids a `with:` entry.

| Script | Ecosystem | Workflow | Input |
| --- | --- | --- | --- |
| `test` | composer | `reusable-phpunit.yml` | `composer-script` |
| `build` | npm | `reusable-playwright-e2e.yml` | `build-script` |
| `test:e2e` | npm | `reusable-playwright-e2e.yml` | `playwright-script` |

## Conventional script lists

`reusable-php-lint.yml` and `reusable-scripts-styles-lint.yml` take a required `scripts` array and run each name as its own job, so the workflows name none of them. The conventional lists are:

- `reusable-php-lint.yml`: `'["lint:php:phpcs", "lint:php:phpcs:tests", "lint:php:phpstan"]'`;
- `reusable-scripts-styles-lint.yml`: `'["lint:scripts", "lint:styles", "lint:types"]'`, where `lint:types` runs `tsc`.

A project that leaves out `lint:php:phpcs:tests` lints its production code but not its tests, because the production profile skips every `tests/` directory.

## Conventional names

No workflow or shared config reads these names. A repository that has the job uses the name, so a script does the same job in every repository, and one without the job leaves the script out.

| Script | Ecosystem | Job |
| --- | --- | --- |
| `setup` | npm | Installs every dependency and starts the development environment. |
| `packages-install`, `packages-update` | composer, npm | Install or update the dependencies; the Composer scripts pass `--ignore-platform-req=php+`. |
| `packages-install:all`, `packages-update:all` | npm | Run the Composer and npm scripts of the same stem, the update ending with `packages-update:wp`. |
| `packages-update:wp` | npm | Moves the `@wordpress/*` packages to their latest releases with `wp-scripts packages-update`. |
| `wp-env:start`, `wp-env:stop`, `wp-env:update`, `wp-env:destroy` | npm | Manage the development environment; each further environment takes `wp-env:<name>:<verb>`, such as `wp-env:tests:start`. |
| `format:php`, `format:php:tests` | composer | Fix PHP with `phpcbf` against the project's two rulesets. |
| `lint:php` | composer | Runs `lint:php:phpcs`, `lint:php:phpcs:tests` and `lint:php:phpstan`. |
| `test:unit`, `test:integration` | composer | Run one PHPUnit suite each. |
| `quality-check` | composer | Validates the manifest, and the changelog fragments where they exist, then runs the PHP lint and the tests: the check before a pull request. |
| `format:all`, `lint:all`, `test:all`, `quality-check:all` | npm | Run the Composer and npm sides of the job together, Composer first. |
| `audit`, `audit:fix` | npm | Audit both lockfiles with the supply-chain audit's default flags, so a local run and CI agree, or fix what npm can. |
| `check:engines`, `check:licenses` | npm | Check the running Node and npm against `engines`, or the dependencies' licenses. |
| `changelog:add`, `changelog:validate`, `changelog:write` | composer | Record a changelog fragment, validate the fragments, or write them into `CHANGELOG.md` for a release. |

## PHPCS

The two shared profiles run as two scripts, one over the project and one over `tests/`:

```json
{
	"scripts": {
		"format:php": "phpcbf --standard=./phpcs.dist.xml --basepath=. ./ -v",
		"format:php:tests": "phpcbf --standard=./phpcs.tests.dist.xml --basepath=. ./tests -v",
		"lint:php:phpcs": "phpcs --standard=./phpcs.dist.xml --basepath=. ./ -v",
		"lint:php:phpcs:tests": "phpcs --standard=./phpcs.tests.dist.xml --basepath=. ./tests -v"
	}
}
```

Each project ruleset references its shared profile with a leading `./`, as in `<rule ref="./vendor/ahegyes/wordpress-configs/php/quality-assurance/phpcs.dist.xml"/>`. PHPCS does not treat a bare `vendor/…` reference as a path, so any exclude pattern, severity or property inside that `<rule>` element is silently ignored.

`phpcbf` exits with 1 after it fixes a file, so the two format scripts stay separate instead of one script running both.

### Lowering the floors

The shared profiles set the PHP version range PHPCompatibility checks and the minimum WordPress version the deprecation sniffs compare against. An included ruleset's `<config>` wins over the including ruleset's own, so a `<config>` in the project's `phpcs.dist.xml` does not lower either floor. A project lowers them in one of two ways.

Include a floors ruleset after the shared profile. A `phpcs.floors.xml` holds the project's floors:

```xml
<?xml version="1.0"?>
<ruleset name="Floors">
	<config name="testVersion" value="8.3-"/>
	<config name="minimum_wp_version" value="6.8"/>
</ruleset>
```

The project ruleset includes it after the profile:

```xml
<?xml version="1.0"?>
<ruleset name="Project">
	<rule ref="./vendor/ahegyes/wordpress-configs/php/quality-assurance/phpcs.dist.xml"/>
	<rule ref="./phpcs.floors.xml"/>
</ruleset>
```

Or pass the floors on the command line:

```sh
vendor/bin/phpcs --standard=./phpcs.dist.xml --runtime-set testVersion 8.3- --runtime-set minimum_wp_version 6.8 --basepath=. ./ -v
```

The floors ruleset keeps the values in one file for every script; `--runtime-set` suits a one-off run.

## PHPStan

PHPStan finds the project's `phpstan.dist.neon` on its own, so the script needs no `-c`:

```json
{
	"scripts": {
		"lint:php:phpstan": "phpstan analyse -v --memory-limit=1G"
	}
}
```

A project with a lower minimum WordPress version sets `WPCompat.requiresAtLeast` in its own `parameters`, which win over the profile's.

## Release

`reusable-release.yml` reads these files from the project:

| File | Requirement |
| --- | --- |
| The main plugin file | The one PHP file at the project root with a `Plugin Name` header in its first 8 KB, as WordPress finds it. Its `Version` header equals the tag, its `Text Domain` equals `plugin-slug`, and its `Requires PHP`, `Requires at least`, `WC requires at least` and `Requires Plugins` cover what the shipped packages need. |
| `readme.txt` | Required with `wp-org: true`. When present, its `Stable tag` equals the release version, or, for a prerelease, the last stable release. |
| `CHANGELOG.md` | Optional. When present, it has a section headed by the version, such as `## 1.2.3 - 2026-10-06` or `## [1.2.3] - 2026-10-06`, which becomes the release notes; a missing or blank section fails the build, dry runs included. |
| `.distignore` | Required; `wp dist-archive` builds the zip from it. Copy this package's baseline and extend it. |
| `package.json` and `package-lock.json` | Declare a Node version and `@wordpress/env`. |
| `.wp-env.json`, or the file `wp-env-config-file` names | Maps `wp-content/plugins/<plugin-slug>` to the plugin source. |
| `composer.json` | `extra.scoping-prefix` makes the archive check the scoped packages; `extra.text-domain`, when declared, equals `plugin-slug`. |
| `.wordpress-org/` | Optional wp.org assets, deployed with `wp-org: true`. |
| `.github/workflows/quality.yml` and `.github/workflows/tests.yml` | Each has a successful push run on the released commit, unless `required-workflows` names other files. |

A package the plugin ships states the WordPress version, the WooCommerce version and the plugins it needs in its own `composer.json`, and the release checks the plugin's headers against them:

```json
{
	"extra": {
		"requires-wp": "7.1",
		"requires-wc": "11.2",
		"requires-plugins": ["woocommerce"]
	}
}
```
