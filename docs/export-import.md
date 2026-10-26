# Schema export and import

Moves the effective schema registry between sites, or from a file package into the database, as a package directory. CLI only; there is no web upload. Code: `src/Registry/Exporter.php`, `src/Registry/Importer.php`, `src/Cli/SchemaCommand.php`.

## Export

```
wp pegwork schema export [--dir=<path>] [--source=all|db|files]
```

- `--dir`: export directory, created when missing. Default `pegwork-export` in the current directory.
- `--source`: which effective objects to write. `all` (default) is the registry as registered; `db` only objects from the `pegwork_schema` option; `files` only objects from file packages. Shadowed DB entries are not effective, so they are never exported.

Output is one package directory, loadable as a file package or importable:

```
<dir>/manifest.json
<dir>/content-types/<key>.json
<dir>/taxonomies/<key>.json
<dir>/relationships/<key>.json
<dir>/forms/<key>.json
<dir>/automations/<key>.json
<dir>/options-pages/<key>.json
```

The six object directories are `Registry\Loader::DIRS`; a directory with no objects is not written.

Format:
- Each object in canonical form: defaults filled, empty optional objects dropped, object keys sorted, list order kept.
- `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, 4-space indent, trailing newline.
- No timestamps, no paths. The same registry exports the same bytes whatever its source.
- Manifest: when exactly one source contributes objects, its manifest (a file package's own, or for the DB source the manifest stored by the last import). Otherwise a generic one: `{ "format": 1, "id": "site", "label": "Site schema", "version": "0.0.0" }`.
- Re-exporting into the same directory removes object files that no longer exist (only `*.json` in the six object directories; other files are left alone). Empty object directories are removed.

## Import

```
wp pegwork schema import <dir> [--prune] [--dry-run]
```

- Validates `<dir>` with the same validator as file packages (manifest, every object, duplicate keys, no callables). References may point at the package, at file packages, or at DB entries that stay. Any error rejects the whole import and nothing is written.
- Upserts each object into `pegwork_schema` by kind and key, in canonical form. Changed entries are replaced in place, new ones appended. The package manifest is stored as `pegwork_schema.manifest`, so a later export of the DB source reproduces it.
- Re-runs registration in the same request and creates missing seed terms by slug. Later requests pick up the new option on `init`; the registry hash changes, so rewrite rules are flushed once.
- Never deletes terms, posts or meta, and never changes file packages.
- `--prune`: also removes DB entries the package does not define (DB entries only).
- Refuses an import that would stop a DB entry it keeps from loading, such as a site form bound to a field the package removes from its content type: one `import_breaks_entry` error per entry, followed by the load errors that explain it, exit status 1, nothing written (a dry run reports the same). Change the package, or use `--prune` to remove such entries on purpose.
- `--dry-run`: lists the changes and the seed terms that would be created; writes nothing.
- A key that a file package also defines is stored but shadowed (file wins); the command warns for each one.

Output: one line per change (`Added|Updated|Removed <kind> <key>`, `Created term <taxonomy>/<slug>`), then a summary such as `Success: 5 change(s): 5 added, 0 updated, 0 removed, 0 term(s) created.` Kind `manifest` is the stored manifest.

## Idempotency

Import compares canonical forms, so importing the same directory again reports `Success: 0 changes.` and writes nothing. Export of an imported registry is byte-identical to the export it came from. Both are checked in CI by `tests/Integration/ExportImportGateTest.php` (the Phase 1 gate).

## `pegwork_schema` option shape

```
{ "content_types": [ ... ], "taxonomies": [ ... ], "relationships": [ ... ], "forms": [ ... ],
  "automations": [ ... ], "options_pages": [ ... ], "manifest": { ... } }
```

Each list holds objects in the file format (`Loader::SECTIONS`); a missing list counts as empty. Forms saved in the form builder and automations saved in the automation editor are entries of these lists too. `manifest` is written by import and read only by export.

## LocalWP runbook (mirrors the gate test)

`wp` below is Local's PHP 8.2 WP-CLI wrapper from `architecture.md`. The site's schema comes from the `sifg-config` file package, so skip that plugin to put the DB source in charge.

1. Baseline: `wp pegwork schema list` (source `sifg`), note Project and page IDs.
2. Export A: `wp pegwork schema export --dir=/tmp/sifg-a`.
3. Dry run with the file package off: `wp pegwork schema import /tmp/sifg-a --dry-run --skip-plugins=sifg-config` (5 changes, option still absent).
4. Import: `wp pegwork schema import /tmp/sifg-a --skip-plugins=sifg-config` (5 changes), then again (`0 changes`).
5. Export B from the DB source: `wp pegwork schema export --dir=/tmp/sifg-b --skip-plugins=sifg-config`; `diff -r /tmp/sifg-a /tmp/sifg-b` prints nothing. `wp pegwork schema list --skip-plugins=sifg-config` shows source `option:pegwork_schema`.
6. With `sifg-config` active, `wp pegwork schema status` lists the 4 shadowed DB entries.
7. Restore: `wp option delete pegwork_schema`. Posts, meta and terms were never touched; `pegwork_registry_hash` is unchanged because the canonical registry is the same.
