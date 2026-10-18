# Pegwork options pages

Site-wide settings (a featured project, a moderator email, review criteria) defined in a schema package and edited on a generated screen. Core, not a module. Definition format: `docs/registry-reference.md` (Options pages). Design: `docs/content-builder-design.md` section 3.

```json
{ "format": 1, "key": "sifg_settings", "title": "SI for Good settings", "menu_title": "Site settings",
  "capability": "manage_options",
  "fields": [
    { "key": "featured_project", "type": "post", "label": "Featured project", "post_type": "sifg_project" },
    { "key": "moderator_email", "type": "email", "label": "Moderator notification email", "required": true, "visibility": "private" },
    { "key": "review_criteria", "type": "rich_text", "label": "Review criteria" },
    { "key": "contact_email", "type": "email", "label": "Contact email" } ] }
```

## Storage

- One option per page, named by the page key (`sifg_settings`), value `{ field key => value }`. Autoloaded (WordPress `auto`), since every bound block reads it.
- Registered with `register_setting( 'pegwork_{key}', '{key}', ... )`: type `object`, default = the fields' `default`s, `show_in_rest` false.
- A page whose option name another plugin already registered as a setting is skipped, with an admin notice. Rename the key.
- Removing a page from the registry unregisters the setting but keeps the stored value. Uninstall keeps it too (it deletes `pegwork_*` options only; page keys may not start with `pegwork_`).
- Not exported by `wp pegwork schema export`: values are content, the definition is schema.

## Screen

Pegwork > `menu_title` (default `title`), slug `pegwork-options-{key}`, submenu positions 10 to 19 in registration order, between Overview and Modules.

- Standard Settings API markup: `settings_fields()`, `do_settings_sections()`, `form-table`, `submit_button()`; the form posts to `options.php` with the group nonce.
- Controls: `text`, `email`, `url`, `number`, `date` inputs with the matching `type`; `textarea` for `textarea` and `rich_text` (filtered with `wp_kses_post` on save); checkbox for `boolean` (a hidden `0` precedes it, so unchecking saves); `select` with a "None" option; `post` as a searchable picker (below).
- Labels with `for`. Required fields say "(required)" in the label and carry `required` and `aria-required`; private fields show a "Private" badge and a description, tied by `aria-describedby`. The form has `novalidate`, so the server checks every value and reports errors as text, not browser bubbles.
- After a save with errors: a summary notice ("1 field was not saved:") whose items link to the fields, an inline "Error: ..." under each failed field (in its `aria-describedby`, plus `aria-invalid`), and "The other fields were saved." when some did.

### Post picker

A `post` field holds one post ID of `post_type`. The server renders a `<select>` of up to 100 published posts by title (plus the stored post if it is not among them), which works without JavaScript. The `options-page` script replaces it with a `ComboboxControl` that searches `/wp/v2/{rest_base}?search=...&status=publish&context=view` (20 results, debounced 300 ms; an empty search lists by title) and posts the ID in a hidden input with the same name. The row label is re-pointed at the combobox input. "No items found" comes from the combobox; "Searching" and "Search failed. Try again." show under it. The clear button chooses none.

## Saving rules

options.php calls the setting's sanitize callback (`Pages::sanitize()`, via `Values::sanitize()`):

1. Fields missing from the submission keep their stored value.
2. Each submitted field is type-checked: email (`is_email`), url (http or https), number, real `Y-m-d` date, select option, published post of `post_type`; text fields go through `sanitize_text_field` / `sanitize_textarea_field`. Empty is always allowed here.
3. Fields that pass are checked against `required` and `validate` with `RecordValidator::check_values()`, the same rules and messages as records.
4. Valid fields are stored. Each invalid field keeps its stored value (or stays unset) and gets a settings error naming the field and quoting the rejected value, for example: `Contact email must be a valid email address. You entered "not-an-email". The previous value was kept.` Messages are escaped when added, since core prints settings errors unescaped.

Core runs the callback twice on a page's first save (`update_option()` then `add_option()`); errors are added once.

## Capability

`capability` (default `manage_options`) guards three places:

- the menu item (`add_submenu_page()` hides it from other users; for an editor it becomes the menu's first item and the top-level link, while Overview and Modules stay denied);
- the screen (`wp_die` 403 for other users);
- the save: `option_page_capability_pegwork_{key}` returns the page capability, which options.php checks instead of `manage_options`.

## No REST

`/wp/v2/settings` requires `manage_options` for every setting and writes the whole object, so a client that cannot see private fields would wipe them. Add a `pegwork/v1` route when a consumer needs remote access.

## Reading values

```php
pegwork_get_option( 'sifg_settings', 'moderator_email' ); // 'mod@example.org' (private values included)
pegwork_get_option( 'sifg_settings', 'featured_project' ); // 24, or null
pegwork_get_option( 'sifg_settings' );                    // [ 'featured_project' => 24, ... ]
```

`pegwork_get_option( string $page, ?string $field = null ): mixed`: the stored value cast by type, else the field default. Boolean fields bool; number fields int, float or null; post fields the post ID or null; others string (`''` when unset). With no `$field`, every field by key. Null for an unknown page or field. Server code only: never print a private value. Front-end output goes through the `pegwork/option` binding source (`docs/display.md`).

## CLI

```
wp pegwork options list [--format=table|csv|json|yaml]
wp pegwork options get <page> [<field>] [--format=table|csv|json|yaml]
wp pegwork options set <page> <field> <value>
```

`list` reads `Registry\Status::options_pages()` (the Overview reads the same rows). `get` includes private values (the CLI user is trusted). `set` applies the screen's checks and exits 1 with the message when the value is rejected; booleans take `1`/`0`/`true`/`false`, post fields a post ID, `''` clears.
