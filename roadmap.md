# Pegwork roadmap

Purpose: planned work and goals with status; completed items move to changelog.md.

## Phases
1. Foundation: schema registry, types, fields, taxonomies, REST, JSON export. Gate: config exports and re-imports cleanly. Status: PRs 1-5 and 7 merged; PR 6 (export/import, gate test) in review, done when merged; PR 8 (sifg-config sign-off) next.
2. Display: listings, templates, filters. Gate: directory fast with 500 test records (budget in `docs/phase-2-design.md` section 10). Status: design drafted 2026-10-18, awaiting owner review.
3. Forms. Gate: two weeks of real submissions without defects. Status: design `docs/phase-3-design.md` (decisions 2026-10-18); PR 1 folded into the shared registry sections PR (merged) and PR 2; PR 2 (runtime without JS, entry-only forms) merged (#21); PR 3 (record mapping, confirmation, `form.confirmed`) merged (#25); PR 7 (entries admin, privacy) in review 2026-10-18, branch `forms-entries`; then PR 4 (Turnstile, uploads; file links and file deletion go into `Entries::delete()` and the entry screen), PR 5 (front-end script), PR 6 (builder), PR 8 (siforgood forms; adds the `pegwork_forms_draft_type` filter for Share a story to `sifg_story`).
4. Automation v1. Gate: one full staleness check cycle. Status: PR 1 (event bus, Records API) in review 2026-10-18; PR 3 (engine, WP-Cron scheduler) in review 2026-10-18, branch `automation-engine`; PR 4 (email, schedules, waits, retries, `run|tick|retry`) in review 2026-10-18, branch `automation-email`; PR 5 (signed links, link handler, `examples/sifg` staleness automation, `StalenessCycleGateTest`) in review 2026-10-18, branch `automation-links` (#27); PR 6 (admin screens: list, toggles, Run now, run log and detail, Retry) merged (#26); PR 7 (webhooks: `call_webhook`, inbound endpoint, secrets) in review 2026-10-18, branch `automation-webhooks`; PR 8 (`sifg-config` automations, thank-you page, owner runs the Local runbook) closes the gate. Owner review item: move the WordPress minimum to 6.9 and add an Action Scheduler 4.2+ adapter (design decision 8).
5. BarnCRM foundation. Gate: pilot nonprofit runs daily work on it.
6. Automation v2. Ongoing.

## Phase 1 PRs
1. Scaffold (merged)
2. Registry (merged)
3. Content registration (merged)
4. Fields and validation (merged)
5. Editor panel (merged)
6. Export/import and DB source (in review; done when merged)
7. Module loader and public API (merged)
8. sifg-config (in siforgood repo)

## Phase 2 PRs (`docs/phase-2-design.md`)
- Listings A: `pegwork/field` source, `pegwork/conditional`, block category, `Registry::meta_key()`, `pegwork_get_field()`, `listings`/`filters` module skeletons (in review, branch `listings-a`).
- Listings B: `pegwork` Query Loop attribute, variations, `where` presets, constraint pipeline, archive main query, generated patterns, seed fixture, query budget test (in review, branch `listings-b`).
- Filters server and client (PRs 4 and 5 together): `pegwork/filters` block, URL state, constraints, directory pattern with Clear all, Interactivity store and router (in review, branch `filters`).
- Gate and docs (PR 6): `bin/perf-directory.sh` on LocalWP nginx, CI timing guard: not started. The size check landed with the filters PR.
- Related records (closes `docs/content-builder-design.md` section 1 "Project page lists its stories"): `where.related` preset, Show if relationship condition, `pegwork/<type>-related-<key>` patterns; plus multi-slug `module enable|disable`, featured `{}` fix, export doc (in review, branch `listings-related`). Follow-up in the siforgood repo: Project template "Stories about this project" and Story and Resource "Related projects" from these patterns (Resources need a relationship first; `examples/sifg` has none).

## Shared registry sections PR (in review, branch `registry-sections`)
- Registry side of `listing` (Phase 2), forms (Phase 3), automations (Phase 4) and options pages (content builder PR 4): schema, validation, normalization, export/import, API accessors. Runtime for each stays in its own PR.
- Follow-up in the siforgood repo: copy `examples/sifg` `listing`, `options-pages/`, `forms/` and `automations/` into `sifg-config/schema/` once those modules ship.

## Content builder completion PRs (`docs/content-builder-design.md` section 9)
1. Admin skeleton: menu, Overview, Modules, `Registry\Status`, disable dependents rule (merged, PR #13)
2. Relationships backend (merged, PR #15)
3. Relationship picker plus E2E harness (harness merged, PR #12; picker in review, branch `rel-picker`)
4. Options pages (registry kind in the shared registry sections PR, merged #18; runtime, screen, post picker and CLI in review, branch `options-pages`, combined with 5)
5. Block Bindings and docs: `pegwork/option` source (in review, branch `options-pages`)

Follow-up in the siforgood repo: copy `examples/sifg/options-pages/sifg_settings.json` into `sifg-config/schema/`; the theme binds the footer contact email (`format: "mailto"` on the button) and review criteria with `pegwork/option` (`docs/display.md`).

Follow-up in the siforgood repo: copy `examples/sifg/content-types/sifg_story.json` and `examples/sifg/relationships/story_projects.json` into `sifg-config/schema/` so the two stay byte-identical.

## Done
- Shared browser E2E harness (2026-10-18, in review). Later feature PRs add specs under `tests/e2e/specs/`; the admin axe spec starts checking Pegwork admin screens once they land.
- Choose product name: Pegwork (2026-10-17).

## Open questions
- Open source vs open core.
- Ownership: Mead Solutions, Nonprofit Collaborative, or separate.
- Name of the SI for Good config plugin: `sifg-config` (default) or `pegwork-config`.
