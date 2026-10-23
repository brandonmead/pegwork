# Browser end-to-end tests

Playwright specs in `tests/e2e/specs/`, with axe for accessibility. They run against any WordPress that has Pegwork active and the `examples/sifg` package registered.

```
playwright.config.js
.wp-env.json                          CI site: plugin plus the mu-plugins below
bin/e2e-local-site.sh                 throwaway local site (no Docker)
tests/e2e/mu-plugins/pegwork-e2e-sifg.php   registers examples/sifg as a file package
tests/e2e/mu-plugins/pegwork-e2e-forms.php  forms test package, mail log, Turnstile test keys per mode, admin-only helper routes (docs/forms.md)
tests/e2e/mu-plugins/pegwork-e2e-automation.php  automation test package, mail failure switch, module toggle, in-process cron, registry automation read, reset (runs and overrides; e2e_ site automations) (docs/automation.md)
tests/e2e/mu-plugins/pegwork-e2e-links.php  signed link helpers: Project create and read, link mint, link rate limit raised (docs/automation.md)
tests/e2e/mu-plugins/pegwork-e2e-webhooks.php  webhook test package, in-process receiver for receiver.e2e.test, fixed test secret on reset, receiver down switch (docs/automation.md)
tests/e2e/packages/                   file packages that only the browser tests register
tests/e2e/utils/                      env, auth, records, a11y, no-js, fixtures
tests/e2e/specs/                      *.e2e.js (not *.spec.js, which Vitest would run)
```

## Connection settings

| Env var | Default | Meaning |
|---|---|---|
| `PEGWORK_E2E_BASE_URL` | `http://localhost:8888` | Site under test (wp-env's default). |
| `PEGWORK_E2E_USER` | `admin` | Admin login. |
| `PEGWORK_E2E_PASSWORD` | `password` | Admin password (wp-env's default). |

`npm run test:e2e` logs in once (global setup, cookies in `test-results/.auth/`) and runs every spec. Run one file with `npm run test:e2e -- tests/e2e/specs/no-js.e2e.js`. First time on a machine: `npx playwright install chromium`.

## CI

The `E2E` job in `.github/workflows/ci.yml` builds the assets, starts wp-env (`npx wp-env start`, WordPress latest, PHP 8.2), and runs the specs. On failure it uploads `playwright-report/` and `test-results/` (traces, screenshots) as the `playwright-report` artifact. View a trace with `npx playwright show-trace <trace.zip>`.

Locally with Docker: `npm run wp-env -- start`, then `npm run test:e2e`.

## Local site without Docker

`bin/e2e-local-site.sh` builds a throwaway WordPress with Local's PHP and MySQL and serves it with `wp server`. It needs Local running with at least one site started (for the MySQL socket) and `wp` at `/opt/homebrew/bin/wp`.

```sh
bin/e2e-local-site.sh start e2e 8121      # create if needed, then serve http://127.0.0.1:8121
source ~/wp-envs/e2e/.e2e-env             # exports the three PEGWORK_E2E_* variables
npm run test:e2e
bin/e2e-local-site.sh stop e2e
bin/e2e-local-site.sh destroy e2e         # stop, drop database wpenv_e2e; delete ~/wp-envs/e2e yourself
```

Subcommands: `create <name> <port>`, `start <name> <port>`, `stop <name>`, `destroy <name>`. All are idempotent. The script only touches database `wpenv_<name>` and folder `~/wp-envs/<name>`, and writes the random admin password only to `~/wp-envs/<name>/.e2e-env` (mode 600).

What `create` does, step by step (the same by hand):

```sh
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
SOCK="$HOME/Library/Application Support/Local/run/iKB6dEYW-m/mysql/mysqld.sock"
wp() { "$PHP" -d "mysqli.default_socket=$SOCK" /opt/homebrew/bin/wp --path="$HOME/wp-envs/e2e" "$@"; }
"$PHP" /opt/homebrew/bin/wp core download --path="$HOME/wp-envs/e2e"
wp config create --dbname=wpenv_e2e --dbuser=root --dbpass=root --dbhost="localhost:$SOCK"
wp db create        # needs Local's mysql client on PATH (the script adds it)
wp core install --url=http://127.0.0.1:8121 --title=E2E --admin_user=admin --admin_password=<pw> --admin_email=e2e@example.com --skip-email
ln -s <pegwork clone> ~/wp-envs/e2e/wp-content/plugins/pegwork
ln -s <pegwork clone>/tests/e2e/mu-plugins/*.php ~/wp-envs/e2e/wp-content/mu-plugins/   # every test mu-plugin
wp plugin activate pegwork
wp rewrite structure '/%postname%/'
wp server --host=127.0.0.1 --port=8121
```

The plugin clone is the checkout the script lives in (override with `PEGWORK_DIR`). Run `npm run build` there first; the editor panel needs `assets/build/`.

## Writing a spec

- `const { test, expect } = require( '../utils/fixtures' )`: `test` adds the `sifg` fixture, which fails fast if `sifg_project` is not registered and deletes Projects made with `sifg.createProject( data )` after the test.
- `utils/records.js`: `createRecord`, `deleteRecord` over REST (cookie plus nonce).
- `utils/a11y.js`: `expectNoSeriousA11yViolations( page )` fails on serious or critical axe violations (WCAG 2 A and AA tags).
- `utils/no-js.js`: `test.use( noJs )` runs a file with JavaScript off and as a visitor; import `test` from `@playwright/test` there, since the `sifg` fixture needs a login.
- `utils/listings.js`: `enableModule( page, 'Listings' )` (Modules screen; modules start disabled on a fresh site), `recordTracker( request )` (published Projects passing publish validation, pages, cleanup), `seedProjects`, `patternContent`, `cardTitles`, `publishedProjects`.
- Specs share one site and run serially (one worker). Clean up what you create.
- Block editor specs (`forms-builder.e2e.js`): the canvas is an iframe (`page.frameLocator( 'iframe[name="editor-canvas"]' )`); inserter results are `option`s whose accessible name starts with an icon glyph, so match them with `^[^A-Za-z]*<Title>$`, not `exact`; WordPress 7 focuses the inserter's Blocks tab before its search box, and a reopened inserter keeps focus on its last result; wait for the quick inserter's search box to be focused before typing; drag from the inserter with `page.mouse` (down, move in steps, up) aimed below the block toolbar, because `dragTo()` hits the toolbar popover. The spec builds form `e2e_built`; its post and page are deleted after the run and `pegwork-e2e/v1/reset` clears its entries.
- Turnstile: `forms-turnstile.e2e.js` loads Cloudflare's real widget with the published test keys, so the site and browser need `challenges.cloudflare.com`. `POST pegwork-e2e/v1/turnstile { mode }` picks the keys the forms mu-plugin defines on the next request: `pass`, `fail` (failing secret), `block` (blocking site key), `offline` (siteverify returns a `WP_Error` through `pre_http_request`), `none` (no constants); `reset` returns to `pass`.
- A page with a Turnstile form never reaches network idle: the widget keeps polling Cloudflare. Never wait for `networkidle` there; wait for an element (the form, the widget iframe, the `pegwork_form[captcha]` token having a value) or a response instead. The one `networkidle` wait in Pegwork's specs is `filters.e2e.js` on a directory page with no form, where the Interactivity API gives no hydration signal to wait for.
- With `javaScriptEnabled: false`, Chromium still parses `<noscript>` as raw text, so check its `textContent`, not its visible text.
- A full-page screenshot leaves Cloudflare's widget iframe blank; take an element screenshot of the form instead.
- Upload specs pass files with `setInputFiles( { name, mimeType, buffer } )`; a file input is focused with Tab and opened with Space.
- After a form post that reloads a core admin screen, wait for the new page's `load` event (`const loaded = page.waitForEvent( 'load' )` before submitting, `await loaded` after) before pressing a button that core's footer scripts bind. The markup shows before those scripts run, and a press on an unbound `type="button"` does nothing (the old "Erase Personal Data" flake).
