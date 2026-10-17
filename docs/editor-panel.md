# Editor panel

One `PluginDocumentSettingPanel` ("Details") in the block editor for every post type in the config. It edits meta through core-data (`useEntityProp( 'postType', postType, 'meta' )`), so values save in the same REST request as the post. Server-side validation (PR 4, `RecordValidator`) is authoritative; the panel only gives hints.

Source: `assets/src/editor-panel/`. PHP: `src/Admin/EditorPanel.php`.

## Config contract

PHP localizes `window.pegworkEditor` (inline script before `pegwork-editor-panel`):

```js
window.pegworkEditor = {
	types: {
		<post_type>: {
			fields: [ Field, ... ],         // definition order
			taxonomies: [ Taxonomy, ... ],  // order of the content type's `taxonomies`
		},
	},
};
```

### Field

| Key | Type | Notes |
|---|---|---|
| `key` | string | Field key. |
| `type` | string | `text`, `textarea`, `rich_text`, `url`, `email`, `number`, `boolean`, `date`, `select`. Unknown types render as text. |
| `label` | string | Defaults to `key`. |
| `required` | bool | Always required (hint only). |
| `visibility` | `public` or `private` | `private` shows a "Private" badge. |
| `meta_key` | string or null | `wp.meta_key`, else `_{post_type}_{key}`. Null unless `storage` is `meta`. |
| `storage` | `meta`, `post_title`, `post_content` | From `wp.storage`; default `meta`. |
| `show_when` | `{field, equals}` or null | Field shows only while the referenced field's value `===` `equals`. |
| `required_when` | `{field, equals}` or null | Required (hint) while the condition holds. |
| `validate` | object | Registry rules (`min_words`, `max_words`, `max_length`, ...). PHP may send `[]` for none; JS reads it as `{}`. |
| `options` | `[{value, label}]` | Select options; `[]` otherwise. |
| `default` | any or null | Shown while the meta value is missing or null. |

### Taxonomy

| Key | Type | Notes |
|---|---|---|
| `key` | string | Taxonomy key. |
| `rest_base` | string | Post attribute holding term IDs. Taxonomy `wp.rest_base`, else `key`. |
| `select` | `single` or `multi` | Default `multi`. |
| `required` | bool | Hint only. |
| `label` | string | Singular label for `single`, plural for `multi`; falls back to `key`. |

The JS parser (`config.js`) drops malformed entries and fills these defaults, so a bad config never throws. Meta fields without a `meta_key` are dropped.

## Behavior

| Field type | Control |
|---|---|
| text | `TextControl` |
| textarea, rich_text | `TextareaControl` |
| url, email, number, date | `TextControl` with `type` url, email, number, date |
| boolean | `ToggleControl` |
| select | `SelectControl` with a "Choose an option" empty entry |

- `post_title` and `post_content` fields are not rendered; core UI edits them. A `post_content` field with `validate.min_words` or `max_words` gets a live counter ("142 of 50 to 300 words"), counted from `core/editor` content with block comments and tags stripped.
- Empty number inputs store `null`, which deletes the meta value.
- Taxonomies: `single` renders `RadioControl` (plus "None" when not required); `multi` renders checkboxes. Terms come from `getEntityRecords( 'taxonomy', key, { per_page: -1, orderby: 'id' } )`, so seeded order is kept. Core panels are removed with `removeEditorPanel( 'taxonomy-panel-<key>' )`.

## PHP side

- `EditorPanel::register()` (called from `Plugin::boot()`) hooks `enqueue_block_editor_assets` and `allowed_block_types_all`.
- Both do nothing when `pegwork_registry()` does not exist.
- The script enqueues only on post editor screens whose post type is in the config, and only when `assets/build/editor-panel.asset.php` exists.
- `EditorPanel::build_config( $content_types, $taxonomies )` is pure (registry arrays in, config out).
- Filter `pegwork_editor_panel_config` ( array `$config` ) adjusts the config before it is localized.
- `allowed_block_types_all`: a content type with `wp.allowed_blocks` limits the editor to that list, intersected with any list already set; `false` stays `false`.

Requirements on other PRs, for the panel to save end to end:
- PR 3: taxonomies registered with `show_in_rest`; post type `supports` includes `custom-fields`, which core needs to expose `meta` in REST.
- PR 4: meta registered with `show_in_rest` and an `auth_callback`. Keys start with `_`, so they are protected and the callback is required for REST writes.

## Build and test

```sh
npm ci            # npm 11 or later (see architecture.md)
npm run build     # assets/build/ (gitignored; CI builds it)
npm run start     # watch
npm run lint:js
npm run test:unit # Vitest, via wp-scripts test-unit-js
```

Fixtures `tests/fixtures/editor-panel/registry.json` (the `sifg_project` example) and `config.json` (its expected config) are shared: PHPUnit checks the PHP builder produces `config.json`, and the JS tests parse it.
