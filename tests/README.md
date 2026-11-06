# Tests

The tests prove that the shared configs, the scoping pipeline and the reusable workflows behave as documented, by running the real tools on fixtures rather than mocks.

## Tiers

- **Unit** (`tests/Unit/`, `composer test`). PHPUnit tests that run PHPCS, PHPStan, php-scoper and workflow step scripts on fixtures copied to a temporary directory:
  - `PhpcsProfilesTest` scans files with both PHPCS profiles: the files each profile scans and the tooling, generated and case-sensitive vendor paths it skips, the `index.php` stub waiver, slash-separated hook names, generic docblock types over their native hints, the full `WordPress` standard, and the two ways a project lowers the PHP and WordPress floors.
  - `PhpstanConfigTest` analyzes fixtures with the shared PHPStan rules: level 10 findings, nested `vendor/` directories scanned but not analyzed, and the types `@global` tags declare.
  - `ScopePhpDependenciesTest` scopes `Fixtures/scoping-project/` end to end and checks the output, the generated autoloader, the recipes, the `function_exists()` checks php-scoper adds to guards of global functions, and every failure the scope run reports. Its dompdf render test is in the `slow` group.
  - `ScopedPackagesTest` resolves the packages to scope from inline packages through each link Composer records: a replaced dependency, a provided virtual package, a Composer plugin, a metapackage, and a requirement the root replaces or provides. `Support/RecordsInstalledPackagesTrait.php` writes `installed.json` and `installed.php` for both tests, as Composer does.
  - `BlockJsonCheckWorkflowTest`, `CodeqlWorkflowTest`, `PhpunitWorkflowTest`, `PlaywrightWorkflowTest`, `PluginCheckWorkflowTest`, `ReleaseWorkflowTest`, `SupplyChainAuditWorkflowTest` and `WorkflowChecksWorkflowTest` run steps of the reusable workflows they are named after. `Support/RunsWorkflowStepsTrait.php` loads a workflow with `symfony/yaml`, finds a step by its job and `name`, runs its script with `bash -e`, as GitHub Actions does for a step that names no shell, and reads the outputs and environment it writes, multi-line values included. Renaming a tested step breaks its test.
  - `PhpunitWorkflowTest` also checks that the end-to-end workflow resolves the site's PHP with the PHPUnit workflow's own step, and `BlockJsonCheckWorkflowTest` that `node/block-json/`'s lock holds the ajv-cli the check runs.
- **CI smokes** (`.github/workflows/quality.yml`). Checks that run a shared config as a consumer would:
  - the PHPCS production profile explains itself with `phpcs -e` and scans `Fixtures/plugin-stub/`;
  - `npm run lint:config` loads each Node baseline against the installed toolchain through `Fixtures/node-config/smoke.mjs`;
  - the syntax lane for files that must parse on an older PHP, and Plugin Check, run on `Fixtures/build-fixture/`;
  - the block.json check runs on `Fixtures/block-json/`;
  - the supply-chain audit runs on this repository's own lockfiles.

## Mutation testing

Mutation testing is opt-in. `composer test:unit:mutation` runs Infection over the directories PHPUnit's coverage source lists, with the configuration in `infection.json`, skipping the `slow` group, and reports the mutation score; no gate or floor enforces it. `php/phpstan/` stays out, because only a PHPStan subprocess runs its visitor, which `PhpstanConfigTest` pins. `composer test:all` runs the unit suite and then Infection, and `composer quality-check:all` validates the manifest and runs the PHP lint before both.

## Not covered here

This repository's own CI runs the PHP lint, PHP syntax, PHPUnit, scripts lint, supply-chain audit, block.json, Plugin Check, workflow checks and CodeQL workflows. `reusable-playwright-e2e.yml` and `reusable-release.yml` need a plugin to act on, so CI checks them only with actionlint and zizmor, and the unit tests run the release's assertion steps; a full run of either happens first in a consumer's CI. `reusable-phpunit.yml`'s wp-env branch runs in no job here either: every consumer's Integration lane runs it, and a pull request that moves a consumer's pin catches a break.

## Fixtures

`tests/Fixtures/` holds the inputs the tiers above use:

| Fixture | Used by |
| --- | --- |
| `block-json/` | The block.json check: one schema-valid block. |
| `composer-audit/` | `SupplyChainAuditWorkflowTest`: reports captured from real Composer runs, one with a malware filter-list match, one with an advisory and one with a package in its cooldown. |
| `build-fixture/` | The older-PHP syntax lane and Plugin Check: a GitHub-distributed build with a scoped package under `vendor-prefixed/`. |
| `node-config/` | `npm run lint:config`: the smoke harness and its probe inputs. |
| `phpstan/` | `PhpstanConfigTest`: a level 10 probe, a project with nested vendor directories and a `@global` probe. |
| `plugin-stub/` | The PHPCS smoke: a minimal, standards-compliant plugin. |
| `scoping-project/` | `ScopePhpDependenciesTest`: a project with path-repository packages, stubs and a `scoper.inc.php`. |

Inline test data is preferred for small, scenario-specific inputs; a fixture directory holds data that is multi-line, real tool output, or shared by several tests.
