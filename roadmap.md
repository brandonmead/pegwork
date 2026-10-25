# Pegwork roadmap

Purpose: planned work and goals with status; completed items move to changelog.md.

## Phases
1. Foundation: schema registry, types, fields, taxonomies, REST, JSON export. Gate: config exports and re-imports cleanly. Status: PRs 1-5 and 7 merged; PR 6 (export/import, gate test) in review, done when merged; PR 8 (sifg-config sign-off) next.
2. Display: listings, templates, filters. Gate: directory fast with 500 test records (budget in `docs/phase-2-design.md` section 10). Status: design drafted 2026-10-18, awaiting owner review.
3. Forms. Gate: two weeks of real submissions without defects. Status: design `docs/phase-3-design.md` (decisions 2026-10-18); PR 1 folded into the shared registry sections PR (merged) and PR 2; PR 2 (runtime without JS, entry-only forms) merged (#21); PR 3 (record mapping, confirmation, `form.confirmed`) merged (#25); PR 7 (entries admin, privacy) in review 2026-10-18, branch `forms-entries`; PR 4 (Turnstile, uploads) merged (#31); PR 6 (builder, Forms list) merged (#33); PR 5 (front-end view script) in review 2026-10-18, branch `forms-view`; then PR 8 (siforgood forms; adds the `pegwork_forms_draft_type` filter for Share a story to `sifg_story`).
4. Automation v1. Gate: one full staleness check cycle. Status: PR 1 (event bus, Records API) in review 2026-10-18; PR 3 (engine, WP-Cron scheduler) in review 2026-10-18, branch `automation-engine`; PR 4 (email, schedules, waits, retries, `run|tick|retry`) in review 2026-10-18, branch `automation-email`; PR 5 (signed links, link handler, `examples/sifg` staleness automation, `StalenessCycleGateTest`) in review 2026-10-18, branch `automation-links` (#27); PR 6 (admin screens: list, toggles, Run now, run log and detail, Retry) merged (#26); PR 7 (webhooks: `call_webhook`, inbound endpoint, secrets) in review 2026-10-18, branch `automation-webhooks`; PR 8 (`sifg-config` automations, thank-you page, owner runs the Local runbook) closes the gate. Decided 2026-10-22: WordPress minimum stays 6.7, no Action Scheduler adapter; production runs WP-Cron from a server cron. Scheduling health (Site Health test, notice, production docs) in review, branch `cron-health`. Before SI for Good launch on Cloudways: set up the server cron (`docs/automation.md#production-scheduling`) and verify the items marked "verify" there (absolute path, `wp` on cron's `PATH`, Cron Optimizer interval and `DISABLE_WP_CRON`).
5. BarnCRM foundation. Gate: pilot nonprofit runs daily work on it.
6. Automation v2. Gate: "ongoing" (per the spec; no one-time gate). Design `docs/phase-6-design.md` (approved 2026-10-22, PR #38). Status: the six design PRs are done (6b in review, branch `phase6-barn-e2e`); further v2 work is ongoing.

## Phase 6 PRs (`docs/phase-6-design.md` section 12)
1. Registry: `decision` step and form binding (merged, #42).
2. Engine: branches, `Plan`, form binding in triggers, run log path (merged, #50).
3. Save API and editing surface (merged, #52).
4. Preview and dry run (merged, #53).
5. Editor UI (merged, #55).
6. BarnCRM proof: 6a (fixture package `tests/fixtures/valid/barn`, E2E package `tests/e2e/packages/barn`, `BarnFlowsGateTest` for both section 11 timelines, Local runbook) merged (#54); 6b (keyboard-only editor E2E `automation-barn-flows.e2e.js` that builds all three BarnCRM automations and compares them with the fixtures, Test run, screenshots) done, in review (branch `phase6-barn-e2e`).

## Automation v3 PRs (`docs/automation-v3-design.md` section 11)
1. Core: `Records::find()`, `relate()`, `unrelate()`, relationship keys in `create()`/`update()` and in record events, `Content\RecordQuery`, API 0.3.0: done, in review (branch `v3-records`).
2. Registry: new steps, outputs and triggers: done, in review (branch `v3-registry`). The engine refuses v3 shapes (`Engine::unsupported()`) until PRs 3 to 5; each removes its feature from that guard.
3. Engine: reads, relate, on_error, templates: done, in review (branch `v3-engine-reads`). Only `loop` (PR 4) and the `manual` trigger (PR 5) stay refused. Left for later PRs: read record titles in the run detail (PR 4's run detail grouping).
4. Engine: loops: done, in review (branch `v3-loops`). Read record titles in the run detail included. Left for PR 7: item headings in the editor's Test run panel (the report entries carry `item`; the panel lists them nested without the heading).
5. Manual trigger: done, in review (branch `v3-manual`). REST start route, record editor section (`automation-actions.js`, 1.3 KB gzipped), the list's Run now for no-record manual automations, CLI `run`. The trigger picker ("Started by staff") stays for PR 6.
6. Editor: reads, relate, on_error, manual trigger: done, in review (branch `v3-editor-reads`). Get a record, Find records, Link records inspectors, "If this step fails after retries", "Started by staff", grouped variable picker with `| add` and `| number`, `[from step <id>: <Field label>]` in the preview. The builder is 24,893 bytes gzipped of its 25,600 budget: PR 7 (loop block, "Insert list of records") needs room, by trimming or by raising the budget with a reason.
7. Editor: loops and lists: done, in review (branch `v3-editor-loops`). "Repeat for each" block, inserter and paste rules (no Wait or loop in a body), Move to into loop bodies, "Insert list of records", Test run item headings (closes PR 4's leftover), run-detail step ids on one line. Builder budget raised to 27,136 bytes gzipped (26,994 measured).
8. BarnCRM proof with reads: done, in review (branch `v3-barn-proof`). Barn fixture on v3 flows, `BarnFlowsGateTest` timelines, editor E2E, runbook checked by hand, plugin 0.3.0. Automation v3 is built; done when PRs 1 to 8 are merged.

## Automation v4 PRs (`docs/automation-v4-design.md` section 11)
1. Core: `Records::trash()` and `created` reads, API 0.4.0: done, in review (branch `v4-core-trash`). Its leftover (`Records::get()`/`update()` still read trashed records) is handled in the engine by PR 4.
2. Registry: v4 shapes: done, in review (branch `v4-registry`). Runs using v4 shapes fail `unsupported_step` before their first step until PRs 3 to 6. Left for Editor PR 9: refuse trashing an automation post whose definition is in use (the definition already stays); round-trip the `v4` package in `reads.test.js`.
4. Engine: trash: done, in review (branch `v4-trash`). `trash_record` runs; `update_record` and `relate` on a trashed record fail in the engine (core unchanged). The read `created` gap it found is closed by PR 7. Left for Editor PR 9: round-trip `tests/fixtures/valid/engine-trash` in `reads.test.js`.
7. Bulk Run, plus the read `created` engine gap: done, in review (branch `v4-bulk`). `Admin\BulkRun` on core list tables (confirmation screen, 50 per request, one throttle hit, per-record keys, source `bulk`). Left for PR 10: bulk on Local in the runbook, and `barn_gift_receipt` ("Run: Send receipt") once the barn fixture gains it; the E2E uses `e2e_manual_thanks`. `Steps\Reads` resolves `created` operands to site days and passes them to core; `Engine::unsupported()` no longer refuses `created`, so `barn_volunteer_retention` runs.

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
- Gate and docs (PR 6): `bin/perf-directory.sh`, CI timing guard, axe specs, docs, budget numbers (in review, branch `display-gate`). The size check landed with the filters PR. Next: PR 7 in the siforgood repo (owner sign-off).
- Related records (closes `docs/content-builder-design.md` section 1 "Project page lists its stories"): `where.related` preset, Show if relationship condition, `pegwork/<type>-related-<key>` patterns; plus multi-slug `module enable|disable`, featured `{}` fix, export doc (in review, branch `listings-related`). Follow-up in the siforgood repo: Project template "Stories about this project" and Story and Resource "Related projects" from these patterns (Resources need a relationship first; `examples/sifg` has none).

## Open-source readiness PR (in review, branch `repo-hygiene`)
- Community files, CI hardening, PHP 8.5, CodeQL, release zip, Plugin Check clean of errors (`changelog.md` 2026-10-22).
- Left: 40 Plugin Check `UnescapedDBParameter` warnings (prepared queries; switching table names to `%i` is a runtime change for the security review). Dependabot alerts (all npm dev tooling) cleared by the deps PR (branch `deps-a11y-2026-10`, 2026-10-25): `@wordpress/env` 12, `@wordpress/scripts` 37, plus `overrides` (`architecture.md`, JS).
- Owner settings to consider (not changed): branch ruleset on `main` (PR required, required checks, no force-push or deletion), Dependabot security updates, secret scanning and push protection, "Require actions pinned to a full SHA", delete branch on merge, a WordPress.org username for `Contributors:` in readme.txt.

## Security fixes PR (in review, branch `security-fixes`)
- SR-01 to SR-03, L-01 to L-10 and I-01 to I-10 fixed or documented (`changelog.md` 2026-10-22; status column in `docs/security-review-2026-10.md`). I-11 done in the open-source readiness PR.
- Left, by decision: I-08 (`update_record.record` from a webhook payload may name any Pegwork record; a declared `content_type` check belongs with the Phase 6 registry work on steps), I-09 (relationship validation existence oracle for contributors; `read_post` on linked IDs would break anonymous form and automation writes). Optional later: a setting to store form uploads outside the web root (I-03; the nginx rule is documented).
- SI for Good: deploy from the release zip (Site Health now warns when a checkout with dev dependencies runs on a production or staging site); set `pegwork_client_ip` if Cloudways does not restore the visitor IP behind Cloudflare, and add the nginx deny rule for `uploads/pegwork-forms/`.

## UX polish PR (in review, branch `ux-polish`)
- Clears the UX backlog and bumps the plugin to 0.2.0 (`changelog.md` 2026-10-20). Also holds the SI for Good final QA fixes (confirm panel, corrected submits, conditional required, step heading, URLs, rate-limit wording, `| date` and `{{option:...}}` templates).
- Done in branch `ux-leftovers` (`changelog.md` 2026-10-22): the filters panel can collapse on phones (`collapseOnSmall`, opt-in). Follow-up in the siforgood repo: add `messages.confirmed` to Submit a project and use `| date` and `{{option:sifg_settings.contact_email}}` in `sifg-config` emails.
- Left for later (minor, from the admin sweep; the other four items are done in `ux-leftovers`): 8 jsdoc lint warnings in `assets/src/forms-builder/` (controls.js, field-edit.js, logic.js, step-edit.js).

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
