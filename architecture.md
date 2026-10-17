# Pegwork architecture

Purpose: durable reference facts for the Pegwork plugin (envs, packaging, decisions, gotchas). Last verified: 2026-10-17.

## Product
- Pegwork (full name "Pegwork Platform for WordPress"): one WordPress plugin, an application builder: content builder (JetEngine-like CPTs, fields, taxonomies, relationships), listings/templates, filters (JetSmartFilters-like), form builder (Gravity Forms-like), automation builder (Salesforce Flow-like), all on one schema registry.
- First site: SI for Good (sibling folder `../SIforGood`, private repo brandonmead/siforgood).
- Main long-term consumer: BarnCRM for WordPress (separate add-on plugin requiring core). BarnCRM Salesforce edition: github.com/Nonprofit-Collaborative/BarnCRM (Apache-2.0).

## Canonical sources
- BMemory is canonical: `specification/wordpress-platform-plugin.md`, `decision/wordpress-platform-plugin-packaging.md`. Local docs are copies.
- Phase 1 technical design: `docs/phase-1-design.md` (approved 2026-10-17).

## Packaging
- One plugin with switchable modules.
- Add-ons only for: BarnCRM for WordPress, third-party integrations, paid extras if open core.

## Rendering
- Standard WordPress blocks (any block theme).
- Divi 5 path for other existing sites later.

## Versions and Phase 1 owner decisions
- PHP minimum 8.2. WordPress minimum 6.7.
- Schema files win over DB.
- Description of 50-300 words blocks publish/pending (drafts exempt).
- License: GPL-2.0-or-later (decided 2026-10-17; BMemory `decision/wordpress-platform-plugin-license.md`). BarnCRM for WordPress also GPL; BarnCRM Salesforce edition stays Apache-2.0.

## Name and identifiers (final, decided 2026-10-17)
| Thing | Value |
|---|---|
| Name | Pegwork ("Pegwork Platform for WordPress") |
| Slug, text domain | `pegwork` |
| Main file | `pegwork.php` |
| Function, hook, option prefix | `pegwork_` |
| Constant prefix | `PEGWORK_` |
| PHP namespace | `Pegwork\` (public API `Pegwork\Api`) |
| WP-CLI | `wp pegwork` |
| Future REST namespace | `pegwork/v1` |
| Repo | `brandonmead/pegwork` (private for now) |
| License | GPL-2.0-or-later |
- Likely-free domains at naming time: pegwork.org, pegworkwp.com, getpegwork.com (not registered).

## Repo
- GitHub `brandonmead/pegwork` (private for now). Local folder `/Users/bmead/Documents/Mead Drive/AI Stuff/Claude/Claude Code Projects/Pegwork`; not yet a git repo.

## Dev environment
- LocalWP site `siforgood`: domain siforgood.local, path `~/Local Sites/siforgood`, PHP 8.2.29, nginx, MySQL 8.4. Created 2026-10-17 via Local's GraphQL API.
- Plugin and theme repos to be symlinked into its wp-content.
- WP admin user `admin`. Password not stored: use Local's one-click admin or `wp user update`.

## Gotchas
- Folder is under Mead Drive (synced), which creates "<name> 2.<ext>" duplicates, even inside .git. Stage with explicit paths, never `git add -A`.
