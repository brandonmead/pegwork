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

## Reading the registry and values

`Pegwork\Api\VERSION` is `0.2.0`.

- `pegwork_registry()`: read-only `Pegwork\Api\Registry` with `content_types()`, `content_type( $key )`, `fields( $type, $visibility = null )`, `taxonomies()`, `relationships()`, and (0.2.0) `meta_key( $type, $field ): ?string`, the post meta key of a meta-stored field (`wp.meta_key`, else `_{type}_{field}`; null for unknown fields and `wp.storage` fields).
- `pegwork_get_field( int|WP_Post $post, string $key, string $context = 'view' ): mixed` (0.2.0): one field as a typed value. Boolean fields return bool, number fields int, float or null when unset, everything else a string; registered defaults apply when unset. Private fields return null unless `$context` is `edit` and the current user can `edit_post`. Null for an unknown post or field. It applies no display rules (`show_when`, password, post status): display code such as the `pegwork/field` binding source adds those (`docs/display.md`).

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

The manifest maps `slug => [ label, description, requires[], path ]`, where `path` is the absolute module directory. It ships `listings` and `filters` (requires `listings`; a skeleton until later Phase 2 PRs); add entries with the `pegwork_module_manifest` filter. Bundled modules use their own namespace outside `Pegwork\` (for example `PegworkListings\`) and register their own autoloader in `module.php`.

Enabled slugs live in the `pegwork_modules` option. Manage them with WP-CLI:

```
wp pegwork module list
wp pegwork module enable <slug>
wp pegwork module disable <slug>
```

Behavior: a disabled module's files are never included. An enabled slug missing from the manifest is ignored. A module whose `requires` are not all booted is skipped. Each of these shows an admin notice. `enable` rejects unknown slugs and slugs whose requirements are not yet enabled.
