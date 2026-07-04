# Security policy

## Reporting a vulnerability

Please report security vulnerabilities privately via [GitHub's security advisory form](https://github.com/ahegyes/wordpress-configs/security/advisories/new). Do not open a public issue.

You can expect an initial response within 7 days. Once the report is triaged, you'll receive updates as fixes land. Once a fix is released, credit is given in the advisory unless you request anonymity.

## Supported version

This package is consumed via `dev-trunk` only — no version tags are published. Security fixes land on `trunk` and propagate immediately to all downstream consumers via their next `composer update`.

## Release workflow ref pinning

The reusable release workflow (`reusable-release.yml`) is consumed by downstream plugins at the mutable `@trunk` ref, consistent with the `dev-trunk` model above — immutable SHA pinning would defeat immediate fix propagation. Its privileged deploy job is gated three ways: an in-workflow version-tag guard — the ref must be a tag matching `^v(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*)){2}$`, enforced at the top of the build job and re-checked immediately before the deploy step — so a non-version ref cannot publish to wp.org; a GitHub Environment (`wp-org-release`), which subjects the job to whatever deployment-protection rules the consumer configures (required reviewers, wait timers, allowed branches); and environment-scoped credentials — `SVN_USERNAME` / `SVN_PASSWORD` exist only as `wp-org-release` environment secrets in the calling repository and resolve to the deploy job through that environment, never as `workflow_call` secrets.

## Scope

In scope:
- Vulnerabilities in the Composer scripts (`CollectScopingStubs`, `ScopePhpDependencies`) — particularly path-traversal or arbitrary-file-read in stub catalog resolution.
- Vulnerabilities in the php-scoper base config (`scoper-base.inc.php`, `contrib/*.inc.php`) — e.g., unsafe finder / exclude-file handling, or prefix-stripping that leaves symbols incorrectly scoped.
- Vulnerabilities in the reusable GitHub Actions workflows (`reusable-*.yml`) — particularly script injection via PR-controlled inputs or unpinned third-party action references that could compromise consuming repositories.
- Vulnerabilities in the Node configs (eslint/stylelint/playwright/tsconfig bases).

Out of scope:
- Vulnerabilities in WordPress core, third-party Composer dependencies, or third-party GitHub Actions — report those to their respective maintainers.
