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
- [Automations](#automations), with [reads](#reads), [loops](#loops), [relate](#relate), [date waits](#date-waits), [trash](#trash), [start another automation](#start-another-automation) and [role recipients](#role-recipients)
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

`requires` is validated for shape only (string values, no callables) and is never compared with the running versions: Pegwork does not refuse a package whose constraint it does not meet. It tells readers and tools which plugin version the package was written for. An older Pegwork rejects the newer sections on its own (unknown keys), which is the real guard. The plugin version (`PEGWORK_VERSION`, plugin header) and the API version (`Pegwork\Api\VERSION`) are both 0.3.0.

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
| `visibility` | `public` | `public` or `private` (private fields are edit-context only in REST and never leave the site through public outputs). A private field is stored in meta only: `wp.storage` (`post_title` and `post_content` are public in REST, feeds, search and the theme) is `private_storage` |
| `default` | | must match the type (`invalid_default`); not allowed on `post` and `file` |
| `options` | | `select` only, required there: `[ { "value", "label" } ]` |
| `show_when`, `required_when` | | `{ "field": <key in the same object>, "equals": <scalar> }` (`unknown_field`). On a public field, the target may not be private (`private_condition_field`): whether the public field shows would reveal the private value |
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
| `messages` | no | | `{ success, submit_label, confirmed, confirm_failed }`, non-empty strings. `confirmed` is shown after a successful email confirmation (default "Thank you. Your submission is confirmed."). `confirm_failed` is shown after a confirmation whose values no longer pass validation (the entry is `failed`, a draft is kept for review) |
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
| `steps` | yes | | non-empty list of steps; step `id`s unique across the automation, branches included (`duplicate_key`) |
| `idempotency` | no | | template; runs with the same rendered value are skipped |
| `links` | no | | map of link name to [link](#signed-links) |
| `ui` | no | | reserved free-form editor data; preserved, never read by the engine (the Phase 6 editor does not write it) |

### Triggers

| `type` | Properties |
|---|---|
| `event` | `event` (required): dot-separated name such as `record.updated` (`invalid_event`); `content_type` narrows `record.*` events and `form` narrows `form.*` events (`trigger_filter_mismatch` otherwise; `unknown_content_type`, `unknown_form`); `when`: condition |
| `schedule` | `every` (required): `hour`, `day` or `week`; `at`: `HH:MM` site time; `for_each`: `{ content_type (required), status (default `["publish"]`; any of `publish`, `future`, `draft`, `pending`, `private`), where (condition), max_runs (default 200) }` |
| `webhook` | none; the inbound request body is `trigger.payload` (endpoint, signature and limits: `docs/automation.md#webhooks`) |
| `manual` | `button` (required): text, at most 40 characters (`invalid_value`); `content_type`: with it the Run button is on that type's edit screen and the run binds the chosen record, without it the run has no record (Run now on the list); `allow`: `admins` (default; `manage_options`) or `editors` (users who can edit the record; needs `content_type`, else `invalid_value`); `when`: condition checked at the click. Payload `trigger.user_id`, `trigger.source` (`editor` or `list`) |

A run is bound to a record when the trigger is a `record.*` event, a schedule with `for_each`, a `manual` trigger with `content_type`, or a form event that binds (below). Without one, `record.*` paths, `{{link.*}}`, `call_webhook` `fields` (without `record`) and `update_record` without `record` are `automation_no_record`.

Automation v3 shapes (reads, loops, `relate`, `on_error`, `manual`, `call_webhook.record`, relationship `values`, `| add`, `| number`, `{{#each}}`) validate, normalize, export and import. The automation module runs reads, `relate`, `on_error`, `call_webhook.record`, relationship `values` and the new template filters (`docs/automation.md`); until it adds loops and the manual trigger (`docs/automation-v3-design.md` section 11, PRs 4 and 5), a run of an automation with a `loop` fails with `unsupported_step` before its first step, and a `manual` trigger starts no run. Packages that use them declare `requires.pegwork` `>=0.3.0`.

Automation v4 shapes (a `wait` `until` a date field, `trash_record`, `start_automation`, `{role:<slug>}` recipients and a read's `created`; `docs/automation-v4-design.md`) validate, normalize, export and import. The automation module runs a `wait` `until` (v4 PR 3, `docs/automation.md#wait-until-a-date`). Until it runs the others (design section 11, PRs 4 to 6), a run of an automation that uses one fails with `unsupported_step` before its first step, so nothing half-runs. Packages that use them declare `requires.pegwork` `>=0.4.0`.

Form binding: an `event` trigger on `form.confirmed`, or on `form.submitted` of a form without `confirm`, whose `form` has a `target`, binds the record the form wrote (`trigger.record_id`). Record paths and `update_record` without `record` then check against the target's `content_type`. Any other form trigger binds nothing: `form.submitted` of a `confirm` form (the record is written only on confirmation), a form without `target`, or a trigger without `form`. This check needs the form, so it runs with the references (`wp pegwork schema validate` reports it like `unknown_form`).

### Steps

Every step has `id` (key pattern) and `type`, plus optional `retry` (`{ max: integer >= 0, backoff: duration or list of durations }`; no stored default; not on `decision` or `loop`). `update_record`, `create_record`, `send_email`, `call_webhook`, `relate`, `get_record`, `find_records`, `trash_record` and `start_automation` may set `"on_error": "continue"` (any other value `invalid_value`; on other types `unknown_property`): after its retries a failure is logged, the step's outputs `failed` (true) and `error` (the code) are set, and the run continues; on success `failed` is false.

| `type` | Properties |
|---|---|
| `update_record` | `values` (required); `record`: template giving a record ID (default the bound record) |
| `create_record` | `content_type` (required, defined), `values` (required), `status` (default `draft`; `draft`, `pending`, `publish`, `private`). Output `steps.<id>.record_id` |
| `send_email` | `to`, `subject`, `text` (required), `html`, `reply_to` (templates; `text` and `html` may hold `{{#each}}` blocks). `to` has 1 to 5 parts separated by `,` or `;` (`too_many_recipients`): addresses, merge tags, `{option:<page>.<field>}` and [`{role:<slug>}`](#role-recipients) |
| `call_webhook` | `url` (required, template, public context), `fields` (record field or taxonomy keys sent in the body; private ones are `automation_private_field`), `timeout` (seconds, more than 0 and at most 15, default 10), `record` and `content_type` (both or neither, `missing_property`): send that record (a template giving its ID) instead of the bound record, with `fields` checked against `content_type`. Without `fields` every public field and taxonomy is sent. Body, headers and signature: `docs/automation.md#webhooks` |
| `wait` | exactly one of `for` (a duration) or `until` (see [Date waits](#date-waits)); both or neither is `wait_source` |
| `condition` | `when` (required): condition; the run stops when it is false (inside a loop body: skips to the next item) |
| `decision` | `branches` (required), `otherwise`: see [Decisions](#decisions) |
| `get_record`, `find_records` | see [Reads](#reads) |
| `loop` | see [Loops](#loops) |
| `relate` | see [Relate](#relate) |
| `trash_record` | see [Trash](#trash) |
| `start_automation` | see [Start another automation](#start-another-automation) |

`values` maps record field or taxonomy keys (`unknown_field`) to literals or templates; a literal must fit the field type (`automation_value_type`). Templates are rendered in a private context. A key may also be a relationship whose `from` end is the written type (`unknown_field` otherwise): a template rendering one ID or comma-separated IDs, comma-separated ID literals, or a list of integers (`automation_value_type` otherwise). It replaces the record's links; an empty value clears them. A list of strings is for multi-select taxonomies only.

The written type of `update_record` is the bound type without `record`, and is known too when `record` is exactly `{{record.id}}`, `{{steps.<id>.record_id}}` (a `get_record` or `create_record` step) or `{{steps.<loop>.item.id}}`: then its `values` keys are checked. Any other `record` template is checked at run time only.

Durations are ISO 8601 (`P7D`, `PT6H`, `P1Y2M`; `invalid_duration`), from one hour to two years (`duration_out_of_range`).

### Decisions

A `decision` runs the steps of the first branch whose `when` holds, or `otherwise` when none does, then the run continues after the decision. With no match and no `otherwise`, it continues after the decision. One step type covers if/else and multi-way routing.

```json
{ "id": "size", "type": "decision",
  "branches": [
    { "label": "Major gift", "when": { "var": "record.amount", "op": "gte", "value": 250 },
      "steps": [ { "id": "thank_major", "type": "send_email", "to": "{{record.donor_email}}", "subject": "Thank you", "text": "..." } ] } ],
  "otherwise": [ { "id": "thank", "type": "send_email", "to": "{{record.donor_email}}", "subject": "Thank you", "text": "..." } ] }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `id`, `type` | yes | | as every step; no `retry` (`unknown_property`) |
| `branches` | yes | | 1 to 10 items (`invalid_value`), each `{ label, when, steps }`, all required. `label`: non-empty text, unique within the decision (`duplicate_key`). `when`: a [condition](#conditions). `steps`: a step list, may be empty (the branch matches and does nothing) |
| `otherwise` | no | | step list run when no branch matches; an empty list is dropped from the canonical form |

- Step ids are unique across the automation's whole tree (`duplicate_key`, "already used in this automation"). Each link's steps are a tree of their own, so links may reuse ids.
- A decision inside a branch is level 2; at most 3 levels (`decision_too_deep`, at the decision that is too deep).
- `steps.<id>.<output>` must name a step that runs on every path before this point: an earlier sibling, or an earlier sibling of an enclosing list. An output created inside a branch is usable later in that branch only; elsewhere it is `invalid_var` ("step "x" runs only in another branch").
- `automation_no_record`, private field and type rules apply inside branches as anywhere.
- Not allowed in link steps (`automation_link_step`).
- Decisions need a plugin with Phase 6 (an older one rejects the file with `invalid_value` on `type`); declare it in the manifest's informational `requires.pegwork`.

### Reads

`get_record` reads one record (the first by `sort`); `find_records` reads up to `limit` records plus totals over every match. Both share one query model, the one `Pegwork\Api\Records::find()` runs (`docs/extension-api.md`), and use its codes.

```json
{ "id": "donor", "type": "get_record", "content_type": "barn_contact",
  "related": { "relationship": "gift_donor", "of": "{{record.id}}" } }
{ "id": "due", "type": "find_records", "content_type": "barn_task", "status": [ "publish" ],
  "filter": { "all": [ { "field": "done", "op": "ne", "value": true },
                       { "field": "due_on", "op": "lte", "value": { "date": "today", "offset": "P7D" } } ] },
  "sort": { "field": "due_on", "order": "asc" }, "limit": 50, "sum": [ "amount" ] }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `content_type` | yes | | defined (`unknown_content_type`); the type of the records read |
| `related` | no | | `{ relationship, of }`, both required. `relationship` is defined (`unknown_relationship`) and has `content_type` at one end (`relationship_end`). `of` is a template giving a record ID at the other end; when it is exactly a typed reference (`{{record.id}}`, `{{steps.<id>.record_id}}`, `{{steps.<loop>.item.id}}`), its type must be the other end (`relationship_end`) |
| `filter` | no | | `{ "all": [leaf...] }` or `{ "any": [leaf...] }` (`invalid_condition`), one level, 1 to 10 leaves (`condition_too_complex`). Leaf `{ field, op, value }`: `field` is a field or taxonomy key of `content_type`, or `id` (`unknown_field`); `rich_text`, `textarea` and `post_content` fields cannot be filtered (`filter_field`). `op` and `value` follow the [condition](#conditions) rules without `changed`; `value` is a literal, `{ var }` (any path in scope) or `{ date, offset }` |
| `status` | no | `["private", "publish"]` | one or more of `publish`, `future`, `draft`, `pending`, `private`, each once (`invalid_value`); sorted in the canonical form. Trash and auto-drafts are never read |
| `sort` | no | `{ "field": "id", "order": "asc" }` | `field` (required): `id`, or a `text`, `number`, `date`, `select` or `email` field, or a title-storage field (`filter_field`); `order`: `asc` (default) or `desc` |
| `created` | no | | Creation time (API 0.4.0): `{ before, after }`, at least one (`invalid_value`). Each bound is a `Y-m-d` date, `{ "date": "today" or "now", "offset" }` or `{ "var": "<path>" }` (a path in scope; one of a known type must read a date, else `invalid_value`); anything else is `invalid_value`. Days are site days: `before` matches records created before that day starts, `after` records created from the start of the next day. An empty `{ var }` matches nothing. Kept as written in the canonical form. A property of the query, not a filter field, because a type may define a field called `created` |
| `record` | no | | `get_record` only: a template giving one ID. Exclusive with `related`, `filter` and `created` (`read_source`) |
| `limit` | no | `10` | `find_records` only: integer 0 to 50 (`invalid_value`); 0 loads no records (totals only) |
| `sum` | no | | `find_records` only: at most 5 `number` field keys (`invalid_value`, `filter_field`) |

- `get_record` needs `record`, `related`, `filter` or `created`; `find_records` needs `related`, `filter` or `created`, so reading a whole type is never an accident (`read_source`).
- Outputs. `get_record`: `steps.<id>.found` (boolean), `steps.<id>.record_id` (number), `steps.<id>.record.<key>` (a field or taxonomy of `content_type`, `id`, `status`, `type`; templates also `permalink`, `edit_url`). `find_records`: `steps.<id>.count` (all matches), `steps.<id>.sum.<field>` (a field listed in `sum`), `steps.<id>.records` (the loaded records: only a loop's `over` and `{{#each}}`; anywhere else `invalid_var`).
- `steps.<id>.record.<key>` is checked as `record.<key>` is: the key exists (`unknown_field`), private fields stay out of public contexts (`automation_private_field`), and conditions type-check against the field. A sum over a private field is private too.

### Loops

```json
{ "id": "each_task", "type": "loop", "over": "steps.due.records",
  "steps": [ { "id": "remind", "type": "send_email", "on_error": "continue",
               "to": "{{steps.each_task.item.assignee_email}}", "subject": "Due: {{steps.each_task.item.name}}", "text": "..." } ] }
```

| Property | Required | Rules |
|---|---|---|
| `over` | yes | `steps.<id>.records` of a `find_records` step in scope (`invalid_var`); a path, not a template |
| `steps` | yes | 1 to 10 steps counting the steps inside decisions (`invalid_value`); no `wait` at any depth (`loop_wait`) and no `loop` (`loop_nested`) |

- No `retry` or `on_error` (`unknown_property`). A loop counts as a nesting level toward the 3-level limit (`decision_too_deep`), and is not allowed in link steps (`automation_link_step`).
- Inside the body: `steps.<loop>.item.<key>` (a record of the collection's type, checked as `record.<key>`) and `steps.<loop>.index` (number, 1-based). After the loop: `steps.<loop>.count` (number). Outputs of body steps exist only later in the same item (`invalid_var`, "step "x" runs inside loop "each_task"").
- A false `condition` in the body skips to the next item.
- Static bound: one run takes at most 500 step attempts without retries, counting each step once, each loop body step 50 times (the most items a find returns) and a decision's longest branch (`automation_too_large`). The loop step itself counts once. Work out the room as: steps outside bodies + 50 x body steps <= 500. So with one step before a 50-item loop, the body can hold 9 steps (1 + 1 + 9 x 50 = 452), and 48 more steps fit outside it; a body of 10 never fits (at least 1 + 10 x 50 = 501). The error message says the same.

### Relate

```json
{ "id": "link_gift", "type": "relate", "relationship": "task_gift", "action": "add",
  "from": "{{steps.call_task.record_id}}", "to": "{{record.id}}" }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `relationship` | yes | | defined (`unknown_relationship`) |
| `action` | no | `add` | `add` or `remove` |
| `to` | yes | | template giving one ID |
| `from` | no | the bound record | template giving one ID. Without it the bound record must be of the relationship's `from` type (`missing_property`) |

A `from` or `to` that is exactly a typed reference (as for `related.of`) must be of that end's type (`relationship_end`). No outputs.

### Date waits

A `wait` with `until` waits until a date field of the run's record (Automation v4). The engine re-reads the field on every wake-up, so a date moved later, earlier or cleared is honoured (`docs/automation-v4-design.md` section 3.2).

```json
{ "id": "before_due", "type": "wait", "until": { "var": "record.due_on", "offset": "-P3D", "at": "09:00" } }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `until.var` | yes | | `record.<key>`, a path (not a template), where `<key>` is a `date` field of the bound type; private date fields are allowed. A read or payload path is `wait_field`; a trigger that binds no record is `automation_no_record`; one that binds a record of no named type (a `record.*` event without `content_type`) is `wait_field` |
| `until.offset` | no | `P0D` | signed ISO 8601 duration, at most `P2Y` either way (`invalid_value`); calendar units in site time. A zero offset is dropped from the canonical form |
| `until.at` | no | `09:00` | `HH:MM` site time on the date, before the offset applies (`invalid_value`); filled by the normalizer |

No outputs. As for `for`: no wait in a loop body (`loop_wait`) or in link steps (`automation_link_step`).

### Trash

`trash_record` moves one record to the trash (Automation v4). It never deletes permanently: the run fails `trash_disabled` when trash is off.

```json
{ "id": "remove", "type": "trash_record", "content_type": "barn_volunteer", "record": "{{steps.each_old.item.id}}" }
```

| Property | Required | Rules |
|---|---|---|
| `content_type` | yes | defined (`unknown_content_type`); the only type this step may trash |
| `record` | no (default the bound record) | exactly one typed reference: `{{record.id}}`, `{{steps.<id>.record_id}}` of a `get_record` or `create_record` step, or `{{steps.<loop>.item.id}}` (`trash_target`; a payload or form value can never name the record). Its type must be `content_type` (`trash_type`). Without `record` the trigger must bind a record (`automation_no_record`) of `content_type` (`trash_type`) |

- Not in a schedule with `for_each` (`trash_in_sweep`): use a schedule, `find_records` and a loop, which trash at most 50 records a run. Not in link steps (`automation_link_step`). Allowed in loop bodies and branches.
- `retry` and `"on_error": "continue"` as for other steps. No outputs.

### Start another automation

`start_automation` starts a `manual` automation and does not wait for it (Automation v4): the child is a run of its own, at the parent's depth plus one.

```json
{ "id": "receipt", "type": "start_automation", "automation": "barn_gift_receipt" }
```

| Property | Required | Rules |
|---|---|---|
| `automation` | yes | key of a defined automation (`unknown_automation`) with a `manual` trigger (`start_target`). Not itself, and no chain of starts back to this automation (`start_cycle`). A package automation may start only package automations (`start_target`), because a package whose start target is missing does not load |
| `record` | depends | when the target's trigger has a `content_type`: a template giving one record ID; the default is the bound record when it is of that type (else `missing_property`); a typed reference of another type is `start_target`. When the target has no `content_type`, `record` is `start_target` |

- Output `steps.<id>.started` (boolean): false when the target's `when` is false for the record or the depth cap skipped it.
- Allowed anywhere except link steps (`automation_link_step`), loop bodies included. `retry` and `"on_error": "continue"` as for other steps.
- Deleting a site automation that another automation starts, or saving it so it can no longer be started, is refused with `pegwork_automation_in_use` (409, `docs/extension-api.md`).

### Role recipients

A `send_email` `to` part that is exactly `{role:<slug>}` (slug of lowercase letters, digits, `_` and `-`, at most 64) sends to the members of that WordPress role (Automation v4): in Bcc, staff roles only (the role has `edit_posts`), at most 25 members.

```json
{ "id": "notify", "type": "send_email", "to": "{role:editor}, {option:barn_settings.volunteer_manager}", "subject": "...", "text": "..." }
```

- Role tags are read from the template, never from rendered values, so record data cannot name a role. A malformed role part is `role_recipient`. A role counts as one of the 5 parts (`too_many_recipients`).
- `{role:` anywhere else, `reply_to`, `subject`, `text`, `html` and every other template included, is `role_recipient`.
- Roles live in the database, so the registry does not check that the role exists; an unknown role fails at run time.

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

`button`, `success_url`, `success_message` and `expired_url` are templates in a public context: the page is shown to whoever holds the link. A URL is a site path (`/thanks/`, under the home URL) or an absolute URL that `wp_validate_redirect()` allows; anything else shows the built-in page. Each emailed link acts once; the runtime is in `docs/automation.md#signed-links`.

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

Paths are dot-separated (`invalid_var`). Templates are `{{ path [| add:"<signed duration>"] [| date | number[:<0-4>]] [| default:"text"] }}`, filters in that order, for example `{{ record.last_verified | date | default:"never" }}`; anything else is `invalid_template`.

- `| add:"P2D"`, `| add:"-PT6H"`: on `today`, `now` and date fields (record, read, item and options fields); site-time calendar math, giving `Y-m-d` for dates and ISO 8601 for `now`. On any other path, or with a duration that is not ISO 8601, `invalid_template`.
- `| date`: on `today`, `now` and date fields: the site date format. Paths whose type is not known at load time (payloads, form values) are allowed; numbers, text outputs, `site.*`, `run.id` and `link.*` are `invalid_template`.
- `| number`, `| number:2`: on number fields and number outputs (`record_id`, `count`, `index`, `sum.*`): the site locale's number format; without decimals, integers print bare and others with 2 decimals. Other paths are `invalid_template`.
- Block `{{#each steps.<id>.records}} ... {{item.<key>}} ... {{else}} ... {{/each}}`: only in `send_email` `text` and `html`, not nestable, one `{{else}}` at most, closed (`invalid_template`). The path is the records of a `find_records` step in scope (`invalid_var`). Inside, `item.<key>` is checked against the records' type; `item.*` anywhere else is `invalid_var`. `{{else}}` renders for an empty list.

| Root | Paths |
|---|---|
| `record` | `record.<field or taxonomy key>`, `record.id`, `record.status`, `record.type`; templates also `record.permalink`, `record.edit_url`. Keys must exist on the bound type (`unknown_field`) |
| `trigger` | `record.created`, `record.deleted`: `id`, `type`, `status`, `user_id`, `origin`. `record.updated`: also `changed`, `old.<key>`. `record.status_changed`: `id`, `type`, `old_status`, `new_status`, `user_id`, `origin`. `form.*`: `form`, `entry_id`, `post_id`, `record_id`, `values.<item key>` (checked against the form when `form` is set). Webhook: `payload.*`. Manual: `user_id`, `source`. Schedules have no payload |
| `steps` | `steps.<id>.<output>` of an earlier step that runs on every path to this point ([Decisions](#decisions), [Loops](#loops)): `create_record` `record_id`; [reads](#reads) `found`, `record_id`, `record.<key>`, `count`, `sum.<field>`; `loop` `item.<key>` and `index` inside, `count` after; `start_automation` `started`; `failed` and `error` of a step with `on_error`. Types: `found`, `started` and `failed` boolean, `record_id`, `count`, `index` and `sum.*` number, `error` text, so conditions type-check (`condition_type_mismatch`) |
| `item` | `item.<key>` inside an `{{#each}}` block only |
| `site` | `site.name`, `site.url` |
| `today`, `now`, `run.id`, `link.<name>` | templates only |
| `option` | `option:<options page>.<field>`, templates only. Page and field must exist (`unknown_options_page`, `unknown_field`); a private field is `automation_private_field` in any template |

Private fields may be read in conditions, emails, `values` and `idempotency`, but not in public contexts: `call_webhook` (`url`, `fields`) and link page texts (`automation_private_field`). A form value `trigger.values.<item key>` whose item binds a private field of the form's target type counts as that private field (rejected in public contexts, `[private]` in the run log).

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
| `unknown_taxonomy`, `invalid_slug`, `invalid_default`, `invalid_pattern`, `validate_not_applicable`, `invalid_meta_key`, `unknown_field`, `reserved_field_key`, `post_field_not_allowed`, `private_storage`, `private_condition_field` | content types, taxonomies, fields |
| `listing_reserved_key`, `listing_duplicate_key`, `listing_one_target`, `listing_unknown_target`, `listing_private_field`, `listing_field_type` | listing |
| `unknown_content_type`, `self_relationship`, `storage_not_supported`, `meta_key_conflict` | relationships |
| `not_allowed_in_options_page` | options pages |
| `form_fields_or_steps`, `form_bind_target`, `form_bind_without_target`, `form_unknown_bind`, `form_display`, `form_missing_required`, `form_condition_not_bound`, `form_access_required`, `form_confirm_field`, `form_confirm_url_missing`, `form_premature_mail`, `form_recipient_not_email`, `form_accept_denied`, `invalid_recipient`, `unknown_options_page` | forms |
| `invalid_event`, `trigger_filter_mismatch`, `unknown_form`, `automation_no_record`, `automation_link_step`, `decision_too_deep`, `automation_unknown_link`, `automation_private_field`, `automation_value_type`, `invalid_duration`, `duration_out_of_range`, `invalid_condition`, `condition_operand`, `condition_type_mismatch`, `condition_too_complex`, `invalid_template`, `invalid_var` | automations |
| `unknown_relationship`, `relationship_end`, `read_source`, `filter_field`, `loop_wait`, `loop_nested`, `automation_too_large` | automations (Automation v3; the read codes are the ones `Records::find()` returns) |
| `wait_source`, `wait_field`, `trash_target`, `trash_type`, `trash_in_sweep`, `unknown_automation`, `start_target`, `start_cycle`, `role_recipient`, `too_many_recipients` | automations (Automation v4) |
