# Pegwork events

Public API since 0.2.0: `Pegwork\Api\Events` and the record events below. Design: `docs/phase-4-design.md` sections 2 and 3.

## Bus

```php
Pegwork\Api\Events::emit( string $name, array $payload ): void
```

- Fires `do_action( 'pegwork_event', $name, $payload )`, then `do_action( 'pegwork_event_' . str_replace( '.', '_', $name ), $payload )`.
- Names match `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$` (`record.updated`, `form.submitted`). Anything else triggers `_doing_it_wrong()` and is not emitted.
- In-process and synchronous. Listeners that do slow work (email, HTTP) must queue it. Events are never persisted.

```php
add_action( 'pegwork_event_record_status_changed', function ( array $e ) {
	if ( 'sifg_project' === $e['type'] && 'publish' === $e['new_status'] ) {
		// Queue work; do not send mail or call HTTP here.
	}
} );

add_action( 'pegwork_event', function ( string $name, array $payload ) { /* every event */ }, 10, 2 );
```

## Record events

Emitted for registry content types only. Other post types, revisions, autosaves and auto-drafts emit nothing.

| Event | Hook | Payload |
|---|---|---|
| `record.created` | `pegwork_event_record_created` | `id, type, status, user_id, origin` |
| `record.updated` | `pegwork_event_record_updated` | `id, type, status, changed, old, user_id, origin` |
| `record.status_changed` | `pegwork_event_record_status_changed` | `id, type, old_status, new_status, user_id, origin` |
| `record.deleted` | `pegwork_event_record_deleted` | `id, type, status, user_id, origin` |

- `id` (int), `type` (content type key), `status` (post status after the save; for `deleted`, the status when deleted, usually `trash`), `user_id` (current user, 0 for none).
- `changed`: registry field keys, then taxonomy keys, whose stored value differs, then `status` when the post status moved. A field named `status` would be indistinguishable here, so avoid that field key.
- `old`: previous values of the `changed` keys. Fields are typed (bool, int or float or null for numbers, string otherwise); taxonomies are sorted term slug lists; `status` is the old post status. New values: read the record (`Records::get()`).
- `origin`: `{ source, automation?, run_id?, depth }`. `source` is `rest` (inside a REST request), `php` (any other `wp_insert_post`/`wp_update_post`/`wp_delete_post` caller, WP-CLI, cron) or `records_api`. `depth` is 0 for people; a save made while a record event is being dispatched (by its listeners) gets that event's depth plus 1. A Records API caller may pass a higher `depth`, plus `automation` and `run_id`, in its `$origin`.

Ordering per logical save: one `record.created` or `record.updated`, then `record.status_changed` when the status moved. Creation reports `old_status` `new`. A save that changes nothing emits nothing.

Example, an editor publishes a pending Project through REST:

```php
// record.updated
array(
	'id'      => 42,
	'type'    => 'sifg_project',
	'status'  => 'publish',
	'changed' => array( 'status' ),
	'old'     => array( 'status' => 'pending' ),
	'user_id' => 3,
	'origin'  => array( 'source' => 'rest', 'depth' => 0 ),
)
// record.status_changed
array(
	'id'         => 42,
	'type'       => 'sifg_project',
	'old_status' => 'pending',
	'new_status' => 'publish',
	'user_id'    => 3,
	'origin'     => array( 'source' => 'rest', 'depth' => 0 ),
)
```

Example, a listener stamps a field with `Records::update( 42, array( 'last_verified' => '2026-10-18' ), array( 'automation' => 'sifg_project_approved', 'run_id' => 7 ) )`:

```php
// record.updated
array(
	'id'      => 42,
	'type'    => 'sifg_project',
	'status'  => 'publish',
	'changed' => array( 'last_verified' ),
	'old'     => array( 'last_verified' => '' ),
	'user_id' => 3,
	'origin'  => array( 'source' => 'records_api', 'automation' => 'sifg_project_approved', 'run_id' => 7, 'depth' => 1 ),
)
```

### When events fire

| Save | Snapshot | Emit |
|---|---|---|
| REST create or update (block editor) | `rest_pre_insert_{type}`, after validation | `rest_after_insert_{type}` (post, meta and terms written) |
| PHP `wp_insert_post`/`wp_update_post`, `wp_trash_post`, `wp_publish_post` | `pre_post_update` (or the status before, for `wp_publish_post`) | `wp_after_insert_post` |
| `Records::create`/`update` | before writing | once, after post, meta and terms |
| Permanent delete | `before_delete_post` | `deleted_post` |

- Block editor "new post" inserts an auto-draft: nothing. Its first real save emits `record.created`.
- Trash emits `record.updated` (`changed` = `status`) and `record.status_changed` to `trash`.
- Autosaves (`/autosaves` routes, autosave revisions) emit nothing, including a draft autosave that updates the post itself.
- A save rejected by validation emits nothing.
- Limitation: a bare `update_post_meta()` or `wp_set_object_terms()` outside a save emits nothing. Use `Records::update()`.

## Form events

Emitted by the `forms` module (`docs/forms.md`).

| Event | Hook | Payload |
|---|---|---|
| `form.submitted` | `pegwork_event_form_submitted` | `form, entry_id, record_id, values` |

- Fires once per stored, non-spam entry, after its `submitted` notifications, before the redirect. Spam, invalid, rate-limited, duplicate and expired submissions emit nothing.
- `form` (form key), `entry_id` (int, row in `{prefix}pegwork_entries`), `record_id` (int or null: the record the entry created or updated; always null until record mapping lands in Phase 3 PR 3), `values` (sanitized values by item key, only items whose `show_when` held: booleans bool, numbers int or float, other types string; file items will appear as `{name, size, mime}`, never contents or paths).
- `form.confirmed` (same payload, after email confirmation creates the record) arrives with Phase 3 PR 3.

```php
array(
	'form'      => 'request_help',
	'entry_id'  => 12,
	'record_id' => null,
	'values'    => array( 'name' => 'Ada', 'email' => 'ada@example.org', 'organization' => '', 'need' => 'Grant writing.' ),
)
```

## Private fields

Payloads may hold private field values (`old.contact_email`): the bus is in-process PHP, and the boundary is output. Never echo, log, return over REST, or send a payload as is; outbound contexts (webhooks, public pages) must select public fields.

## Records API

`Pegwork\Api\Records`, values by registry key:

```php
Records::get( int $id ): ?array
// { id, type, status, values{ field keys (private included), taxonomy keys => sorted slugs }, permalink }, or null.

Records::create( string $type, array $values, string $status = 'draft', array $origin = [] ): int|WP_Error
Records::update( int $id, array $values, array $origin = [] ): true|WP_Error
```

- Fields map to `post_title`/`post_content` (`wp.storage`) or meta; taxonomy values are slugs (a string or a list). Unknown keys, unknown term slugs (never created) and values the field sanitizer empties (bad email, date, select option) are rejected.
- The merged record is validated like a REST save: completeness rules only when the resulting status is publish, pending or future; single-select term counts always. Errors: `pegwork_invalid_record`, status 400, `data.fields` = key => message. Also `pegwork_unknown_type`, `pegwork_invalid_status`, `pegwork_record_not_found`.
- No capability check: callers (automation runs, link handlers) authorize by their own rules.
- `update()` does not change the post status.
