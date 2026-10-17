# Pegwork changelog

Purpose: dated, newest-first log of changes actually made.

## 2026-10-17
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
