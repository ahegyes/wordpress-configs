# Reusable workflows

This document is the reference for the reusable workflows in `.github/workflows/reusable-*.yml`: what each one runs, its `workflow_call` inputs, and the permissions a caller grants. Each input's `description:` in the workflow file is the authoritative wording; the tables below summarize it.

A caller references a workflow by its full commit SHA, with the commit as a comment, because zizmor, which Workflow Checks runs, rejects any `uses:` reference that is not pinned to a commit SHA:

```yaml
jobs:
  phpunit:
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-phpunit.yml@<sha> # trunk
```

A reusable workflow cannot elevate above the caller's token, so a caller grants every permission the called jobs declare, or the call fails at startup. Each section names those permissions.

## Shared behavior

- **Node.** `reusable-scripts-styles-lint.yml`, `reusable-phpunit.yml` (when it starts wp-env), `reusable-playwright-e2e.yml` and `reusable-release.yml` install the Node version the project's `package.json` declares, in `engines.node` or in the `volta` or `devEngines.runtime` fields that `actions/setup-node` reads, and fail when it declares none. `reusable-supply-chain-audit.yml` and `reusable-block-json-check.yml` run no project npm code and use a fixed Node.
- **wp-env.** The workflows that start WordPress run the project's own `@wordpress/env`, the version its `package-lock.json` pins, so the project declares it in `devDependencies`. They export `php-version` as `WP_ENV_PHP_VERSION`, which wp-env applies over the `phpVersion` of its config files.
- **Composer.** Installs pass `--ignore-platform-req=php+`, which ignores only the PHP upper bound, so a PHP newer than a package declares still installs while extension requirements and floors stay checked.
- **Matrix inputs.** A JSON-array input that fans out into a matrix (`scripts`, `php-versions`, `languages`) must be non-empty: an empty array would expand to zero jobs and report success.

## block.json schema check — `reusable-block-json-check.yml`

Finds every `block.json` under the project path, skipping `node_modules/` and `vendor/`, and validates each against the trunk schema from `schemas.wp.org`. It succeeds with a message when it finds none.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |

The caller grants `contents: read`.

```yaml
jobs:
  block-json:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-block-json-check.yml@<sha>
```

## CodeQL — `reusable-codeql.yml`

Runs CodeQL initialization and analysis as one matrix job per language. CodeQL has no PHP analyzer; PHPStan covers PHP.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `languages` | `string` | No | `'["actions"]'` | Non-empty JSON array of CodeQL languages. |

The caller grants `actions: read`, `contents: read` and `security-events: write`.

```yaml
jobs:
  codeql:
    permissions:
      actions: read
      contents: read
      security-events: write
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-codeql.yml@<sha>
    with:
      languages: '["actions", "javascript-typescript"]'
```

## PHP lint — `reusable-php-lint.yml`

Runs `composer validate --strict` as its own job, then each named Composer script as a parallel matrix job. `composer validate --strict` fails on a lock out of date with `composer.json` and on any warning, which a dependency install only prints.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `scripts` | `string` | Yes | — | Non-empty JSON array of Composer scripts; each runs as its own job. |
| `php-version` | `string` | No | `'8.5'` | PHP version to run against. |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |
| `composer-options` | `string` | No | `'--prefer-dist --ignore-platform-req=php+'` | Options for the dependency install. |

The caller grants `contents: read`. [The scripts contract](scripts-contract.md#conventional-script-lists) lists the conventional script names.

```yaml
jobs:
  lint-php:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-lint.yml@<sha>
    with:
      scripts: '["lint:php:phpcs", "lint:php:phpcs:tests", "lint:php:phpstan"]'
```

## PHP syntax check — `reusable-php-syntax-check.yml`

Runs `php -l` over the PHP files under `paths`, once per PHP version, without installing dependencies. Directories are searched for `*.php` files, skipping any `vendor/` and `node_modules/` inside them, and a path list that matches no PHP file fails the check instead of passing it empty.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |
| `php-versions` | `string` | No | `'["8.5"]'` | Non-empty JSON array of PHP versions, such as `'["8.5", "8.6"]'`. |
| `paths` | `string` | No | `'["."]'` | Non-empty JSON array of files and directories to check, relative to `project-path`. |

`paths` narrows the check to the files that must parse on an older PHP, such as files loaded before a PHP version gate: `php-versions: '["7.4"]'` with `paths: '["uninstall.php", "vendor-prefixed/your-vendor/your-bootstrap"]'`. This workflow takes an array of PHP versions because matrixing across versions is the point of a syntax check; the other workflows run one PHP version per call, so a caller wraps them in its own matrix.

The caller grants `contents: read`.

```yaml
jobs:
  php-syntax:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-php-syntax-check.yml@<sha>
    with:
      php-versions: '["8.5", "8.6"]'
```

## PHPUnit — `reusable-phpunit.yml`

Installs Composer dependencies, optionally starts the project's wp-env, runs a Composer test script, and stops the environment under `always()`.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |
| `php-version` | `string` | No | `'8.5'` | PHP for Composer, for any suite outside wp-env, and for wp-env through `WP_ENV_PHP_VERSION`. |
| `wp-version` | `string` | No | `''` | WordPress release tag, such as `'6.9.4'`. Empty defers to the wp-env config's `core`, or wp-env's latest stable release. |
| `wp-env-core` | `string` | No | `''` | Full `WP_ENV_CORE` value, such as a repository ref or a zip URL. Overrides `wp-version`. |
| `composer-script` | `string` | No | `'test'` | Composer script that runs the suite. |
| `composer-options` | `string` | No | `'--prefer-dist --ignore-platform-req=php+'` | Options for the dependency install. |
| `wp-env-config-file` | `string` | No | `''` | wp-env config file relative to `project-path`. Empty uses `.wp-env.json`. |
| `wp-env-xdebug` | `string` | No | `''` | Value for `wp-env start --xdebug=<mode>`, such as `coverage`. Empty starts without Xdebug. |
| `multisite` | `boolean` | No | `false` | Starts wp-env as a multisite network. |
| `needs-wp-env` | `boolean` | No | `true` | Whether to install npm dependencies and start and stop wp-env. |

- `needs-wp-env: false` suits a unit-only suite: the workflow skips Node and wp-env entirely. Left at `true`, the project declares `@wordpress/env` in `package.json` and `package-lock.json`.
- `WP_ENV_CORE` and `WP_ENV_PHP_VERSION` are set for the whole job, so a Composer script that starts wp-env again gets the same WordPress and PHP. The config file and Xdebug mode do not carry over, so such a script passes its own `--config` and `--xdebug`.
- `multisite: true` merges `"multisite": true` into the override file of the config in use (`.wp-env.override.json`, or `<name>.override.json` beside `wp-env-config-file`), because wp-env reads that setting only from a config file.

The caller grants `contents: read`.

```yaml
jobs:
  phpunit:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-phpunit.yml@<sha>
    with:
      wp-env-config-file: .wp-env.tests.json
```

## Playwright E2E — `reusable-playwright-e2e.yml`

Installs Composer and npm dependencies, builds assets, installs Chromium, starts the project's wp-env, runs the Playwright script, and uploads a failure report.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |
| `artifact-slug` | `string` | Yes | — | Label for the uploaded failure-report artifact. |
| `php-version` | `string` | No | `'8.5'` | PHP for Composer and for wp-env through `WP_ENV_PHP_VERSION`. |
| `wp-version` | `string` | No | `''` | WordPress release tag. Empty defers to the wp-env config's `core`. |
| `wp-env-core` | `string` | No | `''` | Full `WP_ENV_CORE` value. Overrides `wp-version`. |
| `composer-options` | `string` | No | `'--prefer-dist --ignore-platform-req=php+'` | Options for the dependency install, which includes development packages so a scoping pipeline runs. |
| `build-script` | `string` | No | `'build'` | npm script that builds assets before wp-env starts. Empty skips the build. |
| `wp-env-config-file` | `string` | No | `''` | wp-env config file relative to `project-path`. Empty uses `.wp-env.json`. |
| `playwright-script` | `string` | No | `'test:e2e'` | npm script that runs the Playwright suite. |

On failure, the `playwright-report-<artifact-slug>` artifact holds `playwright-report/`, `artifacts/` and `tests/.cache/artifacts/`, the output location the shared Playwright base sets. The caller grants `contents: read`.

```yaml
jobs:
  e2e:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-playwright-e2e.yml@<sha>
    with:
      artifact-slug: your-plugin-slug
```

## Plugin Check — `reusable-plugin-check.yml`

Runs WordPress Plugin Check against a built plugin directory in a fresh wp-env, after activating the plugin on `php-version`. The calling workflow uploads that directory as an artifact first, with `include-hidden-files: true`, so the check sees every hidden file the zip ships.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `artifact` | `string` | Yes | — | Name of the artifact holding the built plugin directory. |
| `plugin-slug` | `string` | Yes | — | Plugin slug: the directory the build is mounted as. |
| `php-version` | `string` | No | `'8.5'` | PHP of the WordPress the check runs in; it must meet the plugin's `Requires PHP`. |
| `wp-org` | `boolean` | No | `false` | Runs the full check set, for a build published to wp.org. |

The default GitHub profile skips `plugin_updater` and `plugin_readme`, which report two by-design traits of a GitHub-distributed build: its `Update URI` header and its missing `readme.txt`. Plugin Check skips `vendor-prefixed/`, so scoped dependencies are checked at their source. The job fails on any error, while warnings only annotate. The action installs the newest Plugin Check release from wp.org, not a pinned one.

The called job needs no permissions.

```yaml
jobs:
  plugin-check:
    needs: build
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-plugin-check.yml@<sha>
    with:
      artifact: your-plugin-build
      plugin-slug: your-plugin-slug
```

## Release — `reusable-release.yml`

Builds a plugin's release zip, tests it, checks that the released commit's required workflows passed, and publishes it as a GitHub release, with an opt-in deploy to wp.org.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `plugin-slug` | `string` | Yes | — | Plugin slug: the directory the zip unpacks to, the name of the archive and the POT, and the required Text Domain. |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |
| `entry-file` | `string` | No | `''` | Main plugin file, a PHP file name at the project root. Empty uses `<plugin-slug>.php`. |
| `php-version` | `string` | No | `'8.5'` | PHP for the build, Plugin Check and the artifact test; it must meet `Requires PHP`. |
| `wp-env-config-file` | `string` | No | `''` | wp-env config file the artifact test starts. Empty uses `.wp-env.json`. |
| `plugin-check` | `boolean` | No | `true` | Whether to run Plugin Check against the built zip. |
| `generate-pot` | `boolean` | No | `true` | Whether to regenerate `languages/<plugin-slug>.pot` before archiving; turn it off only for a plugin with no translatable strings. |
| `wp-org` | `boolean` | No | `false` | Also publish to wp.org. |
| `required-workflows` | `string` | No | `'[".github/workflows/quality.yml", ".github/workflows/tests.yml"]'` | JSON array of workflow files that must have a successful push run on the released commit. |
| `publish` | `boolean` | No | `true` | Whether to publish; `true` requires a version tag. |

The workflow runs five jobs:

- `build` (`contents: read`, no secrets) checks the release metadata, installs Composer and npm dependencies without restoring a dependency cache, regenerates the POT, prunes to production dependencies, builds the zip, checks its contents, runs Plugin Check, and outputs the version and the zip's SHA-256.
- `test` (`contents: read`, no secrets) mounts the extracted zip in the project's own wp-env, activates it with `wp plugin activate`, requests the home page, and runs `npm run test:e2e` when the project defines it, after installing Chromium when `@playwright/test` is installed.
- `provenance` (`actions: read`) requires a successful push run of every file in `required-workflows` on the released commit, so a release reuses the suites proven there; tag a commit once they pass.
- `release` (`contents: write`) verifies the zip's digest and creates the GitHub release with the zip attached. Its notes are the version's section of `CHANGELOG.md` when that file exists, and GitHub's generated notes otherwise.
- `wp-org` runs only with `wp-org: true`, after `release`. It deploys the same zip and the `.wordpress-org` assets to wp.org SVN from the `wp-org-release` environment, the only job that holds the SVN credentials.

`publish: false` skips `release` and `wp-org` and runs `build` and `test` on any ref, which exercises the release path without a tag. A dry run started with `workflow_dispatch`, such as one on trunk after CI passes, also runs `provenance`; a dry run on a pull request or a push skips it, because a pull request's merge commit has no push runs and a push would race the runs it looks up.

The `build` job fails when:

- the tag is not `v<major>.<minor>.<patch>` (no leading zeros), or `publish` is on without a tag;
- the entry file's `Version:` header differs from the tag, or from the `Stable tag:` of `readme.txt` when that file exists; a wp.org release requires `readme.txt`;
- the entry file's `Text Domain:` header, or `extra.text-domain` in `composer.json` when declared, differs from `plugin-slug`;
- `vendor-prefixed/` carries strings in the plugin's text domain but the regenerated POT has none of them;
- `Requires PHP` is below the PHP floor of a shipped package, the highest lower bound of the `require.php` constraints of the production packages in `vendor/` and the scoped packages in `vendor-prefixed/`;
- `Requires at least` is below the `extra.requires-wp` version a shipped package declares, or `Requires Plugins` lacks a slug listed in a shipped package's `extra.requires-plugins`;
- the archive misses the entry file, `readme.txt` for a wp.org release, or, when `composer.json` declares `extra.scoping-prefix`, `vendor-prefixed/scoper-autoload.php` and the files of every scoped package;
- the archive holds a development or secret file, such as a `phpcs*.xml*` ruleset, `composer.json` or `.env`.

A strict `>` lower bound in a package's PHP constraint is read as inclusive. The version is taken from the tag and verified against the files, never stamped in.

The artifact test needs the wp-env config to map `wp-content/plugins/<plugin-slug>` to the plugin source: it remaps exactly that `mappings` key at the built zip, so co-mounted plugins, the port and lifecycle scripts survive the per-key merge, and pins the environment to the latest stable WordPress core.

`plugin-check` runs the GitHub profile, which skips `plugin_updater` and `plugin_readme` as `reusable-plugin-check.yml` does, unless `wp-org` is on.

The `wp-org` job reads `SVN_USERNAME` and `SVN_PASSWORD` from the calling repository's `wp-org-release` environment directly, because environment secrets cannot pass through `workflow_call`. Create that environment, store the two secrets there and only there, and attach its deployment-protection rules, such as required reviewers; a preflight step fails the deploy with instructions when they are missing.

The caller grants `actions: read` and `contents: write`. [The scripts contract](scripts-contract.md#release) lists the files and scripts the workflow expects.

```yaml
name: Release
on:
  push:
    tags: ['v*']
  workflow_dispatch:

concurrency:
  group: release
  cancel-in-progress: false

jobs:
  release:
    permissions:
      actions: read
      contents: write
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-release.yml@<sha>
    with:
      plugin-slug: your-plugin-slug
      # A dispatch on a branch rehearses the release without publishing it.
      publish: ${{ github.ref_type == 'tag' }}
```

## Scripts and styles lint — `reusable-scripts-styles-lint.yml`

Runs each named npm script as a parallel matrix job, after `npm ci` on the Node version `package.json` declares.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `scripts` | `string` | Yes | — | Non-empty JSON array of npm scripts; each runs as its own job. |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |

The caller grants `contents: read`.

```yaml
jobs:
  lint-scripts-styles:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-scripts-styles-lint.yml@<sha>
    with:
      scripts: '["lint:scripts", "lint:styles"]'
```

## Supply-chain audit — `reusable-supply-chain-audit.yml`

Audits the committed `composer.lock` and `package-lock.json` in two parallel jobs without installing anything, so the audit never runs the code it vets.

| Input | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `project-path` | `string` | No | `'.'` | Path to the project, relative to the repository root. |
| `composer-audit` | `boolean` | No | `true` | Whether to run `composer audit --locked`. |
| `npm-audit` | `boolean` | No | `true` | Whether to run `npm audit --package-lock-only`. |
| `composer-audit-flags` | `string` | No | `'--abandoned=report'` | Extra `composer audit` flags; the default fails on advisories and reports abandoned packages. |
| `npm-audit-flags` | `string` | No | `'--audit-level=high'` | Extra `npm audit` flags; the default fails on high and critical advisories. |
| `fail-on-findings` | `boolean` | No | `true` | Whether findings fail the job. |

At least one of `composer-audit` and `npm-audit` stays on. Both audit the full graph, development dependencies included, unless the flags say `--no-dev` or `--omit=dev`. `fail-on-findings: false` turns advisories and abandoned packages into warnings, but a package on a Composer filter list whose policy fails the audit, such as the malware list, still fails it, and the error names the package and its lists.

The caller grants `contents: read`.

```yaml
jobs:
  supply-chain:
    permissions:
      contents: read
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-supply-chain-audit.yml@<sha>
    with:
      npm-audit-flags: '--omit=dev --audit-level=high'
```

## Workflow checks — `reusable-workflow-checks.yml`

Runs actionlint over the workflow files and zizmor over the repository. With GitHub Advanced Security, zizmor uploads SARIF to the Security tab and a finding-count gate fails the job; without it, zizmor runs in native mode and fails the job itself. Both modes fail on any finding. The workflow takes no inputs.

The caller grants `actions: read`, `contents: read` and `security-events: write`.

```yaml
jobs:
  workflow-checks:
    permissions:
      actions: read
      contents: read
      security-events: write
    uses: ahegyes/wordpress-configs/.github/workflows/reusable-workflow-checks.yml@<sha>
```
