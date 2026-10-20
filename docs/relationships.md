# Relationships

Links between records of two content types, defined in the registry and stored as post meta. Design: `docs/content-builder-design.md` section 2.

## Definition

`relationships/<key>.json` in a schema package:

```json
{ "format": 1, "key": "story_projects", "from": "sifg_story", "to": "sifg_project",
  "cardinality": "many_to_many", "label": "Projects", "required": true }
```

| Property | Required | Meaning |
|---|---|---|
| `key` | yes | `^[a-z][a-z0-9_]*$`. Meta key `_rel_{key}`, REST param `rel_{key}`. |
| `from`, `to` | yes | Content type keys; must differ. Links are stored on the "from" record. |
| `cardinality` | yes | `many_to_many`: no limit. `one_to_many`: each "to" record is linked from at most one "from" record. A "from" record may always hold many links. |
| `storage` | no | `meta` (default). `table` is reserved for Phase 5 and rejected (`storage_not_supported`). |
| `label` | no | Picker heading on the "from" editor. Default: the "to" type's plural label, resolved at runtime (not stored). |
| `required` | no | Default `false`. When `true`, publish, pending and future saves need at least one link. |

Validation errors (PHP validator; the JSON Schema covers the per-file rules):

| Code | Rule |
|---|---|
| `self_relationship` | `from` equals `to` (self links need a direction model, Phase 5). PHP only. |
| `meta_key_conflict` | `_rel_{key}` is already a field meta key on the "from" type. PHP only. |
| `storage_not_supported` | `storage: table`. |
| `unknown_content_type` | `from` or `to` is not defined in any accepted source. PHP only. |

## Storage

- One `_rel_{key}` meta row per linked ID on the "from" record (`single => false`, `integer`, `absint`, `edit_post` auth). Values are an unordered set. Not revisioned.
- Registered on `init` priority 5 with the other meta. A "from" type gets `custom-fields` support automatically so core REST exposes the meta.
- Deleting a "to" record removes its ID from every `_rel_{key}` row (`deleted_post`). Trashing keeps links, so restore keeps them.
- Removing a relationship from the registry unregisters the meta but never deletes rows.
- Reverse lookups are a `meta_query` on the value, which is unindexed. Fine at SI for Good scale; `table` storage (Phase 5) is the answer at larger scale.

## Rules on save

`RecordValidator` on `rest_pre_insert_{type}`, the same 400 `pegwork_invalid_record` shape as fields. The key in `data.fields` is the relationship key.

| Rule | When | Message |
|---|---|---|
| Each ID exists (trashed counts) and is a "to" record | every save | `Record 99 is not a Project.` |
| `one_to_many`: no ID is linked from another "from" record | every save | `Venue "Town hall" is already linked from Event "Spring fair".` (titles only when the user can read the record, else `#ID`) |
| `required`: at least one link | publish, pending, future | `At least one Project is required.` |

`pegwork_validate_record( $type, $values, $post_id )` applies the same rules; pass linked IDs under the relationship key and the record's own ID so its existing links do not count against `one_to_many`. With a post ID and no value for a relationship key, the stored links are checked.

`Pegwork\Api\Records` does not write links yet. `Records::update()` validates against the stored links; `Records::create()` of a record with a `required` relationship fails unless the status is a draft (link it afterwards through REST or WP-CLI).

## REST

- `_rel_{key}` appears in `meta` on the "from" type, read and written like any meta: `POST /wp/v2/stories` with `meta: { "_rel_story_projects": [ 24, 25 ] }`. An empty list clears.
- Collection param `rel_{key}` (integer) on the "from" collection lists the records linked to one "to" record: `GET /wp/v2/stories?rel_story_projects=24`. Status visibility stays core's (anonymous readers see published records only).
- Links are IDs only. No field of the linked record is exposed; there are no private relationships.

## Editor

The "from" type's Details panel has a picker per relationship: a searchable "Add {singular}" combobox and the list of linked records with Remove buttons. Links save in the same REST request as the post; a rejected save shows the rule's message under the picker. Config entry, states, focus and announcements: `docs/editor-panel.md`. The "to" type has no picker.

## PHP

```php
pegwork_related_ids( $post, 'story_projects' );        // int[]; from a Story: its Projects; from a Project: its Stories. All statuses.
pegwork_related_query_args( $post, 'story_projects' ); // WP_Query args; callers add status, order and paging.
pegwork_registry()->relationships_for( 'sifg_project' ); // relationships with the type at either end.
```

`pegwork_related_query_args()` returns `[ post_type => from, meta_query => [ [ key => _rel_{key}, value => id, type => NUMERIC ] ] ]` from a "to" post and `[ post_type => to, post__in => ids ]` from a "from" post (`post__in => [ 0 ]` when nothing is linked). For an unknown key or a post of neither type it returns `[ post__in => [ 0 ] ]`, which matches nothing.

## Listings

With the listings module, no PHP is needed to show related records (`docs/display.md` "Related records"):

- Query Loop preset `"pegwork":{"where":{"related":"story_projects"}}`: a Stories loop on a Project lists its published Stories, a Projects loop on a Story its published Projects. It calls `pegwork_related_query_args()` for the record in block context and keeps the loop's order and paging. No record, no links, a wrong type or an unknown key list nothing.
- "Show if" condition `{ "relationship": "story_projects", "op": "not_empty" }` hides a heading together with the loop when nothing published is related.
- Generated patterns `pegwork/sifg_project-related-story_projects` ("Project related Stories") and `pegwork/sifg_story-related-story_projects` ("Story related Projects"), one per end of each relationship.

## WP-CLI

```
wp pegwork relationship get <key> <post> [--format=table|csv|json|yaml|ids|count]
wp pegwork relationship set <key> <post> [<id>...]
```

`get` works from either end. `set` replaces the links of a "from" record with the same rules as a REST save (the `required` rule applies when the record is published, pending or future); no IDs clears. Errors exit 1. Runs as the WP-CLI user.
