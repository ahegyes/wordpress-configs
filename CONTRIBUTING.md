# Contributing

Consumers install `trunk` directly: Composer projects require `dev-trunk`, and workflow and npm consumers pin a `trunk` commit. Merge nothing to `trunk` that you would not release.

## Before opening a pull request

Install the dependencies on the PHP, Composer, Node and npm versions the README lists, then run the checks CI runs:

```sh
composer install
npm install
composer quality-check
composer audit --abandoned=report
npm run lint:scripts
npm run lint:config
npm audit --omit=dev --audit-level=high
actionlint
zizmor --config .github/zizmor.yml .github/
```

`composer quality-check` runs both PHPCS profiles, PHPStan and the unit suite; [tests/README.md](tests/README.md) describes the test tiers.

## Reusable workflows are public API

Shipped `workflow_call` input names, types and semantics are frozen. An input may be added only when it is optional and its default keeps the current behavior. Renaming, removing or re-typing an input, or changing a default in a way callers can see, is a coordinated migration: land the change and move every consumer's pin in the same window, never by automerge, because a caller passing an input the workflow no longer declares fails at startup without a failed check on the pull request that moved the pin.

Give every input a `description:` in the workflow and a row in [docs/workflows.md](docs/workflows.md). Consumers review, never automerge, a pin bump that changes the release workflow's `release` or `wp-org` jobs.

## Filing changes

- Keep one concern per pull request.
- State the consumer impact in the description, especially for a reusable workflow.
- Fill in the template's breaking-changes section.
- Follow the code, documentation and commit conventions in [AGENTS.md](AGENTS.md).
