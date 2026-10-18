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
| 30 | Automations | module-defined | `automation` |
| 90 | Modules | `pegwork-modules` | core |

WordPress treats `position` as an index into the submenu built so far, not a sort key. Pegwork therefore adds Overview first (`admin_menu` priority 9, position 0) and Modules last (priority 999); screens added at the default priority sit between them in the order they register. Pass the position from the table anyway.

A screen may use a capability lower than `manage_options` (an options page for editors, for example). `add_submenu_page()` leaves out screens the user cannot open, so for such a user that screen is the menu's first item and the top-level Pegwork link opens it; Overview and Modules stay denied.

Overview (read-only) lists content types with their fields, taxonomies, relationships, shadowed DB entries and rejected packages. Its data comes from the same service as `wp pegwork schema list` and `wp pegwork schema status`.
