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
- `modules/listings` (`PegworkListings\`, own autoloader in `module.php`, `PEGWORK_LISTINGS_DIR`); `modules/filters` likewise (`PegworkFilters\`, `PEGWORK_FILTERS_DIR`). Modules use `PEGWORK_DIR`/`PEGWORK_FILE` only to find `assets/build/`.
- Core keeps a block's own markup when a source returns null, so hide-when-empty is a `render_block` filter on paragraph, heading, button, image. Core adds the source's `uses_context` to `WP_Block::$context` during binding processing, so the filter sees `postId`.
- Core swaps a schema-invalid block attribute for its default before render; the conditional reads `parsed_block['attrs']` so malformed conditions fail closed.
- WP 7.x adds `class="wp-block-paragraph"` to paragraphs; tests match `>text</p>`.
- Editor bindings (verified 2026-10-18 in headless Chrome): 6.7 and 7.1.3 both register custom sources and call `getValues` (preview in post editor and Query Loops). `getFieldsList` is dropped by the blocks store for every source except `core/post-meta` through 6.8; from 6.9 it is kept and its shape is `[ { label, args, type } ]` (7.1 lists "Pegwork field" in the Attributes picker). Templates have no `postId`, so the preview shows field labels.
- Throwaway browser-check sites: `~/wp-envs/listings` (7.1.3, DB `wpenv_listings`, port 8131) and `~/wp-envs/core-6.7` (DB `wpenv_listings67`, port 8132); plugin symlinked to `~/worktrees/pegwork/listings-a`, mu-plugin registers `examples/sifg`. Admin password not stored.

## Display: Listings B (Phase 2 PR 3)
- `docs/display.md` "Directories"; hooks in `docs/extension-api.md`. Classes `QueryHooks`, `Constraints`, `Patterns` in `modules/listings/src/`.
- core/query gets attribute `pegwork` and provides it as context `pegwork/listing`; every block type whose `uses_context` has `query` gets it through `get_block_type_uses_context` (WP 6.5+). So post template, pagination next/previous/numbers, no results and query total all build the same constrained `WP_Query` via `query_loop_block_query_vars`. No `pre_render_block` global state (design said `pre_render_block`; context is per block, so pagination page counts always match).
- `WP_Block_Type::$uses_context` is a magic property (getter filter); appending to it fails ("Indirect modification of overloaded property"). Already registered core/query is patched through its public `attributes`/`provides_context` (tests boot the module after core blocks register).
- `build_query_vars_from_query_block()` sets no `post_status`, so core lists private records to users who can read them; the pipeline forces `publish`. Core also caches identical `WP_Query` runs, so the extra pagination queries cost no SQL.
- Featured: `pegwork_get_option()` (core since the options pages merge) guarded with `function_exists`; the reference must name a public `post` field whose `post_type` is the listing type, else newest published. Tested against the real function.
- Newest-record fallback cached in non-persistent group `pegwork_listings`, keyed on `wp_cache_get_last_changed( 'posts' )`.
- PHPUnit classes that seed terms in `wpSetUpBeforeClass` must delete `Registrar::HASH_OPTION` after the class: class data deletion removes the terms but not the hash, so later classes would never re-seed them (locked taxonomies then block `wp_set_object_terms`).
- Query counting: on WP 6.7 `WP_Theme_JSON_Resolver::$theme` is built (with `wp_get_theme()`: `stylesheet_root` and theme roots, 2 queries) only on the first `wp_get_global_settings()` object cache miss, so the first cold render pays it once. `DirectoryPerformanceTest::measure()` does flush, render, flush, then counts. Same counts on 6.7.10 and 7.1.3. Local 6.7 PHPUnit: core and `wp-phpunit/wp-phpunit` 6.7 in `~/wp-envs/listingsb-67/` (`WP_TESTS_DIR` to its wp-phpunit, `WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php`, `WP_CORE_DIR`); no svn needed.
- Editor: the variation's card is `core/pattern {slug}`; the editor replaces it on insert (verified 7.1.3 E2E). `render_block_core_pattern()` uses `do_blocks()` without parent context, so generated patterns inline the card for server rendering.
- Query budget, 500 records (`tests/perf/seed.php`), cold object cache, `DirectoryPerformanceTest` (2026-10-18, WP 7.1.3): directory Query Loop 7 queries unfiltered (page 1 and 3, 12 and 48 per page), 11 filtered; archive main query plus inherit loop 7 unfiltered, 11 filtered; identical at 12 and 48 (budget 35). Filter scenario: Type nonprofit, impact sdg-04 or sdg-13, Needs volunteers, search "data" (through a `pegwork_listing_constraints` stand-in until the filters module exists).
- Server time, 500 records, throwaway site `~/wp-envs/listingsb` (WP 7.1.3, Twenty Twenty-Five, PHP 8.2 built-in server, no page or object cache), 5 warm-up + 20 curl runs, TTFB p50/p95 ms. With opcache (`php -S` on 8152 with `opcache.so`): sample page 32/33 (baseline); archive p1 40/41, p3 45/59, filtered 45/48; directory page p1 60/65, p3 59/61, filtered 52/56. Full-request queries: archive 23 (filtered 27), directory page 28 (filtered 32). Without opcache (`wp server` as `bin/e2e-local-site.sh` runs it) everything is about 210 to 240 ms, sample page 215: PHP compile time, not the directory. Budget (p50 150, p95 300) met with opcache; PR 6 repeats it on LocalWP nginx with `bin/perf-directory.sh`.
- Browser site for this PR: `bin/e2e-local-site.sh start listingsb 8151` (DB `wpenv_listingsb`), 500 seeded Projects, page `/projects-directory/` (ID 591), throwaway mu-plugin `pegwork-perf-scenario.php` (`?perf=filtered`, `?perfq=1` prints the query count).

## Filters (Phase 2 PRs 4 and 5)
- `docs/display.md` "Filters"; classes `Definition`, `UrlState` (pure), `Hooks`, `Count`, `FiltersBlock` in `modules/filters/src/`; block `modules/filters/blocks/filters/`; view module `assets/src/filters-view/` (store logic in `store.js` with injected deps, unit tested in Node), editor `assets/src/filters-editor/`. Talks to listings only through `pegwork_listing_constraints`, `pegwork_listing_pattern` and `render_block_core/query-no-results` (module boundary).
- wp-scripts 36 `--experimental-modules`: command-line entries go to the script config only, and the module config finds entries only from `block.json` under `src/`, so `webpack.config.js` sets the module entry `filters-view`. Build output `assets/build/filters-view.js` plus `.asset.php`.
- `withSyncEvent` exists only from 6.8: the view imports `@wordpress/interactivity` as a namespace and reads it by computed name (a named import of a missing export stops the module loading on 6.7).
- Router state: 6.7 and 7.1 `populateServerData` merge a navigated page's state into the store without overwriting existing keys; `getServerState( ns )` (6.7+) has the new values. The count is read from it.
- core/query sets `data-wp-router-region` only with enhanced pagination; without it the store falls back to `location.assign`.
- Count: the block renders before the grid, so `Count` builds the bound loop's exact query vars (via a synthetic `core/post-template` `WP_Block` and `build_query_vars_from_query_block()`); the loop then hits the `WP_Query` cache. No bound loop: id-only query (1 row).
- Query counts, 500 records, `FiltersPerformanceTest` (2026-10-18, WP 7.1.3, budget 35, identical at 12 and 48 per page): page with the directory pattern (including `go_to`) 14 unfiltered, 16 filtered; bare blocks 10/12; archive with filters block 8/10. Listings counts unchanged (7/11). Full request on the seeded site (`wp server`, no object cache): directory page 29 unfiltered, 31 with impact plus volunteers, 30 on page 2 filtered, 28 all filters; archive 24, filtered 25.
- View module size: 4112 bytes raw, 1870 bytes gzipped (`npm run check:size`, limit 10240, CI JS job).
- Browser sites: `bin/e2e-local-site.sh start filters 8153` (WP 7.1.3, DB `wpenv_filters`, 500 seeded Projects, page "Projects directory" ID 504 `/projects-directory/`, router region `query-726`); `filters67` on 8154 (WP 6.7 via `wp core download --version=6.7 --force` over a created site, DB `wpenv_filters67`). On 6.7 the admin password from a 7.x install must be re-set (`wp user update --user_pass`): 6.7 cannot read the 6.8+ bcrypt hash. PHPUnit DB `pegwork_tests_filters`.
- E2E: `filters-no-js.e2e.js`, `filters.e2e.js`, helpers `tests/e2e/utils/filters.js` (16 fixture records scoped by search token `zqfiltertoken`; expected results from REST, so specs work with or without the 500 seed). Passed on 7.1.3 (seeded) and 6.7 (fixture only).

## Related records in listings (2026-10-20, branch `listings-related`)
- `docs/display.md` "Related records". `where.related` becomes constraint `related: { key, post }`; `Constraints::related_args()` calls `pegwork_related_query_args()` and requires its `post_type` to equal the loop type, else `post__in [0]`. Current post: `postId` block context (QueryHooks adds `postId` to every block using `query` context), else the queried `WP_Post`; it must pass `Fields::displayable()`.
- Show if `{ relationship, op: not_empty|empty }` via `Constraints::has_related()` (one `posts_per_page` 1 ids query; none when `post__in` is `[0]`). Core `query-no-results` cannot hide a heading outside the post template, hence the condition.
- Patterns `pegwork/<type>-related-<key>` for both ends of every relationship (no `listing` needed), registered after the per-type patterns.
- Gotcha: `WP_Block_Patterns_Registry::get_registered()` re-serializes pattern markup (`prepare_content()`: `parse_blocks` then `traverse_and_serialize_blocks`), so an attribute `{}` comes back as `[]` and the editor drops it from an object attribute. Generated `pegwork` attributes are never empty (featured without an option field uses `{"filters":false}`).
- `where` presets are block attributes, not registry data, so the relationship key is checked at render time only (no Validator rule or JSON Schema def exists for `where`).
- Query counts (`RelatedListingTest::test_query_budget`, WP 7.1.3, cold cache, related pattern with condition): 7 from a Project (24 Stories), 9 from a Story (24 Projects); same at 6 and 24 per page (budget 35).
- `wp pegwork module enable|disable <slug>...`: `Cli\ModuleCommand::toggle()` (testable without WP-CLI) applies slugs in order; exit 1 when any failed.
- Local runs: test DB `pegwork_tests_listrel` (core `~/wp-envs/core-latest`); site `~/wp-envs/listrel` (`bin/e2e-local-site.sh start listrel 8216`, DB `wpenv_listrel`).

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

## Signed links and the staleness gate (Phase 4 PR 5)
- `docs/automation.md#signed-links`. Classes `PegworkAutomation\Links\{Token, Key, Links, Handler, Page}`; `Engine::links()`. Link key: constant `PEGWORK_LINK_SECRET` (wp-config.php) else option `pegwork_link_secret` (base64 of 32 random bytes, autoload off, created on first mint, removed by the core uninstall with the other `pegwork_` options). Rate limit transients `pegwork_link_rate_<md5 of REMOTE_ADDR>` (10 minutes).
- URL `home_url( '/?pegwork_link=<token>' )`, handled on `template_redirect` priority 0 (query variable, not a rewrite rule: nothing to flush on module toggle, works with plain permalinks). Confirm form POSTs to `home_url( '/' )` with a nonce for action `pegwork_link_<sha256(token)>` (user 0 for visitors).
- Link runs: `trigger_type` `link`, depth 0, `context.link` names the link, idempotency key `link:<sha256(token)>` (each token acts once; the second click shows "Already done"). Executed in the click's request. `Executor::step_list()` is the step-list source; `UpdateRecord` refuses another record in a link run (`link_scope`).
- `Context` gains a sixth constructor argument, the automation key (the executor passes it; `{{link.*}}` needs it). `Templates` `log` mode renders `{{link.*}}` as `[link]` without minting, so no token reaches the run log. `Steps\Values` turns a list written to a field into comma-separated text (taxonomy copied into `paused_from`).
- `examples/sifg` 0.2.0: `sifg_project` gains private `paused_on` (date), `paused_from` (text), `paused_volunteers` (boolean); automation `sifg_staleness_check` (daily 09:00 sweep, P30D wait, pause, link `still_active` P365D); the approval email carries the permalink and Last verified. Tests that pin the example's field list (Overview, Status, Fields, editor panel config and Vitest, export/import counts) were updated.
- Gotchas: PHPMailer's mock stores bodies with CRLF line ends (match `\r?$`). axe cannot run on a JavaScript-off page (and the link pages' CSP would block it): the E2E spec replays the page HTML into a JavaScript-on context, as `forms-no-js.e2e.js` does. `INSERT IGNORE` on a duplicate idempotency key still consumes an auto-increment ID, so run IDs have gaps. `wp post create --tax_input` takes no JSON; set terms with `wp post term set`.
- Local runs: test DB `pegwork_tests_autolinks` (core `~/wp-envs/core-latest`, read only); site `~/wp-envs/autolinks` (`bin/e2e-local-site.sh start autolinks 8193`, DB `wpenv_autolinks`, `WP_ENVIRONMENT_TYPE` local; mail via the forms mu-plugin's `pegwork_e2e_mail` option). E2E mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-links.php` (also mapped in `.wp-env.json`; the module switch is PR 6's `pegwork-e2e-automation.php`).

## Webhooks (Phase 4 PR 7)
- `docs/automation.md#webhooks`. Classes `PegworkAutomation\Steps\CallWebhook`, `PegworkAutomation\Webhooks\{Secrets, Signature, Inbound}`; `Triggers::webhook()`, `Runs::find_key()`. Secrets: option `pegwork_automation_secrets` (`key => hex of 32 random bytes`, autoload off, generated on first use, removed by the core uninstall), one per automation for both directions; printed only by `wp pegwork automation secret <key> [--rotate]`. Rate buckets: transients `pegwork_automation_hook_<md5 of key>` (`md5('')` is the shared bucket for keys that are not enabled webhook automations), fixed 60 s windows on the engine clock.
- Route `pegwork/v1` `/automations/(?P<key>[^/]+)/webhook` (POST; any segment matches so every key gets the same answers). Rate (429), size (413) and media type (415) are checked in `rest_pre_dispatch` because WordPress decodes a JSON body in `has_valid_params()` before the permission callback runs; the signature and replay window are the permission callback (401). The decoded payload is capped at 15 KB (413) because it is stored in the run context, which `Runs::encode_context()` keeps under 16 KB by dropping non-scalar trigger keys.
- Outbound: `wp_safe_remote_post()` with `redirection` 0; signature over `<timestamp>.<body>`; delivery ID `<run id>-<step id>`. `pre_http_request` runs before URL validation, so tests and the E2E receiver (`receiver.e2e.test`, mu-plugin `pegwork-e2e-webhooks.php`) need no DNS. Real loopback receivers are blocked by `wp_safe_remote_post()` (127.0.0.1, ports other than 80/443/8080) and `wp server` is single-threaded, so the E2E receiver is in process.
- Gotcha: with only `call_webhook` added, every registry step type has a handler; `AutomationEngineTest::test_unsupported_step_fails_the_run` removes the handler by reflection to keep `unsupported_step` covered.
- Local runs: test DB `pegwork_tests_autohooks` (core `~/wp-envs/core-latest`); site `~/wp-envs/autohooks` (`bin/e2e-local-site.sh start autohooks 8214`, DB `wpenv_autohooks`).

## Automation admin screens (Phase 4 PR 6)
- `docs/automation.md#admin-screens`. Classes `modules/automation/src/Admin/` (`Screen` menu, routing, styles, notices; `AutomationList`; `RunLog` log and detail; `Actions` handlers; `Format`). Slug `pegwork-automations`, submenu position 30, `admin_menu` default priority, registered in `Module::boot()`; views `view=log` and `view=run&run=<id>` on the same page, so the menu item stays current.
- Actions: `admin-post.php` actions `pegwork_automation_toggle|run|retry`, nonce `<action>_<key or run id>` (hidden input without `id`), notice via 60 s transient `pegwork_automation_notice_{user}`. Rules shared with the CLI: `Overrides::toggle()` (new; the CLI's `enable|disable` now calls it), `Manual::run()` and `Manual::retry()` without `--sync` (queued for WP-Cron).
- Read helpers added for the log: `Runs::page( $automation, $status, $limit, $offset )`, `Runs::count()`. Core API (0.2.0, additive): `Registry::automation_source()`.
- axe `link-in-text-block`: WordPress admin does not underline links in table cells; the screen's inline style underlines `.pegwork-table a`.
- E2E: loopback WP-Cron is unreliable in containers, so mu-plugin `pegwork-e2e-automation.php` exposes `POST /pegwork-e2e/v1/automation/cron`, which runs due `pegwork_automation_run` and `_sweep` events in that request (the atomic claim absorbs a concurrent real spawn). `pre_wp_mail` at priority 20 fails mail while option `pegwork_e2e_mail_fail` is set. The spec enables the module in `beforeAll` and disables it in `afterAll`, so other specs see the menu as before.
- Local runs: test DB `pegwork_tests_autoadmin` (core `~/wp-envs/core-latest`, WP 7.1.3); site `~/wp-envs/autoadmin` (`bin/e2e-local-site.sh start autoadmin 8195`, DB `wpenv_autoadmin`).

## Forms runtime (Phase 3 PR 2)
- `docs/forms.md`. Module `forms` (`modules/forms`, `PegworkForms\`, `PEGWORK_FORMS_DIR`). Table `{prefix}pegwork_entries` (dbDelta on boot when option `pegwork_forms_db_version` != `Schema::VERSION` `1`); kept on uninstall (`modules/forms/uninstall.php`, PR 7, only clears the cleanup cron).
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

## Entries admin and privacy (Phase 3 PR 7)
- `docs/forms.md#entries-admin`. Classes `EntriesAdmin` (menu, screens, notices, nonce check), `EntriesTable` (`WP_List_Table`), `EntryActions` (one `admin-post.php?action=pegwork_entries` handler, `op` spam|not_spam|delete|create_post), `EntryFields` (labels and display text shared by screen, CSV and exporter), `Export`, `Privacy`, `Cleanup`. Table access only through `Entries`.
- The list's action form posts to `admin-post.php`, so the bulk select is named `op` (`action` picks the admin-post hook) and renders at the top only. Nonce `bulk-pegwork_entries` is the one `WP_List_Table` prints for `plural` `pegwork_entries`; the entry and delete screens print it too. Filter and search are a separate GET form (`#pegwork-entries-filter`); the form select in the table bar joins it through the HTML `form` attribute, so filter URLs never carry a nonce.
- Uses `wp-list-table` despite the small-screen note under Admin area: owner allowed it for this screen; at 782 px core's toggle buttons reveal the other cells with their `data-colname` labels; axe clean at 782.
- Row actions go in `handle_row_actions()`: calling `row_actions()` from a column adds a second toggle button.
- Sortable column `array( 'date', true, '', '', 'desc' )` (WP 6.3+ initial order) marks the default newest-first order in the header.
- `wpdb::prepare()` with no placeholders is `_doing_it_wrong`: `Entries::query()`/`status_counts()` skip prepare when the WHERE has no values.
- Nonce failures die with an explicit 403 (`EntriesAdmin::verify_nonce`); `check_admin_referer()` passes a bare int to `wp_die()`, which the WP test handler reports as code 0.
- "Create post anyway" claims the entry with `EntryState::claim( id, previous, 'processing' )`, so a concurrent confirm claim (`WHERE status='unconfirmed'`) finds nothing; it writes with `RecordWriter::draft()` (create forms, as the admin) or the key-intersection path (filter-typed entry-only forms), never `Confirm::complete()` (would mail an unproven address); it ends `received` via `EntryState::set()`. Bulk spam and not spam use `EntryState::transition()`; `Entries` keeps reads plus `insert`, `update_meta`, `delete`.
- Cron `pegwork_forms_cleanup` scheduled on boot when missing; cleared by `modules/forms/uninstall.php` (table kept). A disabled module leaves the event scheduled with no callback (harmless).
- E2E seed route `pegwork-e2e/v1/entries/seed` (mu-plugin `pegwork-e2e-entries.php`, mapped in `.wp-env.json`). The privacy E2E reads the export zip with the system `unzip`.
- Local runs: test DB `pegwork_tests_formsentries` (core `~/wp-envs/core-latest`); site `~/wp-envs/formsentries` (`bin/e2e-local-site.sh start formsentries 8167`, DB `wpenv_formsentries`).

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
