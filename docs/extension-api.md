# Pegwork extension API

For add-on plugins and for Pegwork's own optional modules.

## Stability

Everything in the `Pegwork\Api` namespace (`src/Api/`) is public and follows semantic versioning: no breaking change without a major release. Everything else under `Pegwork\` is internal and may change in any release. Do not call it from an add-on.

The API version is `Pegwork\Api\VERSION` (0.2.0 adds `Events` and `Records`).

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
