# wordpress-configs

Shared PHPCS, PHPStan and php-scoper configurations, Node tool baselines and reusable GitHub Actions workflows for WordPress projects. It is a standalone library: generic knobs anyone can use, no knowledge of a particular framework or plugin, and no knob without a feasible consumer need. Composer `ahegyes/wordpress-configs`, npm `@ahegyes/wordpress-configs`, PHP namespace `DeepWebSolutions\Config\`, MIT. Consumers install `dev-trunk` and pin reusable workflows and the npm package to commit SHAs; there are no tags or releases.

Code, comment, documentation and commit style follow the maintainer's v2 style guide, `references/v2-style-guide.md` in the project vault.

## Layout

```
wordpress-configs/
├── php/
│   ├── quality-assurance/      # the shared PHPCS profiles and base, the PHPStan profile and base, opt-in path discovery
│   ├── php-scoper/             # scoper-base.inc.php and per-library recipes/<vendor>/<package>.inc.php
│   └── composer/               # ScopePhpDependencies, ScopedPackages, CollectScopingStubs and their AST visitors
├── node/                       # tsconfig, ESLint, Stylelint, PostCSS and Playwright baselines
├── docs/                       # workflows.md (input reference) and scripts-contract.md (scripts, files, floors)
├── tests/
│   ├── Unit/                   # PHPUnit tests that run the real tools
│   ├── Support/                # RunsWorkflowSteps, which runs a workflow step's script
│   └── fixtures/               # projects and captured tool output the tests use
├── phpcs.dist.xml              # self-lint: the production profile minus WordPress-runtime sniffs
├── phpcs.tests.dist.xml        # self-lint for tests/
├── phpstan.dist.neon           # self-lint: the shared profile over php/ and tests/
└── .github/workflows/          # 11 reusable workflows and 4 self-CI workflows (codeql, quality, tests, workflow-checks)
```

## Commands

```sh
composer install
composer quality-check          # PHPCS (both profiles), PHPStan, unit tests
composer test:unit:mutation     # opt-in Infection run, no floor
npm run lint:scripts            # ESLint over node/ and the smoke harness
npm run lint:config             # load-smokes every Node baseline
actionlint
zizmor --config .github/zizmor.yml .github/
```

## Conventions

- Shared config files are named `<tool>.dist.<ext>`, so IDEs recognize the trailing extension.
- Self-lint uses the root rulesets and PHPStan config, which extend the shared profiles with this repository's exclusions; the files under `php/quality-assurance/` are for consumers.
- Composer installs pass `--ignore-platform-req=php+` in scripts, CI and the reusable workflows: only the PHP upper bound is ignored, so extension requirements and floors stay checked.
- Tests run PHPCS, PHPStan, php-scoper and workflow step scripts on fixtures copied to a temporary directory, never mocks.
- Workflow-step tests find a step by its job and `name` and run it with `bash -e`, as GitHub Actions does for a step that names no shell; renaming a tested step breaks its test.
- Every reusable-workflow input has a `description:`; shipped input names, types and defaults change only as a coordinated migration (CONTRIBUTING.md).
- Self-CI calls the reusable workflows through `./`, and `.github/zizmor.yml` turns off zizmor's `self-repository` audit until actionlint accepts `$/`.
- The README is an index; workflow inputs live in `docs/workflows.md`, script and file contracts in `docs/scripts-contract.md`, test tiers in `tests/README.md`. A behavior change updates them in the same change.

## Limitations

- `reusable-playwright-e2e.yml` and a full `reusable-release.yml` run have no target here: CI checks them with actionlint and zizmor, and the unit tests run the release's assertion steps.
- `symfony/filesystem` and `symfony/finder` stay on 7.4, because `humbug/php-scoper` 0.18 caps them.
- The release's PHP-floor check reads a strict `>` lower bound as inclusive.
