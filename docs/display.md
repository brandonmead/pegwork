# Pegwork display

Field output, conditional display and directories on standard blocks. Record fields (`pegwork/field`), the "Show if" block and directories are provided by the `listings` module:

```
wp pegwork module enable listings
```

Options page values (`pegwork/option`, [below](#option-output-the-pegworkoption-binding-source)) are core and need no module.

Design: `docs/phase-2-design.md` sections 4 to 9. The `listing` registry section is in `docs/registry-reference.md`. The filters block (`pegwork/filters`, [below](#filters-the-pegworkfilters-block)) is the `filters` module (`wp pegwork module enable filters`, requires `listings`).

## Field output: the `pegwork/field` binding source

Binds an attribute of a core block to a field of the record in block context (`postId`, `postType`): the current post in a single template, or each card in a Query Loop.

| Block | Attributes |
|---|---|
| `core/paragraph`, `core/heading` | `content` |
| `core/button` | `url`, `text` |
| `core/image` | `url`, `alt`, `title` |

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pegwork/field","args":{"key":"last_verified","format":"M Y","prefix":"Verified "}}}}} -->
<p>Last verified</p>
<!-- /wp:paragraph -->
```

The text inside the block is only a placeholder for the editor; the site shows the field value, or nothing.

### Args

| Arg | Applies to | Meaning |
|---|---|---|
| `key` | all (required) | Field key on the record's content type. |
| `format` | date fields | PHP date format for `wp_date()` (localized month names). Default: the site's date format. |
| `prefix` | text attributes | Text placed before a non-empty value, e.g. `"Verified "`. Not used for `url`. |
| `label` | boolean fields | Text shown when the field is on. Default: the field label. |
| `keepEmpty` | all | `true` keeps the block, with its placeholder text, when the value is empty. |

### Output by field type

| Type | Text attributes | `url` attribute |
|---|---|---|
| text, textarea, number | escaped with `esc_html` (markup shows as text) | refused |
| select | the option label | refused |
| boolean | `label` (or field label) when on; empty when off | refused |
| date | `wp_date( format )`; the stored calendar date never shifts with the site timezone | refused |
| rich_text | filtered with `wp_kses_post` | refused |
| url | escaped text | `esc_url` with `http`, `https`, `mailto` only; anything else (e.g. `javascript:`) is empty |
| email (public) | escaped text | `mailto:` plus the address |

### When nothing shows

The value is empty, and the block renders nothing at all (not its placeholder), when:

- the field is unknown, not on the record's type, or private. Private fields are refused for every user, in every context, including administrators;
- the record is password-protected or not publicly viewable (draft, private, a non-public type);
- the field's `show_when` is unmet (a stale Volunteer link never renders after Needs volunteers is turned off);
- the stored value is empty, false, or invalid for its type.

A block with several `pegwork/field` bindings renders when at least one resolves. Blocks without Pegwork bindings are untouched.

### In the editor

Post editor and Query Loops preview real values from the record being edited. Templates have no record, so bound blocks show the field label. Bound values are read-only in the block; edit them in the Pegwork panel of the record.

The block inspector's Attributes panel lists Pegwork fields on WordPress 6.9 and later. On 6.7 and 6.8 core keeps field lists only for `core/post-meta`, so add bindings in the code editor or from a pattern there; preview works on all versions.

## Option output: the `pegwork/option` binding source

Binds an attribute of a core block to a public field of an options page (`docs/options-pages.md`). No block context is needed, so it works in any template, pattern or post. Same blocks and attributes as `pegwork/field`.

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"contact_email"}}}}} -->
<p>hello@example.org</p>
<!-- /wp:paragraph -->

<!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"contact_email","format":"mailto"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Email us</a></div>
<!-- /wp:button -->
```

| Arg | Meaning |
|---|---|
| `page` | Options page key (required). |
| `field` | Field key on that page (required). |
| `format` | `mailto` on an email field: `mailto:` plus the address (for a button `url`; an email bound to `url` gets it anyway). On a date field: a PHP date format for `wp_date()`. |

Output follows the `pegwork/field` table above: `esc_html` for text-like values, `wp_kses_post` for rich text, option labels for selects, the field label for a boolean that is on, `wp_date()` for dates; the `url` attribute takes url fields (`http`, `https`, `mailto` only) and email fields.

Unlike `pegwork/field`, an unresolved binding keeps the block and its saved text, the fallback: the block's own markup shows when the page or field is unknown, the field is private (for every user, administrators included) or a `post` field (render the featured record with a Query Loop and `post__in` from `pegwork_get_option()`), or the value is empty. Write fallback text a visitor can read, or leave the block out of the pattern.

There is no editor-side registration for this source yet (design: deferred), so the editor shows no live value: depending on the WordPress version it shows the saved text or the source label. Write the markup in patterns or the code editor.

## Conditional display: the "Show if" block (`pegwork/conditional`)

A container that renders its inner blocks only when every condition on the record in context holds (AND). Category "Pegwork".

```html
<!-- wp:pegwork/conditional {"conditions":[{"field":"needs_volunteers","op":"equals","value":true},{"taxonomy":"sifg_project_status","op":"not_in","terms":["paused"]}]} -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/field","args":{"key":"volunteer_link"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Volunteer with this project</a></div>
<!-- /wp:button --></div><!-- /wp:buttons -->
<!-- /wp:pegwork/conditional -->
```

### Conditions

| Shape | Operators | Holds when |
|---|---|---|
| `{ "field", "op", "value" }` | `equals`, `not_equals` | the visible value equals `value` (booleans as booleans, `"1"` counts as on; numbers numerically; others as strings) |
| `{ "field", "op" }` | `empty`, `not_empty` | the visible value is empty (null, off, or `''`), or not |
| `{ "taxonomy", "op", "terms" }` | `in`, `not_in` | the record has any of the term slugs, or none of them |
| `{ "relationship", "op" }` | `not_empty`, `empty` | the record has a published record at the other end of the relationship, or none (one `posts_per_page` 1 query). Hides a "Related ..." heading together with its loop. |

"Visible value" follows the binding rules: an unmet `show_when` counts as empty.

### Fails closed

The block renders nothing when any condition names an unknown or private field (a condition on a private field would reveal whether it is set), a taxonomy or relationship the content type does not use, or an unknown operator; when conditions are malformed; when there is no record in context; or when the record is password-protected or not publicly viewable. No conditions means always shown.

### Editor

The inspector builds conditions from the content type's public fields, taxonomies and relationships ("Related <plural>"): the context post type, the type of a `single-<type>` or `archive-<type>` template, or a type chosen in the panel. Private fields are never offered. The canvas shows a "Show if: ..." summary above the inner blocks, which always display in the editor, and a warning when a condition would make the block render nothing. The block supports Interactivity API client navigation, so it can sit inside directory cards.

## The `listing` section

A content type opts in to directories and filters with an optional `listing` in its schema (full rules in `docs/registry-reference.md`, "Listing"):

```json
"listing": { "search": true, "filters": [ { "key": "impact", "taxonomy": "sifg_impact" }, { "key": "volunteers", "field": "needs_volunteers" } ] }
```

- `search: true` enables the `q` parameter (core search, at most 100 characters).
- Each filter has a URL `key` and exactly one target: a `taxonomy` of the type, or a public `boolean` or `select` `field`. `multiple` (taxonomies only) allows several values, combined as OR; filters combine as AND. `label` is optional.
- It is an allowlist: nothing outside it is read from the URL, and private fields cannot be named (the registry rejects them).
- A type with a `listing` gets the generated patterns, the "<Plural> directory" variation, published-only archives and a filtered main query. A type without one is untouched by this module.

## Directories: Pegwork Query Loops

A directory is a core Query Loop (`core/query`) with one extra attribute, `pegwork`. Layout, pagination, no-results and enhanced pagination stay core's, so themes style it like any Query Loop.

```html
<!-- wp:query {"queryId":726,"query":{"perPage":12,"postType":"sifg_project","inherit":false},"enhancedPagination":true,"pegwork":{"filters":true},"align":"wide"} -->
...
<!-- /wp:query -->
```

| `pegwork` key | Meaning |
|---|---|
| `filters` | `true`: the page's filters (filters module) narrow this loop. |
| `where` | Presets, AND-ed: `{ "<field key>": value }` for public `boolean` (true or false) and `select` (one value or a list) fields stored in meta; `"post_in_option": "<options page>.<field>"` for the record chosen in an options page `post` field; `"related": "<relationship key>"` for the records linked to the current record (see "Related records"). |

Any `pegwork` attribute, even `{}`, makes the loop list **published records only**, for every user (core would add private records for administrators). Unknown, private, post-column and other field types in `where` are ignored. `where` applies to custom loops only; an inherit Query Loop follows the main query (see "Archives").

`post_in_option` is resolved with `pegwork_get_option()` (options pages). The field must be a public `post` field whose `post_type` is the loop's type. When the function is missing, the field is empty, or the record is not a published record of that type, the newest published record shows instead. Use it with `perPage: 1` for a featured slot.

The query wrapper gets `data-pegwork-directory="<type>"` (the filters block finds its regions by it). Inherit Query Loops on the archive of a type with a `listing` get it too.

### Related records

`where.related` lists the published records linked to the current record by a relationship (`docs/relationships.md`), in either direction: a Stories loop with `"related": "story_projects"` on a Project shows the Project's Stories; a Projects loop with the same key on a Story shows the Story's Projects.

```html
<!-- wp:query {"queryId":412,"query":{"perPage":6,"postType":"sifg_story","inherit":false},"pegwork":{"where":{"related":"story_projects"}}} -->
```

- Current record: the `postId` block context, as for bindings and "Show if": the viewed post in a single template, or the card's record when the loop sits inside another Query Loop's post template. Without that context, the queried post. Pegwork adds `postId` to the context of every block that uses `query`. At the top of an archive template the context is the main loop's first post, as for any post block, so put related sections in single templates or cards.
- Query: `pegwork_related_query_args()`, merged like any constraint (the `related` constraint below). The loop keeps its own `post_status` rule (published only), order, per page and paging (`?query-<id>-page=2`), and ANDs with other presets and with filters.
- Nothing, never everything: the loop matches nothing (core's no-results block shows) when there is no current record, the record is password-protected or not publicly viewable, nothing is linked, the key is not a relationship, or the loop's type is not the other end of it (for example a Projects loop on a Project).
- Hiding the whole section: core's `query-no-results` only replaces the loop's items, so a heading above the loop (inside or outside it) would still show. Wrap heading and loop in a "Show if" block with `{ "relationship": "<key>", "op": "not_empty" }`, as the generated related pattern does.
- Validation: `where` lives in block attributes, not the registry, so the key is checked when the loop renders (unknown keys match nothing) and the editor offers only relationships of the loop's type.
- Editor: the "Pegwork directory" panel has "Only records linked to" with one choice per relationship of the loop's type ("The Project being viewed (story_projects)"). The editor preview does not apply presets.

### Inserter, variation and panel

- "Projects directory" (one "<Plural> directory" variation per content type with a `listing`, category Pegwork): `pegwork.filters` on, enhanced pagination, wide, 12 per page, a card grid (columns at least 16rem wide, so it wraps on phones), pagination and the message "No projects match your search or filters.". Its card is a `core/pattern` block of `pegwork/<type>-card`, which the editor swaps for the registered card on insert, so a theme's card override applies.
- Query Loops of a Pegwork type get a "Pegwork directory" inspector panel: a switch for the attribute, "Connect to filters", an "Only: <field>" select per preset field, "Only records linked to" for relationships of the type, and "Show the record chosen in" for options page `post` fields of the type.

### Generated patterns

Registered per content type with a `listing`, in pattern category Pegwork:

| Pattern | Content |
|---|---|
| `pegwork/<type>-card` | Linked title (h3), 20-word excerpt, then meta lines at font size `small`: terms of each taxonomy with its label, public boolean fields as a badge, select fields as "Label: value", date fields as month and year (`format: "M Y"`) with a prefix from the label: "Last X" becomes "X " ("Last verified" reads "Verified Oct 2026"), any other label "Label: ". |
| `pegwork/<type>-details` | Every public field with its label, url fields as buttons, `show_when` fields inside a "Show if" block, then the taxonomies. Offered for `single-<type>` templates. |
| `pegwork/<type>-directory` | The variation above as markup, with the card inline; the no-results message adds a "Clear all filters" link. With the filters module on, a wide `pegwork/filters` block comes first. |
| `pegwork/<type>-featured` | One record: `where.post_in_option` on the first public options page `post` field for the type (else `pegwork: {"filters":false}`, the newest published record). No pagination. |
| `pegwork/<type>-latest` | A plain core Query Loop (no `pegwork` attribute), 3 newest, card grid. |

Per relationship, one pattern for each end (any content type, with or without a `listing`):

| Pattern | Content |
|---|---|
| `pegwork/<type>-related-<relationship key>` | Title "<Type> related <other plural>" ("Project related Stories"), offered for `single-<type>` templates. A "Show if" block with the relationship condition around a wide `<section class="pegwork-related">`: heading "Related <other plural>" (h2) and a card grid of 6 records of the other type (`where.related`), no pagination. Nothing renders when nothing is related. |

Generated `pegwork` attributes are never `{}`: pattern registration parses and re-serializes the markup, which turns an empty object into `[]`, and the editor drops a list from an object attribute.

Overriding: Pegwork registers on `init` priority 20 and skips names already registered. A theme file `patterns/sifg-card.php` with `Slug: pegwork/sifg_project-card` (registered at `init` 10) replaces the card, and the directory, featured and latest patterns embed that card. Filter `pegwork_listing_pattern` changes a pattern or drops it (return `null`); related patterns pass kind `related` and the relationship key as a fourth argument. Labels come from the registry and are not translated.

Patterns are inserted as plain blocks; edit them freely afterwards. Nested `wp:pattern` blocks are resolved by the editor and in theme templates; in post content saved by hand they render without card context, so keep the card inline there.

### Archives

The main query of a post type archive (`/projects/`) whose type has a `listing` runs the same pipeline (`source` `main`), lists published records only, and keeps native paging (`/projects/page/2/`). A search constraint sets `s` after `parse_query`, so `is_search()` stays false and the archive template is kept.

### Constraint pipeline

One translator, `PegworkListings\Constraints::to_query_args()`, serves both paths. Constraints start from `where` (custom loops) or empty (archives), pass through the `pegwork_listing_constraints` filter (`docs/extension-api.md`), are re-checked against the registry, and are merged into the loop's own query: tax and meta queries AND, searches join, `post__in` intersects.

| Constraint | Becomes |
|---|---|
| `taxonomies: { <taxonomy>: [slugs] }` | `tax_query` by slug, `IN` (any) per taxonomy, AND across taxonomies. Taxonomy must be used by the type and publicly queryable; slugs must be in slug form; at most 20. |
| `fields: { <key>: value }` | `meta_query` on `Registry::meta_key()`: boolean true `= '1'`, false "not set or not '1'"; select `=` or `IN` over valid option values (at most 20). Public, meta-stored `boolean` and `select` fields only. |
| `search: string` | `s`, plain text, at most 100 characters. |
| `post__in: [ids]` | `post__in`; an empty list matches nothing. |
| `related: { key, post }` | `pegwork_related_query_args( post, key )` when the relationship links the loop's type to the post's type: a `meta_query` clause on `_rel_{key}` (listing the "from" type) or `post__in` (listing the "to" type, intersected with any other `post__in`). Otherwise, or when the post is not publicly viewable, `post__in: [0]`. |

Anything else is dropped. URL params never reach a query except through this filter (the filters module), so `?contact_email=` or `?meta_key=` on an archive does nothing.

### Query budget and timing (500 records)

Fixture: `tests/perf/seed.php` (`wp eval-file tests/perf/seed.php [count] [seed]`, also used by PHPUnit). `tests/Integration/Listings/DirectoryPerformanceTest.php` renders the directory and the archive with cold caches and fails above 35 queries or when 12 and 48 per page differ. `test_render_time_guard` also fails when the median of five cold-cache renders of the directory (unfiltered or filtered) exceeds 750 ms. Set `PEGWORK_PERF_REPORT=1` to print the counts and median times. Measured numbers: `architecture.md`.

`bin/perf-directory.sh` measures a real site over HTTP:

```sh
bin/e2e-local-site.sh start perf 8215          # a throwaway site
bin/perf-directory.sh perf                     # seeds to 500 Projects if needed, then measures
bin/perf-directory.sh --url http://127.0.0.1:8215 --path ~/wp-envs/perf --max-p95 300 --max-queries 35
```

It prints p50, p95 and max time to first byte (5 warm-up and 20 timed requests per scenario, no page cache) and the full-request query count for the home page (baseline), and for the archive and a page holding the directory pattern ("Perf directory", created if missing), each on pages 1 and 2, unfiltered and filtered. Query counts come from a temporary mu-plugin that the script installs in the target's `wp-content/mu-plugins` and removes on exit. With a site name it seeds; with `--url` and `--path` it seeds only when you add `--seed`, so it never changes a real site by accident (do not point it at siforgood.local unless you mean to). `--max-p95` and `--max-queries` make it exit 1 when exceeded. `wp server` runs PHP without opcache, which adds about 170 ms of compile time to every request, baseline included; for design-budget numbers serve the site with `php -d zend_extension=opcache.so -S`.

Related sections: `tests/Integration/Listings/RelatedListingTest.php::test_query_budget` renders the generated related pattern (condition plus loop) on a Project with 24 Stories and on a Story with 24 Projects, with cold caches, and fails above 35 queries or when 6 and 24 per page differ.

## Filters: the `pegwork/filters` block

Module `filters` (requires `listings`). One block per directory; it narrows every Query Loop of its type with `pegwork.filters` on, and the type's archive main query.

```html
<!-- wp:pegwork/filters {"postType":"sifg_project","align":"wide"} /-->
```

| Attribute | Default | Meaning |
|---|---|---|
| `postType` | `""` | Content type key. Empty: the archive's type, else the only type with a `listing`. No `listing`: renders nothing. |
| `filters` | all | Filter keys from the `listing`, in this order. Unknown keys are ignored. |
| `showSearch` | `true` | The search box, when the `listing` has `search: true`. |

Styles: Horizontal (default, wraps) and Vertical. Supports wide and full alignment, margin and padding. In the editor, the inspector picks the type, the search box and the filters, and the canvas shows a server render.

### Controls

From the type's `listing` (`docs/registry-reference.md`), labels from the registry and taxonomies at runtime:

| Filter | Control | URL |
|---|---|---|
| taxonomy, `multiple: true` | a native `details` disclosure, open or closed, whose summary reads "Impact category" or "Impact category, 2 selected", with a chevron that turns when open and a visible focus outline, holding a fieldset of checkboxes, terms in registry order then by name, at most 200 | `impact=sdg-04,sdg-13` (also `impact[]=`) |
| taxonomy, `multiple: false` | a fieldset of radios, "Any" first | `project_type=nonprofit` |
| `select` field | a labelled `<select>`, "Any" first | `size=small` |
| `boolean` field | one checkbox | `volunteers=1` |
| search | "Search projects", at most 100 characters | `q=data` |

Then "Apply filters" (submit), "Clear all" (hidden until something is active; links to the base URL) and the result count ("36 results", "No results") in a `role="status"` region. Fieldsets have legends; targets are at least 24px.

### URL state

The URL is the only state. Values are kept only when declared: unknown keys, private fields, unknown term slugs, select values outside the options, booleans other than `1`, nested arrays and non-strings are dropped; at most 20 values per filter, one for single-value filters. Values in one filter combine as OR, filters as AND. `where` presets win over the URL for the same field.

The form is a GET form to the base URL: the type's archive on the archive, else the page's permalink (a base URL's own query args, such as `page_id` with plain permalinks, become hidden fields), without `q`, paging or filter params. A filter change therefore starts again at page 1; core's pagination links keep the query string, so paging keeps the filters. The no-results message's `a.pegwork-filters-clear` link gets the same base URL.

### Result count

The block renders before the grid, so it counts with the bound loop's own query: the first filterable `core/query` of the type in the page content or the block template (through `core/pattern` references), built with core's `build_query_vars_from_query_block()`. The loop then reuses the result from the `WP_Query` cache, so the count costs no query. On the archive it is the main query's `found_posts`. With no bound loop it runs a one-row ID query.

### With JavaScript

Script module `@pegwork/filters` (Interactivity API store `pegwork/filters`, about 1.9 KB gzipped; the CI JS job fails above 10 KB via `npm run check:size`). Without it the block is the GET form above.

- A checkbox, radio or select change, or typing in search (300 ms pause), builds the URL and calls the router's `navigate()`, which fetches the server-rendered page and swaps only the Query Loop region (`data-wp-router-region`, set by core when the loop has enhanced pagination). No document load; focus stays on the control.
- While loading, the regions get `aria-busy="true"` and the class `is-loading` (dimmed after 150 ms; no transition with reduced motion).
- After the swap, the count region is emptied and refilled from the new page's state (`getServerState`), so screen readers announce it even when unchanged. The router's own announcement is off.
- Back and forward: the controls are set from the URL; the router restores the results from its cache, and the count is announced.
- Clear all resets the controls, focuses the search box (or the first control) and navigates to the base URL.
- Enhanced pagination stays client-side and keeps the filters.
- No router region (an inherit loop without enhanced pagination, or no loop on the page) or a router error: a normal page load to the same URL.

### Hooks

Signatures and examples are in `docs/extension-api.md`.

| Hook | Use |
|---|---|
| `pegwork_listing_constraints` (filter) | Add or change the constraints of every Pegwork Query Loop and archive main query. The filters module adds URL constraints here. |
| `pegwork_listing_pattern` (filter) | Change or drop a generated pattern (`card`, `details`, `directory`, `featured`, `latest`). |
| `pegwork_module_manifest` (filter) | Add modules. |

A theme overrides a pattern without a hook: a pattern registered earlier under the same name wins (see "Generated patterns").

### Outside blocks

`PegworkFilters\FiltersBlock::render_block( array $attributes ): string` renders the same markup from PHP (a theme template, a shortcode callback), with the server values in place and the state registered. It works as the GET form; for the client behaviour, also enqueue the `@pegwork/filters` script module and run the output through `wp_interactivity_process_directives()`.
