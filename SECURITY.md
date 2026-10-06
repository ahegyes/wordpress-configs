# Security policy

## Reporting a vulnerability

Please report security vulnerabilities privately via [GitHub's security advisory form](https://github.com/ahegyes/wordpress-configs/security/advisories/new). Do not open a public issue.

You can expect an initial response within 7 days. Once the report is triaged, you'll receive updates as fixes land. Once a fix is released, credit is given in the advisory unless you request anonymity.

## Supported version

This package is consumed via `dev-trunk` only — no version tags are published. Security fixes land on `trunk` and propagate immediately to all downstream consumers via their next `composer update`. Reusable workflow consumers receive fixes through dependabot PRs that bump their SHA-pinned `uses:` refs to the latest `trunk` commit.

## Release workflow ref pinning

The reusable release workflow (`reusable-release.yml`) is SHA-pinned by downstream plugins like every other reusable workflow. This repo stays tagless; dependabot tracks the latest `trunk` commit and opens SHA-bump PRs in consumers. Unprivileged build-side bumps may be automerged by consumer policy, while bumps that touch the privileged `release` and `wp-org` jobs require manual review.

Its privileged jobs are gated by:
- A version-tag guard: publishing requires a tag matching `^v(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*)){2}$`, checked at the top of the build job and again in the `wp-org` job before it deploys.
- The `wp-org-release` GitHub Environment applies the consumer repository's deployment-protection rules to the `wp-org` job; the `release` job runs without an environment.
- `SVN_USERNAME` / `SVN_PASSWORD` exist only as `wp-org-release` environment secrets in the calling repository, never as `workflow_call` secrets.
- The immutable SHA-pinned ref prevents credentialed release code from changing in a consumer without a reviewed pin bump.

Environment protection, the tag guard, and environment-only secrets defend against unauthorized callers and misconfiguration. SHA immutability is the control against compromised or accidental changes to the credentialed workflow code. The `build`, `test` and `provenance` jobs are read-only and secretless. The `release` job holds `contents: write` to create the GitHub release and runs no dependency install or test, and the credentialed `wp-org` job performs no checkout, dependency install, or test execution.

## Scope

In scope:
- Vulnerabilities in the scoping scripts (`ScopePhpDependencies`, `ScopedPackages`, `CollectScopingStubs`) — including php-scoper command construction, the symbolic-link refusal before `vendor-prefixed/` is emptied, and stubs file resolution.
- Vulnerabilities in the php-scoper base config (`scoper-base.inc.php`) and its recipes — such as unsafe finder / exclude-file handling, or prefix-stripping that leaves symbols incorrectly scoped.
- Vulnerabilities in the reusable GitHub Actions workflows (`reusable-*.yml`) — particularly script injection via PR-controlled inputs or unpinned third-party action references that could compromise consuming repositories.
- Vulnerabilities in the Node configs (eslint/stylelint/playwright/tsconfig bases).

Out of scope:
- Vulnerabilities in WordPress core, third-party Composer dependencies, or third-party GitHub Actions — report those to their respective maintainers.
