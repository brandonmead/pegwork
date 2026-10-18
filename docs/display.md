# Pegwork display

Field output and conditional display on standard blocks. Provided by the `listings` module:

```
wp pegwork module enable listings
```

Design: `docs/phase-2-design.md` sections 4 and 5. Listings (Query Loop variation), filters and the `listing` registry section arrive in later Phase 2 PRs.

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

"Visible value" follows the binding rules: an unmet `show_when` counts as empty.

### Fails closed

The block renders nothing when any condition names an unknown or private field (a condition on a private field would reveal whether it is set), a taxonomy the content type does not use, or an unknown operator; when conditions are malformed; when there is no record in context; or when the record is password-protected or not publicly viewable. No conditions means always shown.

### Editor

The inspector builds conditions from the content type's public fields and taxonomies: the context post type, the type of a `single-<type>` or `archive-<type>` template, or a type chosen in the panel. Private fields are never offered. The canvas shows a "Show if: ..." summary above the inner blocks, which always display in the editor, and a warning when a condition would make the block render nothing. The block supports Interactivity API client navigation, so it can sit inside directory cards.
