# Summary

<!-- One or two sentences describing what this PR changes and why. -->

## Type of change

- [ ] PHP source (Composer scripts, php-scoper config)
- [ ] Reusable workflow (`.github/workflows/reusable-*.yml`)
- [ ] PHPCS / PHPStan ruleset (`php/quality-assurance/`)
- [ ] Node config (eslint, stylelint, playwright, tsconfig)
- [ ] Documentation
- [ ] Other

## Checklist

- [ ] Tests added or updated (PHPUnit suites under `tests/Unit/`)
- [ ] `composer test` passes locally
- [ ] If this changes a reusable workflow's inputs/outputs, `README.md` is updated to reflect the new contract
- [ ] If this changes a ruleset, downstream consumer behavior is considered (this repo ships to `dev-trunk` consumers immediately)

## Breaking changes for downstream consumers

<!-- Required if this PR changes a reusable workflow input, a Composer script signature, or any public-API surface that downstream repos depend on. Otherwise delete this section. -->
