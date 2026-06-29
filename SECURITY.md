# Security policy

## Reporting a vulnerability

Please report security vulnerabilities privately via [GitHub's security advisory form](https://github.com/ahegyes/wordpress-configs/security/advisories/new). Do not open a public issue.

You can expect an initial response within 7 days. Once the report is triaged, you'll receive updates as fixes land. Once a fix is released, credit is given in the advisory unless you request anonymity.

## Supported version

This package is consumed via `dev-trunk` only — no version tags are published. Security fixes land on `trunk` and propagate immediately to all downstream consumers via their next `composer update`.

## Release workflow ref pinning

The reusable release workflow (`reusable-release.yml`) is consumed by downstream plugins at the mutable `@trunk` ref, consistent with the `dev-trunk` model above — immutable SHA pinning would defeat immediate fix propagation. Its privileged deploy job is gated instead by a GitHub Environment (`wp-org-release`) plus a version-tag guard (`if: github.ref_type == 'tag'`): the environment subjects the job to whatever deployment-protection rules the consumer configures (required reviewers, wait timers, allowed branches), and the guard restricts it to version-tag refs, so a non-tag invocation cannot publish to wp.org. The SVN credentials are passed as `workflow_call` secrets from the calling repository's secrets — the environment does not itself scope them; for that, consumers should store `SVN_USERNAME` / `SVN_PASSWORD` as `wp-org-release` **environment** secrets in the calling repository.

## Scope

In scope:
- Vulnerabilities in the Composer scripts (`CollectScopingStubs`, `ScopePhpDependencies`) — particularly path-traversal or arbitrary-file-read in stub catalog resolution.
- Vulnerabilities in the php-scoper base config (`scoper-base.inc.php`, `contrib/*.inc.php`) — e.g., unsafe finder / exclude-file handling, or prefix-stripping that leaves symbols incorrectly scoped.
- Vulnerabilities in the reusable GitHub Actions workflows (`reusable-*.yml`) — particularly script injection via PR-controlled inputs or unpinned third-party action references that could compromise consuming repositories.
- Vulnerabilities in the Node configs (eslint/stylelint/playwright/tsconfig bases).

Out of scope:
- Vulnerabilities in WordPress core, third-party Composer dependencies, or third-party GitHub Actions — report those to their respective maintainers.
