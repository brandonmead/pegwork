# Pegwork: Phase 2 Display Technical Design

Status: draft for owner review (open questions in section 13). Scope: listings, templates and filters for SI for Good (Projects directory, Project detail, Resources directory, home featured and latest). Builds on `docs/phase-1-design.md`. Version claims about WordPress core are unverified; confirm in PR 1.

## 0. Key decisions

- Listings are the core Query Loop. Pegwork adds a block variation and one attribute, not a listing block.
- Field output is the Block Bindings API with one source, `pegwork/field`, on core blocks (paragraph, heading, button, image).
- Two custom blocks only: `pegwork/filters` (no core faceted filter exists) and `pegwork/conditional` (no core conditional rendering exists).
- Zero custom REST endpoints. With JS, filters navigate with `@wordpress/interactivity-router`, which fetches the server-rendered page and swaps the Query Loop region. Without JS, the same form submits GET.
- Filter state lives only in the URL, so every state is shareable and page-cacheable.
- One additive, optional registry section (`listing`) declares what is filterable. It is the public allowlist: nothing outside it reads `$_GET`, and private fields can never be in it.
- Two switchable modules: `listings`, `filters` (requires `listings`). Block namespace `pegwork/`, block category "Pegwork".

## 1. Requirements trace

| Requirement | Element |
|---|---|
| Card grid: title, excerpt, Type, Status, impact | core `post-title`, `post-excerpt`, `post-terms` in the Query Loop |
| "Needs volunteers" badge, "Verified Oct 2026" | `pegwork/field` binding on a paragraph (boolean label, date format) |
| Search, filters (Type, Status, Impact multi, Needs volunteers) | `listing` section + `pegwork/filters` block |
| URL-shareable, works without JS | GET form; URL is the only state |
| Updates without reload with JS | Interactivity API store + router navigation into the Query Loop region |
| Detail page: all public fields, never contact email | bound core blocks; `pegwork/field` refuses private fields |
| Volunteer button only if Needs volunteers and not Paused | `pegwork/conditional` wrapping a bound core button |
| Resources directory, same tools | nothing type-specific: `listing` on `sifg_resource` reuses everything |
| Home featured project | Query Loop + `pegwork.where` preset on a public boolean field |
| Home latest projects | plain core Query Loop + generated card pattern (no Pegwork code) |
| 500-record speed gate | budget and proof in section 10 |
| Any block theme; later shortcode/Divi | core blocks only; filters render is a pure PHP function; directory renders via `do_blocks()` |
| WCAG 2.2 AA, i18n | section 11 |

## 2. Layout and modules

```
modules/listings/  module.php, src/ (BindingSource, Conditional, QueryHooks, Constraints, Patterns), blocks/conditional/
modules/filters/   module.php, src/ (UrlState, FiltersBlock), blocks/filters/ (block.json, render.php, view.js)
assets/src/listings-editor/   bindings source (editor), query variations, conditional block edit UI
```

- Manifest entries: `listings` (requires none), `filters` (requires `listings`). Enable with `wp pegwork module enable listings filters`.
- Module PHP uses its own namespace outside `Pegwork\` (`PegworkListings\`, `PegworkFilters\`) and only `Pegwork\Api` plus global `pegwork_*` functions, per `bin/check-module-boundary.sh`. Modules never call each other's classes; `filters` talks to `listings` through the `pegwork_listing_constraints` filter (section 6).
- Build: `@wordpress/scripts` gains entries for the listings editor script and the filters view script module (`viewScriptModule`, module build flag confirmed in PR 4).
- Public API additions (`Pegwork\Api\VERSION` 0.2.0): `Registry::meta_key( $type, $field ): ?string`; `pegwork_get_field( int|WP_Post $post, string $key, string $context = 'view' ): mixed` (promised in Phase 1, not yet built; typed value; `null` for private fields unless `$context` is `edit` and the user can `edit_post`); hook `pegwork_listing_constraints` (documented in `docs/extension-api.md`).
- Event bus: not used.

## 3. Registry addition: `listing` (format 1, optional)

Neutral (top level, not under `wp`), on a content type:

```json
"listing": {
  "search": true,
  "filters": [
    { "key": "project_type", "taxonomy": "sifg_project_type", "label": "Type" },
    { "key": "project_status", "taxonomy": "sifg_project_status", "label": "Status" },
    { "key": "impact", "taxonomy": "sifg_impact" },
    { "key": "volunteers", "field": "needs_volunteers" }
  ]
}
```

- `key` is the URL parameter. Pattern `^[a-z][a-z0-9_]*$`; rejected if in `Validator::RESERVED_KEYS` (so not `type` or `status`), equal to `q`, `wp_*`, any content type or taxonomy key (those are core query vars), or duplicated within the type.
- Exactly one of `taxonomy` (must be in the type's `taxonomies`) or `field` (must exist, `visibility: public`, type `boolean` or `select`; other types rejected until a later phase adds ranges).
- `multiple` (bool): taxonomy default from usage `select` (`multi` gives true), select field default false, boolean always false. Values within one filter combine as OR; filters combine as AND.
- `label` optional; default is the taxonomy's singular label or the field label, resolved at runtime so label edits propagate.
- `search` (default false) enables parameter `q` (core search over title, excerpt, content).
- Why in the registry, not block attributes: the inherit (archive) main query runs before any block renders, so the allowlist must exist before render; and keeping it with `visibility` puts the private-field rule where the Validator can enforce it.
- Work: JSON Schema `$defs/listing`; Validator rules above (codes `listing_private_field`, `listing_reserved_key`, `listing_unknown_target`, `listing_field_type`, `listing_duplicate_key`); Normalizer fills `search` and `multiple`; Exporter/Importer carry it through canonical form; valid and invalid fixtures in `tests/fixtures` with `expected.json` (cross-object rules marked `schema: false`). Packages using `listing` should declare `"requires": { "pegwork": ">=0.2.0" }` since 0.1 rejects unknown keys.

## 4. Field output: `pegwork/field` binding source

Steelman `core/post-meta`: it refuses protected (`_`-prefixed) keys by design, and every Pegwork key is protected (Phase 1, section 3). Even with `wp.meta_key` overrides it has no formatting (dates, booleans, select labels) and no visibility rule. So one source is required.

- Server `register_block_bindings_source( 'pegwork/field', [ 'uses_context' => [ 'postId', 'postType' ], ... ] )`; editor `registerBlockBindingsSource` with the same name, reading the entity record through core-data so previews in templates and Query Loops show real values.
- Args: `key` (required), `format` (date: PHP date format, default site `date_format`; output via `wp_date()` so month names localize), `prefix` (text before a non-empty value, e.g. `"Verified "`), `label` (boolean: text shown when true; default the field label).
- Resolution: field must belong to the context post type and be public, else `null`. Also `null` when `post_password_required()` or the post is not publicly viewable, and when the field's `show_when` is unmet (a stale Volunteer link never renders after Needs volunteers is turned off).
- Output by type: text/textarea/number escaped with `esc_html`; select gives the option label; rich_text meta via `wp_kses_post`; url via `esc_url` with protocols `http`, `https`, `mailto` only (anything else becomes `null`); boolean false and empty strings become empty.
- Hide when empty: a `render_block` filter (which receives the `WP_Block` with context) renders `''` for any block whose `pegwork/field` bindings all resolve empty. Without it, core keeps the block's placeholder text when a source returns `null`, so an empty Website would render a dead button. Opt out per binding with arg `"keepEmpty": true`.
- Editor field picker: implement `getFieldsList` where core supports it for custom sources (confirm in PR 2); the generated patterns (section 8) are the dependable path either way.

## 5. `pegwork/conditional` block

Steelman core: there is no conditional rendering in core (block visibility hides unconditionally); `show_when` alone cannot express "not Paused" (a taxonomy rule specific to this site). A container block keeps the rule visible in List View.

- Attributes: `conditions[]` of `{ field, op: equals|not_equals|empty|not_empty, value }` or `{ taxonomy, op: in|not_in, terms: [slugs] }`; all must pass (AND only). Uses context `postId`, `postType`. Renders inner blocks or `''`.
- Fails closed: unknown key, private field, or a taxonomy not on the type renders nothing (a condition on a private field would leak its presence) and shows a warning in the editor.
- Editor: inspector controls built from a localized registry config (same pattern as the editor panel), never offering private fields.
- `supports.interactivity.clientNavigation: true`, so it may sit inside directory cards without disabling router navigation.

## 6. Listings: Query Loop variation, presets, query mapping

Steelman a custom listing block: the Query Loop already gives layout (post-template grid), pagination, no-results, enhanced pagination with router regions, and familiar editing. A custom block would duplicate all of it and break theme styling. Cut.

- Attribute `pegwork` added to `core/query` (server `register_block_type_args`, editor `blocks.registerBlockType` filter): `{ "filters": bool, "where": { field_key: value } }`.
- Variation per content type with a `listing` section, e.g. "Projects directory": `postType` set, `enhancedPagination: true`, `pegwork.filters: true`, inner blocks = generated card pattern + `query-pagination` + `query-total` + `query-no-results`. `isActive` checks `pegwork` and `postType`.
- `where` presets: public `boolean` and `select` fields only (taxonomy presets use the Query Loop's own taxonomy controls). Used for the home featured project.
- Constraint pipeline (one translator, `Constraints::to_query_args`):
  - Secondary queries: `query_loop_block_query_vars` for Query Loops with a `pegwork` attribute (detected in `pre_render_block`, the documented variation pattern).
  - Main query: `pre_get_posts` when `is_main_query()` and `is_post_type_archive( $type )` for a type with `listing`. This lets the existing inherit archive template (`/projects/`) work with native `/page/2/` URLs.
  - Both call `apply_filters( 'pegwork_listing_constraints', $constraints, $post_type, [ 'source' => 'main'|'block', 'filters' => bool ] )`, starting from `where`; the filters module merges URL constraints when `filters` is true or the source is `main`.
  - Taxonomy constraints become `tax_query` (field `slug`, operator `IN`, relation `AND` across filters). Boolean becomes `meta_query` `=` `'1'` on `Registry::meta_key()`; select becomes `=` on the option value. `q` becomes `s`, set in `pre_get_posts` after `parse_query` so `is_search()` stays false and the archive template is kept.
  - The translator re-checks every key against the registry and drops private or undeclared keys (defense in depth behind the Validator).
- Listings add `data-pegwork-directory="<post_type>"` to the query wrapper (`render_block_core/query`) so the filters store can find its regions.
- Related items (relationships) are out of scope; if needed later they depend on the parallel content builder completion design.

## 7. Filters: `pegwork/filters`

Steelman core: `core/search` handles only `s` and post type; nothing in core renders taxonomy or field facets. Custom block justified.

- Attributes: `postType` (default: queried post type on an archive), `filters` (subset and order of declared keys; default all), `showSearch` (default `listing.search`), block style `horizontal|vertical`. `supports.interactivity: true`.
- Render: `render( array $attributes, array $state ): string` is pure (no `WP_Block` dependency), so a later shortcode or Divi module can wrap it.
- Markup: `<form method="get" role="search" aria-label="Filter projects" action="{base URL}">`; base URL is the archive link or page permalink without paging or filter params. Search: labelled `<input type="search" name="q">`. Single filters: labelled `<select>` with an "Any" option. Multiple filters: `<details>` with `<summary>` ("Impact category, 2 selected") holding a `<fieldset>` + `<legend>` of checkboxes named `impact[]`. Boolean: one labelled checkbox `volunteers=1`. Buttons "Apply filters" (submit) and "Clear all" (link to base URL, shown when any filter is active). A persistent visually hidden `<p role="status">` live region.
- URL contract (parse in `UrlState`, pure): accepts `impact=a,b` and `impact[]=a&impact[]=b`; JS writes the comma form. Term values must match existing slugs of that taxonomy (cached term list), select values must match options, boolean only `1`, `q` via `sanitize_text_field` capped at 100 chars, at most 20 values per filter. Anything else is dropped silently. Changing a filter always drops paging (`/page/N/`, `query-N-page`). Pagination links keep filter params (core preserves the query string).
- JS (Interactivity API store `pegwork/filters`): initial state from `wp_interactivity_state()` (sanitized values, base URL, i18n strings). Controls bound with `data-wp-bind--checked` / `--value`. `change` on selects and checkboxes and debounced (300 ms) `input` on search build the URL (pure `buildUrl`) and call `navigate( url )` with the router's own announcement off. While pending: `aria-busy="true"` and an `is-loading` class on each matching `[data-pegwork-directory]` region (CSS dims after 150 ms). After: live region gets "Results updated: " plus the `core/query-total` text from the new region (already translated server-side), or the no-results text. `popstate` re-parses `location.search` into state so controls match back/forward. Focus never moves on update; "Clear all" moves focus to the search input.
- Without JS everything above works by full GET submit; the "Apply filters" button stays visible in both modes.
- No facet counts (section 13 non-goals).

## 8. Editor experience

- Inserter (category "Pegwork"): "<Plural> directory" variations, "Filters", "Show if".
- Generated patterns per content type with `listing` (category "Pegwork"): card, details (all public fields with labels, conditional volunteer-style blocks for `show_when` fields), directory (filters + variation), featured (variation, `perPage: 1`), latest (plain Query Loop, `perPage: 3`). Themes can override with their own styled patterns; SI for Good will.
- Placing a directory: insert "Projects directory pattern" on a page, or in the archive template replace the "Pegwork slot" groups with `pegwork/filters` and add bindings to the existing Query Loop card.
- States: empty directory and no-results use core `query-no-results` (pattern text: "No projects match your search or filters." plus a Clear all link); loading per section 7; the filters block in the editor renders server-side (`ServerSideRender`) with controls disabled.

## 9. Security

- Private fields: excluded at four layers: Validator (not declarable as filters), constraint translator (dropped), binding source and conditional block (refuse, any user, any context). No new REST routes; Phase 1 REST context rules unchanged.
- Input: only declared keys and `q` are read from `$_GET`; strict value whitelists (section 7); values never reach SQL except through `WP_Query` args.
- Output: binding escaping per section 4; filters markup through `esc_html`, `esc_attr`, `esc_url`; JS writes text via Interactivity bindings (no `innerHTML`).
- Visibility: queries keep core `post_status` handling (anonymous sees only `publish`); password-protected posts output no bound values.
- No nonces needed: read-only public GET; no admin-ajax, no writes.
- Tests: anonymous, subscriber, editor, administrator all get no `contact_email` in any rendered HTML or router response; URL with `contact_email=`, `_sifg_project_contact_email=`, `meta_key=` has no effect.

## 10. Performance budget and proof

Fixture: 500 published Projects (seeded by `tests/perf/seed.php`, shared by PHPUnit and `wp eval-file`): random Type and Status, 1 to 3 impact terms, 30% Needs volunteers, 120 to 250 word descriptions. Scenario URL: `/projects/?project_type=nonprofit&impact=sdg-04,sdg-13&volunteers=1&q=data` plus unfiltered page 1 and page 3.

| Metric | Budget | Proof |
|---|---|---|
| DB queries per directory render | at most 35 | PHPUnit `DirectoryPerformanceTest` with `SAVEQUERIES`; blocking in CI |
| N+1 | identical query count at `perPage` 12 and 48 | same test (meta and term caches primed by `WP_Query`) |
| Server time (TTFB, no page cache, no persistent object cache) | p50 at most 150 ms, p95 at most 300 ms on LocalWP | `bin/perf-directory.sh <base-url>`: 5 warm-up + 20 curl runs per scenario; results recorded in `architecture.md` |
| CI regression guard | p95 at most 600 ms | same script against wp-env in the E2E job |
| Filters view module | at most 10 KB gzipped, excluding core interactivity and router | build size check in the JS job |

- Indexes: none added. `tax_query` uses `term_relationships` keys; the boolean `meta_query` joins on the indexed `meta_key`. Scaling note (not Phase 2): a heavily filtered boolean can be mirrored to a hidden taxonomy if meta joins show up in profiling.
- Caching: none custom. URL-only state makes every filter combination page-cacheable, router fetches included; `WP_Query` result caching applies with a persistent object cache.

## 11. Accessibility (WCAG 2.2 AA) and i18n

- 1.3.1: every control labelled; checkbox groups in `fieldset` + `legend`. 4.1.3: result changes announced through the persistent `role="status"` region. 3.2.2: updates change content in place, never context or focus. 2.1.1: native controls only, `details`/`summary` for the long impact list. 2.4.7 / 2.4.11: no focus styles removed, nothing sticky over results. 2.5.8: controls at least 24 by 24 px in Pegwork CSS. Cards keep one link (the title).
- i18n: PHP strings in text domain `pegwork`; script modules cannot use `wp_set_script_translations` yet, so JS strings arrive translated in interactivity state. Counts come from core's translated `query-total`. Registry labels are not translatable (non-goal).

## 12. Testing

- PHPUnit unit: `UrlState` parsing (both array forms, caps, unknown values), `Constraints::to_query_args`, binding formatting and escaping, Validator `listing` rules.
- PHPUnit integration: listing fixtures in both validators (`composer schema-fixtures`); export/import round trip with `listing` byte-identical; archive filtering through `go_to()`; `pegwork` Query Loops through `do_blocks()`; volunteer button matrix (needs on/off by Active/Paused); private-field leak tests (section 9); performance test (section 10).
- JS unit (Vitest): `buildUrl`/`parseUrl` round trips, store actions with a mocked `navigate`, debounce.
- E2E (Playwright with `@wordpress/e2e-test-utils-playwright` on wp-env in CI; against siforgood.local locally): no-JS filtering (`javaScriptEnabled: false`), JS filtering without a document load, shareable URL reload restores state, back/forward, keyboard-only flow, live region text, `@axe-core/playwright` zero serious or critical violations on directory and detail, detail page never contains the contact email.

## 13. Non-goals, open questions, PRs

Non-goals: custom listing/grid block, custom REST endpoints, facet counts, range filters (date, number), sort UI, load more or infinite scroll, more than one independently filtered directory of the same type per page, related items (depends on the content builder completion design), Divi 5 render path and shortcode (render function kept ready), maps, saved searches, custom indexes or tables, registry label translation, module toggle admin screen (see question 5).

Open questions (recommended answer first):
1. Raise the WordPress minimum to 6.8 for `core/query-total`? Recommend yes (SI for Good runs current WordPress). If kept at 6.7, PR 3 adds a small `pegwork/result-count` block.
2. How is the featured project chosen? Recommend a public boolean field `featured` on `sifg_project` and the `where` preset; newest featured wins.
3. Resources at `/resources/` as the `sifg_resource` archive or on the existing Resources page? Recommend the archive (native paging and the main-query path), moving the page intro into `archive-sifg_resource.html`; both work.
4. Impact multi-select: match any or all? Recommend any (OR).
5. Phase 1 deferred a module toggle admin screen to Phase 2; no consumer requirement needs it. Recommend defer; enable once per environment with WP-CLI.
6. Paused projects with Needs volunteers still on would match the volunteers filter but show no button. Recommend an editorial rule (turn it off when pausing) rather than code.

Decisions (2026-10-18, main session under Brandon's build goal; Brandon reviews at the end):
1. Keep the WordPress 6.7 minimum Brandon approved. No `core/query-total` dependency: `pegwork/filters` renders the result count (from the main or bound query's `found_posts`) inside its own live region. No `pegwork/result-count` block. Raising the minimum is on Brandon's review list.
2. Featured project comes from the options page field `sifg_settings.featured_project` (content builder design, `post` field type). The `where` preset gains `post_in_option: "<page>.<field>"`, resolved with `pegwork_get_option()`; empty or unpublished falls back to the newest published record. No `featured` boolean.
3. Resources: both paths stay supported; the SI for Good choice follows the content PR.
4. Impact matches any (OR).
5. Module toggle screen is built by the content builder PR 1 (admin skeleton); not in this phase.
6. Config, not code: SI for Good's pause automation also sets Needs volunteers off and records it privately; reactivation restores it (Phase 4 config).
7. API version stays 0.2.0 for every addition in this build cycle. Registry sections for this phase are built in one shared registry PR together with `forms`, `automations`, and `options_pages`, so Phase 2 PR 1 reduces to `Registry::meta_key()`, `pegwork_get_field()`, and module skeletons (moved into PR 2). E2E tooling (Playwright, axe, wp-env in CI) comes from one shared harness PR used by all phases.

PRs (each merges with CI green):
1. Registry `listing` and API 0.2.0: JSON Schema, Validator, Normalizer, export/import, fixtures, `Registry::meta_key()`, `pegwork_get_field()`, manifest entries, module skeletons, WordPress minimum per question 1. Accept: fixtures agree in both validators; a filter on `contact_email` is rejected; round trip byte-identical; `pegwork_get_field( $p, 'contact_email' )` is null for an administrator in view context.
2. Listings A: `pegwork/field` source (server and editor), formats, hide-empty, `pegwork/conditional`, block category. Accept: the SI for Good detail template shows every public field; contact email absent for all roles; volunteer matrix correct; `<script>` in Who it's for renders as text; `javascript:` URL renders no button; editor preview shows values.
3. Listings B: `pegwork` Query Loop attribute, variations, `where`, constraint pipeline, main-query hook, generated patterns, seed fixture and query-count test. Accept: inserting "Projects directory" renders a working grid with pagination; featured preset returns only featured; no drafts; query budget and N+1 tests pass.
4. Filters, server: block render, `UrlState`, constraint merge, base URL and paging rules, wp-env E2E harness with no-JS specs. Accept: all filter and search combinations work by GET on `/projects/`; URLs reload to the same results; bad or private params ignored; pagination keeps filters and filter changes reset paging.
5. Filters, client: Interactivity store, router navigation, live region, loading state, popstate, JS unit and E2E JS specs. Accept: results update without document load; back/forward restores controls and results; announcements correct; enhanced pagination stays client-side.
6. Gate and docs: `bin/perf-directory.sh`, CI timing guard, size check, axe specs, `docs/display.md` (bindings args, conditional, `listing`, filters, hooks), `extension-api.md` update. Accept: budget met on LocalWP with numbers in `architecture.md`; E2E and axe green.
7. SI for Good (siforgood repo): enable modules, add `listing` (and `featured` per question 2) to `sifg_project` and `sifg_resource`, replace the "Pegwork slot" groups in `archive-sifg_project.html` and `single-sifg_project.html`, bind the home featured and latest patterns. Accept: owner checks directory, detail, resources and home on LocalWP (Phase 2 sign-off).

## Worked example: detail page volunteer button

```html
<!-- wp:pegwork/conditional {"conditions":[{"field":"needs_volunteers","op":"equals","value":true},{"taxonomy":"sifg_project_status","op":"not_in","terms":["paused"]}]} -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/field","args":{"key":"volunteer_link"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Volunteer with this project</a></div>
<!-- /wp:button --></div><!-- /wp:buttons -->
<!-- /wp:pegwork/conditional -->
```

Card line: `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pegwork/field","args":{"key":"last_verified","format":"M Y","prefix":"Verified "}}}}} -->` renders "Verified Oct 2026", or nothing when the date is empty.
