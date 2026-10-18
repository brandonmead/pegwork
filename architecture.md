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
- `pegwork_schema` option shape (written by `wp pegwork schema import`): `{ content_types: [], taxonomies: [], relationships: [], manifest: {} }`, each list holding objects in the file format (canonical form when imported); `manifest` is the last imported package's manifest, read only by export. Source label and error file `option:pegwork_schema`.
- Load order: packages in registration order, then DB entries. Rejection is per package (or per DB entry); references resolve across all accepted sources.
- Decoding to PHP arrays cannot tell `{}` from `[]`; the PHP validator accepts an empty array as either, the JSON Schema does not. Normalizer drops empty optional objects so canonical output never holds `{}`.

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

## Relationships (content builder PR 2)
- `docs/relationships.md`. `Content\Relationships` registers `_rel_{key}` (multi-row integer meta on the "from" type), `rel_{key}` REST collection param, `deleted_post` cleanup; RecordValidator checks target type and `one_to_many` on every save, `required` on complete saves.
- Core REST only exposes meta for types supporting `custom-fields`; `PostTypes::args()` adds it for "from" types too, not just types with meta fields.
- REST meta values are schema-validated in `update_value`, after `rest_pre_insert`, so RecordValidator sees raw IDs first (ID 0 gives `pegwork_invalid_record`, not `rest_invalid_param`).
- `one_to_many` and reverse lookups query all post statuses (trash included), so trash and restore never break the limit.
- Registry `Validator::references()` gets each known object's data (not `true`) so `meta_key_conflict` can read the "from" type's fields.
- `RecordValidator::validate()` with a post ID and no value for a relationship key uses the stored links, so `Pegwork\Api\Records::update()` (which passes the ID and has no relationship values) keeps existing links valid. The Records API does not write links yet.

## Events and Records API (Phase 4 PR 1)
- Contract and payloads: `docs/events.md`. `Pegwork\Api\VERSION` 0.2.0 for the whole build cycle (Phase 4 decision 7); never bump past it in this cycle.
- `Events\RecordEvents` hooks: `rest_pre_insert_{type}` at priority 1000 (after RecordValidator, so rejected saves take no snapshot), `rest_after_insert_{type}`, `pre_post_update`, `wp_after_insert_post`, `before_delete_post`, `deleted_post`, plus `rest_request_before|after_callbacks` to track REST routes (source `rest`, autosave detection).
- Snapshot first-wins per post, consumed on emit; REST marks the post flushed after its listeners run, so the controller's own `wp_after_insert_post` does not emit twice. `wp_publish_post` takes no `pre_post_update` snapshot: the status before comes from `$post_before`.
- Records writes: `wp_insert_post`/`wp_update_post` with `fire_after_hooks` false, `meta_input`, then `wp_set_object_terms` by term ID (no `tax_input`: it checks `assign_terms` for the current user), then `wp_after_insert_post()` called directly; `RecordEvents::claim()` carries the origin.
- Gotcha: the REST autosaves controller defines `DOING_AUTOSAVE` (unless `WP_RUN_CORE_TESTS`), which then stays defined for the rest of a PHPUnit process; autosave detection therefore uses the route stack, never the constant.

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

## Gotchas
- Folder is under Mead Drive (synced), which creates "<name> 2.<ext>" duplicates, even inside .git. Stage with explicit paths, never `git add -A`.
