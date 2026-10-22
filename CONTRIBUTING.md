# Contributing to Pegwork

Thanks for helping. Bug reports, fixes, tests and docs are all welcome. For a new feature, open an issue first so we can agree on the approach before you write code.

Security issues: report them privately as described in [SECURITY.md](SECURITY.md), not in an issue.

## Local setup

You need PHP 8.2 or later, Composer 2, Node 22 (`.nvmrc`), npm 11, MySQL or MariaDB for the PHP tests, and WP-CLI.

```sh
git clone https://github.com/brandonmead/pegwork.git
cd pegwork
composer install
npm ci        # npm 10 cannot resolve the lockfile; use npm 11 (npm install -g npm@11, or npx -y npm@11 ci)
npm run build
```

Link the folder into a development site's `wp-content/plugins/pegwork` and activate it. The README's quick start registers the `examples/sifg` package.

A checkout set up this way has dev dependencies in `vendor/`. It is for development only: production sites must install from a release zip (`bin/build-zip.sh`), which excludes dev dependencies.

## Checks

CI (`.github/workflows/ci.yml`) runs all of these on every pull request. Run them before you push:

| Check | Command |
| --- | --- |
| PHP coding standards (WPCS, PHPCompatibility) | `composer lint` |
| JSON Schema and validator agree on fixtures | `composer schema-fixtures` |
| Module boundary | `bash bin/check-module-boundary.sh modules tests/fixtures/modules` |
| JS lint | `npm run lint:js` |
| JS build and size budget | `npm run build && npm run check:size` |
| JS unit tests (Vitest) | `npm run test:unit` |
| PHP integration tests | `composer test` |
| Browser tests (Playwright, axe) | `npm run test:e2e` |
| Release zip | `bin/build-zip.sh` |

`composer test` needs an empty WordPress test database, which is wiped on every run. Either install the suite with `bin/install-wp-tests.sh <db> <user> <pass> <host> <wp-version>` and set `WP_TESTS_DIR`, or set `WP_CORE_DIR` (a WordPress core copy) and the `WP_TESTS_DB_*` variables read by `tests/wp-tests-config.php`.

### Browser tests

The specs in `tests/e2e/specs/` run against any WordPress with Pegwork active and the test must-use plugins in `tests/e2e/mu-plugins/` loaded. Two ways to get one:

- Docker: `npm run wp-env -- start`, then `npm run test:e2e`.
- No Docker (macOS with Local): `bin/e2e-local-site.sh start e2e 8121`, then `source ~/wp-envs/e2e/.e2e-env` and `npm run test:e2e`. Stop it with `bin/e2e-local-site.sh stop e2e`.

Run one spec with `npm run test:e2e -- tests/e2e/specs/no-js.e2e.js`. Details and conventions: `docs/e2e.md`.

## Coding standards

- PHP follows the WordPress coding standards as configured in `phpcs.xml.dist`: prefix globals with `pegwork`, translate every user-facing string in the `pegwork` text domain, escape on output, check a nonce and a capability on every state change, and use `$wpdb->prepare()` for every query with variables.
- JS follows `@wordpress/scripts` (ESLint and Prettier). WordPress packages are externals, not dependencies.
- New UI needs a Playwright spec with an axe check (no serious or critical violations at 1280 and 782 px wide).
- Behaviour changes need PHPUnit integration tests.
- Strings for translation: `npm run make-pot` regenerates `languages/pegwork.pot` (needs WP-CLI).

## Module boundary

Code in `modules/` may use only the public API, `Pegwork\Api` (`docs/extension-api.md`), never other `Pegwork\` classes. This keeps each module separable into its own plugin. `bin/check-module-boundary.sh` enforces it in CI. If a module needs something the API lacks, propose an addition to the API in your PR.

## Pull requests

- One topic per pull request, branched from `main`.
- Fill in the pull request template: what changed, why, and how you tested it.
- All CI checks must pass. Add or update tests and docs (`docs/`, `readme.txt`) for what you change.
- Keep commits focused, with messages that say what changed and why.

## License of contributions

Pegwork is licensed GPL-2.0-or-later. There is no contributor license agreement. By opening a pull request you agree that your contribution is licensed under GPL-2.0-or-later, the same license as the project, and that you have the right to submit it.
