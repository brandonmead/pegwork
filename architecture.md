# Pegwork architecture

Purpose: durable reference facts for the Pegwork plugin (envs, packaging, decisions, gotchas). Last verified: 2026-10-18.

## Product
- Pegwork (full name "Pegwork Platform for WordPress"): one WordPress plugin, an application builder: content builder (JetEngine-like CPTs, fields, taxonomies, relationships), listings/templates, filters (JetSmartFilters-like), form builder (Gravity Forms-like), automation builder (Salesforce Flow-like), all on one schema registry.
- First site: SI for Good (sibling folder `../SIforGood`, private repo brandonmead/siforgood).
- Main long-term consumer: BarnCRM for WordPress (separate add-on plugin requiring core). BarnCRM Salesforce edition: github.com/Nonprofit-Collaborative/BarnCRM (Apache-2.0).

## Canonical sources
- BMemory is canonical: `specification/wordpress-platform-plugin.md`, `decision/wordpress-platform-plugin-packaging.md`. Local docs are copies.
- Phase 1 technical design: `docs/phase-1-design.md` (approved 2026-10-17).
- Phase 2 technical design: `docs/phase-2-design.md` (draft for owner review, 2026-10-18).

## Packaging
- One plugin with switchable modules.
- Add-ons only for: BarnCRM for WordPress, third-party integrations, paid extras if open core.

## Rendering
- Standard WordPress blocks (any block theme).
- Divi 5 path for other existing sites later.

## Versions and Phase 1 owner decisions
- PHP minimum 8.2. WordPress minimum 6.7.
- Schema files win over DB.
- Description of 50-300 words blocks publish/pending (drafts exempt).
- License: GPL-2.0-or-later (decided 2026-10-17; BMemory `decision/wordpress-platform-plugin-license.md`). BarnCRM for WordPress also GPL; BarnCRM Salesforce edition stays Apache-2.0.

## Name and identifiers (final, decided 2026-10-17)
| Thing | Value |
|---|---|
| Name | Pegwork ("Pegwork Platform for WordPress") |
| Slug, text domain | `pegwork` |
| Main file | `pegwork.php` |
| Function, hook, option prefix | `pegwork_` |
| Constant prefix | `PEGWORK_` |
| PHP namespace | `Pegwork\` (public API `Pegwork\Api`) |
| WP-CLI | `wp pegwork` |
| Future REST namespace | `pegwork/v1` |
| Repo | `brandonmead/pegwork` (private for now) |
| License | GPL-2.0-or-later |
- Likely-free domains at naming time: pegwork.org, pegworkwp.com, getpegwork.com (not registered).

## Repo
- GitHub `brandonmead/pegwork` (private for now). Local folder `/Users/bmead/Documents/Mead Drive/AI Stuff/Claude/Claude Code Projects/Pegwork` (main checkout); session worktrees under `~/worktrees/pegwork/`.

## Dev environment
- LocalWP site `siforgood`: domain siforgood.local, path `~/Local Sites/siforgood`, PHP 8.2.29, nginx, MySQL 8.4. Created 2026-10-17 via Local's GraphQL API.
- Plugin symlinked: `wp-content/plugins/pegwork` -> `~/worktrees/pegwork/pr6-export` (since 2026-10-17; a standalone clone, because the Mead Drive checkout has a corrupt duplicate ref that breaks `git fetch`; repoint with `ln -sfn` when that worktree is removed, then `composer install` and `npm run build` there; the editor panel needs `assets/build/`). `wp-content/plugins/sifg-config` -> `~/worktrees/siforgood/sifg-config/sifg-config` (active; supplies the sifg package). Theme `siforgood` symlinked from `~/worktrees/siforgood/theme/theme/siforgood` (active).
- Register a package for one WP-CLI run without an mu-plugin: `--require=<file>` where the file calls `WP_CLI::add_wp_hook( 'pegwork_register', fn( $b ) => $b->add_package( ... ) )`. Same trick with `pre_option_pegwork_schema` simulates the DB source.
- WP-CLI: `wp` needs Local's PHP 8.2 (`~/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php`) with `-d mysqli.default_socket=<socket>`; socket `~/Library/Application Support/Local/run/iKB6dEYW-m/mysql/mysqld.sock`. WP_DEBUG is off in wp-config; force it per run with `--exec="define('WP_DEBUG',true);"`.
- Tests: local DB `pegwork_tests` (or a per-session one such as `pegwork_tests_pr3` via `WP_TESTS_DB_NAME`; root/root, same socket), emptied each run. Run with `WP_CORE_DIR=<core copy> WP_TESTS_DB_HOST="localhost:<socket>" vendor/bin/phpunit` (wp-phpunit + `tests/wp-tests-config.php`). CI uses `bin/install-wp-tests.sh` and `WP_TESTS_DIR`. Core copy: rsync the site's `app/public` minus `wp-content` and `wp-config.php` into a scratch dir, with empty `wp-content/themes` and `plugins`.
- JSON Schema agreement: `composer schema-fixtures` (`bin/check-schema-fixtures.php`). Each `tests/fixtures/invalid/*` has `expected.json` (`code`, `file`, `pointer`, `schema`); `schema: false` marks cross-object rules JSON Schema cannot express, which must then pass the JSON Schema.

## Registry (PR 2)
- `pegwork_schema` option shape (written by `wp pegwork schema import`): `{ content_types: [], taxonomies: [], relationships: [], forms: [], automations: [], options_pages: [], manifest: {} }`, each list holding objects in the file format (canonical form when imported); `manifest` is the last imported package's manifest, read only by export. Source label and error file `option:pegwork_schema`. Kind to directory and section maps: `Loader::DIRS`, `Loader::SECTIONS` (Exporter, Importer, fixture tests iterate them).
- Load order: packages in registration order, then DB entries. Rejection is per package (or per DB entry); references resolve across all accepted sources.
- Decoding to PHP arrays cannot tell `{}` from `[]`; the PHP validator accepts an empty array as either, the JSON Schema does not. Normalizer drops empty optional objects so canonical output never holds `{}`.
- Every kind and property: `docs/registry-reference.md`.

## Registry sections (shared registry sections PR, 2026-10-18)
- Optional, additive format-1 sections: `listing` on content types, `options-pages/`, `forms/`, `automations/`. Data only: no runtime behavior; the listings, forms and automation modules and the options page screen read them through `Pegwork\Api\Registry`. API stays 0.2.0; registry hash (`Registrar::hash`) unchanged by them.
- Rules live in traits used by `Validator`: `src/Registry/Rules/ListingRules.php`, `OptionsPageRules.php`, `FormRules.php`, `AutomationRules.php`. Shared field checks stay in `Validator::field( $field, $pointer, $context )`, context `content_type|options_page|form` (`Validator::field_types( $context )`: `post` only on options pages, `file` only on forms).
- `Validator::references( $kind, $data, $file, $known )`: `$known[kind][key]` is the normalized object (Loader and Importer both), so cross-object rules read defaults (taxonomy `select`, field `visibility`). A non-array value means "exists" only (tests, `Importer::import` known keys).
- Content type field keys `id`, `status`, `type` are `reserved_field_key` (record event payloads and automation `record.*` paths use them for the post).
- Automation paths, payload keys per event and condition limits are constants on `AutomationRules` (`RECORD_EVENT_KEYS`, `FORM_EVENT_KEYS`, `CONDITION_MAX_DEPTH` 5, `CONDITION_MAX_NODES` 50, `DURATION_MIN` PT1H, `DURATION_MAX` P2Y); keep `RECORD_EVENT_KEYS` in step with `docs/events.md`.
- Options page reserved names: `OptionsPageRules::RESERVED_OPTION_NAMES` (WP 7.1 `populate_options()` plus core-managed options such as `cron`), and `wp_`/`pegwork_` prefixes.
- `wp pegwork schema list` rows for the sections come from `Registry\Status::sections()`.
- Fixtures: `tests/fixtures/valid/sections` uses every section; one invalid fixture per rule (generated fixtures are ordinary files, edit them directly).

## Export and import (PR 6)
- `wp pegwork schema export|import` (`docs/export-import.md`). Export writes one package dir (canonical JSON, sorted keys, trailing newline, no paths); manifest from the only contributing source, else generic `site` 0.0.0. Import validates with `Loader::read_package` (file package rules), upserts by key, `--prune` touches DB entries only, re-runs `Plugin::init()` and seeds missing terms; never deletes terms, posts or meta.
- Gate test `tests/Integration/ExportImportGateTest.php`. Missing-term check queries `term_taxonomy` directly so dry runs work before the taxonomy is registered.
- siforgood.local: import with `--skip-plugins=sifg-config` to make the DB source effective; with sifg-config active, imported entries are shadowed. Restore with `wp option delete pegwork_schema`.

## Content registration (PR 3)
- `Plugin::init` (init 5) registers post types and taxonomies every request. `pegwork_registry_hash` (md5 of canonical registry) gates seeding and `flush_rewrite_rules( false )`, deferred to `wp_loaded` (immediate if it already fired).
- Core lets anyone with `assign_terms` create terms in a non-hierarchical taxonomy (REST terms endpoint checks `assign_terms`, not `edit_terms`). Locked taxonomies therefore also need the `pre_insert_term` guard (`Taxonomies::guard_locked`); seeding and WP-CLI bypass it.
- WP only fills `rewrite['with_front']` when permalinks are on at registration time; tests that set a permalink structure must re-run `Plugin::init()`.
- Non-core WP tests do not reset post types between tests; PostTypes/Taxonomies unregister keys that leave the registry, so `Plugin::init()` in `tear_down` cleans up.

## Fields and validation (PR 4)
- Meta key `_{post_type}_{field}` or `wp.meta_key` (`^_?[A-Za-z][A-Za-z0-9_]*$`, meta storage only; private fields must start with `_`).
- REST meta schemas allow empty values (date pattern allows `''`, email is `anyOf` email or empty, select enum includes `''`) so optional fields can be cleared; completeness is RecordValidator's job. Invalid email gives `rest_no_matching_schema`, bad date `rest_invalid_pattern`.
- RecordValidator: `rest_pre_insert_{type}`; skips `/autosaves` routes (autosave reuses the parent controller's prepare and would block on published posts). Single-select term count applies to every save; all other rules only for publish/pending/future. Error `pegwork_invalid_record`, status 400, `data.fields` = field or taxonomy key => message.
- Word count mirrors the PR 5 panel `countWords()`: block comments, tags and `&nbsp;`/`&#160;` become spaces, trim and split on JS `\s` (incl. U+00A0 etc.).
- Hooks added in `init` are wiped by the WP test suite's hook restore between tests; guard re-adds with `has_filter`, not a static "already hooked" flag.
- siforgood.local sample content: Project ID 24 "Sample Project (delete before launch)" (published, 2026-10-17).

## Display: Listings A (Phase 2 PR 2)
- `modules/listings` (`PegworkListings\`, own autoloader in `module.php`, `PEGWORK_LISTINGS_DIR`); `modules/filters` is a skeleton. Modules use `PEGWORK_DIR`/`PEGWORK_FILE` only to find `assets/build/`.
- Core keeps a block's own markup when a source returns null, so hide-when-empty is a `render_block` filter on paragraph, heading, button, image. Core adds the source's `uses_context` to `WP_Block::$context` during binding processing, so the filter sees `postId`.
- Core swaps a schema-invalid block attribute for its default before render; the conditional reads `parsed_block['attrs']` so malformed conditions fail closed.
- WP 7.x adds `class="wp-block-paragraph"` to paragraphs; tests match `>text</p>`.
- Editor bindings (verified 2026-10-18 in headless Chrome): 6.7 and 7.1.3 both register custom sources and call `getValues` (preview in post editor and Query Loops). `getFieldsList` is dropped by the blocks store for every source except `core/post-meta` through 6.8; from 6.9 it is kept and its shape is `[ { label, args, type } ]` (7.1 lists "Pegwork field" in the Attributes picker). Templates have no `postId`, so the preview shows field labels.
- Throwaway browser-check sites: `~/wp-envs/listings` (7.1.3, DB `wpenv_listings`, port 8131) and `~/wp-envs/core-6.7` (DB `wpenv_listings67`, port 8132); plugin symlinked to `~/worktrees/pegwork/listings-a`, mu-plugin registers `examples/sifg`. Admin password not stored.

## Relationships (content builder PR 2)
- `docs/relationships.md`. `Content\Relationships` registers `_rel_{key}` (multi-row integer meta on the "from" type), `rel_{key}` REST collection param, `deleted_post` cleanup; RecordValidator checks target type and `one_to_many` on every save, `required` on complete saves.
- Core REST only exposes meta for types supporting `custom-fields`; `PostTypes::args()` adds it for "from" types too, not just types with meta fields.
- REST meta values are schema-validated in `update_value`, after `rest_pre_insert`, so RecordValidator sees raw IDs first (ID 0 gives `pegwork_invalid_record`, not `rest_invalid_param`).
- `one_to_many` and reverse lookups query all post statuses (trash included), so trash and restore never break the limit.
- Registry `Validator::references()` gets each known object's normalized data (not `true`) so `meta_key_conflict` can read the "from" type's fields.
- `RecordValidator::validate()` with a post ID and no value for a relationship key uses the stored links, so `Pegwork\Api\Records::update()` (which passes the ID and has no relationship values) keeps existing links valid. The Records API does not write links yet.

## Relationship picker (content builder PR 3)
- Config `types.<from>.relationships[]` from `EditorPanel::build_config( $types, $taxonomies, $relationships )`; picker in `assets/src/editor-panel/relationship-control.js`, pure logic `relationship-logic.js` (`docs/editor-panel.md`).
- `ComboboxControl` (WP 6.7 to 7.1) calls `onChange` before it `speak()`s `messages.selected`, so "Linked {title}" is spoken from an effect after the next render and `messages.selected` is blanked.
- Core `TokenInput` (the combobox input) sets `aria-owns`, not `aria-controls`, while expanded: axe `aria-required-attr` (critical) only with the list open. `expandOnFocus` is off so programmatic focus after a removal does not open it; the E2E axe check runs with the list closed.
- `getEntityRecords` for postType defaults to `context: 'edit'`, which 403s for users without `edit_posts` on the "to" type; the picker passes `context: 'view'` and decodes `title.rendered`.
- Browser checks: throwaway sites `~/wp-envs/picker` (WP 7.1.3, DB `wpenv_picker`, port 8141) and `~/wp-envs/picker67` (WP 6.7, DB `wpenv_picker67`, port 8142), mu-plugin `pegwork-demo.php` registers `examples/sifg`; harness site `~/wp-envs/picker_e2e` (port 8143). PHPUnit core copy `~/wp-envs/picker-core`, DB `pegwork_tests_picker`.

## Options pages (content builder PRs 4 and 5)
- `docs/options-pages.md`. `src/Options/`: `Pages` (register_setting per page from `Registrar::register`, group `pegwork_{key}`, `option_page_capability_pegwork_{key}`), `Screen` (admin_menu default priority, hooked in `Plugin::boot` so it precedes module screens), `Values` (type checks, partial save), `BindingSource` (`pegwork/option`, core, no `uses_context`).
- `RecordValidator::check_values( $fields, $values, $complete = true )` holds the shared required/validate rules; type checks stay with REST schemas (records) or `Values::check_type()` (options pages).
- `register_setting()` adds a new `sanitize_option_{name}` closure each call: `Pages::register()` unregisters its own setting first, since tests and imports re-run `Plugin::init()`.
- Core sanitizes twice on an option's first save (`update_option()` then `add_option()`); errors are deduplicated by code (`pegwork_field_{field}`).
- `settings_errors()` prints messages unescaped: messages are `esc_html`ed when added (they quote submitted text); the screen renders its own notices, `settings_errors( 'general' )` only for core's "Settings saved.".
- `get_option( $page )` returns the registered default (an array) when unset; pass an explicit default (`get_option( $page, false )`) to test for "never saved".
- ComboboxControl `help` text fails axe color contrast on the admin background (#f0f0f1): the picker renders its own `p.description`.
- Browser checks: harness site `~/wp-envs/options` (DB `wpenv_options`, port 8181). PHPUnit DB `pegwork_tests_options` against `~/wp-envs/core-latest` (WP 7.1.3); the bundled wp-phpunit does not run against `~/wp-envs/core-6.7` (missing `class-wp-phpmailer.php`), so 6.7 is CI only.

## Events and Records API (Phase 4 PR 1)
- Contract and payloads: `docs/events.md`. `Pegwork\Api\VERSION` 0.2.0 for the whole build cycle (Phase 4 decision 7); never bump past it in this cycle.
- `Events\RecordEvents` hooks: `rest_pre_insert_{type}` at priority 1000 (after RecordValidator, so rejected saves take no snapshot), `rest_after_insert_{type}`, `pre_post_update`, `wp_after_insert_post`, `before_delete_post`, `deleted_post`, plus `rest_request_before|after_callbacks` to track REST routes (source `rest`, autosave detection).
- Snapshot first-wins per post, consumed on emit; REST marks the post flushed after its listeners run, so the controller's own `wp_after_insert_post` does not emit twice. `wp_publish_post` takes no `pre_post_update` snapshot: the status before comes from `$post_before`.
- Records writes: `wp_insert_post`/`wp_update_post` with `fire_after_hooks` false, `meta_input`, then `wp_set_object_terms` by term ID (no `tax_input`: it checks `assign_terms` for the current user), then `wp_after_insert_post()` called directly; `RecordEvents::claim()` carries the origin.
- Gotcha: the REST autosaves controller defines `DOING_AUTOSAVE` (unless `WP_RUN_CORE_TESTS`), which then stays defined for the rest of a PHPUnit process; autosave detection therefore uses the route stack, never the constant.

## Automation engine (Phase 4 PR 3)
- `docs/automation.md`. Module `automation` (`modules/automation`, `PegworkAutomation\`, `PEGWORK_AUTOMATION_DIR`). Tables `{prefix}pegwork_runs`, `{prefix}pegwork_run_steps` (dbDelta on boot when option `pegwork_automation_db_version` != `Schema::VERSION`); dropped by `modules/automation/uninstall.php`, which core `uninstall.php` includes (it includes every `modules/*/uninstall.php`).
- Hooks: listener on `pegwork_event`; WP-Cron hooks `pegwork_automation_run` [run id] and `pegwork_automation_maintenance` (daily 03:00 site time, self-rescheduling, re-created on boot when missing). Options `pegwork_automation_overrides`, `pegwork_automation_retention_days`. Filters `pegwork_automation_max_depth` (3), `pegwork_automation_time_budget` (20), `pegwork_automation_retention_days`, `pegwork_automation_spawn_cron`.
- Scheduler: WP-Cron (`WpCronScheduler`), not Action Scheduler (Phase 4 decision 8). A request that schedules a due run calls `spawn_cron()` at shutdown; WP-CLI commands also spawn due cron on `wp_loaded`. `Engine::use( $clock, $scheduler )` swaps clock and scheduler; tests use `FrozenClock` and `tests/Support/FakeScheduler.php`.
- Action Scheduler check (2026-10-18), why it is not bundled: 3.9.3 (last release for WP 6.7) has the unpatched insecure deserialization in `ActionScheduler_DBStore::fetch_action()` (`unserialize()` of the stored schedule without `allowed_classes`, `classes/data-stores/ActionScheduler_DBStore.php:397`), public issue https://github.com/woocommerce/action-scheduler/issues/1318, fixed in 4.1.0 by https://github.com/woocommerce/action-scheduler/pull/1341 and https://github.com/woocommerce/action-scheduler/pull/1344 (4.1.0 release notes, "Security" entry: https://github.com/woocommerce/action-scheduler/releases/tag/4.1.0). Exploit needs write access to the actions table. No formal advisory: GitHub advisory database (GraphQL `securityVulnerabilities`, ecosystem COMPOSER, package `woocommerce/action-scheduler`: none), repo security advisories (none), WPScan https://wpscan.com/plugin/action-scheduler/ (0), Patchstack https://patchstack.com/database/wordpress/plugin/action-scheduler (0), NVD keyword search (0); Wordfence's listing page did not load. 4.1 requires WP 6.8, 4.2 requires 6.9; no 3.x backport. 3.9.3 itself loaded and ran async and single actions cleanly in local wp-phpunit on WP 6.7 and 7.1.3 (PHP 8.2), so loading was not the blocker. Other post-3.9.3 fixes of note: 4.2.0 enforces unique inserts atomically.
- Test DB gotcha: `CREATE TABLE` inside a WP test is rewritten to a temporary table and DDL commits the test transaction, so `tests/Support/AutomationTestCase` installs the tables in `set_up_before_class`. The WP test suite defines `DISABLE_WP_CRON`, so the shutdown spawn is checked on a real site only.
- Local runs: test DB `pegwork_tests_automation`; throwaway site `~/wp-envs/automation` (port 8171, DB `wpenv_automation`, mu-plugin `pegwork-engine-fixture.php` registers `tests/fixtures/valid/engine`). WP 6.7 locally needs `wp-phpunit/wp-phpunit` 6.7.x as `WP_TESTS_DIR` (the 7.1 suite needs `class-wp-phpmailer.php`); even then about 30 existing Pegwork tests fail locally on 6.7 with or without the module's tests (environment, not code; CI's `install-wp-tests.sh` 6.7 job is the reference).

## Automation email, schedules, waits, retries (Phase 4 PR 4)
- `docs/automation.md`. New WP-Cron hooks `pegwork_automation_schedule` [key] (one per enabled schedule automation, ensured on boot) and `pegwork_automation_sweep` [key, last ID, created] (sweep continuation); both cleared by `uninstall.php`. Steps `send_email`, `wait`; retry policy in `Retry` (email 2 x PT15M, webhook 3 with PT1M/PT10M/PT1H). Waits and retries both use `waiting` + `resume_at` + `current_step`.
- Recipients `{option:<page>.<field>}` call `pegwork_get_option( $page, $field )` (core options pages PR) when it exists, else `options_unavailable`; `SendEmail` takes an injectable reader for tests. Option tags are parsed from the template, never from rendered values.
- Log privacy: email step summaries store masked recipients (`o***@example.org`) and the subject rendered in `log` mode (private fields `[private]`); deliberate tightening of the design, which stored full recipients.
- Text-mode merge tags decode WordPress-stored entities (`wp_specialchars_decode`, as core emails do); HTML mode escapes with `esc_html`/`esc_url` (no double encoding).
- CLI `run|tick|retry` logic is in `PegworkAutomation\Manual` (testable without WP-CLI). `--now` is refused when `wp_get_environment_type()` is `production`, which is WordPress's default when `WP_ENVIRONMENT_TYPE` is unset (true in the WP test suite and on `bin/e2e-local-site.sh` sites; `wp config set WP_ENVIRONMENT_TYPE local --type=constant` enables it). `--now`/`--sync` swap in `InlineScheduler`; manual runs never reschedule the regular firing, and `tick` never prunes or interrupts.
- Gotchas: Records sanitizes text fields with `sanitize_text_field` (tags stripped), so escaping tests write markup with `$wpdb->update`. Tests that jump the clock by a year make every older record stale. The WP test env is `production`, so `Manual::clock()` takes an explicit environment in tests.
- Local runs: test DB `pegwork_tests_autoemail` (core copy `~/wp-envs/autoemail-core`, WP 7.1.3); throwaway site `~/wp-envs/autoemail` (port 8191, DB `wpenv_autoemail`, `WP_ENVIRONMENT_TYPE` local, mu-plugins `pegwork-engine-fixture.php` and `mail-capture.php` logging `wp_mail()` to `wp-content/mail.log`).

## Forms runtime (Phase 3 PR 2)
- `docs/forms.md`. Module `forms` (`modules/forms`, `PegworkForms\`, `PEGWORK_FORMS_DIR`). Table `{prefix}pegwork_entries` (dbDelta on boot when option `pegwork_forms_db_version` != `Schema::VERSION` `1`); kept on uninstall, so no `modules/forms/uninstall.php`.
- Core API (0.2.0, additive): `pegwork_sanitize_field()` (`Content\Meta::sanitize`, URLs http/https only), `pegwork_validate_fields()` (`RecordValidator::check_values()`, shared with records and options pages; its `$checked_boolean` makes a required boolean mean "must be true"), `pegwork_save_form()`/`pegwork_delete_form()` (`Registry\Forms`: Validator plus references against the effective registry, upsert into `pegwork_schema.forms`, `Plugin::init()`; file keys 409).
- Fail closed: `Definition::unsupported()` keeps forms with `captcha` or `file` items unrendered and unhandled until PR 4 (PR 3 opened `target`, `confirm` and bound items).
- Record mapping and confirmation (PR 3, `docs/forms.md`): bound items resolve at runtime in `Definition::resolve()` (registry field or taxonomy plus presentational overrides); validation splits unbound (`pegwork_validate_fields()`) and bound (`pegwork_validate_record()`); writes go through `Pegwork\Api\Records` in `RecordWriter`, run as the entry's user (`wp_set_current_user`, restored), `post_content` as escaped `core/paragraph` blocks. Confirmation in `Confirm`: sha256 `token_hash`, expiry in `meta.confirm_expires`, GET page plus POST button, `EntryState::claim()` (single `UPDATE ... WHERE status = 'unconfirmed'`), re-validation, draft plus `failed` plus admin mail on failure, `form.confirmed`. Cap 3 confirmation emails per address per 24 h counted from entries with a token. Confirmation requests: `nocache_headers()`, `wp_robots_no_robots`, `Referrer-Policy: no-referrer`; the block is prepended to the main content (`the_content` priority 8) when the page lacks it.
- Gotcha: a bound `rich_text` item is a textarea, so `sanitize_textarea_field` strips tags and turns a lone `<` into `&lt;`; `RecordWriter::paragraphs()` escapes without double encoding.
- Test fixture `tests/fixtures/forms-records` references `examples/sifg` types, so it lives outside `tests/fixtures/valid/` (FixturesTest loads every valid package alone).
- POST handled on `template_redirect` priority 1; inputs `pegwork[<key>]`, control fields `pegwork_form[key|token|nonce|url]` (honeypot `url`). Redirect 303 to `?pegwork_sent=<key>`.
- No `#fragment` in the form action or the success URL: Chrome (per the HTML spec) skips `autofocus` when the URL targets an element, so the error summary and success region would not get focus (verified in the E2E spec 2026-10-18).
- Rate limits in transients `pegwork_forms_rl_<hmac>`; IP limit counts spam too. Time token HMAC-SHA256 with `wp_salt( 'nonce' )`, 3 s floor, 7 day ceiling.
- `wp_mail` refuses an empty body (PHPMailer "Message body empty"); a body that renders empty is replaced by `{all_fields}`. The WP test mailer turns `\n` into `\r\n` in bodies.
- E2E: axe cannot inject into a JavaScript-off page (`page.evaluate` hangs), and `browser.newContext()` in a spec inherits `test.use( noJs )`; the spec re-serves the page HTML through `route.fulfill` to a context with `javaScriptEnabled: true`. axe excludes `.wp-block-navigation` (Twenty Twenty-Five's page list fails `list`, not Pegwork markup).
- Local runs: test DB `pegwork_tests_forms` with core `~/wp-envs/core-latest` (read only); site `~/wp-envs/forms` (`bin/e2e-local-site.sh start forms 8161`, DB `wpenv_forms`). The script now links every `tests/e2e/mu-plugins/*.php`. PR 3: test DB `pegwork_tests_formsconfirm`, site `~/wp-envs/formsconfirm` (port 8165, DB `wpenv_formsconfirm`). Last verified 2026-10-18.

## Admin area (content builder PR 1)
- Top-level menu slug `Pegwork\Api\ADMIN_MENU` (`pegwork`), `manage_options`: Overview (`pegwork`, `admin_menu` priority 9, position 0), Modules (`pegwork-modules`, priority 999 so it stays last). Submenu `position` is an index into the list built so far, not a sort key; contract in `docs/extension-api.md`.
- `add_submenu_page()` skips screens the user lacks the capability for, so a lower-capability screen becomes the first item and the top-level link for that user (`AdminMenuTest`).
- `Registry\Status` turns a `Result` plus package dirs into rows for Overview and `wp pegwork schema list|status`. Rejected errors group by the package dir containing the error file (raw or realpath), else `option:pegwork_schema`.
- Module toggles: form posts to `admin-post.php?action=pegwork_module`, nonce `pegwork_module_{slug}` (hidden input without `id`, since `wp_nonce_field` repeats `id="_wpnonce"` per row), notice via 60 s transient `pegwork_module_notice_{user}`. Rules live only in `Modules\Loader` (`missing_requirements()`, `dependents()`).
- Tables use `widefat striped`, not `wp-list-table`: core's small-screen list-table CSS stacks cells without headers.
- Browser checks: throwaway site `~/wp-envs/admin` (DB `wpenv_admin`, `wp server` on 127.0.0.1:8111, mu-plugin `pegwork-demo.php` registers `examples/sifg`, fixture modules, a demo `edit_others_posts` submenu; option `pegwork_demo_rejected` adds an invalid package). Playwright 1.56 with the cached `chromium_headless_shell-1243` via `executablePath`, `@axe-core/playwright` 4.11, installed in a scratch dir. `wp core download` needs `-d memory_limit=1G`; `wp db create` needs a `mysql` client on PATH (create the DB with Local's `mysql-8.4.0+2` binary instead).

## Tooling
- PHPUnit 9.6 (WP 6.7 test suite rejects PHPUnit 10+), yoast/phpunit-polyfills 3, WPCS 3, PHPCompatibilityWP 2.1 (PHPCompatibility 9.3.5; 10.x still alpha). `composer.lock` resolved for platform PHP 8.2.
- CI matrix PHP 8.2/8.4 x WP 6.7/latest, MySQL 8.0 service; runner needs `subversion` for the install script.
- Verified 2026-10-17: `register_meta` `label` arg present in WP 6.7, absent in 6.6; `registerBlockBindingsSource` public in 6.7.
- JS: `@wordpress/scripts` 36.0.0 (Node `^22.22.2`), `test-unit-js` runs Vitest 5 (Jest is legacy as of scripts 36). Lockfile needs npm 11 (npm 10.9 crashes with `edgesOut` on the Vitest peer set): locally `npx -y npm@11.20.0 ci`. WordPress packages are externals, declared in `eslint.config.cjs` `import/core-modules`, not installed.
- WP admin user `admin`. Password not stored: use Local's one-click admin or `wp user update`.
- Browser E2E (`docs/e2e.md`): Playwright 1.63.0 + axe 4.13.0, specs `tests/e2e/specs/*.e2e.js` (not `.spec.js`, Vitest would run those), `npm run test:e2e`. CI job `E2E`: wp-env 11.16.0 (`.wp-env.json`, WP latest, PHP 8.2, mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-sifg.php` registers `examples/sifg`), site on `localhost:8888`, admin/password. Locally: `PEGWORK_E2E_BASE_URL`, `PEGWORK_E2E_USER`, `PEGWORK_E2E_PASSWORD`; `bin/e2e-local-site.sh start <name> <port>` builds a throwaway site in `~/wp-envs/<name>` (DB `wpenv_<name>`, Local PHP and MySQL, `wp server`) and writes credentials to `~/wp-envs/<name>/.e2e-env`. `wp db create` needs Local's `mysql` client on PATH (the script adds `lightning-services/mysql-8.4.0+2`). Last verified 2026-10-18.

## Gotchas
- Folder is under Mead Drive (synced), which creates "<name> 2.<ext>" duplicates, even inside .git. Stage with explicit paths, never `git add -A`.
