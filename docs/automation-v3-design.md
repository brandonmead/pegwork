# Pegwork: Automation v3 (reads) Technical Design

Status: build-now set approved by the main Claude session 2026-10-23 (recommended answers to section 10 adopted); Brandon reviews later, including whether any "later" rows in section 1 move to build now. The Salesforce Flow, Zapier and Make cells in section 1 are from general knowledge and were not checked against vendor docs. Scope: cross-record reads in the automation engine and registry, the smallest set of companions that make reads useful (relationship writes, bounded loops, a manual trigger, continue-on-error), and their editor UI. Brandon asked for "a full featured automation platform with reads" and overruled Phase 6 open question 2 ("defer `get_record` to Phase 5"): reads are in scope now. Builds on `docs/phase-4-design.md` (engine, limits, scheduler), `docs/phase-6-design.md` (`decision`, `Plan`, the block-editor builder, dry run), `docs/automation.md` (as built), `docs/relationships.md` and `docs/registry-reference.md`. Checked against `main` 0ebb67f.

Goal used for every verdict: BarnCRM-class nonprofit workflows (donors, gifts, volunteers, tasks) run reliably on shared hosting with WP-Cron and a server cron, and non-developers can edit them in the block-editor builder.

## 0. Key decisions

- Reads are explicit steps, never hidden in paths. `get_record` (one record) and `find_records` (many, with `count` and `sum`) share one query model: `related`, `filter`, `status`, `sort`. Inline traversal such as `{{record.related.gift_donor.0.name}}` is never built (section 1, row 3).
- Read outputs extend the existing scope rule: `steps.<id>.record.<key>`, `steps.<id>.count`, `steps.<id>.sum.<field>`, and in a loop `steps.<loop>.item.<key>`. The validator knows each output's content type, so field, type and privacy checks apply to read records exactly as they do to `record.*`.
- Records are references and numbers are snapshots. Run context stores read results as record IDs plus `count`, `sum` and `found`. Records are re-read through `Records::get()` on each wake-up, as the bound record already is, so a read after a 30-day wait sees current values and no private values are copied into `pegwork_runs.context`.
- Counts and sums come from SQL over every match, not from a capped list. There are no `| count` or `| sum` filters and no in-memory collection filtering, because they would give wrong totals once matches pass the limit.
- One `loop` step, deliberately narrow. It iterates only a `find_records` result, at most 50 items. It may not hold a `wait` or another `loop`. A false `condition` inside a loop skips that item instead of ending the run. The cursor is still `current_step` plus a small `context.loops` entry. Waits inside loops are forbidden by rule, not by mechanism (section 3.4).
- Relationships become writable: relationship keys in `create_record` and `update_record` `values` (replace the set), and a `relate` step (add or remove one link). Core `Pegwork\Api\Records` gains the reads and link writes; the module still uses only `Pegwork\Api`.
- Declarative language, no expressions. New template filters: `| add:"P3D"` (date math) and `| number` (formatting), plus one block form `{{#each}}...{{else}}...{{/each}}` for listing records in an email. There is no formula language, no mutable variables and no eval.
- Small companions: a `manual` trigger (a Run button on the record editor and the list), step property `"on_error": "continue"` with `failed` and `error` outputs, and `call_webhook.record` to send a read record's public fields.
- Registry format stays 1, and every change is additive. `Pegwork\Api\VERSION` goes to 0.3.0, because `Records` gains methods and now accepts relationship keys (section 3.6). The automation tables go to `Schema::VERSION` 2, which adds one nullable column.

## 1. Gap analysis

| Capability | Salesforce Flow | Zapier / Make | Pegwork today | Verdict and reason |
|---|---|---|---|---|
| Get one record by id, field or relationship | Get Records, "first record only"; lookup fields | Zapier "Find" search actions per app; Make "Search" modules with limit 1 | Bound record only | **Build now** (`get_record`). Without it the gift cannot reach its donor: every gift carries copies of the contact's name and email (`donor_*` stand-ins), which drift when the contact changes, and webhook imports cannot find an existing contact by email |
| Find many records with filters, sort, limit | Get Records, all records, filter and sort | Zapier searches return one item for most apps; Make search modules with a limit | Schedule `for_each` sweep only (whole type, `where` in PHP) | **Build now** (`find_records`, limit 50). Without it there is no "is this the donor's first gift?" and no digest; the donor flow stays two automations with its same-day known limit |
| Relationship traversal in templates and conditions | Cross-object references (`$Record.Account.Name`) | No; a search step comes first | None | **Build now, through read step outputs** (`{{steps.donor.record.email}}`). Inline `record.related.*` is **never**: each tag would be a hidden query with no limit, log row or dry-run line, and link sets are unordered, so `.0` has no stable meaning |
| Loops over found records with hard caps | Loop element (bounded by governor limits) | Looping by Zapier (500 iterations); Make Iterator | None (Phase 6 non-goal) | **Build now, restricted**: over a `find_records` result, at most 50 items, no wait, no nested loop. Without it, event-driven fan-out (each overdue task, each linked record) needs a separate sweep per case, and no run can report what it did for all of them |
| Variables, assignment, formulas (counts, sums, date math) | Assignment element, formula resources | Zapier Formatter (numbers, dates), Storage; Make variables and functions | `today`, `now`; date offsets in conditions | **Partly now**: `| add:"<duration>"` for dates and `| number` for display. Counts and sums are `find_records` outputs. Mutable variables and a formula language are **never**: scope stops being static, it adds a second grammar to secure, and no BarnCRM flow needs one. Numeric arithmetic comes **later**, on a real need |
| Delete or trash a record | Delete Records | App delete actions | None | **Later**, trash only, never permanent delete. No proof needs it; a status or stage field covers retention; the risk is a misconfigured sweep that trashes 200 records a day |
| Relate and unrelate records | Set a lookup; create junction records | App-specific | Links editable only through REST, CLI and the picker; `Records` cannot write them | **Build now**: relationship keys in `values` and a `relate` step. Without it a gift or task created by a run has no links, so the next flow's reads find nothing, and a `required` relationship blocks `create_record` of any non-draft record |
| Error and fault paths | Fault connectors | Zapier custom error handling; Make error handler routes (Resume, Ignore, Break) | Retries, then the run fails; run log and failure counts | **Build now, minimal**: `"on_error": "continue"` with outputs `failed` and `error`, so a later `decision` can route. Without it a Mailchimp outage also stops the thank-you task. Full fault branches come **later** |
| Wait until a date field or relative to one | Scheduled paths; Wait elements | Zapier Delay Until; Make Sleep (short) or scheduling | `wait` with a duration; daily sweep with a today-relative `where` | **Later**. The sweep recipe (`due_on eq today + P3D`, keyed by `{{record.id}}:{{record.due_on}}`) already does "3 days before" and corrects itself when the date is edited, whereas a stored resume time goes stale |
| Subflows with inputs | Subflow element | Zapier Sub-Zaps; Make scenario calls | Record events chain automations (depth cap 3) | **Later**. Nothing breaks: shared steps repeat in a few flows. A synchronous child run with outputs would double the cursor problem |
| Manual or on-demand trigger | Button, quick action, screen or autolaunched flow | Zapier manual runs and Interfaces buttons; Make "Run once" | Run now for schedules; `run --record` (CLI) for event flows | **Build now** (`manual` trigger). Without it staff cannot resend a receipt or start a flow for one contact without shell access |
| User and role reads, send to role | Get Records on User; groups and queues | App-specific | Addresses from records and options pages | **Later**. Options page fields cover the proofs; user reads bring staff personal data and role churn into flows |
| Aggregate reads (count, sum of related records) | Roll-up summary fields; loop plus assignment | Zapier line-item sums; Make numeric aggregator | None | **Build now**, as `find_records` `count` and `sum`, in SQL. Without it there is no lifetime giving, no first-gift check, no "N tasks due" |
| Collection filtering and sorting | Collection Filter, Collection Sort | Formatter utilities; Make array functions | None | **Never**: filter and sort in the query. A second filter over a capped list is wrong once matches pass the cap |
| Approvals | Approval processes | Zapier Human in the Loop; Make none built in | Signed links, `wait`, `condition` | **Never as a feature**: an approve link sets a field, the run waits and checks it. Document the recipe |
| Screen flows | Screen flows | Zapier Interfaces | Forms module (steps, confirm) | **Never**: forms are the data-entry surface |
| SMS and other channels | Messaging add-ons | SMS by Zapier, Twilio; Make Twilio | `call_webhook` | **Never in core**: paid gateways and stored credentials. `call_webhook` to n8n or a gateway covers it |
| AI steps | Einstein and Agentforce actions | AI by Zapier, OpenAI apps; Make AI modules | None | **Never**: no paid AI API unattended (Brandon's rule), and no free model runs on shared hosting |

### Build-now set: steelman for leaving each out

| Capability | Strongest case for leaving it out | Why build anyway |
|---|---|---|
| `get_record` | Phase 5 could denormalize contact fields onto gifts at save time | Copies go stale, every new field needs a copy, and webhook imports still need "find by email" |
| `find_records` | Sweeps already query whole types | Sweeps start one run per record; nothing can ask "how many gifts has this donor made?" inside a run |
| Read outputs in templates | A read step is more clicks than an inline path | One explicit, logged, limited, dry-run-visible read per record, and the picker hides the path length |
| `loop` | Sweeps fan out per record already; `{{#each}}` lists records in one email | Sweeps are schedule-only. Event-driven fan-out ("this contact opted out: close their open tasks") and "act on each, then summarize" need one run. The limits keep it bounded |
| Relationship writes | Staff can link records by hand | Run-created records are then invisible to every read, and `required` relationships block `create_record` |
| `on_error: continue` | Failed runs already show in the log | A failed optional step (CRM sync) should not cancel the essential ones (receipt, task) |
| `manual` trigger | `wp pegwork automation run --record` exists | BarnCRM staff are not shell users; "Resend receipt" is a weekly request |
| Count and sum | A loop could add up amounts | That needs mutable variables, and it is wrong past 50 gifts |
| `| add`, `| number` | Staff can set due dates by hand; donors can read 1250.5 | A task due "today" for a call that needs two days; "$1250.5" in a donor letter |

## 2. Requirements trace (proofs and what breaks without each element)

| Element | Needed by | Without it |
|---|---|---|
| `get_record` by relationship | Gift thanks, milestone, resend | `donor_*` stand-ins stay |
| `get_record` by filter | Donation webhook (find contact by email) | Duplicate contacts per donation |
| `find_records` `count` | One-automation donor flow (first gift; second gift within 30 days) | Two automations plus `first_gift_on` stamps, and the same-day limit |
| `find_records` `sum` | Lifetime giving milestone | No milestone |
| `loop` | Weekly task digest (remind each, then summarize) | One sweep for reminders plus a separate digest that cannot know what was sent |
| `{{#each}}` | Digest summary | A summary with only a count |
| Relationship keys in `values` | Call task linked to its gift; gift linked to its donor on import | Unlinked records |
| `relate` | Adding one link to a many-to-many set without replacing it | Replace semantics would drop existing links |
| `on_error` | Digest: one task without an assignee email must not fail the whole digest | One bad row stops every reminder |
| `manual` trigger | "Resend thank-you" on a gift | CLI only |
| `| add` | Call task due in two days; digest "due by" | Due today |
| `call_webhook.record` | Send the donor (not the gift) to a mailing-list sync | Only bound-record fields leave the site |

## 3. Registry shape (format 1, additive)

### 3.1 Read query model (shared by `get_record` and `find_records`)

| Property | Required | Default | Rules |
|---|---|---|---|
| `content_type` | yes | | defined type (`unknown_content_type`); the type of the records read |
| `related` | no | | `{ relationship, of }`. `relationship` is defined (`unknown_relationship`) and has `content_type` at one end (`relationship_end`). `of` is a template that renders a record ID at the other end. From a "from" record it reads that record's linked "to" records; from a "to" record it reads the "from" records that link to it |
| `filter` | no | | `{ "all": [leaf...] }` or `{ "any": [leaf...] }`, one level, 1 to 10 leaves (`condition_too_complex`). Leaf `{ field, op, value }`: `field` is a field or taxonomy key of `content_type`, or `id` (`unknown_field`); `rich_text`, `textarea` and `post_content` fields cannot be filtered (`filter_field`). `op` and `value` follow the condition rules (operators by type, literals typed, `condition_type_mismatch`); `value` is a literal, `{ var }` (any run path in scope) or `{ date, offset }`. Not `changed` |
| `status` | no | `["private", "publish"]` | any of `publish`, `future`, `draft`, `pending`, `private`. Trash and auto-drafts are never read |
| `sort` | no | `{ "field": "id", "order": "asc" }` | `field`: `id`, or a `text`, `number`, `date`, `select`, `email` or title-storage field; `order` `asc` or `desc` |

Runtime rules:
- An operand that resolves empty (a `{ var }` with no value) makes the whole read match nothing. It reports `found: false` or `count: 0` and the summary `empty_operand: <field>`. A `get_record` by email with no email in the payload therefore never matches every record with an empty email. To look for empty values, use the `empty` operator.
- `ne` and `not_in` also match records with no stored value, so `done ne true` includes tasks never marked.
- An `of` record that is not at the relationship's other end, or does not exist, means no match. It is not a failure.

### 3.2 New steps

`get_record`: one record (the first by `sort`).

```json
{ "id": "donor", "type": "get_record", "content_type": "barn_contact",
  "related": { "relationship": "gift_donor", "of": "{{record.id}}" } }
```
```json
{ "id": "contact", "type": "get_record", "content_type": "barn_contact",
  "filter": { "all": [ { "field": "email", "op": "eq", "value": { "var": "trigger.payload.email" } } ] } }
```
```json
{ "id": "gift", "type": "get_record", "content_type": "barn_gift", "record": "{{trigger.payload.gift_id}}" }
```

| Property | Rules |
|---|---|
| query model | section 3.1 |
| `record` | template giving one ID. Exclusive with `related` and `filter` (`read_source`); without `record`, at least one of `related` or `filter` (`read_source`) |
| Outputs | `found` (boolean), `record_id` (number or empty), `record` (a record of `content_type`: `steps.<id>.record.<key>` for fields, taxonomies, `id`, `status`, `type`; templates also `permalink`, `edit_url`) |

`find_records`: up to `limit` records, plus totals over every match.

```json
{ "id": "giving", "type": "find_records", "content_type": "barn_gift",
  "related": { "relationship": "gift_donor", "of": "{{steps.donor.record_id}}" },
  "sort": { "field": "received_on", "order": "desc" }, "limit": 0, "sum": [ "amount" ] }
```

| Property | Rules |
|---|---|
| query model | section 3.1; at least one of `related` or `filter` (`read_source`), so "read the whole type" is never an accident |
| `limit` | integer 0 to 50, default 10 (`invalid_value`). 0 loads no records (totals only) |
| `sum` | list of `number` field keys (`filter_field` for other types), at most 5 |
| Outputs | `count` (number of all matches), `records` (collection of `content_type`, at most `limit`; usable only as a loop's `over` and in `{{#each}}`, else `invalid_var`), `sum.<field>` (number, 0 with no matches) |

`loop`: runs its steps once per record of a collection.

```json
{ "id": "each_task", "type": "loop", "over": "steps.due.records",
  "steps": [ { "id": "remind", "type": "send_email", "...": "..." } ] }
```

| Property | Rules |
|---|---|
| `over` | a `steps.<id>.records` path of a `find_records` step in scope (`invalid_var`). A path, not a template |
| `steps` | step list (may hold decisions), 1 to 10 steps counting nested ones (`invalid_value`). No `wait` at any depth (`loop_wait`), no `loop` (`loop_nested`) |
| no `retry`, no `on_error` | `unknown_property` |
| Outputs | inside the body: `item` (a record of the collection's type: `steps.<loop>.item.<key>`) and `index` (1-based number). After the loop: `count` (iterations run) |

`relate`: adds or removes one link.

```json
{ "id": "link_gift", "type": "relate", "relationship": "task_gift", "action": "add",
  "from": "{{steps.call_task.record_id}}", "to": "{{record.id}}" }
```

| Property | Rules |
|---|---|
| `relationship` | defined (`unknown_relationship`) |
| `action` | `add` (default) or `remove` |
| `from`, `to` | templates giving one ID each. `from` defaults to the bound record when the bound type is the relationship's `from` type (else `missing_property`) |
| Output | none. Summary `{ relationship, action, from, to, changed }` (`changed` false when the link already existed or was already absent) |

### 3.3 Additions to existing shapes

- `values` in `create_record` and `update_record` accept relationship keys whose `from` is the written type (`unknown_field` otherwise). A value is a template rendering one ID or comma-separated IDs, or a list of integers. It replaces the record's links (REST semantics; an empty value clears them).
- `update_record.record` that is exactly `{{steps.<id>.record_id}}`, `{{steps.<loop>.item.id}}` or `{{record.id}}` now has a known type, so `values` keys are checked (`unknown_field`), where today they are unchecked.
- `call_webhook` gains `record` plus `content_type`, both or neither (`missing_property`). The body's `record` is that record; `fields` are checked against `content_type` and stay public only (`automation_private_field`). At runtime a record of another type fails with `record_type` (no retry).
- Any `update_record`, `create_record`, `send_email`, `call_webhook`, `relate`, `get_record` or `find_records` step may set `"on_error": "continue"` (other values `invalid_value`). After its retries, a failure logs the step `failed`, sets outputs `failed: true` and `error: "<code>"`, and the run continues. A step with `on_error` also outputs `failed: false` on success. The run ends `completed` with outcome `completed_with_errors:<ids>`, which counts in the list's "failures in the last 7 days".
- Trigger `manual`:

```json
{ "type": "manual", "content_type": "barn_gift", "button": "Resend thank-you", "allow": "editors",
  "when": { "var": "record.amount", "op": "gt", "value": 0 } }
```

| Property | Rules |
|---|---|
| `content_type` | optional; with it the run binds the chosen record (button on that type's edit screen), without it the run has no record (Run now on the list) |
| `button` | required, text up to 40 characters |
| `allow` | `admins` (default; `manage_options`) or `editors` (users who can `edit_post` the record; needs `content_type`, else `invalid_value`) |
| `when` | condition checked at click; false shows "This automation does not apply to this record" |
| Payload | `trigger.user_id`, `trigger.source` (`editor` or `list`) |

### 3.4 Output and variable model

The Phase 6 rule is unchanged: `steps.<id>.<output>` must name a step that runs on every path before this point. It is extended in four ways:
- Outputs may have one sub-key level where the output is a record or a sum: `steps.<id>.record.<key>`, `steps.<id>.item.<key>`, `steps.<id>.sum.<field>`. `<key>` is checked against the output's content type, with the same `unknown_field`, privacy and type rules as `record.<key>`.
- `steps.<loop>.item` and `steps.<loop>.index` are in scope only inside that loop's body. `steps.<loop>.count` is in scope after it. Outputs of steps inside a loop body are in scope only later in the same iteration (`invalid_var`, "step "x" runs inside loop "each_task"").
- `failed` and `error` exist only on steps with `on_error`.
- `STEP_OUTPUTS` gains types, so conditions type-check: `found` and `failed` are boolean; `record_id`, `count`, `index` and `sum.*` are number; `error` is text.

There are no user-named variables and no assignment. A value is always a trigger payload, the bound record, a read, or a step output, so every path in a definition can be checked at load time.

Collection types: `records` is the only collection. It is an ordered list of record references of one content type, at most 50. It is not a template value and not a condition operand; it is consumed only by `loop.over` and `{{#each}}`. Totals are separate number outputs.

### 3.5 Loop semantics and the cursor

- The loop snapshots the collection's IDs when it starts. Each item is read fresh with `Records::get()` when the body uses it. An item deleted meanwhile is skipped (summary `skipped_item`).
- `current_step` stays a plain step id. Plan rule: the last step of a loop body has `next` = the loop's id. The cursor arriving at a loop id finds `context.loops.<id>` absent (start) or present (advance). There are no nested loops and no `goto`, so a loop starts at most once per run and its state is never stale.
- Stored state: `"loops": { "each_task": { "ids": [ 41, 38, 52 ], "index": 1 } }` (0-based index of the current item). It is removed when the loop ends.
- A false `condition` inside a body skips to the next item (summary `passed: false, skipped_item: true`); the editor labels it "Continue with this item only if". A `decision` inside a body works as anywhere.
- Waits inside loops are forbidden (`loop_wait`). The cursor could hold a waiting loop, but:
  - A 50-item snapshot held for days acts on stale membership.
  - Per-item waits multiply run length (50 items with P2D is 100 days) and block later items.
  - Per-record timing belongs in per-record runs (record events or a sweep).
- Retries inside loops use the same cursor and are allowed. The type's default retry policy is off inside a loop body, so a mail outage cannot stretch one run over 25 hours. An explicit `retry` is allowed. Retry from the run log resumes at the failed item.
- Loops are not allowed in link steps or in signed-link trees (`automation_link_step`). They count as a nesting level toward the existing 3-level limit (`decision_too_deep`).

### 3.6 Versions, schema and migration

- JSON Schema: `step.type` gains the four types and `#/$defs/read_query`, `loop`, `relate` and `trigger.manual`. PHP-only rules (scope, relationship ends, the static size bound) get `schema: false` fixtures. The normalizer fills `status`, `sort`, `limit` and `action`, and sorts `status`.
- Every existing fixture and `examples/sifg` keeps a byte-identical canonical form.
- `Pegwork\Api\VERSION` goes to 0.3.0, not 0.2.0. Phase 4 decision 7 froze the version for one build cycle; this cycle changes `Records::create()` and `update()` behavior (relationship keys were `Unknown field` before), and BarnCRM for WordPress needs something to require. Plugin version: the next minor at sign-off. Packages using the new steps declare `requires.pegwork`.

New error codes: `unknown_relationship`, `relationship_end`, `read_source`, `filter_field`, `loop_wait`, `loop_nested`, `automation_too_large` (section 4.2). Runtime only: `read_limit`, `record_type`.

## 4. Engine changes

### 4.1 Core API (`Pegwork\Api\Records`, 0.3.0)

- `find( string $type, array $query ): array{ ids: int[], count: int|null, sums: array<string, float> }`. `$query` holds `related { relationship, of }`, `filter { all|any: [ { field, op, value } ] }` with resolved literal values, `status`, `sort`, `limit`, `count` (bool) and `sum` (keys). Core knows nothing of run paths: the module resolves operands first.
- Implementation `Pegwork\Content\RecordQuery`:
  - Builds `WP_Query` args: meta, tax, `post__in` or `meta_query` from `Relationships::query_args()`, title for title-storage fields, and `ne`/`not_in` as `OR [ !=, NOT EXISTS ]`.
  - Uses `fields => ids`, and `no_found_rows` unless `count` is asked.
  - Computes sums with one SQL `SUM( CAST( meta_value AS DECIMAL(20,4) ) )` joined to the same `WHERE` (captured from `WP_Query`), so no ID list is built in PHP.
  - Relationship storage stays behind `query_args()`, so Phase 5 `table` storage needs no engine change.
- `relate( string $relationship, int $from, int $to, array $origin = [] ): true|WP_Error` and `unrelate(...)`. Both validate through `RecordValidator` (types, `one_to_many`, `required` on complete statuses) and emit one `record.updated` on the "from" record with the relationship key in `changed` and `old.<key>` as the previous IDs.
- `create()` and `update()` accept relationship keys in `$values`: validated with the rest of the record, written as `_rel_{key}` rows, reported in `changed`.
- Callers authorize. This is unchanged: runs act as the system.

### 4.2 Plan, cursor, resume

- `Plan` walks loop bodies:
  - Nodes gain `loop` (the enclosing loop id).
  - `next( last body step )` is the loop id.
  - New `body_first( $loop )` and `loop_of( $id )`; `types()` includes body types.
- Executor cursor at a `loop` step:
  - With no state: snapshot `ids` from `steps.<over>.ids`, store `{ ids, index: 0 }`, log one row (summary `{ items }`), continue at `body_first`. An empty collection continues at `next( loop )` with output `count: 0`.
  - With state: `index + 1`; if items remain continue at `body_first`, else remove the state, set output `count`, and continue at `next( loop )`.
  - A `stop` result from a step whose `loop_of` is set advances the loop instead of ending the run.
  - The advance itself writes no row and counts as no attempt.
- `pegwork_run_steps` gains `iteration` (smallint unsigned null, 1-based; `Schema::VERSION` 2, `dbDelta`). Attempts are numbered per step and iteration. The run detail and `log --run` group body rows under "Item 2 of 12: Task #38" (the title shown only when the viewer can read the record).
- Webhook delivery IDs inside loops are `<run>-<step>-<iteration>`, stable across retries.
- Resume: waits, retries, time-budget requeues and Retry from the log store `current_step` plus `context.loops`, so all of them resume mid-loop. A definition edit that removes the step at the cursor fails the run (`definition_changed`), as today. A loop whose `over` step was removed also fails.
- Read-after-write: the per-wake-up record cache is invalidated by ID after `update_record`, `create_record` and `relate`. Phase 6's bound-record rule therefore generalizes: a later step reads written values of any record.

### 4.3 Limits

| Limit | Value | Enforced | On exceed |
|---|---|---|---|
| `find_records` `limit` | 0 to 50 | validator | `invalid_value` |
| Loop items | at most the collection (50) | engine | n/a |
| Loop body | 10 steps, no nesting of loops | validator | `invalid_value`, `loop_nested` |
| Worst-case attempts without retries (top-level steps, plus body steps times 50) | 500 | validator | `automation_too_large` |
| Step attempts per run (`MAX_STEPS`) | 100 rises to 600 (filter `pegwork_automation_step_limit`) | executor | run fails `step_limit` |
| Records read per run (IDs returned by reads plus loop items) | 500 (filter `pegwork_automation_read_limit`) | Context counter, stored in `context.reads` | step fails `read_limit`, no retry (honours `on_error`) |
| Sum and count | all matches, in SQL | core | n/a; perf gate in section 8 |
| `{{#each}}` | 50 items, 64 KB rendered per template | Templates | step fails `template_too_large` |
| Time budget | 20 s per wake-up, unchanged; checked before every step, loop iterations included | executor | requeue at the cursor |
| Loop depth cap (`origin.depth` 3) | unchanged; `relate` events carry depth | listener | `skipped` |

The 100-attempt limit predates loops: trees were bounded by size. With a static bound of 500 plus bounded retries, 600 is a safety net, not a design limit.

### 4.4 Privacy and post status

- Reads see only registry content types (`Records::get()` returns null for others), with private fields included, as for the bound record. Contexts decide what is shown.
- Private fields of read records follow the existing context rules:
  - Allowed in conditions, `send_email`, `values` and `idempotency`.
  - Rejected in `call_webhook` (`url`, `fields`) and signed-link page texts (`automation_private_field`).
  - Masked in the run log (`[private]`), including a `sum` over a private field.
- Status: `status` filters every read mode; the default is `private` and `publish` (BarnCRM records are private posts). Trash is never readable, so a trashed gift drops out of lifetime giving. Drafts count only when listed.
- Run log summaries for reads hold `content_type`, `found`, `record_id`, `count`, `returned`, the first 50 IDs, and sums of public fields. They never hold field values or titles; titles are rendered live in the run detail for viewers who can read the record.
- Context storage holds IDs and numbers only. The 16 KB payload cap is unaffected; read outputs add at most about 2 KB per find.

### 4.5 Idempotency and dry run

- Reads are pure, so retrying or re-running them is safe. Run-level idempotency is unchanged. Inside loops, delivery IDs carry the iteration. `interrupted` runs still never repeat a side effect automatically; Retry resumes at the interrupted item.
- `DryRun` walks the same Plan:
  - Reads run for real (read-only queries, same limits and counters) and report found records by ID and title, counts and sums. Writes, relates, email and HTTP are not applied, as today.
  - Reads after a would-be write see the site as it is now, and the report says so once. A `get_record` of a record a test step "would create" reports "not found in a test run".
  - Loops iterate every item. The report shows the first 3 iterations in full and then "and 9 more items, same steps".
  - The CLI and REST reports stay identical.
- The preview (merge tags) does not run reads: `steps.<id>.record.<key>` renders `[from step <id>: <Field label>]`. Test run shows real values.

## 5. Template and condition language

Decision: keep it declarative. A small safe expression language (an own parser, with arithmetic and functions) was steelmanned. It would cover formulas and fee math, but non-developers cannot edit it in the builder, the validator cannot type-check it as simply, it is a new grammar to secure, and no proof needs it.

- Paths: `steps.<id>.record.<key>`, `steps.<id>.item.<key>`, `steps.<id>.sum.<field>`, `steps.<id>.count`, `found`, `record_id`, `index`, `failed`, `error`. They work in templates and as condition `var`s with typed operators.
- New filters. Tag grammar: `{{ path [| add:"<signed duration>"] [| date | number[:<0-4>]] [| default:"text"] }}`.
  - `| add:"P2D"`, `| add:"-PT6H"`: on `today`, `now` and date fields or outputs. It adds in site time with calendar units (`Duration::add()`) and yields `Y-m-d` for dates and ISO for `now`. On other paths it is `invalid_template`.
  - `| number`, `| number:2`: on number fields and number outputs, via `number_format_i18n()` (site locale). Without decimals, integers print bare and other values print with 2 decimals.
- Block: `{{#each steps.<id>.records}} ... {{item.<key>}} ... {{else}} ... {{/each}}`.
  - Only in `send_email` `text` and `html`. It is not nestable, and `subject`, `values`, `url` and `idempotency` reject it (`invalid_template`).
  - Inside the block, `item.*` is checked against the collection's type; every other path in scope still works. `{{else}}` renders for an empty collection.
  - Escaping in `html` mode is per value, as today.
- Conditions: no new operators. The `{ var, offset }` operand (a date field plus a duration) is later.
- No `| count` or `| sum` filters, by decision (section 0).

## 6. Editor additions

Everything stays inside the Phase 6 builder patterns: registry JSON is the source, blocks with variations, the inspector, List View, fieldsets with legends, specific button names, errors by JSON pointer, keyboard-only E2E.

- Inserter variations of `pegwork/automation-step`:
  - "Get a record" (`get_record`), "Find records" (`find_records`) and "Link records" (`relate`).
  - New block `pegwork/automation-loop`, "Repeat for each": inner blocks are steps and decisions. The Wait variation and loops are not offered inside it (inserter filter). A pasted Wait shows `loop_wait` at its card.
- Get a record inspector:
  - Content type select.
  - Radio group (fieldset, legend "Find the record by"): "Its ID", "A relationship", "Matching fields".
  - Relationship combobox filtered to relationships with that type at one end, plus an "Of" template field with the merge-tag picker.
  - Filter rows reuse the condition-builder row component with one All/Any group: the field is from the target type, private fields are marked, and operands can be "another value" (a run path).
  - Sort select and order, and status checkboxes, under "More options".
- Find records inspector: the same, plus Limit (number input 0 to 50, with the help text "Totals count every match, not only these") and "Also total" (checkbox fieldset of number fields).
- Link records inspector: relationship combobox, Add or Remove radio, From and To template fields. The "From" default is shown as text ("This gift").
- Repeat for each:
  - "For each" select of collections in scope ("Tasks from due").
  - The card reads "For each Task from due (at most 50)", followed by a closing note "Then continue below".
  - The appender is named "Add step for each item".
  - List View label "Repeat each_task".
  - "Continue only if" cards inside it read "Continue with this item only if".
- Cards:
  - "Get Contact by relationship Donor of this Gift".
  - "Find Gifts linked to donor: total Amount".
  - "Link Task to Gift (add)".
  - A step with `on_error` shows "Continues if it fails" as text.
- Advanced section: "If this step fails after retries" radio, "Stop the run" (default) or "Continue". Under Continue the help text says "Later steps can check Failed".
- Merge-tag picker, scope-aware, mirroring the validator through `tests/fixtures/automation/scope.json` (extended for reads, loops and `#each`):
  - Groups: "This Gift", "Donor (from donor)", "Each Task (in each_task)" shown only inside the body, "Totals (from giving)", Site, Time, Options, Links.
  - Private fields are badged "private" and omitted in public contexts.
  - Email templates get "Insert list of records", which inserts an `{{#each}}` block with the first field.
  - Date tags offer "plus or minus a duration" (`| add`) and number tags "formatted" (`| number`).
- Condition builder: fields gain read outputs and loop items in scope, typed.
- Trigger picker: "Started by staff" (`manual`), with content type, button text, and "Who can run it" (Administrators, or "Anyone who can edit the record"). The second option carries the help text "It runs with this automation's full access: it may email people and read other records".
- Record editor: a "Run an automation" section in the Pegwork Details panel (`docs/editor-panel.md`) lists manual automations for the type that the user may run.
  - Each button opens core `ConfirmDialog` ("Resend thank-you for Gift from Ada?").
  - The result is announced with `speak()` and shown as a notice, with a link to the run for admins.
  - Script `automation-actions.js`, budget 3 KB gzipped, loaded only on types that have manual automations.
- Move to branch becomes "Move to", listing every step list, loop bodies included. It omits targets that would break `loop_wait`, `loop_nested` or the nesting limit.
- Test run report: read lines ("Get donor: Contact #12 Ada Lovelace", "Find giving: 3 match; Amount totals 1,250.00") and iteration headings.
- Size: the builder is about 20 KB gzipped in the current local build, against a 25 KB budget. These additions are estimated at 4 to 6 KB (open question 4).

## 7. Security checklist

- Authoring stays `pegwork_manage_automations` mapped to `manage_options`, with no filter to lower it. Reads make an automation able to see any registry record, which is an administrator's power.
- Leaks through email:
  - Private fields of read records may go into emails to any recipient the author writes. This is the same trust as bound-record private fields today, widened to other records. It is accepted because only administrators author.
  - Mitigations: "private" badges in the picker and on the card ("Includes private fields of Donor"), the dry run shows rendered text, the log masks, and `{{#each}}` is capped at 50.
- Leaks through webhooks and links: private fields of read records are rejected in `call_webhook` and link pages at load time. `call_webhook.record` sends public fields only. Read and loop steps are not allowed in signed links.
- Query safety:
  - Field keys, sort fields and relationship keys are static and validated; only values come from data, passed to `WP_Query` and `$wpdb->prepare()`.
  - An empty operand matches nothing.
  - Trash is never read.
- Manual trigger:
  - REST `POST pegwork/v1/automations/<key>/start { record_id, request }` with the cookie nonce and the capability per `allow` (`manage_options`, or `edit_post` on that record). The record type is checked.
  - `Throttle` allows 10 per user per minute.
  - A double submit is deduplicated by idempotency key `manual:<request>`, where `request` is a random ID per dialog.
  - Editors cannot change what runs. The admin list labels such automations "Editors can run".
- Denial of service:
  - The limits in 4.3: 50 per find, 500 reads, 600 attempts, a static bound of 500, and 20 s wake-ups.
  - Inbound webhooks that trigger reads are already limited to 30 per minute.
  - Sweeps (`max_runs` 200) times reads stay bounded per firing.
  - The perf gate covers SQL sums at BarnCRM scale.
- Writes: relationship writes go through `RecordValidator` (`one_to_many`, types, `required`) and emit events with origin depth, so loops of automations stay capped at depth 3.
- The run context and log store IDs and numbers, never read field values.

## 8. Testing plan

Fixture `tests/fixtures/valid/barn` is rewritten (worked examples below):
- `barn_gift` drops `donor_id`, `donor_name` and `donor_email`.
- `barn_contact` drops `first_gift_on` and gains `milestone_1000_on` (date, private).
- `barn_task` drops `related_gift` and gains `reminded_on` (date).
- New relationships `gift_donor` (from `barn_gift` to `barn_contact`, `many_to_many`, picker on the gift) and `task_gift` (from `barn_task` to `barn_gift`).
- `barn_gift_followup.json` is removed. `barn_gift_thanks` becomes the whole donor flow.
- New automations: `barn_lifetime_milestone` (aggregate), `barn_tasks_digest` (loop), `barn_resend_thanks` (manual), `barn_donation_inbound` (find or create, relationship in `values`).
- `barn_volunteer_intake` stays byte-identical and must pass unchanged.
- `tests/e2e/packages/barn` gains the relationships; `FixturesTest` keeps the copies equal.

Unit:
- `RecordQueryTest`: every operator by field type, `ne` with no stored value, empty operands, the default status, trash excluded, both relationship directions, sort, limit 0, sums equal to PHP sums, a private filter.
- `PlanTest`: loop next pointers, `body_first`, `loop_of`, loops inside branches.
- `LoopCursorTest`: start, advance, empty, skip on a false condition, deleted item.
- `TemplatesTest`: `| add` across DST, `| number` locales, `{{#each}}` with `else`, escaping, the 64 KB cap.

Registry fixtures:
- Valid `tests/fixtures/valid/reads` covers every new shape.
- Invalid fixtures, each asserting its code and pointer: `read-no-source`, `read-record-and-filter`, `read-unknown-relationship`, `read-relationship-end`, `read-filter-rich-text`, `read-limit-51`, `read-sum-not-number`, `read-private-in-webhook-url`, `read-output-other-branch`, `records-as-template`, `loop-wait`, `loop-wait-in-branch`, `loop-nested`, `loop-over-not-collection`, `loop-output-outside`, `loop-too-large`, `loop-in-link`, `each-nested`, `each-in-subject`, `add-on-text`, `relate-wrong-end`, `values-relationship-wrong-type`, `on-error-on-decision`, `manual-editors-without-type`, `webhook-record-without-type`.
- Export and import are byte-identical; existing canonical forms are unchanged.

Integration (FrozenClock, FakeScheduler, mock PHPMailer):
- `AutomationReadsTest`: outputs resolve fresh after a wait, read-after-write by ID, the `read_limit` failure, `on_error` outcome and counts, `call_webhook.record` public fields only, relate events and depth.
- `AutomationLoopTest`:
  - Time budget 0 gives the same emails, in the same order, with no duplicates.
  - A retry inside a loop resumes at the same item.
  - Default retries are off in bodies.
  - Retry from the log resumes mid-loop.
  - Iteration rows and run-detail markup.
  - Delivery IDs carry the iteration.
  - `definition_changed` mid-loop.
  - A Phase 6 shaped waiting run resumes on Schema 2.
- `AutomationManualTest`: 403 for subscribers, and for editors when `allow` is `admins`; editor allowed with `editors` on an editable record and refused on another type; nonce; throttle; double-submit dedupe; `when` refusal.
- `AutomationDryRunTest` extended:
  - Reads report real data.
  - Post, meta and `_rel_` row counts are unchanged.
  - `pre_wp_mail`, `pre_http_request` and `pegwork_event` counters stay 0.
  - Loop report truncation.

Perf (CI MySQL, `bin/perf-directory.sh` pattern): 10,000 gifts across 3,000 contacts. A related `find_records` with `count` and `sum` takes under 200 ms, and a 50-item digest wake-up takes under 5 s. Both are recorded in the PR.

Simulated-time gate `BarnFlowsGateTest` (site in New York, so the 30-day wait crosses the DST start):

- Donor (one automation):
  1. 2027-03-01 09:00: G1 (C1, 300) sends email A to C1's email, with Reply-To the development email. It creates one call task linked to G1, due 2027-03-03, and stamps C1 `last_gift_on`. The run waits at `gifts_later` until 2027-03-31 09:00. G2 (C2, 40) sends email B, creates no task, and waits. A gift of exactly 250 takes the major branch.
  2. Same day: C3 gives twice. The first run waits and the second stops at `first_gift`. On 2027-03-31 the first stops at `no_second_gift`. This fixes the Phase 6 same-day limit.
  3. 2027-03-10: G3 (C1, 75) sends email B, and its run stops at `first_gift`.
  4. Nothing is sent on 2027-03-30 or before 09:00 on 2027-03-31. Then C1's run ends `stopped_at:no_second_gift`, and C2 gets the one follow-up.
  5. A gift with no donor link ends `stopped_at:has_donor` with no email (behavior change, open question 8).
- Milestone:
  1. 2027-04-01 to 04-03: C4 gives 600, 300 and 100. The third gift sends one email "now total $1,000.00" and stamps `milestone_1000_on`.
  2. 2027-04-04: a 50 gift stops at `not_yet`.
  3. C5: a trashed 500 gift and a draft 500 gift are not counted, so no email.
- Digest:
  1. Tasks T1 (due 06-08), T2 (due 06-10, done), T3 (due 06-20), T4 (due 06-01, open, no assignee email).
  2. Monday 2027-06-07 08:00: the loop runs T4 then T1. T4's email fails `no_recipient` and the loop continues (`on_error`). T1 gets a reminder, and both are stamped `reminded_on`.
  3. The summary to the development email lists T4 and T1 in due order with "2 tasks due".
  4. The outcome is `completed_with_errors:remind`.
  5. A second firing the same day creates nothing (idempotency). On 2027-06-14 T3 is included.
- Inbound: a signed payload for a new email creates a contact and a linked gift, and the gift's `record.created` run (depth 1) thanks the donor. The same email again reuses the contact. An empty email stops at `has_email` and creates nothing.
- Volunteer: the Phase 6 timeline is unchanged.

E2E `automation-barn-flows.e2e.js` (keyboard only):
- Builds `barn_gift_thanks`, `barn_lifetime_milestone` and `barn_tasks_digest` and compares them to the fixtures.
- Covers the Repeat block, "Insert list of records", the picker's Donor group, and Wait not offered inside a loop.
- Runs Test run on a gift (read lines).
- Covers the manual button on a gift by keyboard, as editor and as admin.
- Runs axe and checks no horizontal scroll at 1280 and 782 px.
- Updates the Local runbook in `docs/automation.md`.

## 9. Non-goals

- Inline relationship paths (`record.related.*`): hidden, unlimited queries (section 1).
- Mutable variables, assignment and a formula language: they break static scope and add a grammar; numeric arithmetic waits for a real need.
- In-memory collection filter and sort: wrong past the cap; the query does it.
- Waits in loops and nested loops: stale snapshots, and unbounded run length.
- Wait until a date field: the sweep recipe is self-correcting.
- Subflows: record events chain flows already.
- Delete or trash steps: no proof needs them, and the blast radius is large.
- User and role reads, send to role: options pages cover it.
- Approvals as a feature: the link pattern covers it.
- Screen flows: forms cover them.
- SMS and channels in core: paid gateways.
- AI steps: no paid AI API unattended.
- Bulk "run for selected records" on list tables: a sweep or a loop covers it.
- Relationship `table` storage: Phase 5, behind the same `query_args()`.
- Action Scheduler: Phase 4 decision 8 stands.
- Multisite.

## 10. Open questions (recommended answers)

1. Gift to donor modeling. `one_to_many` would force the picker onto the contact, because links live on the "from" record. Recommend `gift_donor` from `barn_gift` to `barn_contact`, `many_to_many`, with `get_record` taking the first by ID, and adding a "single link" relationship option when Phase 5 designs households.
2. Default read statuses `private` and `publish`. Recommend yes, so drafts never count toward totals unless asked.
3. A false condition inside a loop skips the item instead of ending the run. Recommend yes, with the distinct label.
4. Builder size budget 25 KB gzipped. Recommend raising it to 30 KB only if PR 7 measures over 25, and recording the number.
5. `Pegwork\Api\VERSION` 0.3.0. Recommend yes (section 3.6).
6. Step limit 100 raised to 600, with the static bound of 500. Recommend yes.
7. `manual` with `allow: editors`. Recommend allowing it, with the default `admins` and the warning text.
8. A gift with no linked donor is no longer thanked; the Phase 6 stand-ins thanked it. Recommend yes: there is no address to thank, and the gift picker saves the link in the same request, so the run (which reads later, in the background) finds it.
9. Milestone race: two gifts from one donor processed concurrently could both send. Recommend accepting it: WP-Cron from a server cron runs events serially, and the stamp comes before the email. Add a step-level "once per" key only if a pilot sees duplicates.
10. Private fields of read records in emails to any recipient. Recommend yes, the same trust as bound records, with the badges.
11. Historical imports: bulk-imported old gifts would start follow-ups. Recommend documenting "disable `barn_gift_thanks` while importing" in the runbook, rather than adding a date guard to the fixture.

## 11. PRs (each merges with CI green)

1. **Core: Records reads and link writes.**
   - Contents: `Records::find()`, `relate()`, `unrelate()`, relationship keys in `create()` and `update()`, `RecordQuery`, relationship keys in record events, API 0.3.0, `docs/extension-api.md`, `docs/relationships.md`.
   - Accept: `RecordQueryTest` and relate validation (`one_to_many`, `required`, wrong type) pass; events carry `changed`; the perf numbers are recorded and under the targets; the module-boundary check passes; every existing test passes.
2. **Registry: new steps, outputs and triggers.**
   - Contents: JSON Schema, Validator (query model, scope extension, loops, `#each`, `| add`, `| number`, `on_error`, `manual`, `call_webhook.record`, relationship values, static bound), Normalizer, fixtures, `docs/registry-reference.md`.
   - Accept: the fixture agreement job passes with every section 8 fixture; existing canonical forms and `examples/sifg` are byte-identical; export and import round-trip `reads`.
3. **Engine: reads, relate, on_error, templates.**
   - Contents: `GetRecord`, `FindRecords` and `Relate` handlers; Context outputs by reference, cache invalidation and read counter; Templates `| add`, `| number` and `{{#each}}`; `call_webhook.record`; the `completed_with_errors` outcome; DryRun for these; `docs/automation.md`.
   - Accept: `AutomationReadsTest` and the DryRun no-side-effect counters pass; `BarnFlowsGateTest` (old fixture) and `StalenessCycleGateTest` pass unchanged.
4. **Engine: loops.**
   - Contents: Plan loop nodes, the cursor and `context.loops`, Schema 2 `iteration`, delivery IDs, the default-retry rule, run detail and `log --run` grouping, DryRun loops.
   - Accept: `PlanTest`, `LoopCursorTest` and `AutomationLoopTest` pass; the real WP-Cron test resumes a loop after a time-budget requeue; a Schema 1 site upgrades with waiting runs intact.
5. **Manual trigger.**
   - Contents: trigger handling, the REST start route, the list's Run now for no-record manual automations, CLI `run` for manual, the record editor section and `automation-actions.js`.
   - Accept: `AutomationManualTest` passes; a keyboard E2E runs one from a gift as editor and as admin; axe is clean; the script is at most 3 KB gzipped.
6. **Editor: reads, relate, on_error, manual trigger.**
   - Contents: variations and inspectors, filter rows, the trigger picker, picker groups, Test run lines, the scope fixture extension.
   - Accept: Vitest round-trips every valid read fixture byte for byte; `AutomationBuilderScopeTest` agrees with the PHP rules; a keyboard E2E builds `barn_lifetime_milestone`; the size check passes.
7. **Editor: loops and lists.**
   - Contents: the `pegwork/automation-loop` block, inserter rules, "Move to" rules, "Insert list of records", loop labels.
   - Accept: the round-trip test covers loop fixtures; a keyboard E2E builds `barn_tasks_digest` and refuses a pasted Wait with focus on the error; axe passes at 1280 and 782 px; the size check passes (open question 4).
8. **BarnCRM proof with reads.**
   - Contents: the rewritten `tests/fixtures/valid/barn` and README, `tests/e2e/packages/barn`, the `BarnFlowsGateTest` timelines, the E2E for the new flows, the runbook, screenshots.
   - Accept: every section 8 timeline passes; the keyboard-built automations save identical to the fixtures; the runbook (`run --record`, `tick --now=+31days`, the digest with `run --now`, `log --run`) is checked once by hand on Local.

## Worked examples: BarnCRM flows with reads (test fixtures)

Relationships (`tests/fixtures/valid/barn/relationships/`):

```json
{ "format": 1, "key": "gift_donor", "from": "barn_gift", "to": "barn_contact", "cardinality": "many_to_many", "label": "Donor" }
```
```json
{ "format": 1, "key": "task_gift", "from": "barn_task", "to": "barn_gift", "cardinality": "many_to_many", "label": "Gift" }
```

### (a) Donor acknowledgment and first-gift follow-up, one automation

`automations/barn_gift_thanks.json`

```json
{ "format": 1, "key": "barn_gift_thanks", "label": "Gift received: thank the donor, follow up a first gift",
  "trigger": { "type": "event", "event": "record.created", "content_type": "barn_gift" },
  "steps": [
    { "id": "donor", "type": "get_record", "content_type": "barn_contact",
      "related": { "relationship": "gift_donor", "of": "{{record.id}}" } },
    { "id": "has_donor", "type": "condition", "when": { "var": "steps.donor.found", "op": "eq", "value": true } },
    { "id": "size", "type": "decision",
      "branches": [
        { "label": "Major gift (250 or more)",
          "when": { "var": "record.amount", "op": "gte", "value": 250 },
          "steps": [
            { "id": "thank_major", "type": "send_email", "to": "{{steps.donor.record.email}}",
              "reply_to": "{{option:barn_settings.development_email}}",
              "subject": "Thank you for your generous gift",
              "text": "Dear {{steps.donor.record.name}},\n\nThank you for your gift of ${{record.amount | number}} on {{record.received_on | date}}. Our director will call you this week to thank you in person.\n\nNo goods or services were provided in exchange for this gift.\n\n{{site.name}}" },
            { "id": "call_task", "type": "create_record", "content_type": "barn_task", "status": "publish",
              "values": { "name": "Thank-you call: {{steps.donor.record.name}}", "due_on": "{{today | add:\"P2D\"}}",
                "assignee_email": "{{option:barn_settings.development_email}}", "task_gift": "{{record.id}}" } } ] } ],
      "otherwise": [
        { "id": "thank", "type": "send_email", "to": "{{steps.donor.record.email}}",
          "subject": "Thank you for your gift",
          "text": "Dear {{steps.donor.record.name}},\n\nThank you for your gift of ${{record.amount | number}} on {{record.received_on | date}}.\n\nNo goods or services were provided in exchange for this gift.\n\n{{site.name}}" } ] },
    { "id": "stamp_donor", "type": "update_record", "record": "{{steps.donor.record_id}}",
      "values": { "last_gift_on": "{{record.received_on}}" } },
    { "id": "gifts", "type": "find_records", "content_type": "barn_gift", "limit": 0,
      "related": { "relationship": "gift_donor", "of": "{{steps.donor.record_id}}" } },
    { "id": "first_gift", "type": "condition", "when": { "var": "steps.gifts.count", "op": "eq", "value": 1 } },
    { "id": "grace", "type": "wait", "for": "P30D" },
    { "id": "gifts_later", "type": "find_records", "content_type": "barn_gift", "limit": 0,
      "related": { "relationship": "gift_donor", "of": "{{steps.donor.record_id}}" } },
    { "id": "no_second_gift", "type": "condition", "when": { "var": "steps.gifts_later.count", "op": "eq", "value": 1 } },
    { "id": "follow_up", "type": "send_email", "to": "{{steps.donor.record.email}}",
      "reply_to": "{{option:barn_settings.development_email}}",
      "subject": "What your gift made possible",
      "text": "Dear {{steps.donor.record.name}},\n\nA month ago you made your first gift to {{site.name}}. Here is what it is already doing.\n\nIf you would like to give again: {{option:barn_settings.donate_url}}" } ] }
```

Why it works:
- The donor is read through the relationship, so the email and name are the contact's current values. After the 30-day wait, `steps.donor.record.email` is re-read: an address corrected in the meantime is used.
- "First gift" is a count of the donor's live gifts, this one included, so two gifts on the same day are counted correctly (the Phase 6 limit is gone), and trashed or draft gifts do not count.
- `gifts_later` re-counts after the wait. Any new gift in the 30 days stops the run at `no_second_gift`. No `first_gift_on` stamp or second automation is needed.
- The call task is linked to its gift in the same validated write (`task_gift` in `values`). Its due date uses `| add`.
- `stamp_donor` writes the contact by a typed reference, so its `values` keys are checked against `barn_contact` at load time.

### (b) Aggregate: lifetime giving reaches 1,000

`automations/barn_lifetime_milestone.json`

```json
{ "format": 1, "key": "barn_lifetime_milestone", "label": "Lifetime giving reaches 1,000: thank the donor",
  "trigger": { "type": "event", "event": "record.created", "content_type": "barn_gift" },
  "steps": [
    { "id": "donor", "type": "get_record", "content_type": "barn_contact",
      "related": { "relationship": "gift_donor", "of": "{{record.id}}" } },
    { "id": "has_donor", "type": "condition", "when": { "var": "steps.donor.found", "op": "eq", "value": true } },
    { "id": "not_yet", "type": "condition", "when": { "var": "steps.donor.record.milestone_1000_on", "op": "empty" } },
    { "id": "giving", "type": "find_records", "content_type": "barn_gift", "limit": 0, "sum": [ "amount" ],
      "related": { "relationship": "gift_donor", "of": "{{steps.donor.record_id}}" } },
    { "id": "reached", "type": "condition", "when": { "var": "steps.giving.sum.amount", "op": "gte", "value": 1000 } },
    { "id": "stamp", "type": "update_record", "record": "{{steps.donor.record_id}}",
      "values": { "milestone_1000_on": "{{today}}" } },
    { "id": "celebrate", "type": "send_email", "to": "{{steps.donor.record.email}}",
      "reply_to": "{{option:barn_settings.development_email}}",
      "subject": "Thank you for {{steps.giving.count}} gifts to {{site.name}}",
      "text": "Dear {{steps.donor.record.name}},\n\nYour gifts to {{site.name}} now total ${{steps.giving.sum.amount | number:2}}. Thank you for standing with us.\n\n{{site.name}}" } ] }
```

Why it works: the sum is computed in SQL over every live gift, not over a loaded list. The stamp before the email makes it once per donor; if the email fails after its retries, the run fails visibly and Retry resumes at `celebrate`.

### (c) Loop: weekly digest of tasks due

`automations/barn_tasks_digest.json`

```json
{ "format": 1, "key": "barn_tasks_digest", "label": "Monday: remind assignees and summarize tasks due this week",
  "trigger": { "type": "schedule", "every": "week", "at": "08:00" },
  "idempotency": "tasks:{{today}}",
  "steps": [
    { "id": "due", "type": "find_records", "content_type": "barn_task", "status": [ "publish" ],
      "filter": { "all": [
        { "field": "done", "op": "ne", "value": true },
        { "field": "due_on", "op": "lte", "value": { "date": "today", "offset": "P7D" } } ] },
      "sort": { "field": "due_on", "order": "asc" }, "limit": 50 },
    { "id": "each_task", "type": "loop", "over": "steps.due.records",
      "steps": [
        { "id": "remind", "type": "send_email", "on_error": "continue",
          "to": "{{steps.each_task.item.assignee_email}}",
          "subject": "Due {{steps.each_task.item.due_on | date}}: {{steps.each_task.item.name}}",
          "text": "{{steps.each_task.item.name}} is due {{steps.each_task.item.due_on | date}}. Mark it done when finished: {{steps.each_task.item.edit_url}}" },
        { "id": "stamp", "type": "update_record", "record": "{{steps.each_task.item.id}}",
          "values": { "reminded_on": "{{today}}" } } ] },
    { "id": "summary", "type": "send_email", "to": "{{option:barn_settings.development_email}}",
      "subject": "{{steps.due.count}} tasks due by {{today | add:\"P7D\" | date}}",
      "text": "Open tasks due by {{today | add:\"P7D\" | date}} (the first 50 by due date):\n\n{{#each steps.due.records}}- {{item.name}}, due {{item.due_on | date}}, {{item.assignee_email | default:\"no assignee\"}}\n{{else}}Nothing is due this week.\n{{/each}}" } ] }
```

Why it works: one run reminds each assignee, stamps each task, and then sends a summary that knows exactly what was due. A task without an assignee fails only its own reminder (`on_error`), and the run ends `completed_with_errors:remind`, so it shows in the failure count. A time-budget requeue or a retry resumes at the same item.

### (d) Manual and inbound (abridged)

`automations/barn_resend_thanks.json`: trigger `{ "type": "manual", "content_type": "barn_gift", "button": "Resend thank-you", "allow": "editors" }`, then `donor` (as in (a)), `has_donor`, and one `send_email` to `{{steps.donor.record.email}}`.

`automations/barn_donation_inbound.json`

```json
{ "format": 1, "key": "barn_donation_inbound", "label": "Donation platform: record the gift",
  "trigger": { "type": "webhook" },
  "steps": [
    { "id": "has_email", "type": "condition", "when": { "var": "trigger.payload.email", "op": "not_empty" } },
    { "id": "existing", "type": "get_record", "content_type": "barn_contact",
      "filter": { "all": [ { "field": "email", "op": "eq", "value": { "var": "trigger.payload.email" } } ] } },
    { "id": "new_contact", "type": "decision",
      "branches": [ { "label": "Not in the CRM yet", "when": { "var": "steps.existing.found", "op": "eq", "value": false },
        "steps": [ { "id": "add_contact", "type": "create_record", "content_type": "barn_contact", "status": "private",
          "values": { "name": "{{trigger.payload.name}}", "email": "{{trigger.payload.email}}" } } ] } ] },
    { "id": "contact", "type": "get_record", "content_type": "barn_contact",
      "filter": { "all": [ { "field": "email", "op": "eq", "value": { "var": "trigger.payload.email" } } ] } },
    { "id": "add_gift", "type": "create_record", "content_type": "barn_gift", "status": "private",
      "values": { "name": "Gift from {{steps.contact.record.name}}", "amount": "{{trigger.payload.amount}}",
        "received_on": "{{today}}", "gift_donor": "{{steps.contact.record_id}}" } } ] }
```

Why it works: "find, create if missing, find again" stays inside the output scope rule, because `add_contact` runs in one branch only, so the second `get_record` gives one reference on every path. The gift is created already linked, so `barn_gift_thanks` (depth 1) finds its donor.

### (e) Volunteer intake

`automations/barn_volunteer_intake.json` is unchanged from Phase 6 and is the regression proof that existing flows run as before.

### Critical Files for Implementation
- /Users/bmead/worktrees/pegwork/site/src/Api/Records.php
- /Users/bmead/worktrees/pegwork/site/src/Registry/Rules/AutomationRules.php
- /Users/bmead/worktrees/pegwork/site/modules/automation/src/Executor.php
- /Users/bmead/worktrees/pegwork/site/modules/automation/src/Plan.php
- /Users/bmead/worktrees/pegwork/site/modules/automation/src/Context.php
- /Users/bmead/worktrees/pegwork/site/modules/automation/src/Templates.php
- /Users/bmead/worktrees/pegwork/site/modules/automation/src/DryRun.php
- /Users/bmead/worktrees/pegwork/site/assets/src/automation-builder/scope.js