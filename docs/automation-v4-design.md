# Pegwork: Automation v4 Technical Design

Status: adopted by the main Claude session 2026-10-25 with the recommended answers to section 10, under Brandon's "build whatever is possible and recommended to build now" (2026-10-25). Brandon reviews later. Scope: the v3 "later" items worth building now, to move toward "a full featured automation platform with reads". Those items are a wait until a date field, a trash step, a step that starts another automation, email to a WordPress role, and bulk "Run automation" on list tables. This document also adds the small companions those items need, found while checking the code (section 1.2). Builds on `docs/automation-v3-design.md`, `docs/automation.md` (as built, plugin 0.3.0), `docs/registry-reference.md`, `docs/events.md` and `architecture.md`. Checked against `main` d2fe5f1.

Goal used for every verdict (unchanged from v3): BarnCRM-class nonprofit workflows (donors, gifts, volunteers, tasks) run reliably on shared hosting with WP-Cron and a server cron, and non-developers can edit them in the block-editor builder.

Right-sizing rule (binding, from Brandon): for each element, name what breaks without it, measured against the goal. If nothing breaks, leave it out. Each element also steelmans the simpler alternative.

## 0. Key decisions

- **Wait until a date field, made self-correcting.** `wait` gains `until: { var: "record.<date field>", offset, at }` as an alternative to `for`. v3 objected that a stored resume time goes stale. v4 answers that objection in two ways:
  - The cursor stays on the wait step, so every wake-up re-reads the field and decides again.
  - A cheap listener on `record.updated` pulls a waiting run's wake-up to now when the field it waits on, or the record's status, changes.
  - Rules: a date moved later waits again; a date moved earlier wakes at once and re-decides; a date already past continues at once; an empty date parks the run (it waits for a date, at most 2 years); a trashed record stops the run.
  - Only the bound record's date fields are allowed, because the listener keys on the bound record.
- **Trash only, never permanent delete.** New step `trash_record`, backed by a new core `Records::trash()`. Core refuses when `EMPTY_TRASH_DAYS` is 0, because in that case `wp_trash_post()` deletes permanently.
  - Targets: only a typed reference (the bound record, a read output, a loop item), and the step must name its `content_type`.
  - Limits: at most 50 trashes per run. Refused in `for_each` sweeps (use schedule, find, loop instead).
  - Editors need `delete_post` to start any automation that can trash.
  - Shown in dry runs. Counts as a write.
  - Read queries gain a `created` property (`before`, `after`), so "older than 2 years" works on records that have no stamped date.
- **Start another automation, asynchronous only.** New step `start_automation`. It starts a `manual` automation through the same checks as `Manual::start()` (enabled, record type, `when`), at origin depth run depth + 1, under the existing depth cap of 3.
  - The child's idempotency key is `start:<run>-<step>[-<iteration>]`, so a resumed or retried parent never starts a second child.
  - Output: `started` (boolean). The child's outputs never come back, so there are no subflows.
  - Cycles are refused at load time.
  - Deleting an automation that another one starts is refused, because the loader would otherwise drop the caller silently.
- **Email to a WordPress role.** A recipient token `{role:<slug>}` in `send_email.to`, beside the existing `{option:<page>.<field>}`.
  - Members are resolved at send time, at most 25 per email.
  - Only staff roles (the role has `edit_posts`; filter to widen).
  - Members are sent in Bcc, so they do not see each other's addresses.
  - The log, previews and dry runs show only `role:<slug> (N)` and never member addresses.
  - There are no user record reads.
- **Bulk "Run automation" on list tables.** For enabled `manual` automations that have a `content_type`, the user can start them on selected records from the core list table. The flow goes through a no-JS confirmation screen.
  - At most 50 records per request. One throttle hit per request from the existing per-user bucket.
  - Per-record idempotency key `manual:<user>:<request>-<record>`.
  - Capability and `when` are checked per record.
  - Not offered for automations that can trash, because core bulk Trash already covers that case.
- **No schema change.** Every new state fits the existing columns plus small `context` entries (`context.until`, `context.trashed`, `context.starts`, `context.parent`). `Schema::VERSION` stays 2. Registry format stays 1 and every change is additive. `Pegwork\Api\VERSION` goes to 0.4.0 (new `Records::trash()` and the `created` query property). The plugin goes to 0.4.0 at sign-off.
- **Editor size:** raise the builder budget by the measured minimum, capped at 30,720 bytes gzipped. If the build exceeds that cap, split the step inspectors into a lazy chunk. Lazy splitting is not the default (section 5.6).

## 1. Verdicts

### 1.1 The proposed set, item by item

| Item | v3 verdict | v4 verdict | What breaks without it (against the goal) | Strongest simpler alternative | Why build it this way |
|---|---|---|---|---|---|
| Wait until a date field, with offset | Later: the sweep recipe (`due_on eq today + P3D`, key `{{record.id}}:{{record.due_on}}`) self-corrects; a stored resume time goes stale | **Build**, with re-read on wake and a change listener | "Remind 3 days before `due_on`" from a record event needs a second, scheduled automation with a non-obvious `where` and idempotency key that staff cannot discover in the editor. A task's per-record timeline (created, reminded, done) is split across two automations and two run logs | Keep the sweep recipe and document it better | A recipe the editor cannot offer is not editable by non-developers. The re-read and the listener remove the staleness objection: an edit earlier, later or to empty is honoured (section 3.2). The sweep recipe stays valid and documented |
| Trash a record | Later: no proof needs it; a status covers retention; risk of a sweep trashing 200 a day | **Build**, trash only, with guards | Data-minimisation retention ("remove volunteer applications older than 2 years") cannot be automated. A status field keeps the personal data in the database indefinitely, which a privacy policy that promises deletion cannot accept | A status field plus a monthly manual bulk Trash in the admin | Manual retention is the step a small nonprofit forgets. The guards answer the v3 risk: 50 per run, no sweeps, typed targets only, dry run, restorable for `EMPTY_TRASH_DAYS` |
| Start another automation | Later: shared steps repeat in a few flows; a synchronous child would double the cursor problem | **Build**, fire-and-forget only | Shared sequences (the gift receipt, used by gift thanks, manual resend and the inbound donation path) are copied into several automations and drift: a wording fix lands in one copy only | Copy the steps; or chain through a record event (stamp a field, react to `record.updated`) | Copies drift, and the event chain needs a junk field plus a `changed` condition that nobody can read. Asynchronous start reuses the `manual` contract that already exists (`Manual::start()` checks, depth, idempotency) and adds no cursor state |
| Email to a role | Later: options pages cover it; user reads bring staff data and role churn | **Build**, recipient token only, no user reads | Notices for several moderators need a shared inbox, or an administrator must edit an options-page address whenever moderators change. On a volunteer-run nonprofit, people change every season | A comma-separated options field of addresses | Someone has to keep that field in step with the actual team, and the 5-recipient cap applies. Roles are already maintained when people join or leave. Bcc, the staff-only rule and masked logs keep the privacy cost low |
| Bulk Run on list tables | Non-goal: a sweep or a loop covers it | **Build**, through a confirmation screen | Ad-hoc staff batches ("resend receipts for these 12 gifts after the address fix") mean opening 12 records and clicking 12 buttons, or asking an administrator to write a sweep with a one-off `where` | The record-editor button only | Sweeps and loops need an administrator and a query that matches the batch. Staff have the batch in front of them (a filtered list). The existing `Manual::start()` path, throttle and idempotency carry it |

### 1.2 Additions found while checking the code (each needed by an item above)

| Addition | Needed by | What breaks without it | Simpler alternative considered |
|---|---|---|---|
| Read query `created: { before, after }` | Trash retention | Records created before the automation existed have no stamped date, so "older than 2 years" cannot select them | A stamped `applied_on` field: it works only for future records, and the BarnCRM volunteer type has no such field today |
| `Records::trash()` refuses when `EMPTY_TRASH_DAYS` is 0 | Trash | With trash turned off, `wp_trash_post()` calls `wp_delete_post( $id, true )`, which is a permanent delete. That breaks the "never permanent delete" rule | Documenting it: the rule must hold in code |
| Editors need `delete_post` to start an automation that can trash (including through `start_automation`) | Trash, manual, bulk | An editor who cannot trash a record could trash it through an `allow: editors` button | Refuse `trash_record` in `allow: editors` automations: too strict for a "Withdraw my application" flow that a coordinator may legitimately trash |
| Start cycle check and `automation_in_use` delete refusal | Start | The loader drops units with unresolved references (`Registry\Loader`), so deleting the receipt automation would silently drop `barn_gift_thanks`. A file package that starts a site automation would drop the whole package | Runtime failure only: not possible, because the caller never loads |
| Change listener ("nudge") for date waits | Wait until | A due date moved earlier would remind after the new date, which is the staleness v3 objected to | Re-check on wake only: that handles "later" and "cleared" but not "earlier" |

### 1.3 Items that stay out (summary; reasons in section 9)

Subflows with outputs, user record reads, full fault branches, numeric arithmetic, mutable variables and formulas, the `{ var, offset }` condition operand, waits until a read record's date, waits in loops.

## 2. Registry shape (format 1, additive)

### 2.1 `wait` with `until`

```json
{ "id": "before_due", "type": "wait", "until": { "var": "record.due_on", "offset": "-P3D", "at": "09:00" } }
```

| Property | Required | Default | Rules |
|---|---|---|---|
| `for` or `until` | exactly one | | Both or neither: `wait_source`. `for` is unchanged (`PT1H` to `P2Y`) |
| `until.var` | yes | | A path, not a template. It must be `record.<key>` where `<key>` is a `date` field of the bound content type (`wait_field`). The trigger must bind a record (`automation_no_record`, the existing code) of a known type (`wait_field`, "the trigger names no content type"). Private date fields are allowed |
| `until.offset` | no | `P0D` (normalizer omits it when zero) | Signed ISO 8601 duration (`OFFSET_PATTERN`), absolute value at most `P2Y` (`invalid_value`). Calendar units in site time |
| `until.at` | no | `09:00` (filled by the normalizer) | `HH:MM` site time (`TIME_PATTERN`, `invalid_value`): the time of day on the date, before the offset applies |

The target instant is the date at `at` in site time, then moved by `offset` (`Duration::add()`, calendar units, so `-P3D` keeps 09:00 across a DST change). The step has no outputs.

Existing rules apply unchanged: no wait in a loop body (`loop_wait`) and none in link steps (`automation_link_step`). The static bound counts the wait once.

### 2.2 `trash_record`

```json
{ "id": "remove", "type": "trash_record", "content_type": "barn_volunteer", "record": "{{steps.each_old.item.id}}" }
```

| Property | Required | Rules |
|---|---|---|
| `content_type` | yes | Defined type (`unknown_content_type`); the only type this step may trash |
| `record` | no (default: the bound record) | Exactly one typed reference: `{{record.id}}`, `{{steps.<id>.record_id}}` of a `get_record` or `create_record`, or `{{steps.<loop>.item.id}}` (`trash_target`). Its known type must equal `content_type` (`trash_type`). Without `record`, the trigger must bind a record of `content_type` (`automation_no_record`, `trash_type`) |
| `on_error` | no | `continue` (added to `ON_ERROR_TYPES`) |
| `retry` | no | As for other steps. The type default is no retries |
| Placement | | Not in a schedule automation with `for_each` (`trash_in_sweep`). Not in link steps (the existing `automation_link_step`). Allowed in loop bodies and branches |
| Output | | None. Summary `{ content_type, record_id, changed }`; `changed` is false when the record was already in the trash |

Why typed references only: a free template such as `{{trigger.payload.id}}` would let a webhook payload or form value name any record. A typed reference names a record the run found by a validated query, and the validator knows its type.

### 2.3 `start_automation`

```json
{ "id": "receipt", "type": "start_automation", "automation": "barn_gift_receipt" }
```

| Property | Required | Rules |
|---|---|---|
| `automation` | yes | Key of a defined automation (`unknown_automation`) whose trigger is `manual` (`start_target`). Not itself, and no cycle through other `start_automation` steps (`start_cycle`; the reference pass walks the start graph of `known['automation']`). A file automation may start only file automations (`start_target`: "a package automation cannot start a site automation", because the loader drops whole units) |
| `record` | depends | When the target's trigger has a `content_type`: a template giving one record ID, default the bound record when the bound type equals the target's type (else `missing_property`). A typed reference of another type is `start_target`. When the target has no `content_type`, `record` is refused (`start_target`) |
| `on_error` | no | `continue` |
| Placement | | Allowed anywhere except link steps (`automation_link_step`), loop bodies included |
| Output | | `started` (boolean): false when the target's `when` is false for the record or the depth cap skipped it. Summary `{ automation, record_id, started, run, skipped }` |

Typed output: `STEP_OUTPUTS['start_automation'] = [ 'started' => 'boolean' ]`.

### 2.4 Role recipients in `send_email.to`

```json
{ "id": "notify", "type": "send_email", "to": "{role:editor}, {option:barn_settings.volunteer_manager}", "...": "..." }
```

- A `to` part that is exactly `{role:<slug>}` (slug `^[a-z0-9_-]{1,64}$`) is a role recipient (`role_recipient` for a malformed token). Like option tags, role tags are read from the template and never from rendered values, so record data cannot name a role.
- The 5-part limit is unchanged (`too_many_recipients`). A role counts as one part at load time and is capped at runtime (section 3.5).
- Role tokens are refused in `reply_to` (`role_recipient`), `subject`, `text`, `html` and every other template. A role cannot be a reply address, and member lists are not rendered anywhere.
- Roles live in the database, so the registry does not check that the role exists. An unknown role fails at runtime (`unknown_role`, no retry).

### 2.5 Read query: `created`

```json
"created": { "before": { "date": "today", "offset": "-P2Y" } }
```

- `before`, `after` (at least one; `invalid_value`): a `Y-m-d` literal, `{ date, offset }` or `{ var }` resolving to a date. It compares the post's creation time (`post_date_gmt`) with the start of that day in site time (`before` is strict, `after` is inclusive of the day after; documented with examples).
- It is a property of the query model and not a filter field, because a content type may already define a field called `created` (only `id`, `status` and `type` are reserved keys).
- It counts as a source for `read_source`, so `find_records` with only `created` is valid.
- Both `get_record` and `find_records`. The same empty-operand rule applies: an empty `{ var }` matches nothing.

### 2.6 Manual trigger and bulk

There is no new trigger property. Bulk availability is derived: an enabled `manual` automation with a `content_type`, whose steps (and the steps of automations it starts, transitively) hold no `trash_record`. Steelman for an opt-in `"bulk": true`: authors choose. It was rejected because nothing breaks: the confirmation screen, the 50 cap and the per-record `when` already bound it, and an opt-in would be one more setting to explain.

### 2.7 JSON Schema, normalizer, fixtures

- JSON Schema: `wait` becomes `oneOf` (`for`, `until`); `step.type` gains `trash_record` and `start_automation`; `#/$defs/read_query` gains `created`. Cross-reference rules (field types, start targets, cycles, typed references, sweeps) are PHP-only and get `schema: false` fixtures.
- Normalizer: fills `until.at` (`09:00`), drops a zero `offset`, keeps `created` operands as written.
- Every existing fixture and `examples/sifg` keeps a byte-identical canonical form, except the deliberate `barn_gift_thanks` change in section 7.

### 2.8 Error codes

New at load time: `wait_source`, `wait_field`, `trash_target`, `trash_type`, `trash_in_sweep`, `unknown_automation`, `start_target`, `start_cycle`, `role_recipient`. Reused: `automation_no_record`, `invalid_value`, `loop_wait`, `automation_link_step`, `too_many_recipients`, `unknown_content_type`, `missing_property`.

New at runtime: `trash_limit`, `trash_disabled`, `start_limit`, `unknown_role`, `role_not_allowed`. Reused: `record_type`, `pegwork_record_not_found`, `automation_disabled`, `no_recipient`, `too_many_recipients`.

New in the save API: `automation_in_use` (409).

## 3. Engine changes

### 3.1 Core API (`Pegwork\Api`, 0.4.0)

- `Records::trash( int $id, array $origin = [] ): bool|WP_Error`:
  - Returns true when the record was moved, false when it was already in the trash, or an error: `pegwork_record_not_found` (not a registry record, or an auto-draft) or `trash_disabled` (`EMPTY_TRASH_DAYS` is 0 or false; never falls through to a delete).
  - Implementation: `RecordEvents::claim()` with the origin, then `wp_trash_post()`. Per `docs/events.md`, that emits `record.updated` (`changed` = `status`) and `record.status_changed` to `trash` with origin depth run depth + 1.
  - Callers authorize, as for every Records write.
- `Records::find()` and `RecordQuery`: `created { before, after }` with resolved literal dates, compiled into the same `WHERE` as `post_date_gmt` comparisons through `$wpdb->prepare()`, so `count` and `sum` stay exact.
- `docs/extension-api.md`, `docs/events.md` (the trash path) and `architecture.md` updated.

### 3.2 Date wait: cursor, resume, re-check

Handler `Steps\Wait` branches on `until`. `StepResult::wait()` gains a flag `stay` (resume at this step rather than the next). The executor stores `current_step` = the wait step when `stay` is set. This is the same storage a retry already uses (`$runs->wait( $id, $step_id, ... )`), so resume, the time-budget requeue, maintenance and `tick` need no change.

Evaluation, every time the cursor reaches the step (first arrival and every wake-up):

1. Bound record: `Records::get()` returns null for a deleted or trashed record. In that case the run ends `completed` with outcome `stopped_at:<id>`, summary `{ no_record: true }`.
2. Empty field: the run parks.
   - `context.until.<id>.since` (UTC, set on first arrival) fixes the horizon `since + P2Y`.
   - The run waits until the horizon (`stay`), summary `{ waiting_for_date: true }`.
   - At the horizon with the field still empty, it ends `stopped_at:<id>`, summary `{ no_date: true }`.
3. Target in the future: the run waits until `min( target, now + P2Y )` (`stay`), summary `{ until, resume_at }`. The `P2Y` clamp only splits very long waits; the next wake re-evaluates.
4. Target now or past: the step completes, `context.until.<id>` is removed, and the cursor continues at `next()`. Summary `{ until, due: true, late: <bool> }`, where `late` means the target had passed at first arrival.

Summary privacy: `until` reads "3 days before due_on at 09:00". The field's value is included only when the field is public, else `[private]`. `resume_at` is stored as for any wait (the run row already holds it, and the run log is administrators only).

Defined rules (the v3 staleness objection):

| Case | Behaviour |
|---|---|
| Already past at start | Continues at once. Authors who must not act on overdue records add a condition (`record.due_on gte today`), as the worked example does |
| Moved later during the wait | The listener wakes the run, the step re-evaluates and waits again. If the listener missed it (a direct `update_post_meta` that emits no event), the original wake-up re-evaluates and waits again |
| Moved earlier | The listener wakes the run now, and it waits until the new target or continues if that has passed. Without the listener (direct meta writes only), the run acts at the old time: documented as a known limit |
| Cleared | The listener wakes it, and it parks (rule 2). Setting a date later wakes it again |
| Record trashed or deleted | The listener wakes it (status is in `changed`) and it stops (rule 1) |

Change listener `Waits::nudge()`, subscribed to `pegwork_event_record_updated`:
- Index built once per request from the registry: for each effectively enabled automation, the `(content_type, field)` pairs of its `until` waits. If the event's type has none, or `changed` holds neither the field nor `status`, it returns with no query.
- Otherwise one indexed query: `SELECT id, automation, current_step FROM pegwork_runs WHERE record_id = %d AND status = 'waiting'`.
- For each run whose `current_step` is an `until` wait on a changed field (or `status` changed): `UPDATE ... SET resume_at = now WHERE id = %d AND status = 'waiting' AND current_step = %s`, then schedule `pegwork_automation_run` now.
- It never runs steps in the saving request (the Phase 4 rule holds). Runs of disabled automations are not nudged, because waking them would cancel them earlier than they would be cancelled otherwise.
- A run waiting for a retry at another step is untouched.

Limits: each re-evaluation is one logged attempt toward `MAX_STEPS` (600). A record edited hundreds of times during one wait could reach `step_limit`, which is visible and accepted. Attempt rows show "Checked the date again" in the run detail (`Format`).

Interaction with loops and links: none (forbidden by rule). Inside branches, the step works as any wait: the stored step id fixes the path.

### 3.3 `trash_record`

- Handler `Steps\TrashRecord`:
  - Resolves the reference, checks the record's type (`record_type`, no retry) and calls `Records::trash()`. `trash_disabled` and not-found are not retried.
  - Invalidates the read cache by ID (`Context::forget()`), so later reads and loop items treat the record as gone.
  - The bound record trashed mid-run: later `update_record` on it fails `pegwork_record_not_found` (Records refuses trashed records), and templates render its last cached values in the same wake-up only.
- Per-run cap: `context.trashed` counts records moved. The 51st fails `trash_limit`, no retry (`on_error` applies). The default is 50 (= one full loop); filter `pegwork_automation_trash_limit` may lower it or raise it to at most 200.
- Sweep refusal is double-checked at runtime (a definition from a newer registry): `trash_in_sweep` fails the step before it acts.
- Manual starts: `StartRoute::can_run()` and the bulk handler also require `current_user_can( 'delete_post', $record )` for non-administrators when `Manual::trashes( $key )` is true. That helper is the static closure over the automation's steps and every automation it starts.

### 3.4 `start_automation`

- Handler `Steps\StartAutomation` calls a new `Manual::chain( $key, $record, $parent_run, $step, $iteration )`. It shares `start()`'s checks (unknown or non-manual, `automation_disabled`, record missing, trashed or of another type, `when`) and calls a new `Triggers::chained()`:
  - Depth = parent run depth + 1. At or past `pegwork_automation_max_depth` (3), it inserts a `skipped` run with `loop_depth: ...` (as events do), and the step succeeds with `started: false`, summary `skipped: loop_depth`.
  - Payload: `trigger.user_id` (the parent's `trigger.user_id`, else 0) and `trigger.source` `automation`. `context.parent { run, automation, step }` is stored outside the payload (not in scope) for the run detail ("Started by run 412 of barn_gift_thanks"). The parent's step summary links the child run.
  - Idempotency key `start:<parent run>-<step>` plus `-<iteration>` inside loops. This wins over the target's own `idempotency`, as request IDs and delivery IDs already do.
  - Asynchronous: the child is queued and scheduled now, and the parent continues. A false `when` gives `started: false`, which is not a failure.
  - Disabled or unknown targets and record errors fail the step, with no retry (`on_error` applies).
- Per-run cap: `context.starts`, 50 by default (filter `pegwork_automation_start_limit`), else `start_limit`.
- The target's `allow` is not consulted, because the system starts it. The author of the parent is an administrator.

### 3.5 Role recipients

- `SendEmail::recipients()` resolves `{role:<slug>}`:
  - The role must exist (`unknown_role`) and hold `edit_posts`, unless filter `pegwork_automation_email_roles` (an allow-list of slugs) admits it (`role_not_allowed`).
  - Members come from `get_users( [ 'role' => $slug, 'fields' => [ 'user_email' ], 'number' => 26 ] )` on the current site, keeping addresses that pass `is_email()`.
  - More than 25 members gives `too_many_recipients`. No members gives `no_recipient`. Neither is retried.
- Delivery: one `wp_mail()` call. Explicit addresses go in `To` and role members in `Bcc` headers, deduplicated case-insensitively against `To`. With only role recipients, `To` is empty (PHPMailer accepts Bcc-only mail), so one call is still one retry unit, as today.
- Privacy: the summary `to` lists masked explicit addresses plus `role:editor (3)`, never member addresses. Error messages name the role, never members. Preview, test run and CLI `test` show the same count line.

### 3.6 Loops

- `trash_record` and `start_automation` work inside loop bodies. Iteration rows, per-item retry, the time-budget requeue and Retry from the log all resume at the same item, as today. Start keys carry the iteration, so a resumed loop never starts a child twice for the same item.
- The static bound treats both like any step (body steps count 50 times).
- Date waits stay forbidden in loops.

### 3.7 Dry run (`DryRun`)

- Wait until: "Wait before_due: until 3 days before Due on (March 20, 2027) at 09:00, that is March 17, 2027 9:00 am. When it wakes it checks the date again." Other forms: "Due on is empty: it would wait for a date (up to 2 years)" and "That time has passed: it continues at once." The walk continues past it.
- Trash: "Move to trash remove: Volunteer 88 "Sam" (not applied)." It counts toward the trash cap in the report. Later reads see the site as it is now (the existing "reads see the site as it is now" note covers it).
- Start: "Start barn_gift_receipt "Send receipt" for this Gift: it applies (not started)." Or "it does not apply (its Only when is false)", or "it would be skipped (depth limit)". The child is not dry-run. The line says "Test it from its own editor."
- Role email: "to role editor (3 people), m***@example.org".
- No side effects, unchanged: the counters on `pre_wp_mail`, `pre_http_request` and `pegwork_event` stay 0, posts and meta are unchanged, and no run rows are written.

### 3.8 Limits

| Limit | Value | Enforced | On exceed |
|---|---|---|---|
| `until.offset` | at most 2 years either way | validator | `invalid_value` |
| Date wait horizon (empty field) | 2 years from first arrival | engine | `stopped_at:<id>`, `no_date` |
| Single wait span | `P2Y`, then re-evaluated | engine | n/a |
| Trashes per run | 50 (filter, at most 200) | `context.trashed` | `trash_limit`, no retry |
| Trash in sweeps | none | validator and engine | `trash_in_sweep` |
| Starts per run | 50 (filter) | `context.starts` | `start_limit`, no retry |
| Start depth | existing cap 3 | `Triggers::chained()` | skipped run, `started: false` |
| Role members per email | 25 | `SendEmail` | `too_many_recipients` |
| Bulk records per request | 50 | bulk handler | refused, nothing started |
| Bulk requests | 1 hit of the existing 10 per user per minute bucket | `Throttle` | 429 notice |

## 4. Bulk "Run automation" on list tables

Class `Admin\BulkRun` (module, no JavaScript):

- Menu: on `edit.php?post_type=<type>` for each type that has a bulk-eligible automation the user may run, `bulk_actions-edit-<type>` adds "Run: <button>" (for example "Run: Send receipt").
  - Administrators see every eligible automation.
  - Other users see `allow: editors` ones only, and only if they hold the type's `edit_posts`.
- Step 1 (core's form, core's `bulk-posts` nonce): `handle_bulk_actions-edit-<type>` validates the IDs (integers, at most 50; more shows "Select at most 50 records" and starts nothing). It redirects to a hidden admin page `admin.php?page=pegwork-automation-bulk&automation=<key>&ids=<...>`, registered with `add_submenu_page( '', ...)` and the type's `edit_posts` capability.
- Step 2, the confirmation screen (standard `wrap` markup, `widefat striped` table with a caption and row headers, the same no-stacking pattern as the Automations list):
  - A heading names the action ("Run "Send receipt" on 12 Gifts?").
  - One row per record: title (only if the user can `read_post`), status, and "Will run", "Does not apply" (its `when`) or "You cannot run it on this record" (capability, including `delete_post` where trash applies).
  - A primary button "Run on 10 Gifts", and Cancel, which returns to the list with its filters.
  - Hidden `request` (random per page view) and nonce `pegwork_automation_bulk_<key>`.
- POST: nonce, capability and throttle (one hit of `automation|manual|<user>`). Then for each record that will run: `Manual::start( $key, $id, $user, 'bulk', $request . '-' . $id )`. A second submit of the same screen starts nothing new, because each key exists.
- Result: redirect to the list with a one-time notice ("Started 10 runs. 2 did not apply."). Administrators also get "View runs" (the run log filtered to the automation). Editors get no run links, as on the record editor today.
- Payload `trigger.source` gains `bulk`. `trigger_type` stays `manual`. The Automations list row adds "Staff can also run it on selected <plural>".

Steelman for core-only bulk (no confirmation screen): one fewer screen. Rejected because the record-editor button already confirms every start (`ConfirmDialog`). A bulk start that emails up to 50 donors needs at least the same safeguard, and the screen is where per-record "does not apply" becomes visible.

## 5. Editor additions

Everything stays inside the existing builder patterns: variations of `pegwork/automation-step`, inspector fieldsets with legends, specific button names, errors by JSON pointer, keyboard-only E2E, and the scope fixture mirrored by `AutomationBuilderScopeTest`.

### 5.1 Wait until

- The Wait inspector gains a radio group (legend "Wait"): "For a length of time" (today's control) or "Until a date".
- "Until a date" offers:
  - "Date field": the bound type's date fields; private ones badged.
  - "How long": a number and a unit, plus Before, On the day, After. These write `offset`.
  - "At": a time input, default 09:00.
- When the trigger binds no record of a known type, "Until a date" is disabled with the reason as text ("Needs a trigger with a record of one content type").
- Card: "Wait until 3 days before Due on, 09:00", followed by the help line "Checks the date again when it wakes. Waits for a date if it is empty."
- Changing a step's id still warns about waiting runs.

### 5.2 Move to trash

- Variation "Move to trash" (`trash_record`, id `trash`). It is not offered when the trigger is a sweep (inserter filter, as for Wait in loops). A pasted one shows `trash_in_sweep` at its card.
- Inspector:
  - Content type.
  - "Which record" (fieldset): "This <Type>", or a list of typed references in scope ("Volunteer from old", "Each Volunteer (in each_old)").
  - Help text: "Moves the record to the trash. WordPress deletes trashed items for good after 30 days (EMPTY_TRASH_DAYS). At most 50 per run."
- Card: "Move Each Volunteer (in each_old) to the trash", with a text warning "Removes records", never colour alone.

### 5.3 Start another automation

- Variation "Start another automation" (`start_automation`, id `start`).
- Inspector:
  - An "Automation" combobox of manual automations ("Send receipt: on each Gift", "Monthly report: no record"). File automations may only pick file automations; the others are disabled with the reason.
  - "For record" (template field with the merge-tag picker; empty shows "This Gift" as text).
  - Help: "It runs separately, as its own run. This automation does not wait for it."
- Card: "Start "Send receipt" for this Gift (runs separately)".
- The picker gains `steps.<id>.started` ("Started", boolean) for conditions.

### 5.4 Role recipients

- Beside "Insert merge tag" on the To field: "Add a role", a select of eligible roles by name. It inserts `{role:<slug>}`.
- Card: "Email to everyone with role Editor and Volunteer manager".
- The builder config (`registry_config()`) gains `roles: [ { slug, name } ]` (eligible roles only, names only, never members) and `automations: [ { key, label, content_type, source, trashes } ]` for the start picker.

### 5.5 Reads

The read inspector's "More options" gains "Created" with Before and After operands, reusing the date operand control from filter rows.

### 5.6 Size budget

- Current state: builder 27,014 bytes gzipped of a 27,136-byte budget (`architecture.md`, v3 PR 8). Estimate for v4: about 2.5 to 3.5 KB (four inspectors, three cards, scope entries, the roles and automations config readers).
- Decision: raise the budget by the measured minimum, recorded in each editor PR, capped at 30,720 bytes. This repeats the v3 practice ("raised by the minimum they needed").
- Steelman for lazy splitting (a dynamic `import()` of the step inspectors): a smaller first load. Rejected as the default:
  - The builder loads only for administrators on one screen, so 3 KB costs nothing noticeable.
  - Asynchronous inspectors add focus and announcement handling plus E2E waits, which put the keyboard guarantees at risk.
  - The split needs `__webpack_public_path__` from the localized config.
- Fallback: if the measured build passes 30,720 bytes, PR 9 moves `read-edit.js` and the new inspectors into one lazy chunk (per-chunk budget 8 KB) with a Spinner and a focus-preserving load.
- Bulk Run has no script. `automation-actions.js` (3 KB budget) is unchanged.

## 6. Security checklist

- Authoring is unchanged: `pegwork_manage_automations`, mapped to `manage_options`, with no filter to lower it. Only administrators write trash, start and role steps.
- Trash blast radius:
  - Trash only. `trash_disabled` refuses when trash is off, so there is never a permanent delete.
  - Typed targets only (`trash_target`) and an explicit `content_type`, checked at load time and at runtime.
  - 50 per run. No sweeps. A retention schedule therefore trashes at most 50 a day per automation.
  - Restorable from the type's Trash view for `EMPTY_TRASH_DAYS`. Run summaries list the IDs (never titles) so an administrator can find and restore them.
  - Site automations start disabled (existing rule), and the dry run lists every record it would trash.
  - Editors need `delete_post` for any automation that can trash, directly or through a start (`Manual::trashes()`).
- Start:
  - No privilege change: the child runs as the system, as every run does. Editors can only start what an administrator wrote, and a start step cannot widen what an editor may start, except through steps the administrator wrote.
  - Bounded by the depth cap 3, 50 starts per run, cycle refusal at load time, and idempotent child keys.
- Role addresses:
  - Bcc delivery; staff roles only (`edit_posts`, or an explicit filter allow-list); 25 per email.
  - Addresses never appear in logs, summaries, errors, previews, dry runs or the builder config.
  - Role tags are parsed from the template only, so data cannot redirect mail to a role.
  - Private record fields may go to role members, the same trust as any recipient the author writes. The picker keeps its "private" badges.
- Bulk:
  - Core `bulk-posts` nonce on step 1. Own nonce `pegwork_automation_bulk_<key>` on the POST; the confirmation GET changes nothing.
  - Per-record capability (`edit_post`, plus `delete_post` where trash applies; `manage_options` passes) and per-record `when`.
  - IDs are validated integers of the automation's type. Throttle hit per request, at most 50 records, per-record idempotency.
  - Titles are shown only to users who can `read_post`.
- Date wait listener: runs in any saving request but only moves `resume_at` of existing waiting runs to now and schedules a wake-up. It runs no steps and reads no private data. Its query is indexed (`record_id`), and the listener is skipped when no enabled automation waits on that type and field.
- Query safety: `created` operands are resolved in the module and passed as prepared values; field keys stay static.
- The run context and log hold IDs, counts and keys, never role members or read field values.

## 7. Testing plan

Fixtures (`tests/fixtures/valid/barn`, manifest 2.1.0, `requires.pegwork >=0.4.0`):
- New automations: `barn_task_due_reminder`, `barn_volunteer_retention`, `barn_gift_receipt`, `barn_volunteer_review` (worked examples below).
- `barn_gift_thanks` gains one `receipt` step. Its copies in `tests/fixtures/valid/reads` and `engine-loops` are updated (`FixturesTest` keeps them equal).
- `barn_volunteer_intake` and the other v3 automations stay byte-identical as the regression proof.
- `tests/e2e/packages/barn` is unchanged (types only).
- New shapes package `tests/fixtures/valid/v4` (`barn_*` keys; never loaded with `barn`, `reads`, `engine-reads` or `engine-loops`) covers every new shape, including a file-to-file start and `created` with `after`.

Registry, invalid fixtures (each asserts its code and pointer): `wait-for-and-until`, `wait-neither`, `wait-until-no-record` (schedule without `for_each`), `wait-until-untyped-event` (record event with no `content_type`), `wait-until-text-field`, `wait-until-read-path`, `wait-until-offset-3y`, `wait-until-bad-at`, `wait-until-in-loop`, `trash-free-template`, `trash-payload-id`, `trash-type-mismatch`, `trash-no-record`, `trash-in-sweep`, `trash-in-link`, `start-unknown`, `start-not-manual`, `start-self`, `start-cycle`, `start-record-for-no-record-target`, `start-type-mismatch`, `start-file-to-site`, `start-in-link`, `role-bad-slug`, `role-in-reply-to`, `role-in-subject`, `role-six-parts`, `created-empty`, `created-bad-operand`. Export and import stay byte-identical. The save API refuses deleting a started automation (409 `automation_in_use`).

Unit:
- `WaitUntilTest`: target from date, `at` and offset; DST in New York; negative and time-unit offsets; the `P2Y` clamp; the horizon.
- `RecordQueryTest` (extended): `created` before and after at day boundaries in site time; with `count` and `sum`.
- `PlanTest`: unchanged behaviour with the new types.
- `ManualTrashesTest`: the trash closure through start chains.

Integration (FrozenClock, FakeScheduler, mock PHPMailer):
- `AutomationWaitUntilTest`:
  - Each rule in section 3.2.
  - The listener skips unrelated fields (resume time unchanged, zero queries when no automation waits on the type).
  - Disabled automations are not nudged.
  - A retry-waiting run is untouched.
  - Time-budget requeue at the wait.
  - Private field masked in the summary.
  - `step_limit` under many edits.
  - Real WP-Cron wake in `AutomationWpCronTest`.
- `AutomationTrashTest`:
  - Bound, read and item targets; `record_type`.
  - `trash_disabled` with `EMPTY_TRASH_DAYS` 0, with the post still present.
  - Already trashed gives `changed: false`.
  - 51st gives `trash_limit`, with and without `on_error`.
  - Events at depth + 1; read cache invalidated.
  - Restore works.
  - Dry run: post and meta rows unchanged.
- `AutomationStartTest`:
  - Child run queued with depth + 1; payload and `context.parent`.
  - `started: false` on a false `when` and at the depth cap (a skipped run logged).
  - A disabled target fails.
  - Parent retry and interrupt Retry start no second child; loop iterations each start one.
  - `start_limit`; run detail links both ways.
  - Delete refusal.
- `AutomationRoleEmailTest`:
  - Bcc headers, empty To, deduplication with an explicit address.
  - 25 and 26 members; `no_recipient`; `unknown_role`; `role_not_allowed` for `subscriber`; the filter allow-list.
  - The summary holds no member address; preview and dry-run counts.
- `AutomationBulkTest`:
  - The menu per role; step 1 nonce; 51 IDs refused.
  - The confirmation table (will run, does not apply, cannot run, titles hidden without `read_post`).
  - POST nonce, throttle 429, per-record keys and resubmit dedupe.
  - Editors refused `delete_post` on trash-capable automations (which are not even listed); notice texts; 403 for subscribers.
- `AutomationManualTest` (extended): `can_run()` with `delete_post`.
- `AutomationDryRunTest` (extended): the four line kinds; the mail, HTTP and event counters stay 0.

Gate `BarnFlowsGateTest`, v4 timelines (site in New York; DST starts 2027-03-14):
- Due reminder (`barn_task_due_reminder`), tasks created 2027-03-01 10:00:
  1. T1 due 03-20 waits until 03-17 09:00 EDT (13:00 UTC). Nothing on 03-16 or at 08:59; one reminder at 09:00; `reminded_on` stamped.
  2. T2 due 03-12, moved to 03-07 on 03-05 10:00: nudged, and reminded at 10:00 that day.
  3. T3 due 03-10, moved to 03-31 on 03-05: nothing on 03-07; reminded on 03-28 09:00.
  4. T3b: the same move written by direct meta (no event): wakes 03-07, re-checks, reminded 03-28.
  5. T4 with no due date parks. Due set to 04-10 on 03-20: reminded 04-07 09:00.
  6. T5 due 03-20, cleared on 03-10: parked, no email. On 2029-03-01 10:00 it ends `stopped_at:before_due` (`no_date`).
  7. T6 due 03-02: target past, reminded at once.
  8. T7 imported with due 2026-12-01: continues, stops at `still_open`, no email.
  9. T8 marked done on 03-15: wakes 03-17 and stops at `still_open`.
  10. T9 trashed on 03-10: nudged and stops (`no_record`).
  11. A `record.updated` of T1's name leaves its `resume_at` unchanged.
- Retention (`barn_volunteer_retention`):
  1. Volunteers created 2027-05-03 (pending): 55 with stage new, 1 placed. One created 2027-06-05.
  2. On 2029-06-01 03:30 the first 50 are trashed by ID, the report says "50 of 55". On 06-02 the other 5 go. The placed one stays.
  3. On 2029-06-06 the 2027-06-05 volunteer goes.
  4. A second firing the same day creates nothing.
  5. With `EMPTY_TRASH_DAYS` 0 the first item fails `trash_disabled`, the run fails, and all rows remain.
  6. A restored volunteer can be read again.
- Receipt (`barn_gift_thanks` plus `barn_gift_receipt`):
  1. A gift of 300 on 2027-03-01 sends email A and, from a child run at depth 1, the receipt.
  2. The parent still waits until 03-31.
  3. A gift of 0 gives `started: false`, no receipt.
  4. The inbound path: thanks at depth 1, receipt at depth 2.
  5. A thanks run forced to depth 2 logs a skipped receipt run (`loop_depth`).
  6. Staff "Send receipt" and bulk on 3 gifts each send one receipt.
- Review alert (`barn_volunteer_review`): two editors, one administrator and one author exist. A sign-up sends one email, To the volunteer manager, Bcc both editors. The log shows `role:editor (2)`.
- The v3 timelines pass unchanged, except the added receipt emails in the donor timeline.

E2E, keyboard only, axe on the Pegwork parts, no horizontal scroll at 1280 and 782 px:
- `automation-builder-v4.e2e.js` on `tests/e2e/packages/barn`:
  - Builds `barn_task_due_reminder` ("Until a date", the disabled reason on a schedule trigger) and compares it with the fixture through `/pegwork-e2e/v1/barn/compare`.
  - Builds `barn_volunteer_retention` (Created before, Move to trash on the item; Move to trash absent from the inserter on a sweep and refused when pasted).
  - Adds "Start another automation" and "Add a role".
  - Test run lines for all four.
- `automation-bulk.e2e.js`: on Gifts, select 3 by keyboard, choose "Run: Send receipt", Apply, the confirmation screen (focus on its heading), Run, the notice. As editor and as administrator. Axe at both widths on the list and the confirmation screen.
- The builder E2E `automation-barn-flows.e2e.js` is updated for the `receipt` step in `barn_gift_thanks`.
- Vitest: round-trips of every v4 fixture through blocks; the scope fixture gains `started`, role tokens refused outside `to`, and the `created` operands. `npm run check:size` with the new number.

## 8. Versions, schema, migration

- `Pegwork\Api\VERSION` 0.4.0: `Records::trash()` is new and `Records::find()` accepts `created`. Packages using the new steps or `created` declare `requires.pegwork >=0.4.0`, so an older plugin refuses the package instead of failing mid-run (an old `Wait` handler would fail `invalid_duration` on `until`).
- Plugin 0.4.0 at sign-off (PR 10).
- `Schema::VERSION` stays 2: no column changes. New `context` keys (`until`, `trashed`, `starts`, `parent`) are additive JSON. Waiting runs from 0.3.0 resume unchanged.
- Registry format 1. Every existing canonical form is unchanged except the edited `barn_gift_thanks` fixture.
- Docs: `docs/automation.md` (new sections "Wait until a date", "Trash", "Start another automation", "Role recipients", "Bulk run", plus the runbook), `docs/registry-reference.md`, `docs/extension-api.md`, `docs/events.md`, `architecture.md` (per PR).

## 9. Non-goals

- Subflows with inputs and outputs: a synchronous child would need a parent cursor waiting on a child cursor. Nothing breaks with asynchronous start, because shared sequences (receipts, notices) do not return values.
- User record reads (`get_record` on users): they bring staff personal data into flows, and no BarnCRM flow needs a user's fields. Role recipients cover "send to the team".
- Full fault branches: `on_error: continue` plus a `decision` on `steps.<id>.failed` already routes a failure. Nothing breaks.
- Numeric arithmetic, mutable variables, formulas: there is no named need. `count` and `sum` are SQL outputs, and `| add` does date math. This stays "on a real need".
- The `{ var, offset }` condition operand (field plus duration compared with another field): no named need. Date waits and `{ date, offset }` cover the flows.
- Waits until a read record's date or a payload date: the change listener keys on the bound record. Per-record timing belongs in a run bound to that record (a record event or `start_automation`).
- Date waits in loops: unchanged v3 reasoning (stale snapshots, unbounded run length).
- Permanent delete, and trash in sweeps: trash plus `EMPTY_TRASH_DAYS` gives data minimisation with an undo window. Schedule, find and loop cover retention at 50 a day.
- Bulk Run for automations that trash: core bulk Trash exists with its own capability checks.
- Starting `event` or `schedule` automations: `manual` is the explicit "can be started" contract, and the others have their own triggers.
- Role recipients in `reply_to`, webhooks or templates, and email to a user by ID.
- Approvals, screen flows, SMS, AI steps, Action Scheduler, multisite: v3 reasons stand.

## 10. Open questions (recommended answers)

1. Default `until.at`. Recommend `09:00`, filled by the normalizer: a reminder at 00:00 (the schedule default) arrives overnight. The editor shows it.
2. Empty date: park (wait for a date, at most 2 years) or stop. Recommend park: tasks often get their due date after creation, and a stopped run would never remind. Parked runs show as open runs labelled "Waiting for Due on".
3. Moved-earlier detection relies on record events. Recommend accepting that direct `update_post_meta()` writes are missed (documented). Every Pegwork save path (REST, editor, Records, forms, imports through Records) emits events.
4. Role delivery in Bcc rather than To. Recommend Bcc: members do not see each other's addresses, and one `wp_mail()` stays one retry unit. Revisit if a pilot needs reply-all among moderators.
5. Role eligibility. Recommend `edit_posts` plus the `pegwork_automation_email_roles` allow-list filter, so an automation can never mass-mail subscribers.
6. Trash per-run cap of 50, filter capped at 200. Recommend yes.
7. Trash in sweeps refused outright rather than opt-in. Recommend refusing: nothing breaks, and an opt-in flag invites exactly the misconfigured sweep v3 warned about.
8. Editors and trash: require `delete_post`. Recommend yes. It matches WordPress semantics and applies transitively through starts.
9. Child idempotency key wins over the target's own `idempotency`. Recommend yes, consistent with request and delivery IDs. A target that must run once per record can add a condition on a stamp.
10. Deleting an automation another one starts: refuse (409 `automation_in_use`). Recommend yes, because otherwise the loader silently drops the caller.
11. Bulk through a confirmation screen. Recommend yes (parity with `ConfirmDialog`). One throttle hit per request. Recommend yes, because the screen makes each request deliberate.
12. Builder budget. Recommend a measured raise capped at 30,720 bytes, with lazy splitting only as the fallback (section 5.6).
13. Changing `barn_gift_thanks` (adding `receipt`) rather than adding a separate caller. Recommend changing it: it is the real shared-sequence case. `barn_volunteer_intake` remains the byte-identical regression proof.
14. `created` as a query property rather than a pseudo-field. Recommend the property, because `created` is not a reserved field key.
15. Historical imports with `barn_task_due_reminder` enabled: past-due tasks continue at once and stop at `still_open`. Recommend keeping the condition in the fixture and the "disable while importing" runbook note from v3.

## 11. PRs (each merges with CI green)

1. **Core: `Records::trash()` and `created` reads.**
   - Contents: `Records::trash()` with origin and the `EMPTY_TRASH_DAYS` refusal, `RecordQuery` `created`, API 0.4.0, `docs/extension-api.md`, `docs/events.md`.
   - Accept: `RecordQueryTest` with `created` (with count and sum); trash events at origin depth; `trash_disabled` leaves the post; the module-boundary check; every existing test.
2. **Registry: v4 shapes.**
   - Contents: JSON Schema, `AutomationRules` (wait `until`, `trash_record`, `start_automation` with the start graph, file-to-site and cycles, role tokens, `created`), Normalizer, `tests/fixtures/valid/v4`, the invalid fixtures, the scope fixture, `automation_in_use` in the save API, `docs/registry-reference.md`.
   - Accept: the fixture agreement job; existing canonical forms and `examples/sifg` byte-identical; export and import round-trip `v4`. Starts after PR 1 (it uses `RecordQuery` constants); it may be drafted in parallel and rebased.
3. **Engine: wait until.**
   - Contents: `Wait` `until`, `StepResult` `stay`, the executor storing the wait step, `context.until`, `Waits::nudge()`, `Format` row labels, DryRun lines, docs.
   - Accept: `WaitUntilTest`, `AutomationWaitUntilTest`, the real WP-Cron wake, the zero-query listener check; `StalenessCycleGateTest` and the v3 `BarnFlowsGateTest` unchanged.
4. **Engine: trash.**
   - Contents: `TrashRecord`, limits, the runtime sweep guard, `Manual::trashes()`, `delete_post` in `can_run()`, DryRun, docs.
   - Accept: `AutomationTrashTest`, `ManualTrashesTest`, the extended `AutomationManualTest`.
5. **Engine: start another automation.**
   - Contents: `StartAutomation`, `Manual::chain()`, `Triggers::chained()`, `context.parent`, run detail links, DryRun, docs.
   - Accept: `AutomationStartTest`.
6. **Engine: role recipients.**
   - Contents: `SendEmail` role resolution and Bcc, the filter, masking in summaries, previews and dry runs, `registry_config()` roles, docs.
   - Accept: `AutomationRoleEmailTest`; `AutomationEmailTest` unchanged.
7. **Bulk Run.**
   - Contents: `Admin\BulkRun` (menu, step 1, confirmation page, POST, notices), the list row text, source `bulk`, docs and screenshots.
   - Accept: `AutomationBulkTest`; `automation-bulk.e2e.js` with axe at 1280 and 782 px. Needs PR 4 for the trash exclusion (or lands first with the check stubbed to the step type name and tightened in PR 4).
8. **Editor A: wait until and role recipients.**
   - Contents: the Wait inspector radio, the date controls and card, "Add a role", config readers, scope fixture cases.
   - Accept: Vitest round-trips; `AutomationBuilderScopeTest`; the E2E part building `barn_task_due_reminder`; size recorded.
9. **Editor B: Move to trash, Start another automation, Created.**
   - Contents: variations, inspectors, inserter rule for sweeps, the start picker, the Created operands; the budget raise (or the lazy-split fallback).
   - Accept: the E2E part building `barn_volunteer_retention`; the pasted-trash refusal with focus; axe; size check.
10. **BarnCRM proof v4 and release 0.4.0.**
    - Contents: the barn fixture 2.1.0 and README, the updated copies, the gate timelines of section 7, the `automation-barn-flows.e2e.js` update, the runbook (`run --record`, `tick --now`, retention with `run --now`, bulk on Local), screenshots, plugin 0.4.0.
    - Accept: every timeline; the keyboard-built automations equal the fixtures; the runbook checked once by hand on Local.

Parallelism:
- PR 1, then PR 2.
- After PR 2: PRs 3, 4, 5 and 6 can run in parallel. They touch separate handlers. Their shared touch points are the handler registration in `Engine::__construct()`, `DryRun` and `docs/automation.md`, and they rebase in merge order.
- PR 7 can run in parallel with PRs 3 to 6, after PR 4 or with the stub noted above.
- PR 8 can start after PR 2, in parallel with the engine PRs (Test run lines arrive as the engine PRs land). PR 9 follows PR 8, so the size accounting is sequential.
- PR 10 is last.

## Worked examples: BarnCRM flows (test fixtures)

These use the existing types `barn_contact`, `barn_gift`, `barn_task` and `barn_volunteer`, the options page `barn_settings`, the relationship `gift_donor` and the form `barn_volunteer_signup`.

### (a) Wait until: remind 3 days before a task is due

`automations/barn_task_due_reminder.json`

```json
{ "format": 1, "key": "barn_task_due_reminder", "label": "Task: remind the assignee 3 days before it is due",
  "trigger": { "type": "event", "event": "record.created", "content_type": "barn_task" },
  "steps": [
    { "id": "before_due", "type": "wait", "until": { "var": "record.due_on", "offset": "-P3D", "at": "09:00" } },
    { "id": "still_open", "type": "condition", "when": { "all": [
      { "var": "record.done", "op": "ne", "value": true },
      { "var": "record.due_on", "op": "gte", "value": { "date": "today" } } ] } },
    { "id": "remind", "type": "send_email", "to": "{{record.assignee_email}}",
      "subject": "Due {{record.due_on | date}}: {{record.name}}",
      "text": "{{record.name}} is due {{record.due_on | date}}. Mark it done when finished: {{record.edit_url}}" },
    { "id": "stamp", "type": "update_record", "values": { "reminded_on": "{{today}}" } } ] }
```

Why it works:
- One run per task holds the whole timeline. Editing the due date earlier, later or to empty is honoured by the listener and the re-check, so the v3 sweep recipe is no longer needed for this case (it stays documented).
- `still_open` keeps imported overdue tasks and finished tasks quiet.
- The Monday digest (`barn_tasks_digest`) stays the overview. This is the per-task nudge.

### (b) Trash: remove volunteer applications older than two years

`automations/barn_volunteer_retention.json`

```json
{ "format": 1, "key": "barn_volunteer_retention", "label": "Remove volunteer applications older than two years",
  "trigger": { "type": "schedule", "every": "day", "at": "03:30" },
  "idempotency": "retention:{{today}}",
  "steps": [
    { "id": "old", "type": "find_records", "content_type": "barn_volunteer",
      "status": [ "draft", "pending", "private", "publish" ],
      "created": { "before": { "date": "today", "offset": "-P2Y" } },
      "filter": { "all": [ { "field": "stage", "op": "ne", "value": "placed" } ] },
      "sort": { "field": "id", "order": "asc" }, "limit": 50 },
    { "id": "any_old", "type": "condition", "when": { "var": "steps.old.count", "op": "gt", "value": 0 } },
    { "id": "each_old", "type": "loop", "over": "steps.old.records",
      "steps": [
        { "id": "remove", "type": "trash_record", "content_type": "barn_volunteer", "record": "{{steps.each_old.item.id}}" } ] },
    { "id": "report", "type": "send_email", "to": "{option:barn_settings.volunteer_manager}",
      "subject": "{{steps.each_old.count}} volunteer applications moved to the trash",
      "text": "{{steps.each_old.count}} of {{steps.old.count}} applications older than two years (not placed) were moved to the trash today. At most 50 go each day. Restore one from Volunteers > Trash within 30 days if it was a mistake." } ] }
```

Why it works:
- `created` selects applications made before the automation existed.
- The form writes volunteers as `pending`, so the statuses are listed explicitly.
- The schedule (not a sweep) is allowed to trash, at most 50 a day, so 55 old records take two days.
- WordPress empties the trash after `EMPTY_TRASH_DAYS`, which completes the data minimisation with an undo window.
- Form entries have their own `retention_days` (`docs/forms.md`), so the records and the entries are both covered.

### (c) Start another automation: one shared receipt

`automations/barn_gift_receipt.json`

```json
{ "format": 1, "key": "barn_gift_receipt", "label": "Send the receipt for a gift",
  "trigger": { "type": "manual", "content_type": "barn_gift", "button": "Send receipt", "allow": "editors",
    "when": { "var": "record.amount", "op": "gt", "value": 0 } },
  "steps": [
    { "id": "donor", "type": "get_record", "content_type": "barn_contact",
      "related": { "relationship": "gift_donor", "of": "{{record.id}}" } },
    { "id": "has_donor", "type": "condition", "when": { "var": "steps.donor.found", "op": "eq", "value": true } },
    { "id": "send", "type": "send_email", "to": "{{steps.donor.record.email}}",
      "reply_to": "{{option:barn_settings.development_email}}",
      "subject": "Your receipt from {{site.name}}",
      "text": "Dear {{steps.donor.record.name}},\n\nReceipt for your gift of ${{record.amount | number:2}} received on {{record.received_on | date}}.\n\nNo goods or services were provided in exchange for this gift.\n\n{{site.name}}" } ] }
```

`barn_gift_thanks` gains one step after `stamp_donor`:

```json
{ "id": "receipt", "type": "start_automation", "automation": "barn_gift_receipt" }
```

Why it works:
- The receipt text lives in one automation. The donor flow starts it for every new gift (the inbound webhook path included, through the gift's `record.created`).
- Staff resend it with the gift's "Send receipt" button, or with bulk Run for several gifts.
- The thanks run continues to its 30-day wait without waiting for the receipt.
- Retrying or resuming the thanks run never sends a second receipt (key `start:<run>-receipt`).

### (d) Role recipients: every editor reviews new volunteer applications

`automations/barn_volunteer_review.json`

```json
{ "format": 1, "key": "barn_volunteer_review", "label": "Volunteer application: tell every reviewer",
  "trigger": { "type": "event", "event": "form.submitted", "form": "barn_volunteer_signup" },
  "steps": [
    { "id": "notify", "type": "send_email", "to": "{role:editor}, {option:barn_settings.volunteer_manager}",
      "subject": "Volunteer application to review: {{record.name}}",
      "text": "{{record.name}} applied to help with {{record.interest}}. Review it here: {{record.edit_url}}" } ] }
```

Why it works:
- Whoever holds the Editor role when the email is sent receives it, so adding or removing a moderator is a WordPress user change, with no automation or options edit.
- Members are in Bcc, so they do not see each other's addresses. The log shows `role:editor (2)` only.
- A site can use a custom staff role (for example `barn_reviewer` with `edit_posts`) in place of `editor`. The fixture uses `editor` to stay portable.

### (e) Bulk Run: resend receipts for a batch of gifts

There is no new automation; `barn_gift_receipt` is bulk-eligible because it is manual, has a `content_type` and trashes nothing. Staff open Gifts, filter to last week, tick 12 gifts, and choose "Run: Send receipt" and Apply. The confirmation screen lists 10 "Will run" and 2 "Does not apply" (amount 0). After Run, 10 runs start (`trigger.source` `bulk`), one receipt goes to each donor, and the notice says "Started 10 runs. 2 did not apply." Submitting the same screen again starts nothing.

### (f) Volunteer intake

`automations/barn_volunteer_intake.json` is unchanged and remains the byte-identical regression proof.

### Critical Files for Implementation
- modules/automation/src/Executor.php (wait `stay` cursor, around lines 239 to 244)
- modules/automation/src/Steps/Wait.php
- modules/automation/src/Manual.php and modules/automation/src/Triggers.php (`staff()` hard-codes depth 0; `start_automation` needs a new `chained()` at run depth + 1)
- modules/automation/src/Steps/SendEmail.php (`recipients()`, role tokens, Bcc)
- src/Registry/Rules/AutomationRules.php (`STEP_TYPES`, `ON_ERROR_TYPES`, `automation_step()`, `automation_references()`)
- src/Api/Records.php (new `trash()`)
- modules/automation/src/StartRoute.php (`can_run()` needs `delete_post` for automations that can trash)
- src/Registry/Loader.php (drops units with unresolved references, which is why `automation_in_use` and the file-to-site rule exist)
