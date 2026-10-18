# Pegwork extension API

For add-on plugins and for Pegwork's own optional modules.

## Stability

Everything in the `Pegwork\Api` namespace (`src/Api/`) is public and follows semantic versioning: no breaking change without a major release. Everything else under `Pegwork\` is internal and may change in any release. Do not call it from an add-on.

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

## Reading records and relationships

Global functions (`includes/functions.php`), available after `init` priority 5:

| Function | Returns |
|---|---|
| `pegwork_registry()` | Read-only `Pegwork\Api\Registry`: `content_types()`, `content_type( $key )`, `fields( $type, $visibility )`, `taxonomies()`, `relationships()`, `relationships_for( $type )` (either end; API 0.2.0). |
| `pegwork_validate_record( $type, $values, $post_id = 0 )` | `true` or a 400 `WP_Error` with per-field messages in `data.fields`. Relationship links go under the relationship key. |
| `pegwork_related_ids( $post, $key )` | IDs at the other end of a relationship, from either end, all statuses. |
| `pegwork_related_query_args( $post, $key )` | `WP_Query` args for the records at the other end; add status, order and paging. Matches nothing for an unknown key or unrelated post. |

Details, REST and CLI: `docs/relationships.md`.

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

The manifest maps `slug => [ label, description, requires[], path ]`, where `path` is the absolute module directory. It ships empty in Phase 1; add entries with the `pegwork_module_manifest` filter.

Enabled slugs live in the `pegwork_modules` option. Manage them with WP-CLI:

```
wp pegwork module list
wp pegwork module enable <slug>
wp pegwork module disable <slug>
```

Behavior: a disabled module's files are never included. An enabled slug missing from the manifest is ignored. A module whose `requires` are not all booted is skipped. Each of these shows an admin notice. `enable` rejects unknown slugs and slugs whose requirements are not yet enabled.
