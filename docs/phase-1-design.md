# Pegwork: Phase 1 Foundation Technical Design

Status: approved by owner 2026-10-17 (open question in section 11). Product name Pegwork (full name "Pegwork Platform for WordPress"), slug `pegwork`, prefix `pegwork_`, final as of 2026-10-17. Canonical product spec: BMemory `specification/wordpress-platform-plugin.md`; packaging: `decision/wordpress-platform-plugin-packaging.md`. Version claims are unverified; confirm in PR 1.

## 0. Key decisions

- Config: JSON files in git are the source of truth. No schema-editing admin UI in Phase 1.
- Field editing: one generic block editor sidebar panel driven by the registry. No classic meta boxes.
- Storage: native post types, post meta, taxonomies. No custom tables in Phase 1.
- Stored keys never contain the product prefix, so a rename never touches content data.
- No custom REST endpoints, no event bus, no Action Scheduler in Phase 1 (section 7).

## 1. Layout, bootstrap, versions, modules

```
pegwork/
  pegwork.php             header, version constants, PHP/WP guard, autoloader, Plugin::boot()
  uninstall.php           deletes pegwork_* options only, never content
  composer.json           PSR-4 "Pegwork\\" => src/, dev deps only (phpunit, wpcs, phpcompatibility)
  package.json            @wordpress/scripts (editor panel build, JS lint)
  src/Registry/           Loader (file + DB sources), Validator, Normalizer, Exporter, Importer
  src/Content/            PostTypes, Taxonomies, Meta, FieldTypes/*, RecordValidator
  src/Admin/              EditorPanel, Notices
  src/Cli/                SchemaCommand, ModuleCommand
  src/Api/                public, semver-stable surface (section 8)
  src/Modules/            Loader + module manifest
  includes/functions.php  pegwork_registry(), pegwork_get_field(), pegwork_validate_record()
  modules/                Phase 2+: listings/, filters/, forms/, automation/ (empty in Phase 1)
  assets/src/editor-panel/  build/
  schema/registry.schema.json   JSON Schema for IDE autocompletion and CI
  tests/  tests/fixtures/  examples/sifg/   (Project package, also the test fixture)
```

- Autoloading: Composer PSR-4 only, no runtime third-party packages (so no PHP-Scoper/Strauss). PHPCS excludes `WordPress.Files.FileName`. Release zip built in CI with `vendor/autoload.php`.
- Minimum versions: PHP 8.2 (decided; 8.1 is out of security support), WordPress 6.7 (`label` arg on `register_meta`, editor-side `registerBlockBindingsSource`; Block Bindings, `Requires Plugins` and Interactivity API all present).
- Bootstrap: `plugins_loaded` version check (admin notice on failure); `init` priority 5 fires `pegwork_register` (packages added), then load, validate, normalize, register post types, taxonomies, meta; fire `pegwork_loaded`; boot enabled modules.
- Rewrite flush: hash of effective registry in `pegwork_registry_hash`; flush once when it changes, so new types never 404.
- Modules: static manifest `slug => [label, description, requires[], path]`; enabled slugs in `pegwork_modules` option; disabled modules' files are never included; each `module.php` returns a `Pegwork\Api\Module` (`id()`, `requires()`, `boot()`); toggled via `wp pegwork module list|enable|disable` (admin toggle screen in Phase 2). CI grep fails if `modules/` references any `Pegwork\` namespace other than `Pegwork\Api` (keeps modules splittable). Loader tested with a fixture module.

### Naming

Final identifiers (decided 2026-10-17): slug and text domain `pegwork`, main file `pegwork.php`, `pegwork_` functions/hooks/options, `PEGWORK_` constants, `Pegwork\` namespace, `wp pegwork` CLI, future REST namespace `pegwork/v1`. Text domain and hook names stay literal (i18n tooling, greppability). Display name only in plugin header, readme.txt, `Plugin::name()`. A scripted rename (`bin/rename`) is no longer needed. Content keys never carry the prefix; only `pegwork_*` options would need an upgrade routine if the name ever changed.

## 2. Schema registry

- Package: directory with `manifest.json`, `content-types/*.json`, `taxonomies/*.json`, `relationships/*.json`, one file per object.
- Portability: neutral properties (key, label, type, required, visibility) top-level; WordPress-only settings under `wp`, so BarnCRM's model maps to Salesforce cleanly.
- Phase 1 field types: `text`, `textarea`, `rich_text`, `url`, `email`, `boolean`, `date` (`Y-m-d`), `number`, `select` (static options). `wp.storage` can map to `post_title` or `post_content`; otherwise meta.
- Validation keys: `required`; `required_when` / `show_when` (`{field, equals}`); `validate`: `min_length`, `max_length`, `min_words`, `max_words`, `min`, `max`, `pattern`; taxonomy usage `select: single|multi`, `required`.
- Visibility: `public|private` per field.
- Taxonomies: label, `hierarchical`, `locked_terms` (only `manage_options` adds terms), seed `terms[]`.
- Relationships (shape only): `{key, from, to, cardinality: one_to_many|many_to_many, storage: meta|table}`. `meta` = one `_rel_{key}` row per related ID on the "from" post; `table` reserved for Phase 5. Validated, exported, imported; not registered or edited.
- Sources and precedence: file packages via `pegwork_register`; site-local definitions in `pegwork_schema` option (import writes there). A key lives in one place; if both, the file wins and `wp pegwork schema status` reports the shadowed DB entry. Duplicate keys across packages: later one rejected with admin notice.
- Validation: hand-written PHP validator is the runtime truth (no PHP callables in JSON). Keys `^[a-z][a-z0-9_]*$`; post types max 20 chars, taxonomies max 32; reserved query-var terms (`type`, `status`, `name`, `author`, ...) rejected. `registry.schema.json` checked in CI against the same fixtures. Invalid package: not registered, admin notice, fails `wp pegwork schema validate`.
- Versioning: integer `format` (old formats upgraded in memory); package `version` semver. Field keys immutable in Phase 1 (rename = new field + migration, deferred).
- Site config ships in a small site plugin (`sifg-config`, in the siforgood repo) calling `$builder->add_package( __DIR__ . '/schema' )`. Not the theme: switching themes must not unregister content types.
- Caching: none; add only if profiling shows over 5 ms.

## 3. Field storage

- Meta key default `_{post_type}_{field}` (e.g. `_sifg_project_website`). The underscore hides it from the classic Custom Fields panel and from core's `core/post-meta` binding, so private values cannot leak there. `wp.meta_key` override can adopt existing keys.
- `register_post_meta( $type, $key, [...] )`: `single => true`, `type` from field type, `label`, `default`, per-type `sanitize_callback` (`sanitize_text_field`, `esc_url_raw`, `sanitize_email`, `rest_sanitize_boolean`, date pattern), `auth_callback` = `current_user_can( 'edit_post', $id )`, `show_in_rest => [ 'schema' => [ format/pattern/enum, 'context' => ... ] ]`.
- Private fields: `context => ['edit']`; `context=edit` needs edit rights. REST test is the backstop.
- `RecordValidator`: required, conditional-required, word counts, single-select term counts. Hooks `rest_pre_insert_{post_type}`, merging stored values with request meta and terms, so editor and API clients hit the same rules. Applies only for target status `publish`, `pending`, `future`; drafts may be incomplete. Phase 3 forms call the same `pegwork_validate_record()`.
- Private meta is never revisioned.

## 4. Admin editing UX

Rejected: classic meta box (compatibility mode, second save request); fields block in content (mixes data and layout); block bindings (display, Phase 2).

Chosen: one generic `PluginDocumentSettingPanel` (`@wordpress/scripts`, `@wordpress/components`), field list localized from the registry, edits meta through core-data so it saves in the same REST request. Handles `show_when` (Volunteer link appears only when Needs volunteers is on), live word counter for Description, single-select taxonomies as radios and multi-select as checkboxes (core panels removed with `removeEditorPanel`), "Private" badge. Description is `post_content`, limited by `allowed_block_types_all` to paragraph, list, heading.

Registry editing is JSON-only in Phase 1: a schema editor UI is JetEngine's largest piece, the gate tests export/import not UI, files are the source anyway, and the DB source makes a later UI additive.

## 5. REST API

Zero custom endpoints. Core gives `/wp/v2/projects` (via `wp.rest_base`) with meta and terms, OPTIONS schema discovery, cookie + `wp_rest` nonce auth, Application Passwords for n8n, status-based visibility. Deferred: `pegwork/v1/schema`, HTTP export/import, webhooks (Phase 4). n8n gets a dedicated low-privilege user.

## 6. Export and import

- `wp pegwork schema export [--dir=<path>] [--source=all|db|files]`: effective registry as package dirs, normalized (defaults filled, object keys sorted, array order kept), `JSON_PRETTY_PRINT | UNESCAPED_SLASHES | UNESCAPED_UNICODE` + trailing newline, no timestamps or paths.
- `wp pegwork schema import <dir> [--prune] [--dry-run]`: upserts into `pegwork_schema` by key, creates missing seed terms by slug, never deletes terms, posts or meta. Idempotent (second run: "0 changes").
- Also `wp pegwork schema validate|status|list`.

Gate test (PHPUnit integration in CI, mirrored by a LocalWP runbook):
1. Load `examples/sifg` as file package, create 3 Projects, capture REST edit-context responses (R1).
2. Export to A.
3. Remove file package and delete `pegwork_schema`; assert `sifg_project` is unregistered.
4. Import A, re-run registration, export to B.
5. Assert A and B byte-identical; re-import reports 0 changes; R2 equals R1; seed term count unchanged; anonymous GET has no `contact_email`.

## 7. Event bus: deferred

Nothing in Phase 1 consumes events. `pegwork_register` and `pegwork_loaded` are extension lifecycle hooks only. Phase 3/4 defines `pegwork_record_created|updated|deleted` (on `wp_after_insert_post`, after meta and terms save), `pegwork_form_submitted`, `pegwork_schedule_tick`, and brings in Action Scheduler. Constraint carried forward: all writes go through native post APIs.

## 8. Extension API for add-ons

- Dependency: `Requires Plugins: pegwork` header.
- Register schema: `add_action( 'pegwork_register', fn( Pegwork\Api\RegistryBuilder $b ) => $b->add_package( __DIR__ . '/schema' ) );`
- Read: `pegwork_registry()` returns read-only `Pegwork\Api\Registry` (`content_types()`, `content_type($key)`, `fields($type, $visibility = null)`, `taxonomies()`, `relationships()`).
- Values: `pegwork_get_field( $post, $key )`; `pegwork_validate_record( $type, array $values )` returns `true|WP_Error`.
- `Pegwork\Api\VERSION` semver; everything outside `Pegwork\Api` is internal.
- Deferred: public field-type API (Phase 2), packages extending another package's type (Phase 5).

## 9. Testing and tooling

PHPUnit with `wp-phpunit` and `yoast/phpunit-polyfills`; GitHub Actions with MySQL service, matrix PHP min + latest, WP 6.7 + latest; locally via LocalWP site shell against a separate test DB (no Docker). PHPCS `WordPress` + `PHPCompatibilityWP`; `wp-scripts lint-js` and `build`. Extra CI jobs: JSON Schema fixture agreement, module-boundary grep. One branch and PR per session; CI green to merge.

## 10. Security checklist

- `map_meta_cap => true`, `capability_type` from registry; locked taxonomies restrict `manage_terms` to `manage_options`.
- Meta `auth_callback` checks `edit_post`; every value sanitized and schema-validated.
- Private fields: edit context, protected keys, no revisions; tests for anonymous, subscriber, editor.
- Schema import CLI-only (no web upload); strict JSON validation, no callables.
- Escaped, prefixed admin notices; translatable strings.
- Editor uses `wp_rest` nonce; no custom forms or AJAX in Phase 1.
- `uninstall.php` never deletes content; removing a field never deletes meta.

## 11. Non-goals, open questions, tasks

Non-goals: schema editor UI, module toggle UI; listings, blocks, bindings, shortcodes, Divi; forms, automation, events, webhooks, Action Scheduler; custom tables, relationship UI/REST; record export; multisite; i18n beyond text-domain readiness.

Owner decisions (2026-10-17): main technical choices approved; PHP minimum 8.2; file wins over DB; Description 50-300 words blocks publish and pending (drafts exempt); name Pegwork and its identifiers; license GPL-2.0-or-later.

Open question for the owner:
1. Confirm SI for Good config lives in a site plugin in the siforgood repo, not the theme, and whether it is named `sifg-config` (default) or `pegwork-config`.

PRs (each merges with CI green):
1. Scaffold: main file, Composer, PHPCS, PHPUnit, CI, license, version guard. Accept: activates on LocalWP; CI green on matrix.
2. Registry: loader, validator, normalizer, JSON Schema, `schema validate|list`. Accept: valid/invalid fixtures pass/fail in both validators.
3. Content registration: post types, taxonomies, seed terms, hash flush. Accept: `examples/sifg` shows Projects and 3 taxonomies with all terms; `/projects/` resolves without re-saving permalinks.
4. Fields and validation: meta registration, private context, RecordValidator. Accept: anonymous GET lacks `contact_email`; editor edit context has it; publish with Needs volunteers on and no link returns 400; draft saves.
5. Editor panel. Accept: Project fully editable in block editor; conditional field toggles; single-select taxonomies are radios; manual checklist passes.
6. Export/import and DB source. Accept: gate test passes in CI; second import reports 0 changes.
7. Module loader and public API (`Pegwork\Api`, `module` CLI, boundary check, API docs). Accept: fixture module loads only when enabled.
8. `sifg-config` (siforgood repo). Accept: owner edits a real Project on LocalWP; exported config re-imports with a clean diff (Phase 1 sign-off).

Implementation notes: key is `sifg_project`, not `project`, because Divi registers `project`. `type`/`status` taxonomy keys would break query vars (validator rejects them).

## Worked example: `examples/sifg/`

`manifest.json`
```json
{ "format": 1, "id": "sifg", "version": "0.1.0", "label": "SI for Good", "requires": { "pegwork": ">=0.1.0" } }
```

`content-types/sifg_project.json`
```json
{
  "format": 1,
  "key": "sifg_project",
  "labels": { "singular": "Project", "plural": "Projects" },
  "public": true,
  "taxonomies": [
    { "key": "sifg_project_type", "select": "single", "required": true },
    { "key": "sifg_project_status", "select": "single", "required": true },
    { "key": "sifg_impact", "select": "multi", "required": false }
  ],
  "fields": [
    { "key": "name", "type": "text", "label": "Name", "required": true, "wp": { "storage": "post_title" } },
    { "key": "description", "type": "rich_text", "label": "Description", "required": true,
      "validate": { "min_words": 50, "max_words": 300 }, "wp": { "storage": "post_content" } },
    { "key": "who_for", "type": "text", "label": "Who it's for", "validate": { "max_length": 200 } },
    { "key": "website", "type": "url", "label": "Website" },
    { "key": "needs_volunteers", "type": "boolean", "label": "Needs volunteers", "default": false },
    { "key": "volunteer_link", "type": "url", "label": "Volunteer link",
      "show_when": { "field": "needs_volunteers", "equals": true },
      "required_when": { "field": "needs_volunteers", "equals": true } },
    { "key": "github_link", "type": "url", "label": "GitHub link" },
    { "key": "contact_email", "type": "email", "label": "Contact email", "required": true, "visibility": "private" },
    { "key": "last_verified", "type": "date", "label": "Last verified" }
  ],
  "wp": {
    "rest_base": "projects",
    "rewrite_slug": "projects",
    "has_archive": true,
    "menu_icon": "dashicons-lightbulb",
    "supports": ["title", "editor", "revisions"],
    "capability_type": "post",
    "allowed_blocks": ["core/paragraph", "core/list", "core/list-item", "core/heading"]
  }
}
```
Unlisted visibility defaults to `public`; meta keys resolve to `_sifg_project_<key>`.

`taxonomies/sifg_project_type.json`
```json
{ "format": 1, "key": "sifg_project_type", "labels": { "singular": "Type", "plural": "Types" },
  "hierarchical": false, "locked_terms": true, "wp": { "rewrite_slug": "project-type" },
  "terms": [
    { "slug": "individual", "name": "Individual" },
    { "slug": "nonprofit", "name": "Nonprofit" },
    { "slug": "community-group", "name": "Community group (informal)" },
    { "slug": "corporation", "name": "Corporation" } ] }
```

`taxonomies/sifg_project_status.json` (labeled "Project status" to avoid confusion with post status)
```json
{ "format": 1, "key": "sifg_project_status", "labels": { "singular": "Project status", "plural": "Project statuses" },
  "hierarchical": false, "locked_terms": true, "wp": { "rewrite_slug": "project-status" },
  "terms": [
    { "slug": "idea", "name": "Idea" }, { "slug": "building", "name": "Building" },
    { "slug": "piloting", "name": "Piloting" }, { "slug": "active", "name": "Active" },
    { "slug": "complete", "name": "Complete" }, { "slug": "paused", "name": "Paused" } ] }
```

`taxonomies/sifg_impact.json`
```json
{ "format": 1, "key": "sifg_impact", "labels": { "singular": "Impact category", "plural": "Impact categories" },
  "hierarchical": false, "locked_terms": true, "wp": { "rewrite_slug": "impact" },
  "terms": [
    { "slug": "sdg-01", "name": "No Poverty" },
    { "slug": "sdg-02", "name": "Zero Hunger" },
    { "slug": "sdg-03", "name": "Good Health and Well-being" },
    { "slug": "sdg-04", "name": "Quality Education" },
    { "slug": "sdg-05", "name": "Gender Equality" },
    { "slug": "sdg-06", "name": "Clean Water and Sanitation" },
    { "slug": "sdg-07", "name": "Affordable and Clean Energy" },
    { "slug": "sdg-08", "name": "Decent Work and Economic Growth" },
    { "slug": "sdg-09", "name": "Industry, Innovation and Infrastructure" },
    { "slug": "sdg-10", "name": "Reduced Inequalities" },
    { "slug": "sdg-11", "name": "Sustainable Cities and Communities" },
    { "slug": "sdg-12", "name": "Responsible Consumption and Production" },
    { "slug": "sdg-13", "name": "Climate Action" },
    { "slug": "sdg-14", "name": "Life Below Water" },
    { "slug": "sdg-15", "name": "Life on Land" },
    { "slug": "sdg-16", "name": "Peace, Justice and Strong Institutions" },
    { "slug": "sdg-17", "name": "Partnerships for the Goals" } ] }
```

Relationship shape (validated only; BarnCRM illustration, not part of `sifg`):
```json
{ "format": 1, "key": "contact_household", "from": "barn_contact", "to": "barn_household",
  "cardinality": "many_to_many", "storage": "meta" }
```
