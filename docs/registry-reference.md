# Registry reference (format 1)

Every object kind, section and property a Pegwork schema package can declare. The PHP validator (`src/Registry/Validator.php` and `src/Registry/Rules/`) is the runtime truth; `schema/registry.schema.json` mirrors every rule it can express in JSON Schema. Each rule has an invalid fixture in `tests/fixtures/invalid/` that both validators agree on (`bin/check-schema-fixtures.php`).

Definitions are data only. Listings, forms, automations and options pages are validated, normalized, exported and imported by the registry; the modules that render or run them read them through `Pegwork\Api\Registry`.

## Contents

- [Packages and sources](#packages-and-sources)
- [Common rules](#common-rules)
- [Manifest](#manifest)
- [Content types](#content-types), with [fields](#fields) and [listing](#listing)
- [Taxonomies](#taxonomies)
- [Relationships](#relationships)
- [Options pages](#options-pages)
- [Forms](#forms)
- [Automations](#automations)
- [Reading definitions](#reading-definitions)
- [Error codes](#error-codes)

## Packages and sources

A package is a directory with `manifest.json` and one JSON file per object:

| Directory | Kind | `pegwork_schema` section | Registry accessor |
|---|---|---|---|
| `content-types/` | content type | `content_types` | `content_types()`, `content_type( $key )`, `listing( $type )` |
| `taxonomies/` | taxonomy | `taxonomies` | `taxonomies()` |
| `relationships/` | relationship | `relationships` | `relationships()`, `relationships_for( $type )` |
| `options-pages/` | options page | `options_pages` | `options_pages()`, `options_page( $key )` |
| `forms/` | form | `forms` | `forms()`, `form( $key )`, `form_source( $key )` |
| `automations/` | automation | `automations` | `automations()`, `automation( $key )` |

- File packages register on `pegwork_register` (`$builder->add_package( $dir )`), in registration order. A package that redefines a key an earlier package defined is rejected whole.
- Site-local definitions live in the `pegwork_schema` option, one list per section plus the imported `manifest`. `wp pegwork schema import` writes it. A DB entry whose key a file package defines is shadowed (reported by `wp pegwork schema status`), not an error.
- An object whose references do not resolve rejects its package (or its DB entry). References resolve across every accepted source, so a DB form may target a file content type.
- Every section is optional. Packages that use `listing`, `options-pages`, `forms` or `automations` should declare `"requires": { "pegwork": ">=0.2.0" }`, since 0.1 rejects unknown keys and directories are ignored.

## Common rules

- `format`: integer `1`, required on every object file.
- Keys: `^[a-z][a-z0-9_]*$`. Content type keys at most 20 characters, taxonomy keys at most 32, options page, form and automation keys at most 64 (`key_too_long`).
- Content type and taxonomy keys become query vars, so WordPress query vars (`type`, `status`, `name`, `author`, ...: `Validator::RESERVED_KEYS`) and `wp_*` are rejected (`reserved_key`).
- Unknown properties are errors (`unknown_property`), at every depth except `automations.ui`.
- No PHP callables: a property ending in `callback`, `_cb` or `_class`, or a PHP object in a DB entry, is `callable_not_allowed`.
- Decoded PHP cannot tell `{}` from `[]`, so the PHP validator accepts an empty array as either; the JSON Schema does not. The canonical (normalized) form never holds an empty optional object.
- Canonical form: defaults filled, empty optional objects dropped, object keys sorted, list order kept. Export writes it; import stores it; a second import of the same package reports 0 changes.

## Manifest

```json
{ "format": 1, "id": "sifg", "version": "0.2.0", "label": "SI for Good", "requires": { "pegwork": ">=0.2.0" } }
```

| Property | Required | Rules |
|---|---|---|
| `format` | yes | `1` |
| `id` | yes | key pattern; unique across registered packages (`duplicate_package`) |
| `version` | yes | semver (`invalid_version`) |
| `label` | yes | non-empty string |
| `requires` | no | map of name to version constraint strings; informational |

## Content types

```json
{
  "format": 1,
  "key": "sifg_project",
  "labels": { "singular": "Project", "plural": "Projects" },
  "public": true,
  "taxonomies": [ { "key": "sifg_impact", "select": "multi" } ],
  "fields": [ { "key": "website", "type": "url", "label": "Website" } ],
  "listing": { "search": true, "filters": [ { "key": "impact", "taxonomy": "sifg_impact" } ] },
  "wp": { "rest_base": "projects", "rewrite_slug": "projects", "has_archive": true }
}
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `format`, `key` | yes | | key at most 20 characters, not reserved |
| `labels` | yes | | `{ "singular", "plural" }`, both non-empty |
| `public` | no | `true` | boolean |
| `taxonomies` | no | `[]` | list of `{ key, select, required }`; `key` must be a registered taxonomy (`unknown_taxonomy`), unique per type; `select` `single` or `multi` (default `multi`); `required` boolean (default `false`) |
| `fields` | no | `[]` | list of [fields](#fields), keys unique per type |
| `listing` | no | | see [listing](#listing) |
| `wp` | no | | WordPress-only: `rest_base`, `rewrite_slug`, `menu_icon`, `capability_type` (strings), `has_archive` (boolean), `supports`, `allowed_blocks` (string lists) |

### Fields

```json
{ "key": "volunteer_link", "type": "url", "label": "Volunteer link",
  "show_when": { "field": "needs_volunteers", "equals": true },
  "required_when": { "field": "needs_volunteers", "equals": true } }
```

| Property | Default | Rules |
|---|---|---|
| `key` | | key pattern; on content types not `id`, `status` or `type` (`reserved_field_key`: record events and automation paths use those for the post itself) |
| `type` | | `text`, `textarea`, `rich_text`, `url`, `email`, `boolean`, `date` (`Y-m-d`), `number`, `select`. Options pages add `post`; forms add `file` (`post_field_not_allowed` elsewhere) |
| `label` | | non-empty string |
| `required` | `false` | boolean |
| `visibility` | `public` | `public` or `private` (private fields are edit-context only in REST and never leave the site through public outputs) |
| `default` | | must match the type (`invalid_default`); not allowed on `post` and `file` |
| `options` | | `select` only, required there: `[ { "value", "label" } ]` |
| `show_when`, `required_when` | | `{ "field": <key in the same object>, "equals": <scalar> }` (`unknown_field`) |
| `validate` | | string types: `min_length`, `max_length`, `min_words`, `max_words`, `pattern` (valid regex, `invalid_pattern`); `number`: `min`, `max`. Other rules on a type are `validate_not_applicable` |
| `wp` | | content types only: `storage` (`post_title` or `post_content`) or `meta_key` (`^_?[A-Za-z][A-Za-z0-9_]*$`, private fields need the leading `_`), not both |

Field storage, REST exposure and record validation: `docs/phase-1-design.md` section 3.

### Listing

Optional, on a content type. The public allowlist of what a directory of this type may filter by: nothing outside it reads `$_GET`.

```json
"listing": {
  "search": true,
  "filters": [
    { "key": "project_type", "taxonomy": "sifg_project_type", "label": "Type" },
    { "key": "impact", "taxonomy": "sifg_impact" },
    { "key": "volunteers", "field": "needs_volunteers" }
  ]
}
```

| Property | Default | Rules |
|---|---|---|
| `search` | `false` | boolean; enables URL parameter `q` (core search) |
| `filters` | `[]` | list of filters |
| `filters[].key` | | the URL parameter. Key pattern; not `q`, not `wp_*`, not a reserved query var, not any content type or taxonomy key (`listing_reserved_key`); unique per type (`listing_duplicate_key`) |
| `filters[].taxonomy` | | a taxonomy in this type's `taxonomies` (`listing_unknown_target`) |
| `filters[].field` | | a field of this type (`listing_unknown_target`) with `visibility: public` (`listing_private_field`) and type `boolean` or `select` (`listing_field_type`) |
| `filters[].multiple` | taxonomy: `true` when its `select` is `multi`; field: `false` | boolean; values in one filter combine as OR, filters as AND. Not `true` on a boolean field (`listing_field_type`) |
| `filters[].label` | | optional; when absent the taxonomy's singular label or the field label applies at runtime (never stored) |

Exactly one of `taxonomy` or `field` per filter (`listing_one_target`).

## Taxonomies

```json
{ "format": 1, "key": "sifg_impact", "labels": { "singular": "Impact area", "plural": "Impact areas" },
  "locked_terms": true, "terms": [ { "slug": "sdg-01", "name": "No poverty" } ] }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `format`, `key` | yes | | key at most 32 characters, not reserved |
| `labels` | yes | | `{ singular, plural }` |
| `hierarchical` | no | `false` | boolean |
| `locked_terms` | no | `false` | boolean; only `manage_options` users add terms |
| `terms` | no | `[]` | seed terms `{ slug, name }`; slug `^[a-z0-9]+(-[a-z0-9]+)*$`, unique |
| `wp` | no | | `rewrite_slug` |

## Relationships

```json
{ "format": 1, "key": "story_projects", "from": "sifg_story", "to": "sifg_project",
  "cardinality": "many_to_many", "label": "Projects", "required": true }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `format`, `key` | yes | | key pattern |
| `from`, `to` | yes | | content type keys (`unknown_content_type`), different (`self_relationship`) |
| `cardinality` | yes | | `one_to_many` or `many_to_many` |
| `storage` | no | `meta` | only `meta` (`table` is `storage_not_supported`); `_rel_{key}` may not be a meta key of a "from" field (`meta_key_conflict`) |
| `label` | no | runtime: "to" type's plural label | non-empty string |
| `required` | no | `false` | boolean |

Behavior: `docs/relationships.md`.

## Options pages

One settings screen per page; its key is the option name that stores its values.

```json
{ "format": 1, "key": "sifg_settings", "title": "SI for Good settings", "menu_title": "Site settings",
  "capability": "manage_options",
  "fields": [
    { "key": "featured_project", "type": "post", "label": "Featured project", "post_type": "sifg_project" },
    { "key": "moderator_email", "type": "email", "label": "Moderator notification email", "required": true, "visibility": "private" },
    { "key": "review_criteria", "type": "rich_text", "label": "Review criteria" } ] }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `format` | yes | | `1` |
| `key` | yes | | key pattern, at most 64 characters; not a core option name from `populate_options()` or another core-managed option (`cron`, `sidebars_widgets`, ...: `OptionsPageRules::RESERVED_OPTION_NAMES`), and not `wp_*` or `pegwork_*` (uninstall deletes `pegwork_*` options) (`reserved_key`) |
| `title` | yes | | non-empty string |
| `menu_title` | no | runtime: `title` | non-empty string |
| `capability` | no | `manage_options` | capability name, `^[a-z][a-z0-9_]*$` |
| `fields` | yes | | list of fields, keys unique |

Fields take the [content type field](#fields) properties, except `show_when`, `required_when` and `wp` (`not_allowed_in_options_page`). Field keys `id`, `status` and `type` are allowed here.

Field type `post` (options pages only): one post ID, `post_type` required (`missing_property`) and must be a registry content type (`unknown_content_type`). `post_type` on any other field type is `unknown_property`.

Behavior (settings screen, saving rules, `pegwork_get_option()`, CLI): `docs/options-pages.md`; front-end output: `pegwork/option` in `docs/display.md`.

## Forms

A form either creates or updates a record of a content type (`target`) or, without `target`, only stores entries.

```json
{
  "format": 1,
  "key": "submit_project",
  "label": "Submit a project",
  "target": { "content_type": "sifg_project", "action": "create", "status": "pending" },
  "confirm": { "field": "contact_email", "subject": "Confirm your submission", "body": "Confirm \"{field:name}\":\n{confirm_url}" },
  "captcha": "turnstile",
  "steps": [
    { "key": "project", "label": "Your project", "fields": [
      { "bind": { "field": "name" } },
      { "bind": { "taxonomy": "sifg_project_type" }, "display": "radio" } ] },
    { "key": "contact", "label": "Contact", "fields": [
      { "bind": { "field": "contact_email" }, "autocomplete": "email" },
      { "key": "consent", "type": "boolean", "label": "I agree", "required": true } ] }
  ],
  "notifications": [
    { "key": "moderator", "when": "confirmed", "to": "{option:sifg_settings.moderator_email}",
      "reply_to": "{field:contact_email}", "subject": "To review: {field:name}", "body": "{all_fields}" }
  ],
  "messages": { "success": "Check your email to confirm." },
  "retention_days": 365
}
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `format`, `label` | yes | | |
| `key` | yes | | key pattern, at most 64 characters |
| `target` | no | | `{ content_type, action, status }`. `content_type` must be defined (`unknown_content_type`). `action` `create` (default) or `update`. `status` `draft`, `pending` or `publish`; default `pending` for `create`, none for `update` (the record keeps its status) |
| `access` | no | `anyone` | `"anyone"` or `{ "capability": "<capability>" }`. A capability object is required when `target.status` is `publish` or `action` is `update` (`form_access_required`) |
| `confirm` | no | | double opt-in: `{ field, subject, body, expires_hours }`. `field` is an email field of the form (`form_confirm_field`); `body` must contain `{confirm_url}` (`form_confirm_url_missing`); `expires_hours` positive integer, default `72` |
| `captcha` | no | | `turnstile`: Cloudflare Turnstile widget on the last step, verified server side. Keys come only from the wp-config constants `PEGWORK_TURNSTILE_SITE_KEY` and `PEGWORK_TURNSTILE_SECRET_KEY`; without both the form is not shown (`docs/forms.md#turnstile`) |
| `fields` | one of | | list of items |
| `steps` | one of | | list of `{ key, label, description, fields }`; step keys unique. Exactly one of `fields` or `steps` (`form_fields_or_steps`) |
| `notifications` | no | `[]` | list of notifications, keys unique |
| `messages` | no | | `{ success, submit_label }`, non-empty strings |
| `retention_days` | no | | positive integer; entries older than this are deleted |

### Form items

Bound items map to the target content type's field or taxonomy and take their type, label, options, rules and conditions from it:

| Property | Rules |
|---|---|
| `bind` | `{ "field": <key> }` or `{ "taxonomy": <key> }`, exactly one (`form_bind_target`); must exist on the target type (`form_unknown_bind`). Needs a `target` (`form_bind_without_target`) |
| `label` | overrides the field or taxonomy label |
| `help`, `placeholder`, `autocomplete` | non-empty strings |
| `display` | `radio`, `checkboxes` or `select`: single-choice controls (select fields, single taxonomies) allow `radio` or `select`, multi taxonomies `checkboxes` or `select`; nothing else takes `display` (`form_display`) |

Unbound items are form-only fields (stored on the entry, not the record): the [field](#fields) properties without `visibility` and `wp`, plus `help`, `placeholder`, `autocomplete`, `display` (select only, single choice), and for `file` fields `accept` (required: list of lowercase extensions; script, markup and executable extensions such as `php*`, `phtml`, `phar`, `html`, `svg`, `js`, `mjs`, `exe` are `form_accept_denied`) and `max_size_mb` (positive number). Unbound items default `required: false`.

Cross-item rules:

- Item keys (field keys of unbound items, bound keys) are unique across all steps (`duplicate_key`).
- `create` forms must bind every required field and required taxonomy of the target type (`form_missing_required`).
- A bound field whose content type `show_when` or `required_when` names another field needs that field bound too (`form_condition_not_bound`).
- Conditions on unbound items name another item of the form (`unknown_field`).

### Notifications

| Property | Rules |
|---|---|
| `key` | key pattern, unique |
| `when` | `submitted` or `confirmed` |
| `to`, `reply_to` | an email address, `{admin_email}`, `{field:<key>}` (an email item of the form, `form_recipient_not_email`) or `{option:<page>.<field>}` (an email field of an options page: `unknown_options_page`, `unknown_field`, `form_recipient_not_email`). Anything else is `invalid_recipient`; double braces get a hint toward the single-brace tag |
| `subject`, `body` | strings; `{field:<key>}` must name an item of the form (`unknown_field`). Other merge tags: `{all_fields}`, `{post:edit_url}`, `{confirm_url}` |

With `confirm`, a `submitted` notification may not go to `{field:...}` (`form_premature_mail`): the confirmation email is the only mail an unconfirmed address receives.

## Automations

A trigger, ordered steps, and optional signed links. Conditions are a JSON expression tree; text uses `{{ path }}` merge tags. Both are checked at load time, so a typo fails `wp pegwork schema validate` instead of a run.

```json
{ "format": 1, "key": "sifg_project_approved", "label": "Approval: verify and email the submitter",
  "trigger": { "type": "event", "event": "record.status_changed", "content_type": "sifg_project",
    "when": { "all": [ { "var": "trigger.old_status", "op": "eq", "value": "pending" },
                       { "var": "trigger.new_status", "op": "eq", "value": "publish" } ] } },
  "steps": [
    { "id": "verify", "type": "update_record", "values": { "last_verified": "{{today}}" } },
    { "id": "notify", "type": "send_email", "to": "{{record.contact_email}}",
      "subject": "{{record.name}} is live on {{site.name}}", "text": "It is live: {{record.permalink}}" } ] }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `format`, `label` | yes | | |
| `key` | yes | | key pattern, at most 64 characters |
| `description` | no | | non-empty string |
| `enabled` | no | `true` | boolean |
| `trigger` | yes | | see below |
| `steps` | yes | | non-empty list of steps; step `id`s unique within the list (`duplicate_key`) |
| `idempotency` | no | | template; runs with the same rendered value are skipped |
| `links` | no | | map of link name to [link](#signed-links) |
| `ui` | no | | free-form editor data (canvas positions); preserved, never read by the engine |

### Triggers

| `type` | Properties |
|---|---|
| `event` | `event` (required): dot-separated name such as `record.updated` (`invalid_event`); `content_type` narrows `record.*` events and `form` narrows `form.*` events (`trigger_filter_mismatch` otherwise; `unknown_content_type`, `unknown_form`); `when`: condition |
| `schedule` | `every` (required): `hour`, `day` or `week`; `at`: `HH:MM` site time; `for_each`: `{ content_type (required), status (default `["publish"]`; any of `publish`, `future`, `draft`, `pending`, `private`), where (condition), max_runs (default 200) }` |
| `webhook` | none; the inbound request body is `trigger.payload` |

A run is bound to a record when the trigger is a `record.*` event or a schedule with `for_each`. Without one, `record.*` paths, `{{link.*}}` and `update_record` without `record` are `automation_no_record`.

### Steps

Every step has `id` (key pattern) and `type`, plus optional `retry` (`{ max: integer >= 0, backoff: duration or list of durations }`; no stored default).

| `type` | Properties |
|---|---|
| `update_record` | `values` (required); `record`: template giving a record ID (default the bound record) |
| `create_record` | `content_type` (required, defined), `values` (required), `status` (default `draft`; `draft`, `pending`, `publish`, `private`). Output `steps.<id>.record_id` |
| `send_email` | `to`, `subject`, `text` (required), `html`, `reply_to` (templates) |
| `call_webhook` | `url` (required, template, public context), `fields` (record field or taxonomy keys sent in the body; private ones are `automation_private_field`), `timeout` (seconds, more than 0 and at most 15) |
| `wait` | `for` (required): duration |
| `condition` | `when` (required): condition; the run stops when it is false |

`values` maps record field or taxonomy keys (`unknown_field`) to literals or templates; a literal must fit the field type (`automation_value_type`). Templates are rendered in a private context.

Durations are ISO 8601 (`P7D`, `PT6H`, `P1Y2M`; `invalid_duration`), from one hour to two years (`duration_out_of_range`).

### Signed links

`links.<name>` defines a one-click link for the bound record, inserted with `{{link.<name>}}` (`automation_unknown_link` if not defined).

| Property | Required | Default | Rules |
|---|---|---|---|
| `expires` | yes | | duration |
| `confirm` | no | `true` | boolean; show a confirm button before acting |
| `button` | no | | button text |
| `steps` | yes | | steps run on click: only `update_record` and `condition` (`automation_link_step`); ids unique within the link |
| `success_url`, `expired_url` | no | | URL or site path |
| `success_message` | no | | text shown after the click |

`button`, `success_url`, `success_message` and `expired_url` are templates in a public context: the page is shown to whoever holds the link.

### Conditions

```json
{ "all": [
  { "var": "record.last_verified", "op": "lte", "value": { "date": "today", "offset": "-P12M" } },
  { "not": { "var": "record.sifg_project_status", "op": "eq", "value": "paused" } },
  { "any": [ { "var": "record.seats", "op": "gt", "value": 10 }, { "var": "record.format", "op": "in", "value": [ "workshop" ] } ] }
] }
```

- Node: `{ "all": [...] }`, `{ "any": [...] }` (non-empty), `{ "not": node }`, or a leaf `{ var, op, value }` (one shape per node, `invalid_condition`).
- `op`: `eq`, `ne`, `in`, `not_in`, `lt`, `lte`, `gt`, `gte`, `empty`, `not_empty`, `contains`, `changed`. `empty`, `not_empty` and `changed` take no `value`; `in` and `not_in` take a list (`condition_operand`).
- `value`: a scalar, `{ "var": path }`, or `{ "date": "today"|"now", "offset": signed duration }`.
- `changed` only under a `record.updated` trigger. Operators and literals must fit the field type: order operators on number and date fields, `contains` on multi taxonomies, dates as `Y-m-d`, select values from the options (`condition_type_mismatch`).
- At most 5 levels and 50 nodes (`condition_too_complex`).

### Paths and templates

Paths are dot-separated (`invalid_var`). Templates are `{{ path }}` or `{{ path | default:"text" }}` (`invalid_template`).

| Root | Paths |
|---|---|
| `record` | `record.<field or taxonomy key>`, `record.id`, `record.status`, `record.type`; templates also `record.permalink`, `record.edit_url`. Keys must exist on the bound type (`unknown_field`) |
| `trigger` | `record.created`, `record.deleted`: `id`, `type`, `status`, `user_id`, `origin`. `record.updated`: also `changed`, `old.<key>`. `record.status_changed`: `id`, `type`, `old_status`, `new_status`, `user_id`, `origin`. `form.*`: `form`, `entry_id`, `post_id`, `record_id`, `values.<item key>` (checked against the form when `form` is set). Webhook: `payload.*`. Schedules have no payload |
| `steps` | `steps.<id>.<output>` of an earlier step (`create_record`: `record_id`) |
| `site` | `site.name`, `site.url` |
| `today`, `now`, `run.id`, `link.<name>` | templates only |

Private fields may be read in conditions, emails, `values` and `idempotency`, but not in public contexts: `call_webhook` (`url`, `fields`) and link page texts (`automation_private_field`).

## Reading definitions

`pegwork_registry()` returns the read-only `Pegwork\Api\Registry` (after `init` priority 5). Every method returns the canonical form: defaults filled, keys sorted. Full list: `docs/extension-api.md`.

```php
$listing = pegwork_registry()->listing( 'sifg_project' ); // { search, filters[] } or null.
$form    = pegwork_registry()->form( 'submit_project' );
$source  = pegwork_registry()->form_source( 'submit_project' ); // "file:sifg", "db" or null.
$page    = pegwork_registry()->options_page( 'sifg_settings' );
```

`wp pegwork schema list` prints one row per listing, form, automation and options page after the content types, taxonomies and relationships; `wp pegwork schema status` reports shadowed and rejected entries of every kind.

## Error codes

| Code | Kind |
|---|---|
| `invalid_json`, `missing_package`, `missing_manifest`, `duplicate_package`, `duplicate_key`, `unsupported_format`, `invalid_type`, `invalid_value`, `missing_property`, `unknown_property`, `callable_not_allowed`, `invalid_key`, `key_too_long`, `reserved_key`, `invalid_version` | any |
| `unknown_taxonomy`, `invalid_slug`, `invalid_default`, `invalid_pattern`, `validate_not_applicable`, `invalid_meta_key`, `unknown_field`, `reserved_field_key`, `post_field_not_allowed` | content types, taxonomies, fields |
| `listing_reserved_key`, `listing_duplicate_key`, `listing_one_target`, `listing_unknown_target`, `listing_private_field`, `listing_field_type` | listing |
| `unknown_content_type`, `self_relationship`, `storage_not_supported`, `meta_key_conflict` | relationships |
| `not_allowed_in_options_page` | options pages |
| `form_fields_or_steps`, `form_bind_target`, `form_bind_without_target`, `form_unknown_bind`, `form_display`, `form_missing_required`, `form_condition_not_bound`, `form_access_required`, `form_confirm_field`, `form_confirm_url_missing`, `form_premature_mail`, `form_recipient_not_email`, `form_accept_denied`, `invalid_recipient`, `unknown_options_page` | forms |
| `invalid_event`, `trigger_filter_mismatch`, `unknown_form`, `automation_no_record`, `automation_link_step`, `automation_unknown_link`, `automation_private_field`, `automation_value_type`, `invalid_duration`, `duration_out_of_range`, `invalid_condition`, `condition_operand`, `condition_type_mismatch`, `condition_too_complex`, `invalid_template`, `invalid_var` | automations |
