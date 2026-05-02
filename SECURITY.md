# Security policy

## Reporting a vulnerability

Please report security vulnerabilities privately via [GitHub's security advisory form](https://github.com/ahegyes/wordpress-configs/security/advisories/new). Do not open a public issue.

You can expect an initial response within 7 days. Once the report is triaged, you'll receive updates as fixes land. Once a fix is released, credit is given in the advisory unless you request anonymity.

## Supported version

This package is consumed via `dev-trunk` only — no version tags are published. Security fixes land on `trunk` and propagate immediately to all downstream consumers via their next `composer update`.

## Scope

In scope:
- Vulnerabilities in the Composer scripts (`CollectScopingStubs`, `ScopePhpDependencies`) — particularly path-traversal or arbitrary-file-read in stub catalog resolution.
- Vulnerabilities in the php-scoper base config (`scoper-base.inc.php`, `contrib/*.inc.php`) — e.g., output-path manipulation that could write outside the intended dependencies/ directory.
- Vulnerabilities in the reusable GitHub Actions workflows (`reusable-*.yml`) — particularly script injection via PR-controlled inputs or unpinned third-party action references that could compromise consuming repositories.
- Vulnerabilities in the Node configs (eslint/stylelint/playwright/tsconfig bases).

Out of scope:
- Vulnerabilities in WordPress core, third-party Composer dependencies, or third-party GitHub Actions — report those to their respective maintainers.
