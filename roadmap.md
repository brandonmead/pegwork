# Pegwork roadmap

Purpose: planned work and goals with status; completed items move to changelog.md.

## Phases
1. Foundation: schema registry, types, fields, taxonomies, REST, JSON export. Gate: config exports and re-imports cleanly. Status: PRs 1-5 and 7 merged; PR 6 (export/import, gate test) in review, done when merged; PR 8 (sifg-config sign-off) next.
2. Display: listings, templates, filters. Gate: directory fast with 500 test records (budget in `docs/phase-2-design.md` section 10). Status: design drafted 2026-10-18, awaiting owner review.
3. Forms. Gate: two weeks of real submissions without defects.
4. Automation v1. Gate: one full staleness check cycle.
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

## Done
- Shared browser E2E harness (2026-10-18, in review). Later feature PRs add specs under `tests/e2e/specs/`; the admin axe spec starts checking Pegwork admin screens once they land.
- Choose product name: Pegwork (2026-10-17).

## Open questions
- Open source vs open core.
- Ownership: Mead Solutions, Nonprofit Collaborative, or separate.
- Name of the SI for Good config plugin: `sifg-config` (default) or `pegwork-config`.
