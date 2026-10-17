# Pegwork memory

Purpose: project context and decision rationale not derivable from code or git.

- Why build: justified only by BarnCRM for WordPress needing one coherent schema/forms/automation layer.
- Why one plugin with switches: every module depends on the core registry and event bus, so splitting adds version-compat and API burden for one maintainer with no user gain.
- Why JSON-in-git as source of truth: avoids ACF-style DB drift.
- Stored content keys never contain the product prefix, so renames never migrate content.
- Name rationale (2026-10-17): pegs hold a timber frame's joints together, the way one shared data model holds types, forms and flows together. Vetted against wordpress.org plugins and themes, Packagist, npm, GitHub and web searches.
- Automation builder starts record-triggered and scheduled; visual canvas comes later.
- Export is one package dir, not one per source, and import stores the package manifest in `pegwork_schema.manifest` (PR 6): the gate needs a file-package export and the export of its DB import to be byte-identical, and keeping the manifest preserves package id and version for the PR 8 clean-diff sign-off.
