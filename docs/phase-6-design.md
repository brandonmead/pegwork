# Pegwork: Phase 6 Automation v2 Technical Design

Status: approved by the main Claude session 2026-10-22 under Brandon's delegation ("work on phase 6 now"); Brandon reviews later (open questions in section 10). Scope: branching in the engine and registry, and a visual editor for automations. Canonical spec: BMemory `specification/wordpress-platform-plugin.md` (Phase 6 row: "Visual canvas, branching, wait steps, webhooks in and out"; proven by BarnCRM donor acknowledgment and volunteer flows; gate "ongoing"). Builds on `docs/phase-4-design.md` (engine, registry `automations` section), `docs/automation.md` (as built) and `docs/phase-3-design.md` section 3 (block editor as an editing surface for registry JSON). Waits, webhooks in and out, retries and the run log shipped in Phase 4 and are not redesigned here.

Approval notes (2026-10-22):

- Checked against `main` c908502: `record.edit_url`, `today`, `update_record` with a templated `record`, and `Context::refresh_record()` exist, so the worked examples need only the `decision` step and form binding.
- Binding requirement: after any step writes the bound record (`update_record` without `record`, or with `record` equal to the bound id), later steps in the same wake-up read the written values (call `refresh_record()`). Worked example (a) depends on it (`stamp_first`, then `only_gift`). PR 2 adds a test.
- The block-editor builder stands in for the spec's "visual canvas" until Brandon answers open question 1. A free-form canvas stays a non-goal.

## 0. Key decisions

- One new step type, `decision`: ordered branches `{ label, when, steps[] }` plus an optional `otherwise` list. The first branch whose `when` holds runs, then the run continues after the decision. The same shape covers if/else and multi-way routing (the Salesforce Flow Decision element), so there is no separate `if` or `switch`. Conditions use the existing grammar unchanged.
- Step ids stay unique across the whole automation, branches included. The runs table does not change: `current_step` is still a step id, and the executor finds that id's position in the tree. A flat Phase 4 list is a valid tree, so existing automations and waiting runs need no migration.
- No `stop`, `goto`, loops or parallel branches: nothing in the proofs breaks without them (section 1).
- Editor: the block editor, with steps as blocks and branches as nested inner blocks, edits a `pegwork_automation` post. It reuses the Phase 3 form-builder pattern: registry JSON is the truth, the post is only a surface, saving goes through the same PHP validator, file automations open read-only with "Duplicate as site automation". There is no free-form node canvas (section 6 compares the three options).
- Test run is a dry run. It shows the path and what each step would do, with no email, HTTP, record writes or run rows. Live runs stay on the CLI (`run --record --sync`).
- One small engine addition outside branching: form events bind the record the form created, so the volunteer flow can check that record after a wait.
- Format stays 1, API stays 0.2.0, and everything is additive. New site automations start with `enabled: false`.

## 1. Requirements trace (each element and what breaks without it)

| Element | Needed by | Without it |
|---|---|---|
| `decision` step | Donor size split, volunteer routing by interest, "remind only if not contacted" | One automation per path, each with a copied trigger and `condition` gates. Three or more paths become chains that are hard to read and to keep in step |
| `otherwise` list | Gift under the threshold, interest not listed | Authors write the negation of every other branch by hand, and it drifts when a branch changes |
| Step ids unique across branches | Run log, step outputs, webhook delivery id `<run>-<step>` | Two `notify` steps in different branches would share log rows, outputs and receiver dedupe keys |
| Tree-aware executor cursor | Wait, retry and time budget inside a branch (volunteer escalation) | A run waiting inside a branch could not resume, because the flat index search finds no position |
| Branch in the run log | Spec "log every run" | Nobody can tell which path a run took |
| Output scope rule (`steps.<id>` only from steps that ran on every path) | `create_record` inside a branch | A template after the decision renders empty without warning when the other branch ran |
| Nesting cap 3, at most 10 branches | Readable editor, bounded validator | Unbounded trees nobody can review |
| Form events bind their record | Volunteer flow reads `record.stage` two days later | Only `record.created` works, which also fires for staff-entered and imported volunteers and loses `trigger.values` |
| Block-editor builder | Spec "visual" builder; BarnCRM pilot staff edit flows without git | JSON-only editing, which is developers only |
| `pegwork_save_automation()` | Editor saves through the same validator | The editor would write unvalidated JSON |
| Condition builder, merge-tag help, live preview | Trigger `when`, branch `when`, email templates | Hand-written JSON; typos found by a failed run at 3 a.m. |
| Dry-run test | Checking a flow before turning it on | Testing sends real email to real donors |
| New automations start disabled | Building on a live site | A half-built flow fires on the next record save |
| Warning on save while runs are open | Editing a flow with waiting runs | Removed steps fail waiting runs (`definition_changed`) with no warning |
| `wp pegwork automation test` | Runbooks, CI, parity with the editor | Small loss; justified because it is the same class as the editor's Test run |

Considered and left out:

- `stop`: a `condition` step already ends the run when false, and its editor label is "Continue only if".
- `goto` and loops: repeat reminders are a second `wait` plus reminder, or a daily `for_each` sweep with an idempotency key. Leaving loops out keeps every run bounded by the tree size.
- Parallel branches: no proof sends two paths at once.
- A `switch` type: `decision` with `eq` branches does the same.

## 2. Registry shape (format 1, additive)

```json
{ "id": "size", "type": "decision",
  "branches": [
    { "label": "Major gift", "when": { "var": "record.amount", "op": "gte", "value": 250 },
      "steps": [ { "id": "thank_major", "type": "send_email", "...": "..." } ] } ],
  "otherwise": [ { "id": "thank", "type": "send_email", "...": "..." } ] }
```

| Property | Required | Rules |
|---|---|---|
| `id`, `type` | yes | as for every step; no `retry` (`unknown_property`) |
| `branches` | yes | 1 to 10 items (`invalid_value`). Each item is `{ label, when, steps }`, all required. `label` is non-empty text, unique within the decision (`duplicate_key`). `when` is a condition (existing grammar, limits and type checks). `steps` is a step list and may be empty: the branch matches and does nothing |
| `otherwise` | no | step list; runs when no branch matches. Without it, no match continues after the decision. An empty list is dropped by the normalizer |

Cross-step rules (PHP validator; `schema: false` fixtures where JSON Schema cannot express them):

- Step ids are unique across the automation's main tree (`duplicate_key`, "already used in this automation"). Each signed link's steps stay a separate tree, so links may reuse ids, as `examples/sifg` already does.
- Nesting: a decision inside a branch is level 2. At most 3 levels (`decision_too_deep`, the one new error code).
- Output scope: `steps.<id>.<output>` must name a step that runs on every path before this point. That means an earlier sibling, or an earlier sibling of an enclosing list. Otherwise it is `invalid_var` ("step "x" runs only in another branch"). Outputs created inside a branch are usable later in that branch.
- `automation_no_record`, private-field and type rules apply inside branches unchanged (the scan recurses).
- `decision` is not allowed in link steps (`automation_link_step`): nothing needs it, and links must stay a short synchronous list.
- Form binding: an `event` trigger on `form.confirmed`, or on `form.submitted` of a form without `confirm`, whose `form` has a `target`, binds the record. `record.*` paths, `{{link.*}}` and `update_record` without `record` then check against `target.content_type`. Any other form trigger stays unbound (`automation_no_record`). This check is in the references pass, because it reads the form.

JSON Schema: `step.type` gains `decision`, and `#/$defs/decision` refers back to `#/$defs/step` for nested lists. `link_steps` is unchanged. Normalizer: recursive, so `create_record` defaults fill inside branches, branch keys are sorted, list order is kept, and empty `otherwise` is dropped. Exporter and Importer need no code change; their gate test gains nested fixtures. `ui` stays reserved and preserved. The editor does not write it.

Migration: none. Every existing fixture and `examples/sifg` keeps a byte-identical canonical form (PR 1 acceptance). Packages that use `decision` should declare `requires.pegwork` of the plugin version that ships Phase 6. `requires` is informational, so older plugins reject the file with `invalid_value` on `type`.

## 3. Executor changes

`Plan` (new, `modules/automation/src/Plan.php`) is built once per wake-up from `Executor::step_list()`. It walks the tree and gives each step id its step definition, its depth, and `next(id)`. `next(id)` is the following sibling; if the step is last in a branch, it is `next(<enclosing decision>)`; at the end of the tree it is null. For a decision, the Plan also records each branch's first step id, or none when the branch is empty.

Loop (replaces the indexed `for`):

1. `$id = current_step ?: plan->first()`. If the Plan does not hold `$id`, the run fails with `definition_changed`, as today.
2. Each step runs as now: budget check, step limit, `progress()`, attempt, run-step row.
3. `Decision` (new handler) evaluates branches in order and returns `StepResult::branch( ?int $index, $summary )`. `index` is a branch, `-1` is `otherwise`, and null means no match. The new `branch` field on `StepResult` is read only by the executor. The next id is that branch's first step, or `next(decision)` when the branch is empty or nothing matched.
4. Every other result continues with `next($id)`.

Inside branches:

- Wait: the run waits with `current_step = next(wait)`, which may be a step after the enclosing decision. A wait whose `next` is null completes the run (the Phase 4 trailing-wait rule, generalized). On resume, the cursor continues from that id. Decisions already passed are not evaluated again, because the id fixes the path.
- Retries: keyed by step id (`context.retries`), unchanged.
- Time budget: re-queues at `next` (or the same id before the first attempt), unchanged.
- Idempotency: run-level, unchanged. Webhook delivery ids stay unique because ids are unique.
- Loop depth cap (`pegwork_automation_max_depth`) and the 100-attempt step limit are unchanged. A decision evaluation counts as an attempt.
- `condition` false anywhere ends the whole run as `completed`, `stopped_at:<id>`, as today.
- Writes to the bound record are visible to later steps in the same wake-up (approval note).

Definition edits while runs are open: a waiting run continues at its step id wherever that step now sits. A removed id fails the run (`definition_changed`). The editor warns before saving an automation with queued or waiting runs (section 6).

Form binding at runtime: `Triggers` binds `payload.record_id` when the definition binds (section 2) and the id is non-null. A null id inserts a `skipped` run (`no_record`).

Run log, showing the path taken:

- A decision's run-step summary is `{ branch: "<label>" | "otherwise" | null, index }`, so the record of which path ran survives later edits.
- Run detail (admin) indents each step row by its Plan depth and puts the branch label above the first row of each branch. Rows whose id is no longer in the definition show flat with "(no longer in this automation)".
- `wp pegwork automation log --run=<id>` indents step ids the same way.

`Schema::VERSION` stays 1 and no table changes. The `unsupported_step` path stays for definitions newer than the module.

## 4. Core API additions (0.2.0, additive)

- `pegwork_save_automation( array $definition ): true|WP_Error` and `pegwork_delete_automation( string $key )`. These come from generalizing `Registry\Forms` into one site-definitions class for both kinds. The forms functions keep their signatures. Behavior: validator plus references against the effective registry, an upsert into `pegwork_schema.automations` in canonical form, 400 `pegwork_invalid_automation` with `data.fields` by JSON pointer, and 409 for a file-defined key.
- Nothing else in core changes. The editor, preview and dry run live in the automation module and use only `Pegwork\Api`.

## 5. Preview and dry run (module)

REST under `pegwork/v1/automation-editor/`. Both endpoints need `pegwork_manage_automations` (section 8), send `no-store`, and accept only JSON.

- `POST preview { template, html, trigger, record_id?, payload? }` returns `{ rendered }`. It renders through `Templates::render()` in a new `preview` mode. `preview` is `text` or `html`, except that `{{link.*}}` renders `[signed link]` and is never minted, and `steps.<id>.*` renders `[from step <id>]`. No link key or secret is created. The sample record must be of the trigger's bound type. The trigger payload is synthesized from that record: `old.*` holds the current values, `changed` is empty, form `values` are the record's bound keys. Webhook triggers take a pasted JSON sample payload instead.
- `POST test { key, record_id?, payload? }` runs `DryRun` on the saved definition (unsaved changes disable the button, with the visible reason "Save to test your latest changes").

`DryRun` walks the same `Plan` and calls no step handler:

- Decisions and conditions are evaluated; a false condition reports "stops here".
- Email shows rendered `to` (masked as in the log), subject and text.
- Record writes show the target id and rendered values. They are not applied, so later steps see the record as it is now.
- Webhooks show the host and field keys.
- Waits show "waits P30D". The test continues as if the wait had passed, against the current record.

It reports the trigger's `when` or `where` result first ("a real event would not start a run for this record") and then runs the steps anyway. It writes no run rows. The CLI `wp pegwork automation test <key> [--record=<id>] [--payload=<json>]` prints the same report.

## 6. Editor design

### Options compared

The baseline adds nothing: JSON in git, `wp pegwork schema import`, the existing list and log. It fails the spec deliverable, and BarnCRM pilot staff are not developers. A JSON text editor (core CodeMirror with lint) is the next rung. It has the same failure: staff still write JSON, and there is no structure for screen readers. Three builders:

| | (1) Structured list editor (React admin page, `@wordpress/components`) | (2) Block editor: steps are blocks, branches are inner blocks | (3) Free-form node canvas (React Flow) |
|---|---|---|---|
| Keyboard and screen reader (WCAG 2.2 AA) | All ours. We would build an accessible editable tree: move within and across levels, focus management, announcements. Drag needs a library or custom code, plus a button alternative (2.5.7) | Core provides block movers, keyboard shortcuts, List View (an accessible tree with expand and collapse), drag with mover alternatives, and undo. We build only labels, inspector controls and one "Move to branch" control | Graph editing by keyboard and screen reader cannot reach AA on its own: meaning sits in spatial layout (1.3.1) and edges are made by dragging (2.5.7). It needs a parallel accessible list, which is option 1 or 2 anyway |
| Nested branches | Indented lists; good | Inner blocks plus List View; good, and the tree is the engine's shape | A graph allows edges the engine forbids (loops, merges anywhere). It needs constraints, auto-layout and positions in `ui`, and the positions churn git diffs |
| Bundle | Ours, plus a drag library | Ours only (forms-builder is 39.5 KB minified today); the editor is core | A third-party runtime that is not a WordPress external, plus layout. Tens of KB gzipped (not measured) |
| One-person maintenance | Own save, dirty state, unsaved-changes guard, undo, error mapping, drag. Roughly 1,500 lines more than (2) | Same pattern as the form builder (generate and compile, post surface, read-only file mode); its gotchas are already in `architecture.md` | Everything in (1) or (2) plus a library with its own major versions |
| WP 6.7+ | Yes | Yes (the form builder passes the 6.7 and latest matrix) | Likely, with WordPress's React 18; to verify |

Recommendation: (2). It is the smallest option that meets keyboard-only and screen-reader editing, drag, and nested branches, because core already maintains the hard parts, and Pegwork already runs this pattern for forms. (1) rebuilds what core gives us. (3) is the largest, and it still needs (1) or (2) for accessibility. The cost of (2) is document-editor chrome around non-content data; the form builder's mitigations (Save wording, hidden status panels, forced `private`) carry over. Phase 4's note that "the canvas stores node positions in `ui`" is superseded: nothing is positioned.

### Surface

- Post type `pegwork_automation`: not public, `show_ui`, `show_in_rest` (needed by the editor), no menu of its own (`edit.php?post_type=pegwork_automation` redirects to the list), status forced `private`, no revisions. Capabilities map to `pegwork_manage_automations`, then to `manage_options`. Meta: `_pegwork_automation_settings` holds everything except `format`, `label` (the post title) and `steps`, including `links` and `ui`, preserved as they are. Also `_pegwork_automation_key` and `_pegwork_automation_hash`. Opening the post regenerates it when the registry hash differs, as forms do.
- Blocks (category Pegwork, allowed only in this post type, and the post type allows only them):
  - `pegwork/automation-step`: attributes are the step object. Variations: Send email, Update record, Create record, Call webhook, Wait, "Continue only if" (`condition`).
  - `pegwork/automation-decision`: attribute `id`; inner blocks are branches only. Variations: "If / Otherwise" (one branch plus otherwise) and "Choose a path" (two branches plus otherwise).
  - `pegwork/automation-branch`: attributes `label`, `when`, and editor-only `otherwise`; inner blocks are steps and decisions. The otherwise branch is locked last; "Remove otherwise" and "Add otherwise" live on the decision toolbar.
- `Editor\Builder::generate()` and `compile()` (PHP) are mechanical inverses: no registry lookups, empty `otherwise` omitted.
- Canvas: one card per step with icon, type, id and a one-line summary ("Email to {{record.donor_email}}: Thank you for your gift"). Branch headings read "If Amount is at least 250" and "Otherwise". Nesting shows as an indent, a left rule and the heading text, so it does not rely on color (1.4.1). After each decision a note says "Then continue below". Block labels (`__experimentalLabel`) give List View names such as "Email thank_major" and "Branch Major gift".
- Document sidebar (Automation tab):
  - Label, key (from the label on first save, then locked), description.
  - Active toggle: edits `enabled`. A site override from the list or CLI shows as a notice with a link to the list.
  - Trigger picker: Record event (content type; created, updated, status changed, deleted), Form event (form; submitted, confirmed), Schedule (every, at, `for_each` type, statuses, `where`, `max_runs`), Webhook (inbound URL after save and the `secret` command, never the secret), Other event (name).
  - "Only when" condition, "Run once per" (idempotency), and links listed read-only.
- Condition builder (trigger `when`, `where`, branch `when`, condition step):
  - Rows are field, operator and value inside All, Any and Not groups. These map one to one onto `all`, `any` and `not` nodes, so any valid tree round-trips losslessly. Depth 5 and 50 nodes, as the validator.
  - Fields: record fields and taxonomies (private ones marked "private"), trigger keys, step outputs in scope.
  - Operators are filtered by type with the validator's rules (order operators for number and date, `contains` for multi taxonomies, `changed` only on `record.updated`).
  - Values: option and term selects, dates with "today plus or minus a duration", or "another field" (`{ var }`).
  - Each group is a `fieldset` with a `legend` ("All of these are true"). Remove buttons have specific names ("Remove condition 2: Amount is at least 250").
- Step inspector:
  - Fields per type. `values` is rows of field picker plus value (literal control or template).
  - `retry` goes under Advanced, and so does step id: generated on insert, de-duplicated on block duplicate, editable, with the help text "Runs waiting at this step fail if you change its ID".
  - Template fields have "Insert merge tag" (paths in scope at that step, mirroring the validator's scope rules, plus `| date`, `| default`, `{{option:...}}`, `{{link.*}}`).
  - "Show preview" renders against a sample record chosen in a combobox (core REST search of the bound type). The preview updates after typing pauses. Its region is not a live region, which avoids chatter.
- Reorder: drag (canvas and List View), core movers and shortcuts within a list, and "Move to branch" (toolbar dropdown of every list, named by path, such as "size: Major gift"), which calls `moveBlocksToPosition`. Delete through core block options. Add through branch appender buttons named "Add step to Major gift".
- Save: `rest_pre_insert_pegwork_automation` compiles and calls `pegwork_save_automation()`. Errors come back by JSON pointer. The compile map turns pointers into a block and attribute or a settings control. The first message goes in core's save notice, and each message shows at its block card and under its control (`aria-invalid`, `aria-describedby`); "Go to first error" selects that block. An HTML `html` template saved by a user without `unfiltered_html` is passed through `wp_kses_post()`.
- Open runs: before saving, if `Runs::summary()` shows queued or waiting runs, the pre-save panel says "N runs are in progress. They continue from their current step; runs at a step you removed will fail."
- Test run: a "Test run" panel with a sample record or payload. Results show as a nested list with branch headings, and focus moves to the results heading.
- File-defined automations: `templateLock: all`, save disabled, inserter hidden (the existing body-class workaround), and the notice "Duplicate as site automation", which opens a new post prefilled from the definition under key `<key>_copy` with `enabled: false`.
- Script `assets/src/automation-builder/` (built `automation-builder.js` and `.css`, styles on `enqueue_block_assets` for the iframe), loaded only on this post type. Budget: 25 KB gzipped (`bin/check-script-size.js` in CI). No new runtime npm dependencies.

## 7. Admin and CLI

- Pegwork > Automations (position 30, unchanged):
  - Page-title action "Add automation".
  - Rows gain Edit (site), or View and "Duplicate as site automation" (file).
  - Visiting the list creates missing `pegwork_automation` posts (forms precedent).
  - Enable/Disable, Run now, the log and Retry are unchanged.
- Run detail: path indentation and branch labels (section 3).
- CLI: `wp pegwork automation test` (section 5); `log --run` indents by depth. Workflow for git: build on Local, `wp pegwork schema export`, commit to the package. The file then wins and the site copy shows as shadowed, as with forms.

## 8. Security checklist

- Editor, preview, test, list actions: `pegwork_manage_automations`, mapped to `manage_options`, with no filter to lower it. An automation can email any address, write any record and call webhooks, so editing it is an administrator's job. REST uses the cookie nonce. Admin actions keep their per-row nonces and POST.
- Authority: Phase 4 trusted definitions because only the CLI imported them. Site definitions now come from administrators, who can already install code on a single site. `html` templates need `unfiltered_html` (otherwise kses). Multisite stays a non-goal.
- Same validator on every save: private fields stay out of public contexts (webhook URL and fields, link pages), no callables, durations bounded.
- Dry run and preview: no `wp_mail`, no HTTP, no Records writes, no run rows, no link minting, no secret or link-key creation (proved by tests). Admins see private values in previews, as they can on the edit screen. Previews are never logged.
- Post type: private status forced, not public, REST route behind the meta capability. The post is never a source.
- Secrets never reach the editor; the webhook URL and `secret` command do.

## 9. Non-goals

Free-form canvas and node positions; version history (git export is the history; core post locking already warns about concurrent editors); multi-user editing; a custom DSL; AI-generated flows (and no paid AI API anywhere); `stop`, `goto`, loops, parallel branches; decisions in signed links; editing links in the editor (read-only, preserved); live test runs from the editor; cross-record reads and relationship reads (open questions 2 and 3); "wait until a date field" waits; multisite; a JSON text editor view.

## 10. Open questions (recommended answers)

1. The spec says "visual canvas". Accept the block-editor builder as the visual editor, and revisit a canvas only if pilot staff cannot follow nested lists. Recommend yes.
2. Cross-record reads (a `get_record` step, Salesforce "Get Records"). It would let the donor flow live in one automation and read the contact's email. Recommend deferring to Phase 5, where BarnCRM's gift-to-contact relationship and Records link reads are designed together. Worked example (a) uses two automations, which run on current steps.
3. BarnCRM types do not exist. Recommend fixtures in `tests/fixtures/valid/barn`, where `donor_id` (number), `donor_name` and `donor_email` stand in for a relationship and are replaced in Phase 5.
4. New site automations start with `enabled: false`. Recommend yes.
5. Limits of 10 branches and 3 nesting levels. Recommend yes; raise them only on a real need.
6. Step ids editable after save, with the warning. Recommend yes, rather than locking them.
7. Test run is dry only. Recommend yes; use `run --record --sync` on staging for live runs.
8. Form events bind their record. Recommend yes.
9. Plugin version for Phase 6: recommend 0.3.0 at the first BarnCRM flow sign-off; API stays 0.2.0 (Phase 4 decision 7).

## 11. Testing

- Unit:
  - `PlanTest`: next pointers across nesting, branch first ids, empty branches, unknown ids.
  - `DecisionTest`: first match wins, otherwise, no match, summaries.
  - Templates `preview` mode.
  - Vitest: condition builder model to JSON and back for every condition in the valid fixtures, byte-identical; merge-tag scope against a shared fixture `tests/fixtures/automation/scope.json` that `AutomationRules` scope tests also read.
- Registry:
  - Valid fixture `tests/fixtures/valid/branching`: if/else, three-way, empty branch, three levels, wait and `create_record` inside a branch, form binding.
  - Invalid fixtures, each asserting its error code and JSON pointer: `automation-decision-too-deep`, `automation-duplicate-step-id-branch`, `automation-branch-output-scope`, `automation-decision-in-link`, `automation-decision-no-branches`, `automation-decision-too-many-branches`, `automation-decision-retry`, `automation-branch-label-duplicate`, `automation-form-binding-confirm`, `automation-form-binding-no-target`.
  - `bin/check-schema-fixtures.php` agreement (PHP-only rules `schema: false`).
  - Export/import gate byte-identical with nested steps.
- Integration `AutomationBranchTest` (FrozenClock, FakeScheduler, mock PHPMailer):
  - Each path runs only its steps.
  - A wait inside a branch resumes at the next step inside it, then continues after the decision.
  - A wait last in a branch resumes after the decision.
  - A retry inside a branch repeats the same step.
  - Time budget 0 (filter) yields the same path as an uninterrupted run.
  - A false condition inside a branch stops the run.
  - Step limit.
  - Removed waiting step gives `definition_changed`; a moved step continues at its new position.
  - Unique webhook delivery ids.
  - Form binding (submitted without confirm, confirmed with confirm, entry-only form `skipped`).
  - A write to the bound record is visible to the next step in the same wake-up.
  - A Phase 4 shaped waiting run resumes.
  - `StalenessCycleGateTest` passes unchanged.
- Round trip `AutomationBuilderRoundTripTest`: for every valid automation in `tests/fixtures` and `examples`, `normalize(compile(generate(d)))` equals `normalize(d)` byte for byte.
- `AutomationDryRunTest`: counters on `pre_wp_mail`, `pre_http_request` and `pegwork_event` stay 0; no run rows; options `pegwork_link_secret` and `pegwork_automation_secrets` unchanged; report shape.
- Save and REST: 400 with pointers, 409 for file keys, editor and subscriber 403 on every route and screen, kses without `unfiltered_html`.
- E2E `automation-builder.e2e.js`, keyboard only (no mouse calls), on package `tests/e2e/packages/barn`. The package holds the fixture types, form and options page, but not the automations.
  - Build worked examples (a) and (b), save, read the definitions through an admin-only e2e route, and assert equality with `tests/fixtures/valid/barn/automations/*.json`.
  - A bad merge tag shows its message at the right block, with focus reaching it through "Go to first error".
  - Move a step into another branch with "Move to branch".
  - Test run report.
  - File automation read-only and Duplicate.
  - axe on the list and the editor (Pegwork blocks and panels must be clean; any core violation that also reproduces on an empty core post is recorded, not ignored silently); no horizontal scroll at 1280 and 782 px.
- Simulated time `BarnFlowsGateTest` (FrozenClock, FakeScheduler, mock PHPMailer, fixture `barn`). Clock in site time.
  - Donor:
    1. 2027-03-01 09:00, first gifts. G1 from C1 for 300 sends email A to its `donor_email` and creates one `barn_task` with `related_gift` G1, due 2027-03-01. G2 from C2 for 40 sends email B and creates no task. The boundary gift of exactly 250 takes the major branch. Each contact has `first_gift_on` = `last_gift_on` = 2027-03-01, and each follow-up run is waiting until 2027-03-31.
    2. 2027-03-10: G3 from C1 for 75 sends email B. C1's new follow-up run stops at `only_gift`.
    3. 2027-03-30: nothing is sent.
    4. 2027-03-31: C1's first run ends `stopped_at:no_second_gift`, and C2 gets one follow-up.
    5. A gift without `donor_id` is thanked and ends `stopped_at:has_donor`.
    6. Totals: one follow-up email, no failed runs.
  - Volunteer:
    1. 2027-05-03 10:00, form submissions. V1 (garden), V2 (events) and V3 (office, so otherwise) each get `coordinator_email` from `barn_settings`, and one notice goes to the right coordinator with `reply_to` set to the volunteer.
    2. 2027-05-04: V2's stage is set to `contacted`.
    3. 2027-05-05: V1 and V3 get reminders. V2 completes with no reminder.
    4. 2027-05-06: V3 is contacted.
    5. 2027-05-08: V1 escalates to the volunteer manager. V3 ends `stopped_at:still_new`.
    6. V1's log shows `route` with branch Garden, then `notify`, `grace`, `check` with branch "Not contacted yet", `remind`, `grace_2`, `still_new` (passed) and `escalate`, with indentation in the run detail.

## 12. PRs (each merges with CI green)

1. **Registry: `decision` and form binding (core).**
   - Contents: JSON Schema, Validator (shape, limits, unique ids across the tree, output scope, links, form binding in references), Normalizer, fixtures, `docs/registry-reference.md`.
   - Accept: the fixture agreement job passes with the new valid and invalid fixtures; the export/import gate round-trips nested steps byte-identically; every existing fixture and `examples/sifg` exports an unchanged canonical form.
2. **Engine: branches.**
   - Contents: `Plan`, `Decision`, `StepResult::branch`, cursor loop, form binding in `Triggers`, bound-record refresh after writes, decision summaries, run detail and `log --run` indentation, `docs/automation.md`.
   - Accept: `PlanTest`, `DecisionTest` and `AutomationBranchTest` pass; `StalenessCycleGateTest` and every Phase 4 test pass unchanged; the real WP-Cron test resumes a wait inside a branch.
3. **Save API and editing surface (PHP).**
   - Contents: `pegwork_save_automation()` and `pegwork_delete_automation()` (shared site-definitions class, forms API unchanged), post type, capability mapping, `Editor\Builder`, the save hook with pointer errors, sync by hash, read-only locks, `docs/extension-api.md`.
   - Accept: the round-trip test passes for all valid automations; 400 and 409 cases pass; editor and subscriber get 403; forms tests pass unchanged.
4. **Preview and dry run.**
   - Contents: REST `preview` and `test`, Templates `preview` mode, `DryRun`, `wp pegwork automation test`.
   - Accept: `AutomationDryRunTest` shows no side effects; previews render links and outputs as placeholders; CLI and REST reports match for the same input; capability and nonce refusals pass.
5. **Editor UI.**
   - Contents: blocks and variations, canvas cards, inspector, trigger picker, condition builder, merge-tag help and preview, Move to branch, error mapping, the open-runs warning, Test run panel, read-only mode and Duplicate, list Edit, View and Add, size budget.
   - Accept: Vitest suites pass; an E2E that builds, by keyboard only, a three-way decision with a nested if, saves it and reloads it passes; axe is clean on Pegwork parts at 1280 and 782 px; size check is at most 25 KB gzipped; CI passes on WP 6.7 and latest.
6. **BarnCRM proof.**
   - Contents: `tests/fixtures/valid/barn` (types, form, options page, three automations marked as Phase 5 stand-ins), `tests/e2e/packages/barn`, `BarnFlowsGateTest`, the keyboard-only E2E for both examples, docs and screenshots.
   - Accept: section 11 donor and volunteer timelines pass; both examples built by keyboard save byte-identical to the fixtures; a Local runbook (`run --record`, `tick --now=+31days`, `log --run`) is documented and checked once by hand.

## Worked examples: BarnCRM flows (test fixtures)

Needs BarnCRM types (Phase 5), so these are fixtures in `tests/fixtures/valid/barn`:

| Type | Fields |
|---|---|
| `barn_contact` | `name` (title), `email` (private), `first_gift_on` and `last_gift_on` (dates, private) |
| `barn_gift` | `name`, `amount` (number), `received_on` (date), plus the Phase 5 stand-ins `donor_id` (number holding the contact ID), `donor_name`, `donor_email` (private) |
| `barn_task` | `name`, `due_on` (date), `assignee_email` (private), `related_gift` (number), `done` (boolean) |
| `barn_volunteer` | `name`, `email` (private), `interest` (select: `events`, `garden`, `office`), `stage` (select: `new`, `contacted`, `placed`; default `new`), `coordinator_email` (private) |

Options page `barn_settings` has public fields `development_email`, `events_coordinator`, `garden_coordinator` and `volunteer_manager` (email) and `donate_url` (url). Form `barn_volunteer_signup` targets `barn_volunteer` (`create`, `pending`, no `confirm`) and binds `name`, `email` and `interest`.

### (a) Donor acknowledgment

Two automations, because a run reads only its bound record (open question 2). The gift-bound flow thanks the donor for every gift and stamps the contact. The contact-bound flow waits and checks for a second gift.

`automations/barn_gift_thanks.json`

```json
{ "format": 1, "key": "barn_gift_thanks", "label": "Gift received: thank the donor",
  "trigger": { "type": "event", "event": "record.created", "content_type": "barn_gift" },
  "steps": [
    { "id": "size", "type": "decision",
      "branches": [
        { "label": "Major gift (250 or more)",
          "when": { "var": "record.amount", "op": "gte", "value": 250 },
          "steps": [
            { "id": "thank_major", "type": "send_email", "to": "{{record.donor_email}}",
              "reply_to": "{{option:barn_settings.development_email}}",
              "subject": "Thank you for your generous gift",
              "text": "Dear {{record.donor_name}},\n\nThank you for your gift of ${{record.amount}} on {{record.received_on | date}}. Our director will call you this week to thank you in person.\n\nNo goods or services were provided in exchange for this gift.\n\n{{site.name}}" },
            { "id": "call_task", "type": "create_record", "content_type": "barn_task", "status": "publish",
              "values": { "name": "Thank-you call: {{record.donor_name}}", "due_on": "{{today}}",
                "assignee_email": "{{option:barn_settings.development_email}}", "related_gift": "{{record.id}}" } } ] } ],
      "otherwise": [
        { "id": "thank", "type": "send_email", "to": "{{record.donor_email}}",
          "subject": "Thank you for your gift",
          "text": "Dear {{record.donor_name}},\n\nThank you for your gift of ${{record.amount}} on {{record.received_on | date}}.\n\nNo goods or services were provided in exchange for this gift.\n\n{{site.name}}" } ] },
    { "id": "has_donor", "type": "condition", "when": { "var": "record.donor_id", "op": "not_empty" } },
    { "id": "stamp_donor", "type": "update_record", "record": "{{record.donor_id}}",
      "values": { "last_gift_on": "{{record.received_on}}" } } ] }
```

`automations/barn_gift_followup.json`

```json
{ "format": 1, "key": "barn_gift_followup", "label": "First gift: follow up if no second gift in 30 days",
  "trigger": { "type": "event", "event": "record.updated", "content_type": "barn_contact",
    "when": { "var": "record.last_gift_on", "op": "changed" } },
  "idempotency": "{{record.id}}:{{record.last_gift_on}}",
  "steps": [
    { "id": "first_gift", "type": "decision",
      "branches": [
        { "label": "No first gift recorded yet",
          "when": { "var": "record.first_gift_on", "op": "empty" },
          "steps": [ { "id": "stamp_first", "type": "update_record",
            "values": { "first_gift_on": "{{record.last_gift_on}}" } } ] } ] },
    { "id": "only_gift", "type": "condition",
      "when": { "var": "record.last_gift_on", "op": "eq", "value": { "var": "record.first_gift_on" } } },
    { "id": "grace", "type": "wait", "for": "P30D" },
    { "id": "no_second_gift", "type": "condition",
      "when": { "var": "record.last_gift_on", "op": "eq", "value": { "var": "record.first_gift_on" } } },
    { "id": "follow_up", "type": "send_email", "to": "{{record.email}}",
      "reply_to": "{{option:barn_settings.development_email}}",
      "subject": "What your gift made possible",
      "text": "Dear {{record.name}},\n\nA month ago you made your first gift to {{site.name}}. Here is what it is already doing.\n\nIf you would like to give again: {{option:barn_settings.donate_url}}" } ] }
```

Why it works:

- Every gift is acknowledged on its own run, which matters for receipts. The major branch adds the call task.
- `stamp_donor` runs at depth 1 and starts the contact flow. A repeat donor stops at `only_gift` at once, so no wait is held.
- A first-time donor waits 30 days. A second gift in that time moves `last_gift_on` past `first_gift_on`, and the run stops at `no_second_gift`.
- `stamp_first` changes only `first_gift_on`, so it does not re-trigger the flow, and `only_gift` reads the stamped value in the same wake-up (approval note).
- Known limit: two gifts on the same day count as one for the follow-up, because the date does not change. The acknowledgment is unaffected.

### (b) Volunteer intake

`automations/barn_volunteer_intake.json`

```json
{ "format": 1, "key": "barn_volunteer_intake", "label": "Volunteer sign-up: route, notify, remind",
  "trigger": { "type": "event", "event": "form.submitted", "form": "barn_volunteer_signup" },
  "steps": [
    { "id": "route", "type": "decision",
      "branches": [
        { "label": "Events", "when": { "var": "record.interest", "op": "eq", "value": "events" },
          "steps": [ { "id": "to_events", "type": "update_record",
            "values": { "coordinator_email": "{{option:barn_settings.events_coordinator}}" } } ] },
        { "label": "Garden", "when": { "var": "record.interest", "op": "eq", "value": "garden" },
          "steps": [ { "id": "to_garden", "type": "update_record",
            "values": { "coordinator_email": "{{option:barn_settings.garden_coordinator}}" } } ] } ],
      "otherwise": [ { "id": "to_manager", "type": "update_record",
        "values": { "coordinator_email": "{{option:barn_settings.volunteer_manager}}" } } ] },
    { "id": "notify", "type": "send_email", "to": "{{record.coordinator_email}}", "reply_to": "{{record.email}}",
      "subject": "New volunteer: {{record.name}}",
      "text": "{{record.name}} signed up to help with {{record.interest}}. Please reach out within two days, then set Stage to Contacted: {{record.edit_url}}" },
    { "id": "grace", "type": "wait", "for": "P2D" },
    { "id": "check", "type": "decision",
      "branches": [
        { "label": "Not contacted yet", "when": { "var": "record.stage", "op": "eq", "value": "new" },
          "steps": [
            { "id": "remind", "type": "send_email", "to": "{{record.coordinator_email}}",
              "subject": "Reminder: {{record.name}} is waiting to hear from you",
              "text": "{{record.name}} signed up two days ago and is still marked New: {{record.edit_url}}" },
            { "id": "grace_2", "type": "wait", "for": "P3D" },
            { "id": "still_new", "type": "condition", "when": { "var": "record.stage", "op": "eq", "value": "new" } },
            { "id": "escalate", "type": "send_email", "to": "{{option:barn_settings.volunteer_manager}}",
              "subject": "Volunteer not contacted after five days: {{record.name}}",
              "text": "{{record.name}} ({{record.interest}}) has not been contacted. Coordinator: {{record.coordinator_email}}. {{record.edit_url}}" } ] } ] } ] }
```

Why it works:

- The form event binds the new volunteer (section 2), so `record.stage` is read fresh after each wait.
- Routing writes the coordinator once, so the steps after the decision are shared by every path (and `notify` reads it in the same wake-up, approval note).
- `check` is a decision rather than a `condition`, so steps added after it later still run for volunteers who were contacted.
- The escalation exercises a wait and a stop inside a branch.
- Every step id is unique across the tree, so the run log reads as one path.
