# Pegwork: Content Builder Completion (Relationships, Options Pages, Admin)

Status: draft for owner review, 2026-10-18. Builds on `docs/phase-1-design.md` (approved 2026-10-17). Completes the spec's content builder row: relationships and options pages, plus the minimal Pegwork admin area that Phase 2 to 4 hang their screens on. Design only. Version claims unverified; confirm in the PR that first relies on them.

## 0. Key decisions

- Relationships, options pages and the admin area are core, not modules. They are registry objects or registry views: listings, forms and automation all read them, and switching them off would orphan stored data and break `pegwork_get_option()` callers.
- Relationships: `meta` storage only (as Phase 1 defined), edited by a searchable picker in the existing Details panel, exposed through core REST meta plus one collection query param. No custom endpoints.
- Options pages: a new registry kind, one autoloaded option per page, a generated Settings API screen, read on the front end through a `pegwork/option` Block Bindings source. Not exposed in REST (section 3.4).
- Admin: top-level Pegwork menu with a read-only registry overview and module toggles. No schema editor UI now (section 5).
- Registry format stays 1. Every addition is optional, so existing packages validate unchanged.

## 1. Requirement trace (SI for Good)

| Requirement | Element | Breaks without it |
|---|---|---|
| Story relates to one or more Projects | relationship `story_projects` registered as `_rel_story_projects` meta, `required` | Stories cannot link to Projects |
| Editors pick related records | relationship picker in Details panel | Editors would type post IDs |
| Project page lists its stories | `pegwork_related_query_args()` (Phase 2 listings call it) | No reverse lookup |
| REST exposes related IDs | `_rel_*` in `show_in_rest`, `rel_{key}` collection param | n8n and listings AJAX cannot read links |
| Resource may relate to Projects | same shape, `required: false` | (no new element) |
| Featured project, moderator email, review criteria, contact email | `options_page` kind, `post` field type (options pages only) | Settings hard-coded in theme |
| Theme shows settings | `pegwork/option` bindings source | Theme cannot read settings without PHP |
| Edit settings in admin | generated settings screen | Settings editable only by CLI |
| Overview, module toggles | Pegwork menu, Overview, Modules screens | Admins need WP-CLI to see config or toggle modules |
| CLI parity | `options`, `relationship` commands; `schema list` extended | Admin-only actions cannot be scripted or tested headless |

Cut for lack of a requirement: reverse-side picker on Project, relationship ordering, relationship `visibility`, editor-side bindings preview, options REST endpoint, web export/import, schema editor UI.

## 2. Relationships

### 2.1 Registry

```json
{ "format": 1, "key": "story_projects", "from": "sifg_story", "to": "sifg_project",
  "cardinality": "many_to_many", "label": "Projects", "required": true }
```

- New optional properties: `label` (picker heading on the "from" editor; default: the "to" type's plural label, resolved at runtime, not stored), `required` (boolean, default `false`; at least one link on publish, pending, future).
- Cardinality defined now (Phase 1 gave shape only): `many_to_many` has no limit; `one_to_many` means each "to" record is linked from at most one "from" record (a Household has many Contacts, each Contact one Household). "From" records may always hold many links.
- `storage: table` becomes a validation error (`storage_not_supported`) until Phase 5, and the JSON Schema enum narrows to `meta`, so nothing silently registers as a no-op. No existing package uses `table`.
- New cross-object rules (PHP only, fixtures with `schema: false`): `from` and `to` must differ (`self_relationship`; self links need a direction model, Phase 5); `_rel_{key}` must not equal a field meta key on the "from" type (`meta_key_conflict`).
- Normalizer fills `storage: meta`, `required: false`. Exporter and Importer need no change (they iterate `Loader::SECTIONS`).

### 2.2 Storage and registration

`Content\Relationships::register( Registry )` on init 5, after meta. For each relationship: `register_post_meta( $from, "_rel_{$key}", [ single => false, type => integer, sanitize_callback => absint, auth_callback => edit_post, show_in_rest => [ schema => [ type => integer, minimum => 1 ] ] ] )`. One row per related ID (Phase 1 contract), so a reverse lookup is a plain `meta_query` and core REST `update_multi_meta_value` diffs rows instead of rewriting them. Values are an unordered set. Not revisioned.

Rules in `RecordValidator` (same `rest_pre_insert_{type}` hook and `pegwork_invalid_record` 400 shape, key in `data.fields` is the relationship key):

- Every save: each ID exists and has post type `to` (trashed counts as existing, so trash and restore keep links).
- Every save, `one_to_many`: no ID is already linked from another "from" record ("Project A is already linked from Story B").
- Publish, pending, future: `required` has at least one ID.

`deleted_post` on a "to" type deletes that ID from every `_rel_{key}` row (`delete_metadata( 'post', 0, $meta_key, $id, true )`). Removing a relationship from the registry never deletes meta (Phase 1 rule).

Scale note: `meta_query` on value is unindexed. Fine at SI for Good scale (Phase 2 gate: 500 records); Phase 5 `table` storage is the answer at BarnCRM scale.

### 2.3 Read API and REST

- `pegwork_related_ids( int|WP_Post $post, string $key ): int[]`: IDs at the other end. Direction follows the post's type (from or to), unambiguous because self relationships are rejected. Returns all statuses; callers filter.
- `pegwork_related_query_args( int|WP_Post $post, string $key ): array`: `WP_Query` args for records at the other end: `[ post_type => from, meta_query => [ [ key => _rel_{key}, value => id, type => NUMERIC ] ] ]` from a "to" post, `[ post_type => to, post__in => ids ?: [0] ]` from a "from" post. Shared contract with Phase 2 listings ("items related to X"); listings add status, order and paging.
- REST: `_rel_{key}` appears in `meta` on the "from" type. Collection param `rel_{key}` (integer) on the "from" collection, via `rest_{type}_collection_params` and `rest_{type}_query`, so `/wp/v2/stories?rel_story_projects=24` lists a Project's stories. Status visibility stays core's.
- `Pegwork\Api\Registry::relationships_for( string $type ): array` (relationships where the type is `from` or `to`). API minor bump.

### 2.4 Editor picker

Config contract addition, `window.pegworkEditor.types.<type>.relationships: [ { key, meta_key, to, label, to_label_singular, cardinality, required } ]` (from-side only). Rendered in the Details panel after fields, one section per relationship, built from `@wordpress/components` and core-data:

- `ComboboxControl` labeled "Add project" (from `to_label_singular`). Typing searches `getEntityRecords( 'postType', to, { search, per_page: 20, status: 'publish,future,draft,pending,private', _fields: 'id,title,status' } )`, debounced 300 ms. Already linked records are excluded from suggestions. Non-published suggestions show their status ("Draft").
- Linked records: a `<ul>` under a visible heading (`label`), each row the record title (link to its editor, new tab with visible "opens in new tab" text), a status badge when not published, and a "Remove" button whose accessible name is "Remove {title}". Titles resolve with one `getEntityRecords( ..., { include: ids } )` call.
- Value: `useEntityProp( 'postType', type, 'meta' )` on `_rel_{key}`, so links save in the same REST request as the post.
- States: empty "No projects linked yet." (plus "Required" text when `required`); no matches "No projects match "x"."; loading spinner with "Searching" text; deleted target "(missing record)" with Remove; save errors under the section, same mechanism as fields.
- Focus and announcements: after add, focus stays in the combobox (cleared); after remove, focus moves to the next row's Remove button, else the combobox. `speak()` announces "Linked {title}" and "Removed {title}".

## 3. Options pages

### 3.1 Registry kind

New directory `options-pages/<key>.json`, DB section `options_pages`, `Registry::options_pages()` and `options_page( $key )`.

```json
{ "format": 1, "key": "sifg_settings", "title": "SI for Good settings", "menu_title": "Site settings",
  "capability": "manage_options",
  "fields": [
    { "key": "featured_project", "type": "post", "label": "Featured project", "post_type": "sifg_project" },
    { "key": "moderator_email", "type": "email", "label": "Moderator notification email", "required": true, "visibility": "private" },
    { "key": "review_criteria", "type": "rich_text", "label": "Review criteria" },
    { "key": "contact_email", "type": "email", "label": "Contact email" } ] }
```

- Fields reuse the content type field validator (types, `label`, `required`, `default`, `validate`, `options`, `visibility`). Not allowed in options pages (`not_allowed_in_options_page`): `show_when`, `required_when` (would need JS on a PHP screen) and `wp` (no storage mapping).
- New field type `post`: one post ID, `post_type` required and must resolve (`unknown_content_type`). Allowed only in options pages (`post_field_not_allowed` in content types: use a relationship), so there is one way to link records.
- `capability` default `manage_options`; `menu_title` default `title`.
- Key rules: standard key pattern plus reserved names (a constant list of core option names from `populate_options()`), since the key is the option name. At registration, a page whose option name is already a registered setting is rejected with an admin notice.

### 3.2 Storage and validation

- One option per page, named by the page key (no product prefix, Phase 1 rule), value `{ field => value }`, autoloaded (small, read by every front-end binding). `register_setting( "pegwork_{key}", $key, [ type => object, default => defaults, sanitize_callback, show_in_rest => false ] )`.
- Shared validation: extract `RecordValidator::check_values( array $fields, array $values ): array` (field key => message) from the existing per-field check; content records and options pages both call it. `post` fields: ID must be a published post of `post_type`.
- Save behavior: valid fields save; each invalid field keeps its stored value and gets an `add_settings_error` naming the field. Submitted text is lost on redirect (Settings API limit), so messages state the rejected value.
- `pegwork_get_option( string $page, string $field ): mixed` returns the stored value cast by field type, else the default, regardless of visibility (server code such as Phase 3 notifications needs the private moderator email). A `post` field returns the ID; Phase 2 listings render the featured card with `post__in`.
- Uninstall keeps option values (they are content; `uninstall.php` deletes `pegwork_*` only).

### 3.3 Block Bindings

`register_block_bindings_source( 'pegwork/option', [ label => 'Pegwork option', get_value_callback ] )` on init. Args `{ page, field, format? }`. Returns null (block keeps its saved markup) when the page or field is unknown, the field is `private`, or the type is `post`. Values come back escaped for their type: `esc_html` for text-like, `wp_kses_post` for `rich_text`, `esc_url_raw` for `url`; `format: "mailto"` (email only) returns `mailto:` plus the address for a button `url`.

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"contact_email"}}}}} --><p>hello@example.org</p><!-- /wp:paragraph -->
```

The theme writes this markup in patterns (WP 6.7 has no bindings picker UI). Editor-side `registerBlockBindingsSource` preview is deferred: the saved fallback text shows in the editor.

### 3.4 Why no REST

`/wp/v2/settings` requires `manage_options` for every setting (it would ignore a lower page capability) and writes the whole object, so a client that cannot see private fields would wipe them. Nothing in SI for Good needs remote access; add a `pegwork/v1` options route when a consumer does.

## 4. Admin area

### 4.1 Menu skeleton (shared contract)

`Admin\Menu::register()` on `admin_menu` priority 9: `add_menu_page( 'Pegwork', 'Pegwork', 'manage_options', 'pegwork', ..., 'dashicons-admin-generic' )`. Public constant `Pegwork\Api\ADMIN_MENU = 'pegwork'`. Other phases call `add_submenu_page( Pegwork\Api\ADMIN_MENU, ... )` on `admin_menu` (default priority 10) with these positions:

| Position | Submenu | Slug | Owner |
|---|---|---|---|
| 0 | Overview | `pegwork` | core |
| 10 to 19 | each options page (`menu_title`) | `pegwork-options-{key}` | core |
| 20, 21 | Forms, Entries | module-defined | `forms` |
| 30 | Automations | module-defined | `automation` |
| 90 | Modules | `pegwork-modules` | core |

An options page whose capability is lower than `manage_options` still appears: WordPress promotes the first accessible submenu when the parent capability fails (verified in PR 1 test).

### 4.2 Overview (read-only)

Standard `.wrap`, one `widefat striped` table per section, each with `<caption>` and `scope` headers: Content types (key, label, REST base, fields, taxonomies, source), expandable per type with `<details>` listing fields (key, type, label, required, visibility, meta key); Taxonomies (terms, locked, source); Relationships (from, to, cardinality, required, source); Options pages (capability, fields, source); Shadowed DB entries and rejected packages (from `Result`, with each validation error's file and pointer). Empty state per section: "None registered. Add a schema package (see the extension API docs)." A footer names the CLI for changes (`wp pegwork schema import`). Data comes from a new `Registry\Status` service that `schema list` and `schema status` also use, so screen and CLI cannot disagree.

### 4.3 Modules

Table: module, description, requires, status, action. Action is an Enable or Disable submit button in a per-row form posting to `admin-post.php?action=pegwork_module` with `wp_nonce_field( 'pegwork_module_' . $slug )`; handler checks `manage_options` and `check_admin_referer`, calls `Modules\Loader::enable|disable`, redirects with a notice (changes apply on that next request). Blocked actions render as `aria-disabled` buttons with visible reason text ("Requires Listings", "Required by Filters"). New rule in `Loader::disable` (so CLI matches): reject when an enabled module requires the slug (`pegwork_module_required_by`). Empty state: "No modules available."

### 4.4 Options page screens

`settings_fields`, `do_settings_sections`, `form-table`, `submit_button`. Controls: text, email, url, number, date inputs with matching `type`; textarea for `textarea` and `rich_text`; checkbox for boolean; select for `select`; `post` as a `<select>` of published posts of `post_type` by title (first 500, "None" option; a searchable picker waits for a site that exceeds it). Labels with `for`, `required` and `aria-required`, descriptions and errors tied by `aria-describedby`, `aria-invalid` on failed fields, "Private" text badge for private fields. `option_page_capability_pegwork_{key}` returns the page capability, since `options.php` otherwise demands `manage_options`.

### 4.5 CLI parity

| Admin action | CLI |
|---|---|
| Overview | `wp pegwork schema list` (adds options pages, relationship `required`), `wp pegwork schema status` (adds rejected packages) |
| Module toggle | `wp pegwork module enable|disable` (gains the dependents check) |
| Options screen | `wp pegwork options list`, `get <page> [<field>] [--format]`, `set <page> <field> <value>` (same validation, exit 1 on error) |
| Editor picker | `wp pegwork relationship get <key> <post>`, `set <key> <post> <id>...` (same RecordValidator rules; empty list clears) |

## 5. Schema editor UI: later, not now

Recommendation: no schema editor in this work; revisit at Phase 5 (BarnCRM foundation), scoped to editing site-local DB entries, after a field migration design exists. Reasons:

1. Files win over the DB. SI for Good's schema is a file package, so a UI could only edit entries that ship nowhere and get shadowed; it would mislead.
2. No consumer needs it. SI for Good is configured by its developer in git; the Salesforce-like audience is BarnCRM's pilot nonprofit, two phases away.
3. Unsafe without migrations. Field keys are immutable and renames, type changes and removals have no migration path; a UI makes those edits one click.
4. Cost. It is JetEngine's largest piece and would delay Phase 2, which SI for Good is waiting on.
5. Additive later. The Importer already writes the DB source through the same validator, and the Overview built here is the UI's read side, so nothing done now is thrown away.

## 6. Security

- Capabilities: Overview and Modules `manage_options`; options pages their declared capability, enforced on menu, render and `options.php` save; relationship and options CLI run as the WP-CLI user.
- Nonces: Settings API nonce on options screens, per-slug nonce on module toggles; no AJAX.
- Private options never leave the server: excluded from bindings, not in REST; private relationships are out of scope (all are public, IDs only).
- Escaping: every registry string (labels can come from the DB source) through `esc_html` or `esc_attr`; binding values escaped per type.
- Relationship writes validated server-side for type and cardinality; `absint` sanitizer; `edit_post` auth.

## 7. Accessibility (WCAG 2.2 AA)

Picker: `ComboboxControl` gives combobox and listbox semantics with keyboard support; visible labels; announcements via `speak()`; managed focus on remove (2.4.3); targets at least 24 px (2.5.8); status shown as text, not color (1.4.1). Admin screens: core markup and styles, table captions and header scope, error text tied to fields (3.3.1), required marked in text, aria-disabled buttons with visible reasons. Automated: axe (`@axe-core/playwright`) on the picker, Overview, Modules and an options screen with zero serious or critical violations; manual keyboard and VoiceOver pass in each UI PR.

## 8. Testing

- PHPUnit unit: valid `complete` fixture gains a relationship with `label`/`required` and an options page; new invalid fixtures `relationship-self`, `relationship-table-storage`, `relationship-meta-key-conflict`, `options-page-disallowed-key`, `options-page-reserved-key`, `options-page-unknown-post-type`, `post-field-in-content-type`, each with `expected.json`; JSON Schema agreement in CI; `check_values` cases.
- PHPUnit integration: meta registered and REST round trip; wrong-type ID, `one_to_many` conflict and missing required return 400; draft saves; `rel_{key}` param; delete cleanup; options sanitize keeps invalid fields' old values; editor denied on a `manage_options` page and allowed on an `edit_others_posts` page through `options.php`; bindings return public values, null for private and `post`; menu, Overview rendering a shadowed entry, module toggle with good and bad nonce, dependents rejected; CLI commands. Gate test extended: example package with a relationship and an options page exports, re-imports byte-identical; `_rel_` meta in R1 equals R2.
- JS unit (Vitest): config parsing of `relationships`, picker add, dedupe and remove logic, empty and error states.
- Browser E2E (Playwright with `@wordpress/e2e-test-utils-playwright`, new in PR 3): link two Projects to a Story by keyboard, save, reload, remove one; required error on publish; settings save with an invalid email; module toggle; axe checks. CI runs against `wp-env`; locally against siforgood.local via `WP_BASE_URL`.

## 9. PRs (each merges with CI green)

1. Admin skeleton: Menu, `ADMIN_MENU` constant, `Registry\Status`, Overview, Modules screen, `Loader::disable` dependents rule. Accept: Pegwork menu shows Overview and Modules; Overview lists `examples/sifg` and a simulated shadowed entry; fixture module toggles from the screen and from CLI with identical rules; bad nonce rejected; axe clean.
2. Relationships backend: registry properties and rules, fixtures, meta registration, RecordValidator rules, delete cleanup, `rel_{key}` param, `pegwork_related_ids()`, `pegwork_related_query_args()`, `relationships_for()`, `relationship` CLI, docs. Accept: `examples/sifg` gains `sifg_story` and `story_projects`; REST create of a Story with Project IDs round-trips; Project ID query returns its stories; publishing a Story with no Project returns 400.
3. Relationship picker plus E2E harness: config contract, picker, `tests/fixtures/editor-panel/config.json`, Playwright and axe in CI, `docs/editor-panel.md`. Accept: E2E story passes; keyboard-only and VoiceOver checklist passes; existing panel tests unchanged.
4. Options pages: registry kind, `post` type, validator and fixtures, `register_setting`, generated screen, capability filter, `check_values` extraction, `pegwork_get_option()`, `options` CLI. Accept: `sifg_settings` screen saves all four fields; invalid email keeps the old value with an error; editor access follows `capability`; gate test covers the options page.
5. Block Bindings and docs: `pegwork/option` source, `docs/extension-api.md` (new functions, menu contract), this document's status. Accept: a paragraph bound to `contact_email` renders the saved value; bound to `moderator_email` renders its fallback; button `mailto` works.

Follow-up in the siforgood repo (not this plugin): `sifg-config` adds Story (and later Resource) types, `story_projects`, `resource_projects` and `sifg_settings`; the theme binds the footer contact email and review criteria.

## 10. Open questions (recommended answers)

1. E2E environment: `wp-env` needs Docker, which Phase 1 avoided locally. Recommend Docker on CI runners only, with E2E pointed at LocalWP locally.
2. Self relationships (Contact to Contact): recommend rejecting until Phase 5 designs direction.
3. Reverse editing (pick Stories from a Project): recommend not now; add a read-only "Linked from" list if editors ask.
4. Options REST route: recommend none until a consumer (n8n) needs one.
5. Phase 2 listings: confirm it consumes `pegwork_related_query_args()` and accepts `post__in` from `pegwork_get_option()` for the featured card. Recommend yes, as the shared contract.
