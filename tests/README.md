# Tests

The tests prove that the shared configs, the scoping pipeline and the reusable workflows behave as documented, by running the real tools on fixtures rather than mocks.

## Tiers

- **Unit** (`tests/Unit/`, `composer test`). PHPUnit tests that run PHPCS, PHPStan, php-scoper and workflow step scripts on fixtures copied to a temporary directory:
  - `PhpcsProfilesTest` scans files with both PHPCS profiles: the case-sensitive vendor exclusions, the `index.php` stub waiver, the full `WordPress` standard, and the two ways a project lowers the PHP and WordPress floors.
  - `PhpstanConfigTest` analyzes fixtures with the shared PHPStan rules: level 10 findings, and nested `vendor/` directories scanned but not analyzed.
  - `ScopePhpDependenciesTest` scopes `fixtures/scoping-project/` end to end and checks the output, the generated autoloader, the recipes and every failure the scope run reports. Its dompdf render test is in the `slow` group.
  - `ReleaseWorkflowTest` and `SupplyChainAuditWorkflowTest` run steps of `reusable-release.yml` and `reusable-supply-chain-audit.yml`. `Support/RunsWorkflowSteps.php` loads a workflow with `symfony/yaml`, finds a step by its job and `name`, and runs its script with `bash -e`, as GitHub Actions does for a step that names no shell. Renaming a tested step breaks its test.
- **CI smokes** (`.github/workflows/quality.yml`). Checks that run a shared config as a consumer would:
  - the PHPCS production profile explains itself with `phpcs -e` and scans `fixtures/plugin-stub/`;
  - `npm run lint:config` loads each Node baseline against the installed toolchain through `fixtures/node-config/smoke.mjs`;
  - the syntax lane for files that must parse on an older PHP, and Plugin Check, run on `fixtures/dws-build-fixture/`;
  - the block.json check runs on `fixtures/block-json/`;
  - the supply-chain audit runs on this repository's own lockfiles.

## Mutation testing

Mutation testing is opt-in. `composer test:unit:mutation` runs Infection over `php/` with the configuration in `infection.json`, skipping the `slow` group, and reports the mutation score; no gate or floor enforces it. `composer test:all` runs the unit suite and then Infection.

## Not covered here

This repository's own CI runs the PHP lint, PHP syntax, PHPUnit, scripts lint, supply-chain audit, block.json, Plugin Check, workflow checks and CodeQL workflows. `reusable-playwright-e2e.yml` and `reusable-release.yml` need a plugin to act on, so CI checks them only with actionlint and zizmor, and the unit tests run the release's assertion steps; a full run of either happens first in a consumer's CI.

## Fixtures

`tests/fixtures/` holds the inputs the tiers above use:

| Fixture | Used by |
| --- | --- |
| `block-json/` | The block.json check: one schema-valid block. |
| `composer-audit/` | `SupplyChainAuditWorkflowTest`: reports captured from real Composer runs, one with a malware filter-list match and one with an advisory. |
| `dws-build-fixture/` | The older-PHP syntax lane and Plugin Check: a GitHub-distributed build with a scoped package under `vendor-prefixed/`. |
| `node-config/` | `npm run lint:config`: the smoke harness and its probe inputs. |
| `phpstan/` | `PhpstanConfigTest`: a level 10 probe and a project with nested vendor directories. |
| `plugin-stub/` | The PHPCS smoke: a minimal, standards-compliant plugin. |
| `scoping-project/` | `ScopePhpDependenciesTest`: a project with path-repository packages, stubs and a `scoper.inc.php`. |

Inline test data is preferred for small, scenario-specific inputs; a fixture directory holds data that is multi-line, real tool output, or shared by several tests.
