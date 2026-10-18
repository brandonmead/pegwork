# Pegwork extension API

For add-on plugins and for Pegwork's own optional modules.

## Stability

Everything in the `Pegwork\Api` namespace (`src/Api/`) is public and follows semantic versioning: no breaking change without a major release. Everything else under `Pegwork\` is internal and may change in any release. Do not call it from an add-on.

The API version is `Pegwork\Api\VERSION` (0.2.0 adds `ADMIN_MENU`, `Events`, `Records`, `Registry::meta_key()`, `Registry::relationships_for()`, the registry section accessors below, `pegwork_get_field()`, `pegwork_get_option()` and the form functions `pegwork_sanitize_field()`, `pegwork_validate_fields()`, `pegwork_save_form()`, `pegwork_delete_form()`).

CI enforces the same rule for bundled modules: PHP under `modules/` may not reference any `Pegwork\` namespace other than `Pegwork\Api` (`bin/check-module-boundary.sh`).

## Declaring the dependency

Add `Requires Plugins: pegwork` to the add-on's plugin header (WordPress 6.5+ blocks activation until Pegwork is active):

```php
/**
 * Plugin Name:   My Pegwork Add-on
 * Requires Plugins: pegwork
 */
```

## Registering a schema package

Hook `pegwork_register` and add a directory of schema files (provided by the schema registry, PR 2):

```php
add_action( 'pegwork_register', fn( $b ) => $b->add_package( __DIR__ . '/schema' ) );
```

Every object kind and property a package can declare (content types, fields, listings, taxonomies, relationships, options pages, forms, automations): `docs/registry-reference.md`.

## Reading records and relationships

Global functions (`includes/functions.php`), available after `init` priority 5:

| Function | Returns |
|---|---|
| `pegwork_registry()` | Read-only `Pegwork\Api\Registry`: `content_types()`, `content_type( $key )`, `fields( $type, $visibility )`, `meta_key( $type, $field )` (API 0.2.0), `taxonomies()`, `relationships()`, `relationships_for( $type )` (either end; API 0.2.0), plus the section accessors below. |
| `pegwork_get_field( $post, $key, $context = 'view' )` | One field as a typed value (API 0.2.0). See below. |
| `pegwork_validate_record( $type, $values, $post_id = 0 )` | `true` or a 400 `WP_Error` with per-field messages in `data.fields`. Relationship links go under the relationship key. |
| `pegwork_related_ids( $post, $key )` | IDs at the other end of a relationship, from either end, all statuses. |
| `pegwork_related_query_args( $post, $key )` | `WP_Query` args for the records at the other end; add status, order and paging. Matches nothing for an unknown key or unrelated post. |
| `pegwork_get_option( $page, $field = null )` | An options page value, or all of a page's values, typed (API 0.2.0). See below. |

Details, REST and CLI: `docs/relationships.md`.

`pegwork_get_option( string $page, ?string $field = null ): mixed`: the stored value cast by field type, else the field default. Boolean fields bool; number fields int, float or null; `post` fields the post ID or null; other fields string (`''` when unset). Null `$field` returns every field's value by key. Null for an unknown page or field. Private fields are included (for server code such as notification recipients): never output them. Show public values with the `pegwork/option` binding source (`docs/display.md`). Guard calls from optional code with `function_exists( 'pegwork_get_option' )`. Screen, saving rules and CLI: `docs/options-pages.md`.

Form functions (API 0.2.0, `includes/functions.php`). None checks capabilities: the caller authorizes.

| Function | Returns |
|---|---|
| `pegwork_sanitize_field( array $field, mixed $value )` | The value sanitized for the field's `type` with the Phase 1 sanitizers: bool for booleans (missing is false); int or float for numbers; a string otherwise. `''` when empty or invalid (bad email, URL other than http/https, impossible date, undefined option, non-numeric number, any array). |
| `pegwork_validate_fields( array $fields, array $values, bool $complete = true )` | `true`, or `WP_Error` `pegwork_invalid_fields` (400, messages by field key in `data.fields`). The record validator's field rules (`required`, `required_when`, `validate`), plus: a required boolean must be true (consent). `$complete = false` accepts anything. |
| `pegwork_save_form( array $definition )` | `true`, or `WP_Error`: `pegwork_invalid_form` (400, messages by JSON pointer in `data.fields`, such as `/notifications/0/to`) or `pegwork_form_file_defined` (409). Validates like a file form, resolves references against the effective registry, upserts the canonical form into the `pegwork_schema` option (by key, in place) and rebuilds the registry. |
| `pegwork_delete_form( string $key )` | `true`, or `WP_Error` `pegwork_form_file_defined` (409) or `pegwork_form_not_found` (404). Removes the DB definition; entries stay. |

The forms module runtime (block, pipeline, entries table, notifications, hooks): `docs/forms.md`.

Registry section accessors (API 0.2.0) return definitions only, in canonical form (defaults filled); the modules that render or run them read them here.

| Method | Returns |
|---|---|
| `listing( string $type ): ?array` | The type's `listing` (`search`, `filters[]` with `key`, `taxonomy` or `field`, `multiple`, optional `label`), or null when the type is unknown or declares none. |
| `forms(): array`, `form( string $key ): ?array` | Forms by key, in load order; one form or null. |
| `form_source( string $key ): ?string` | `file:<package id>` for a file package, `db` for the `pegwork_schema` option, null when undefined. File forms are read-only in a builder. |
| `automations(): array`, `automation( string $key ): ?array` | Automations by key, in load order; one automation or null. |
| `automation_source( string $key ): ?string` | Like `form_source()`: `file:<package id>`, `db`, or null when undefined (shown on Pegwork > Automations). |
| `options_pages(): array`, `options_page( string $key ): ?array` | Options pages by key, in load order; one page or null. The key is also the option name. |

`Registry::meta_key( $type, $field ): ?string` is the post meta key of a meta-stored field (`wp.meta_key`, else `_{type}_{field}`); null for unknown fields and `wp.storage` fields.

`pegwork_get_field( int|WP_Post $post, string $key, string $context = 'view' ): mixed`: boolean fields return bool, number fields int, float or null when unset, everything else a string; registered defaults apply when unset. Private fields return null unless `$context` is `edit` and the current user can `edit_post`. Null for an unknown post or field. It applies no display rules (`show_when`, password, post status): display code such as the `pegwork/field` binding source adds those (`docs/display.md`).

## Modules

A module is a directory containing `module.php`, listed in the module manifest. `module.php` returns an object implementing `Pegwork\Api\Module`:

```php
interface Module {
	public function id(): string;       // the manifest slug
	public function requires(): array;  // slugs of modules that must be enabled first
	public function boot(): void;       // called once, on pegwork_loaded, when enabled
}
```

Timing: on `plugins_loaded` Pegwork resolves the enabled modules, checks requirements and includes each `module.php` (so top-level code in `module.php` runs then and must not need the registry). `boot()` runs later, on `pegwork_loaded` (`init` priority 5, after the registry is built), in dependency order, so `pegwork_registry()` is available inside it.

The manifest maps `slug => [ label, description, requires[], path ]`, where `path` is the absolute module directory. It ships `listings`, `filters` (requires `listings`; a skeleton until later Phase 2 PRs), `automation` and `forms`; add entries with the `pegwork_module_manifest` filter. Bundled modules use their own namespace outside `Pegwork\` (for example `PegworkListings\`) and register their own autoloader in `module.php`. A module that keeps its own tables or cron events ships `uninstall.php` in its directory; Pegwork's `uninstall.php` includes every `modules/*/uninstall.php` before deleting `pegwork_*` options.

## Automation hooks

The `automation` module (`docs/automation.md`) runs registry automations and adds Pegwork > Automations (list, Enable/Disable, Run now, run log, Retry; `manage_options`). Sites and add-ons can tune it with filters; its PHP classes are internal.

The `forms` module (`docs/forms.md`) renders registry forms and handles their submissions. Hooks: `pegwork_forms_capability`, `pegwork_forms_spam_check`, `pegwork_forms_rate_limits`, `pegwork_forms_client_ip`, and the `form.submitted` event; its PHP classes are internal. It keeps its entries table on uninstall (entries are content), so it ships no `uninstall.php`.

| Hook | Kind | Default | Use |
|---|---|---|---|
| `pegwork_automation_max_depth` | filter | 3 | Loop cap: triggers at this origin depth or deeper are logged as skipped |
| `pegwork_automation_time_budget` | filter | 20 | Seconds per executor wake-up before the run is re-queued |
| `pegwork_automation_retention_days` | filter | `{ done: 90, failed: 180 }` | Run log retention; also option `pegwork_automation_retention_days` |
| `pegwork_automation_spawn_cron` | filter | true | Spawn WP-Cron at shutdown after queueing a run; return false with a system cron |
| `pegwork_automation_run` | action (WP-Cron) | | Fired with a run ID to execute it |
| `pegwork_automation_maintenance` | action (WP-Cron) | | Daily maintenance job |

Records written by automation runs carry `origin.automation`, `origin.run_id` and `origin.depth` (run depth plus 1) in their record events.

Enabled slugs live in the `pegwork_modules` option. Manage them on the Pegwork > Modules screen or with WP-CLI:

```
wp pegwork module list
wp pegwork module enable <slug>
wp pegwork module disable <slug>
```

Behavior: a disabled module's files are never included. An enabled slug missing from the manifest is ignored. A module whose `requires` are not all booted is skipped. Each of these shows an admin notice. `enable` rejects unknown slugs and slugs whose requirements are not yet enabled (`pegwork_module_requires`). `disable` rejects a slug that an enabled module requires (`pegwork_module_required_by`). The screen and the CLI call the same `Modules\Loader` methods, so the rules are identical; the screen shows a blocked action as a disabled button with its reason ("Requires Sample", "Required by Needs sample").

## Admin menu

Pegwork adds a top-level menu, slug `Pegwork\Api\ADMIN_MENU` (`'pegwork'`), capability `manage_options`. Add a screen to it on `admin_menu` at the default priority:

```php
add_action(
	'admin_menu',
	fn() => add_submenu_page( Pegwork\Api\ADMIN_MENU, 'Forms', 'Forms', 'manage_options', 'my-forms', 'my_render_forms', 20 )
);
```

| Position | Submenu | Slug | Owner |
|---|---|---|---|
| 0 | Overview | `pegwork` | core |
| 10 to 19 | each options page | `pegwork-options-{key}` | core |
| 20, 21 | Forms, Entries | module-defined | `forms` |
| 30 | Automations | `pegwork-automations` | `automation` |
| 90 | Modules | `pegwork-modules` | core |

WordPress treats `position` as an index into the submenu built so far, not a sort key. Pegwork therefore adds Overview first (`admin_menu` priority 9, position 0) and Modules last (priority 999); screens added at the default priority sit between them in the order they register. Pass the position from the table anyway.

A screen may use a capability lower than `manage_options` (an options page for editors, for example). `add_submenu_page()` leaves out screens the user cannot open, so for such a user that screen is the menu's first item and the top-level Pegwork link opens it; Overview and Modules stay denied.

Options pages are added at the default `admin_menu` priority, one per registry options page in load order (positions 10 to 19), so a module screen registered later at the same priority falls after them.

Overview (read-only) lists content types with their fields, taxonomies, relationships, options pages, shadowed DB entries and rejected packages. Its data comes from the same service as `wp pegwork schema list` and `wp pegwork schema status`.

## Events

`Pegwork\Api\Events::emit( string $name, array $payload ): void` fires `pegwork_event` (name, payload) and `pegwork_event_{name with dots as underscores}` (payload). Core emits `record.created`, `record.updated`, `record.status_changed` and `record.deleted` for registry content types. Payloads, origins and timing: `docs/events.md`.

```php
add_action( 'pegwork_event_record_updated', function ( array $e ) {
	if ( in_array( 'website', $e['changed'], true ) ) { /* ... */ }
} );
```

Payloads may carry private field values: never output them as is.

## Records

`Pegwork\Api\Records` reads and writes records by registry key, through record validation and the event bus. Modules write records only through it.

```php
$record = Pegwork\Api\Records::get( 42 );            // { id, type, status, values, permalink } or null
$id     = Pegwork\Api\Records::create( 'sifg_project', array( 'name' => 'Seed library' ) ); // int|WP_Error, draft
$ok     = Pegwork\Api\Records::update( 42, array( 'last_verified' => '2026-10-18', 'sifg_project_status' => 'active' ), array( 'automation' => 'my_flow', 'run_id' => 7 ) );
```

No capability check: the caller authorizes. Details: `docs/events.md`.
