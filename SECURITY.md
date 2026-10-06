# Security policy

## Reporting a vulnerability

Report a vulnerability privately through [GitHub's security advisory form](https://github.com/ahegyes/wordpress-configs/security/advisories/new), never in a public issue. Expect a first response within 7 days. Once a fix lands on `trunk`, the advisory credits the reporter, unless they ask to stay anonymous.

## Supported version

Only `trunk` is supported; there are no tags or releases. A fix reaches Composer consumers on `dev-trunk` at their next `composer update`, and consumers that pin the reusable workflows or the npm package by commit SHA when they move the pin.

## Release workflow

Pin `reusable-release.yml` by commit SHA like every reusable workflow, and review, never automerge, a pin bump that changes its `release` or `wp-org` jobs. [The workflow reference](docs/workflows.md#release--reusable-releaseyml) describes the tag guard, the `wp-org-release` environment and each job's permissions.

## Scope

In scope is everything this repository ships: the scoping pipeline, its php-scoper configuration and recipes, the shared PHPCS and PHPStan configurations, the reusable workflows, such as script injection through caller inputs or an unpinned third-party action, and the Node baselines.

Out of scope are vulnerabilities in WordPress core, third-party Composer or npm packages, and third-party GitHub Actions; report those to their maintainers.
