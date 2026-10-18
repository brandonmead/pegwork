# Pegwork changelog

Purpose: dated, newest-first log of changes actually made.

## 2026-10-18
- Phase 2 display design drafted (`docs/phase-2-design.md`, in review): Query Loop variation, `pegwork/field` bindings, `pegwork/filters` and `pegwork/conditional` blocks, registry `listing` section, no custom endpoints.

## 2026-10-17
- PR 6 export/import (in review): `wp pegwork schema export [--dir] [--source=all|db|files]` and `import <dir> [--prune] [--dry-run]` (`docs/export-import.md`); `Registry\Exporter`, `Importer`, `ImportPlan`; `Loader::read_package` public, `Result::manifests`, `Plugin::packages()`; import stores the package manifest in `pegwork_schema.manifest`. Phase 1 gate test (`ExportImportGateTest`) plus unit tests. siforgood.local: export, dry run, import twice (second `0 changes`), DB export byte-identical to file export; option deleted after, Project 24 and pages 5-9 unchanged. Plugin symlink -> `pr6-export`.
- PR 5 editor panel: `PluginDocumentSettingPanel` from `pegwork_registry()` config (`docs/editor-panel.md`), `allowed_block_types_all`, `@wordpress/scripts` 36 build, Vitest and PHPUnit tests, CI JS job; `pegwork_invalid_record` field messages shown inline. End-to-end checked in headless Chrome on siforgood.local (Project 24, values restored after). siforgood.local: plugin symlink -> `pr5-editor-panel`.
- PR 4 fields and validation (in review): `src/Content/Meta.php` (register_post_meta, per-type sanitizers, edit_post auth, REST schema, private = edit context), `RecordValidator` (rest_pre_insert, publish/pending/future), `pegwork_validate_record()`, `wp.meta_key` in validator + JSON Schema with 3 fixtures. siforgood.local: plugin symlink -> `pr4-fields`; sample Project 24 published.
- PR 3 content registration (in review): `src/Content/` PostTypes, Taxonomies (seed terms by slug, locked-terms guard), Registrar (`pegwork_registry_hash`, flush + seed on `wp_loaded` when it changes). siforgood.local: plugin symlink -> `pr3-content`, `sifg-config` symlinked and activated.
- PR 2 registry (in review): `src/Registry/` Loader (file packages + `pegwork_schema` option, file wins, duplicate packages rejected), Validator, Normalizer; `Pegwork\Api\Registry`, `RegistryBuilder`, `VERSION`; `pegwork_registry()`; admin notice for schema errors; `wp pegwork schema validate|list|status`; `schema/registry.schema.json` with CI fixture-agreement job (opis/json-schema, dev only); `examples/sifg`; 3 valid and 32 invalid fixtures. Plugin symlink on siforgood.local repointed to `~/worktrees/pegwork/pr2-registry`.
- PR 1 scaffold: `pegwork.php` (constants, PHP/WP guard, boot), `uninstall.php`, Composer, PHPCS, PHPUnit, CI matrix, GPL-2.0 LICENSE, readme.txt. Activated on siforgood.local via symlink.
- PR 7 module loader: `Pegwork\Api\Module`, `Modules\Loader` (manifest, `pegwork_modules` option, requirement checks, notices), `wp pegwork module`, `bin/check-module-boundary.sh` + CI job, `docs/extension-api.md`.
- Named Pegwork; folder renamed from WPPlatform. Identifiers recorded in architecture.md; docs updated.
- License decided: GPL-2.0-or-later (BMemory decision record created).
- Git standing authorization added to CLAUDE.md.
- Project folder created.
- Phase 1 design drafted and approved (`docs/phase-1-design.md`).
- LocalWP site `siforgood` created.
- Project docs created.
