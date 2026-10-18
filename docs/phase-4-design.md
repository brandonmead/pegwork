# Pegwork: Phase 4 Automation Technical Design

Status: draft for owner review 2026-10-18 (open questions in section 12). Scope: the core event bus plus a switchable `automation` module (a Salesforce Flow-style builder, linear in Phase 4). Canonical spec: BMemory `specification/wordpress-platform-plugin.md`; first consumer: `specification/siforgood-site-plan.md`. Builds on `docs/phase-1-design.md` (structure, registry, module loader). Version claims were checked 2026-10-18 against GitHub; confirm in PR 3.

## 0. Key decisions

- Event bus in core, `Pegwork\Api\Events::emit()`; first PR, because Forms (Phase 3, designed in parallel) emits on it.
- One general engine, small: 4 trigger kinds, 4 actions, 2 control steps (`wait`, `condition`). SI for Good's flows are its first config, not code.
- Definitions live in the registry as a new optional `automations` section (format 1, additive), so they ship in `sifg-config` and export/import with the schema. JSON-only editing in Phase 4.
- Conditions: a declarative JSON expression tree, checked at load time against the registry. No eval, no callables, no PHP in JSON.
- Background work: bundled Action Scheduler (AS) as the wake-up transport; Pegwork's own `runs` table is the source of truth for automation state, so AS stays replaceable.
- All time comes from an injectable clock, so tests and a WP-CLI `--now` flag can run a year-long cycle in seconds.
- Signed one-click links: stateless HMAC tokens with expiry; the link can only run the steps its automation declares, on one record.

## 1. Traceability (each element and what breaks without it)

| Element | Needed by | Without it |
|---|---|---|
| Event bus, record events | Approval flow (req 1), Forms, webhook on publish (req 4) | Every module hooks `save_post` itself and sees half-saved REST records |
| `Pegwork\Api\Records` | update/create actions, link handler, Forms | Modules may only use `Pegwork\Api`, so they could not write records without bypassing validation and events |
| Record-triggered automation | Req 1, 4 | No reaction to approval |
| Scheduled trigger with `for_each` | Req 2 (daily sweep for 12-month-old Last verified) | Yearly check needs per-record timers that drift when Last verified changes |
| `wait` step + persistence | Req 3 (30 days after the email) | Second sweep plus extra state fields per record |
| `condition` step | Req 3 (skip pause if re-verified during the wait), link restore | Pauses projects that replied |
| Idempotency key | Req 2 (daily sweep must email once per cycle) | One email per day for 30 days |
| `send_email` with merge tags | Req 1, 2, 3 | No notifications |
| Signed links | Req 2, 3 (one-click "still active?", reactivate) | Owner must log in; no accounts exist for owners |
| `call_webhook` (outbound, async, retries) | Req 4 (optional, must not depend on n8n) | Publish would block on n8n, or no integration |
| Inbound webhook | Spec trigger list | Spec capability unmet; no SI for Good need, so it is last and smallest |
| Runs and run-steps tables, log screen, CLI | Spec (log every run), gate proof | Silent failures; no way to prove a cycle |
| Clock, `--now`, fake scheduler | Gate (simulate time) | A real 13-month wait |
| Loop depth cap | Spec mitigation | Automation writes re-trigger without end |

## 2. Event bus (core)

Contract (fixed): `Pegwork\Api\Events::emit( string $name, array $payload ): void` fires `do_action( 'pegwork_event', $name, $payload )` then `do_action( 'pegwork_event_' . str_replace( '.', '_', $name ), $payload )`. Names match `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$` (else `_doing_it_wrong`, not emitted). In-process and synchronous; listeners that do slow work must queue it (the automation module does). Core never persists events.

Core record events, for registry content types only (`src/Events/RecordEvents.php`):

| Event | Payload |
|---|---|
| `record.created` | `id, type, status, user_id, origin` |
| `record.updated` | `id, type, status, changed[], old{}, user_id, origin` |
| `record.status_changed` | `id, type, old_status, new_status, user_id, origin` |
| `record.deleted` | `id, type, status, user_id, origin` |

- `changed` lists registry field keys and taxonomy keys whose stored value differs, plus `status` when `post_status` moved. `old` holds the previous values of the changed keys (term slugs for taxonomies). New values are read from the record. Private values may appear in `old`: the bus is in-process PHP, the leak boundary is outbound output (section 5).
- `origin`: `{ source: rest|php|records_api, automation?, run_id?, depth }`. Depth 0 for people; Records API writes made by a run carry the run's depth plus 1.
- One logical save emits one `created` or `updated` event, followed by `status_changed` when the status moved. A save that changes nothing emits nothing.

Change detection (snapshot and diff). A snapshot is `{status, field values via storage, sorted term slugs}`, taken once per post per logical save and held in a request-local map:
- REST: snapshot on `rest_pre_insert_{type}` (before any write); emit on `rest_after_insert_{type}`, which core fires after `wp_insert_post`, meta and terms are all written, so meta arriving after `save_post` is included. The post is then marked flushed so the later `wp_after_insert_post` does not emit again.
- PHP callers (`wp_insert_post`/`wp_update_post`, WP-CLI): snapshot on `pre_post_update`; emit on `wp_after_insert_post`.
- Records API: snapshot, write post, meta and terms with flushing suppressed, then emit once.
- Created: no snapshot, or snapshot status `auto-draft`. Inserting an auto-draft (block editor "new post") emits nothing; the first real save emits `created` (`old_status` = `new`).
- Ignored: revisions, autosaves (`/autosaves` routes, `wp_is_post_autosave`), non-registry types. Trash is `status_changed` to `trash`; permanent delete emits `record.deleted` on `deleted_post` from a snapshot taken on `before_delete_post`.
- Limitation (documented): a bare `update_post_meta()` outside any save emits nothing. Use `Records::update()`.

Forms emits `form.submitted` and `form.confirmed`; the automation module only assumes `form` (key) and optional `record_id` in their payloads (align with the Forms design, open question 5).

## 3. Records API (core, `Pegwork\Api\Records`)

- `get( int $id ): ?array`: `{id, type, status, values{field and taxonomy keys}, permalink}`.
- `create( string $type, array $values, string $status = 'draft', array $origin = [] ): int|WP_Error`
- `update( int $id, array $values, array $origin = [] ): true|WP_Error`
- Values by registry key; mapped to `post_title`, `post_content`, meta or terms (slugs). Sanitized per field type; the merged record goes through `pegwork_validate_record()` when the resulting status is complete, so automations cannot publish invalid records. Writes run without a user capability check: callers (automation runs, link handler) are authorized by their own rules (section 10).
- `Pegwork\Api\VERSION` becomes 0.2.0; `docs/extension-api.md` documents Events and Records.

## 4. Definition format: registry `automations` section

A package may add `automations/*.json`, one automation per file. Optional and additive, so format stays 1. Core (not the module) validates, normalizes, exports and imports them, so a site with the module disabled still round-trips its schema. `pegwork_schema` option gains an `automations` list; `Registry::automations()` and `automation( $key )` are added. JSON Schema (`#/$defs/automation`), PHP Validator, Normalizer, Exporter/Importer and valid/invalid fixtures are extended together, kept in agreement by the existing CI job.

Top level: `format`, `key` (`^[a-z][a-z0-9_]*$`, max 64), `label`, `description?`, `enabled` (default true), `trigger`, `steps[]`, `idempotency?` (template), `links?{}`, `ui?{}` (reserved, ignored by the engine, preserved by the normalizer).

Triggers:
- Event: `{ "type": "event", "event": "record.status_changed", "content_type": "sifg_project", "when": <condition> }`. Any bus event name; `content_type` (record events) or `form` (form events) narrows it. Record events bind the run's record.
- Schedule: `{ "type": "schedule", "every": "hour|day|week", "at": "09:00", "for_each": { "content_type", "status": [], "where": <condition>, "max_runs": 200 } }`. Without `for_each` the run has no record. `at` is site time.
- Webhook: `{ "type": "webhook" }` (endpoint in section 7). Payload under `trigger.payload`.

Steps (ordered list, each with a stable `id`, unique in the automation):
- `update_record`: `record?` (template, default the bound record), `values{key: template or literal}`.
- `create_record`: `content_type`, `status`, `values{}`; output `steps.<id>.record_id`.
- `send_email`: `to`, `subject`, `text`, `html?`, `reply_to?`.
- `call_webhook`: `url`, `fields?[]` (public fields only), `timeout?` (max 15 s).
- `wait`: `for` (ISO 8601 duration, `PT1H` to `P2Y`).
- `condition`: `when`. False ends the run as `completed` with outcome `stopped_at: <id>`.
- Any step may set `retry: { max, backoff }` (defaults: email 2 x `PT15M`, webhook 3 with backoff `PT1M`, `PT10M`, `PT1H`, others 0).

`links{ name: { expires, confirm, button, steps[], success_url?, success_message?, expired_url? } }`: one-click actions (section 7). Link steps are limited to `update_record` and `condition` so the click completes synchronously.

Room for Phase 6: branching adds a `decision` step type holding `branches[{ when, steps[] }]` (nested lists, ids stay unique per automation), and the canvas stores node positions in `ui`. The run log is keyed by step id, so history stays readable. Both are additive under format 1.

## 5. Conditions and templates

Condition (JSON tree, max depth 5, max 50 nodes):
- Groups: `{ "all": [ ... ] }`, `{ "any": [ ... ] }`, `{ "not": <condition> }`.
- Leaf: `{ "var": "<path>", "op": "<op>", "value": <operand> }`. Ops: `eq`, `ne`, `in`, `not_in`, `lt`, `lte`, `gt`, `gte`, `empty`, `not_empty`, `contains` (multi-taxonomy), `changed` (key in `trigger.changed`).
- Operand: a literal, `{ "var": "<path>" }`, or `{ "date": "today|now", "offset": "-P12M" }`. Dates compare as `Y-m-d` (or ISO datetime) in site time.
- Paths: `record.<field|taxonomy|id|status|type>`, `trigger.<key>` (event payload; `trigger.payload.*` for webhooks), `steps.<id>.<output>`, `site.name|url`.
- Evaluated by a small interpreter (`Conditions::evaluate( array $tree, Context $c ): bool`). The validator rejects unknown ops, unknown `record.*` keys for the bound type, type mismatches (for example `lt` on a boolean) and bad durations at load time, so a typo fails `wp pegwork schema validate` instead of failing silently at 3 a.m.

Templates (merge tags): `{{ path }}` with one filter, `{{ path | default:"active" }}`. Extra paths: `today`, `now`, `run.id`, `record.permalink`, `record.edit_url`, `link.<name>` (mints a signed URL for the bound record). Taxonomy keys render term names in text and slugs in `values`. In `values`, the rendered string is cast to the field type and then validated by the Records API.

Render contexts enforce privacy statically: `email` and `values` may use private fields (the email goes to the owner's private address by design); `public` contexts (webhook body, link pages, success messages) reject private fields at validation time. HTML email bodies escape every value with `esc_html` (`esc_url` for URLs); the authored template itself is trusted (committed config, imported only by CLI).

## 6. Engine (module `automation`)

Module `modules/automation/` (namespace outside `Pegwork\`, per the boundary check), booted on `pegwork_loaded` when enabled. It uses only `Pegwork\Api` plus WordPress and AS.

Execution model:
1. Event trigger: the listener evaluates `when` synchronously (old values are only available now), computes the idempotency key, inserts a run row (`queued`) and enqueues an AS async action `pegwork_automation_run` [run_id]. The save request never runs steps.
2. Schedule trigger: one self-rescheduling AS single action per scheduled automation, next time computed by the clock in site time (DST-safe). The sweep queries `post_type` and `status` in ID order, 50 IDs per AS action (chained), evaluates `where` in PHP, and creates one run per match unless its idempotency key exists. `max_runs` (default 200 per sweep) stops a misconfigured sweep from mass-mailing; excess is logged and resumes next sweep.
3. Executor: claims the run atomically (`UPDATE ... SET status='running' WHERE id=%d AND status IN ('queued','waiting')`, proceed only if 1 row), so a duplicate AS execution is a no-op. Re-reads the record, runs steps from `current_step`, writes one run-step row per attempt. Time budget 20 s per AS action (below AS's 30 s runner limit and typical shared-host limits); when exceeded, it saves `current_step`, sets `queued` and re-enqueues.
4. `wait` and retries share one mechanism: status `waiting`, `resume_at`, `current_step`, plus an AS single action at `resume_at`. On resume, a disabled or removed automation cancels the run; a step id missing from the current definition fails it (`definition_changed`).
5. Maintenance (daily AS recurring action, also `wp pegwork automation tick`): resumes `waiting` runs whose `resume_at` passed (recovers lost AS actions), marks runs stuck in `running` over 15 minutes as failed (`interrupted`, never auto-repeating a side effect), prunes logs.

Data model (custom tables, created with `dbDelta` on module enable, versioned by `pegwork_automation_db_version`; dropped by `uninstall.php`, since logs are not content). Posts or meta would bloat `wp_posts` and cannot index `status, resume_at`.

`{prefix}pegwork_runs`: `id` PK, `automation` varchar(64), `trigger_type` varchar(16), `record_id` bigint null, `status` varchar(16) (`queued|running|waiting|completed|failed|cancelled|skipped`), `current_step` varchar(64), `resume_at` datetime null (UTC), `depth` tinyint, `idempotency_key` varchar(191) null, `definition_hash` char(32), `context` longtext (JSON: trigger payload capped at 16 KB, step outputs), `error` text, `created_at`, `started_at`, `finished_at`. Indexes: `(status, resume_at)`, `(automation, created_at)`, `(record_id)`, unique `(automation, idempotency_key)`.

`{prefix}pegwork_run_steps`: `id` PK, `run_id`, `step_id`, `step_type`, `attempt`, `status` (`ok|skipped|failed|retrying`), `started_at`, `duration_ms`, `summary` (JSON: email `to` and subject; webhook URL host, HTTP status, delivery id; changed keys), `error` text. Index `(run_id)`.

Safety:
- Loop depth: a run's depth is its trigger's `origin.depth`; a trigger at depth 3 or more (filter `pegwork_automation_max_depth`) is recorded as `skipped` (`loop_depth`) with no steps. Also 100 steps maximum per run.
- Idempotency: the optional `idempotency` template is rendered when a run would be created; if a run with the same `(automation, key)` exists, none is created (unique index). Sweeps need one; event triggers usually do not (each save is a new event); inbound webhooks use the delivery id. Failed runs keep their key; `wp pegwork automation retry <run>` (or the log screen) resumes from the failed step.
- Retention: completed, skipped and cancelled runs older than 90 days are pruned, failed runs after 180 days (option `pegwork_automation_retention_days`, filterable); `queued`, `running` and `waiting` runs are never pruned. Keys must be data-derived (as below) so pruning cannot cause a re-send.
- Site kill switch: option `pegwork_automation_overrides` (`key => on|off`) overrides the JSON `enabled` without a deploy.

### Scheduler: Action Scheduler vs WP-Cron (steelman)

WP-Cron alone plus our runs table and a once-a-minute tick would work: no dependency, no extra tables, no licence question, and AS itself is woken by WP-Cron, so it is no more punctual on a low-traffic site. Rejected because we would rebuild what AS already provides and has hardened at WooCommerce scale: per-action rows with claim locking and stuck-claim release (WP-Cron's single serialized `cron` option double-runs under concurrency and bloats with per-run events), a queue runner with time and memory limits for shared hosting, async loopback dispatch for "run soon" work, failure logs, the Tools > Scheduled Actions screen, and `wp action-scheduler run` for a real system cron. Kept small: AS is only the wake-up transport behind an internal `Scheduler` interface (AS adapter, test fake); state lives in `pegwork_runs`.

Licence: AS is GPL-3.0-or-later (`composer.json`, checked 2026-10-18). Pegwork is GPL-2.0-or-later, which permits combination with GPLv3 code: Pegwork's own files keep their GPL-2.0-or-later notices, and the release zip that bundles AS is conveyed as a whole under GPLv3. Compatible with WordPress and BarnCRM for WordPress (GPL); it would only conflict with a GPL-2.0-only component, which Pegwork does not have.

Version: AS 4.2.0 (2026-09-16) requires WP 6.9; 4.0 and 4.1 require 6.8; 3.9.3 (2025-07) is the last supporting Pegwork's WP 6.7 minimum (open question 1). Loading: AS must be included before `plugins_loaded` priority 0, but `module.php` is included during `plugins_loaded`. The module manifest gains an optional `preload` list of files that `pegwork.php` includes at plugin load for enabled modules; AS goes through Composer (`woocommerce/action-scheduler`, loaded by its own bootstrap, not the autoloader) and is listed there. With several copies on a site, AS runs the newest, which is fine for its public API.

### Hard-coding the SI for Good flow in sifg-config (steelman)

About 150 lines of site PHP (daily WP-Cron, `WP_Query`, `wp_mail`, a token handler) would satisfy requirements 1 to 3 sooner, with no tables. Rejected: the spec's Phase 4 deliverable is the automation builder, BarnCRM needs record-triggered and scheduled automation next, the site version has no run log or retry (failures are silent), and it would be re-tested and re-built per site. The engine stays small: everything in it is traced in section 1.

## 7. Actions, webhooks, links

Email (`send_email`): `wp_mail` from the site's default sender. `to` renders to 1 to 5 addresses, each validated with `is_email`. With `html`, sends `text/html` and sets PHPMailer `AltBody` to the text body through a `phpmailer_init` closure scoped to that call; otherwise plain text. A `false` return captures the `wp_mail_failed` message into the step error and retries per policy. The run log stores recipient and subject, not the body.

Outbound webhook (`call_webhook`): async (a step in a background run), so publishing never waits for or depends on n8n. `wp_safe_remote_post`, JSON body `{event, automation, run_id, delivered_at, record: {id, type, status, permalink, fields{public}}}`. Headers `X-Pegwork-Delivery: <run_id>-<step_id>` (stable across retries, for receiver dedupe), `X-Pegwork-Timestamp`, `X-Pegwork-Signature: sha256=` HMAC-SHA256 of `timestamp + "." + body` with the automation's secret. Non-2xx or transport error retries (3 attempts), then the run fails and shows in the error log.

Inbound webhook: `POST /wp-json/pegwork/v1/automations/<key>/webhook`, `permission_callback` verifies the same signature scheme against the automation's secret with `hash_equals`, rejects timestamps more than 300 s off (replay window). Limits: `application/json` only (415), body at most 64 KB checked before decoding (413), 30 requests per minute per automation via a transient bucket (429). Unknown, disabled or non-webhook automations get the same 401 as a bad signature. Optional `X-Pegwork-Delivery` becomes the run's idempotency key. Responds 202 with the run id, never echoes the payload. Payload values reach records only through `Records` (sanitized and validated).

Secrets: one per automation in `pegwork_automation_secrets` (autoload off), generated on first use, never in JSON or exports. `wp pegwork automation secret <key> [--rotate]` prints or rotates it.

Signed links:
- Token: `v1.<base64url(json {a: automation, l: link, r: record_id, e: expiry})>.<base64url(HMAC-SHA256(key, "v1." + payload))>`. Key: constant `PEGWORK_LINK_SECRET` if defined in `wp-config.php`, else option `pegwork_link_secret` (32 random bytes, autoload off, created on module enable). Verified with `hash_equals`, expiry against the clock.
- Stateless on purpose: no table lookup, and repeat clicks are harmless (the steps are idempotent). Rejected: WordPress nonces (user-bound, 24 h life), `wp_salt` as key (a salt rotation would silently kill every outstanding link), stored random tokens (only buy single-use and revocation, which these links do not need; global revocation is `wp pegwork automation link-key --rotate`). Tokens are minted when the template renders, so every email carries a link valid for the full `expires` from its send time.
- URL `home_url( '/?pegwork_link=<token>' )`, handled on `template_redirect` (no rewrite rule to flush). With `confirm: true`, GET renders a minimal page (site name, link label, one button) that POSTs the token; this stops mail scanners that prefetch links from verifying projects by themselves (open question 2). POST runs the link's steps synchronously as a `link` run in the log, then redirects to `success_url` or renders `success_message` (public context). Expired or invalid tokens render an "expired" page (or `expired_url`) with no change. Pages are standalone HTML with `noindex` and `no-store`.

## 8. Admin UX and WP-CLI

Pegwork > Automations (capability `manage_options`, added under the Pegwork menu, created if Phase 2 has not):
- List table: label, key, trigger summary, enabled (JSON value and site override, with a toggle), last run (status, time), next scheduled run (from AS), waiting count, failed in the last 7 days. Row actions: Run now (schedule triggers only; nonce, capability, POST), View log.
- Run log: filter by automation and status; run detail shows each step attempt with status, duration, summary and error; Retry on failed runs.
- Editing stays JSON: definitions are reviewed in git and shipped in `sifg-config`, the gate is a correct cycle not a UI, a form editor for nested conditions and steps is large and would be replaced by the Phase 6 canvas, and the DB source (import) means a later editor writes the same format.

WP-CLI `wp pegwork automation`:
- `list`, `enable|disable <key>` (site override), `log [<key>] [--run=<id>] [--status=<s>] [--limit=<n>]`, `retry <run>`, `secret <key> [--rotate]`, `link-key --rotate`, `link <key> <link> --record=<id>` (prints a signed URL).
- `run <key> [--record=<id>] [--now=<datetime|+offset>] [--sync]`: runs a scheduled automation now (or an event automation against a record). `tick [--now=...]`: resumes due waits. `--sync` executes in-process instead of via AS. `--now` sets the clock for that process only and is refused when `wp_get_environment_type()` is `production`.

## 9. Testing

- Unit: condition interpreter (every op, dates, nesting limits), template rendering and contexts, token sign/verify/expiry/tamper, duration parsing, next-run computation across DST.
- Integration (wp-phpunit): record events from REST saves (meta and terms in the same request produce one event with correct `changed`), auto-draft silence, trash and delete, Records API events with origin; registry fixtures for automations (JSON Schema agreement); executor claim race (two executions, one run); time budget re-enqueue; depth cap with a self-triggering fixture; retention prune; inbound signature, size, rate, replay; outbound signature and retries via `pre_http_request`. One test runs real AS end to end; the rest use the fake scheduler.
- Time: `FrozenClock` (`now()`, `advance( 'P12M' )`) and `FakeScheduler` (`drain( $until )` runs due jobs in order). The engine never calls `time()` or `current_time()` directly.
- Gate test `tests/Integration/StalenessCycleGateTest.php` (fixture `examples/sifg` with its automations, mock PHPMailer):
  1. Clock 2026-11-02 09:00. A contributor creates a pending Project; an editor publishes via REST; drain. Assert `last_verified` = 2026-11-02 and one email to `contact_email` containing the permalink.
  2. Advance to 2027-11-01: sweep sends nothing. 2027-11-02: one email with a link; run `waiting` until 2027-12-02. Sweep again 2027-11-03: no second email.
  3. Advance to 2027-12-02, drain: Project status `paused`, `paused_on` = 2027-12-02, `paused_from` = `active`; pause notice with a fresh link sent; anonymous REST GET shows `paused` and no `contact_email`.
  4. 2028-01-15, POST the original link: `last_verified` = 2028-01-15, status back to `active`, pause fields cleared, redirect to the success URL.
  5. Second Project clicks during the wait: at day 30 its run completes `stopped_at: still_stale`, status unchanged.
  6. Tampered token and expired token: no change. Every run and step is in the log with no failures.
- Local runbook (siforgood.local, Local's mail catcher): publish a test Project, then `wp pegwork automation run sifg_staleness_check --now=+12months --sync`, open the email, `wp pegwork automation tick --now=+13months --sync`, check Paused on the front end, click the link, check `log`.

## 10. Security checklist

- Admin screens, toggles, Run now and Retry: `manage_options`, nonces, POST only. CLI unrestricted (shell access already implies admin).
- Runs act as the system, not a user; their authority is the committed definition (imported by CLI only). Link clicks may only run that link's declared steps on the token's record; inbound webhooks only their automation.
- Private fields: allowed in email and record values, rejected at validation time in public contexts; outbound bodies carry public fields only; the run log (admin only) stores recipient addresses, pruned by retention.
- Secrets and link key: autoload off, never exported, never logged, compared with `hash_equals`; HMAC-SHA256 throughout.
- Outbound: `wp_safe_remote_post` (blocks private IPs), timeouts at most 15 s. Inbound: size, type, rate, replay limits; no payload echo.
- Link pages: `noindex`, `no-store`, escaped output, POST to act when `confirm` is on.
- Conditions and templates: data only, interpreted; no callables (existing `check_callables` also covers automations).

## 11. Non-goals

Visual canvas and branching (Phase 6); form-based definition editor; per-record long-lived timers; user-authored PHP actions; SMS or other channels; email templates beyond text plus simple HTML; email open or click tracking; private fields in outbound webhooks; multisite; real-time (sub-minute) scheduling guarantees.

## 12. Open questions (recommended answers)

1. Action Scheduler version vs WP minimum: raise Pegwork's minimum to WP 6.9 and bundle current AS 4.2.x (maintained line; SI for Good is a new site on current WP). Fallback: pin AS 3.9.3 and keep WP 6.7.
2. Confirm step on the "still active?" link: keep `confirm: true` (email-scanner prefetch would otherwise verify every project), so it is one click in the email plus one button.
3. On pause, email the owner ("we paused your listing, reactivate here") with a freshly minted link: yes. Links expire 365 days after each send, so both the original and the new link reactivate.
4. Projects with empty Last verified are skipped by the sweep: backfill them to their publish date once at launch (a one-off WP-CLI line in the launch runbook), so none silently escape the yearly check.
5. Forms event payloads: Forms design to include `form` and, when a record is created, `record_id` in `form.submitted` and `form.confirmed`.
6. Reactivation restores the pre-pause status from `paused_from` (default `active`): confirm, rather than always setting Active.

Decisions (2026-10-18, main session under Brandon's build goal; Brandon reviews at the end):
1. Keep the WordPress 6.7 minimum Brandon approved: pin Action Scheduler 3.9.3 behind the `Scheduler` interface. Moving to WP 6.9 plus AS 4.2.x is on Brandon's review list; the interface keeps that a dependency bump, not a redesign. Before pinning, check AS 3.9.3 for known security advisories and confirm it loads cleanly on WP 6.7 and latest in CI; if it fails either, stop and report.
2. Confirm step on: yes. 3. Pause notice with a fresh link: yes. 4. Launch backfill: yes, as a WP-CLI line in the SI for Good runbook. 5. Forms payloads include `form` and `record_id`: yes (Phase 3 design agrees). 6. Restore from `paused_from`: yes; the SI for Good pause steps also set Needs volunteers off and store the prior value in a private `paused_volunteers` field, restored on reactivation (config only).
7. API version stays 0.2.0 for every addition in this build cycle. The `automations` registry section is built in one shared registry PR together with `listing`, `forms`, and `options_pages`, so PR 2 here moves into that shared PR. E2E tooling comes from one shared harness PR used by all phases.
8. Action Scheduler is not bundled in this cycle (supersedes decision 1's pin; checked 2026-10-18 in PR 3). AS 3.9.3, the last release supporting WordPress 6.7, has the unpatched insecure deserialization flaw reported in woocommerce/action-scheduler#1318: `ActionScheduler_DBStore::fetch_action()` calls `unserialize()` on the stored schedule without `allowed_classes` (`classes/data-stores/ActionScheduler_DBStore.php` line 397 at 3.9.3), which gives PHP object injection to anyone who can write the actions table. It is fixed only in 4.1.0 ("Security" changelog entry, PRs #1341 and #1344); 4.1 requires WordPress 6.8 and 4.2 requires 6.9; there is no 3.x backport. No GHSA, CVE, WPScan or Patchstack entry exists. The WordPress 6.7 minimum stands, so PR 3 implements the `Scheduler` interface with WP-Cron (`WpCronScheduler`, single events keyed by run id), made safe by the existing design: `pegwork_runs` is the source of truth, the atomic claim absorbs concurrent cron spawns, the 20 s time budget bounds each wake-up, and the daily maintenance job resumes runs whose wake-up was lost. The manifest `preload` key is dropped (only AS needed it), and so is the licence note (nothing GPLv3 is bundled). An AS 4.2+ adapter is deferred until the minimum moves to WordPress 6.9 (on Brandon's review list); it implements the same three `Scheduler` methods, so it stays a dependency bump. Sections 6 and 9 still say "AS" where WP-Cron now does the job; the real-scheduler test runs on WP-Cron.

## 13. PRs (each merges with CI green)

1. Event bus and Records API (core): `Pegwork\Api\Events`, `Records`, `RecordEvents`, API docs, VERSION 0.2.0. Accept: a block-editor-style REST create then update with meta and terms emits exactly one `created` then one `updated` with correct `changed` and `old`; pending to publish adds `status_changed`; auto-draft emits nothing; trash and delete covered; `Records::update` emits one event with `origin.depth`; module-boundary check passes with a fixture module listening on `pegwork_event_record_updated`.
2. Registry `automations` section (core): JSON Schema, Validator (conditions, templates, contexts, durations), Normalizer, Exporter/Importer, `Registry::automations()`, fixtures. Accept: schema-fixtures job green with new valid and invalid fixtures (unknown op, unknown field, private field in webhook, bad duration, duplicate step id, callable); export/import gate extended with automations, byte-identical, second import 0 changes.
3. Automation engine (module): `Scheduler` with a WP-Cron implementation (decision 8; no manifest `preload`, no AS) and `Clock`, tables, executor with claims and time budget, event triggers, `update_record`, `create_record`, `condition`, depth cap, maintenance and retention, CLI `list|log|enable|disable`. Accept: approval fixture sets `last_verified` on pending to publish and logs a completed run; duplicate execution yields one run; self-triggering fixture stops at depth 3 with a `skipped` row; real-scheduler (WP-Cron) test passes on the CI matrix.
4. Email, schedules and waits: `send_email`, schedule trigger with `for_each`, idempotency, `wait`, retries, CLI `run|tick|retry` with `--now`. Accept: approval email captured with permalink; sweep plus wait test with `FrozenClock` (one email per cycle, resume after 30 days); `--now` refused in production.
5. Signed links and the gate: tokens, `template_redirect` handler, confirm and expired pages, `link` and `link-key` CLI, `examples/sifg` automations and fields. Accept: `StalenessCycleGateTest` passes (section 9); tampered and expired tokens change nothing.
6. Admin screens: Automations list, toggles, Run now, run log and detail, Retry. Accept: manual checklist on siforgood.local; subscriber and editor get 403 on every action; Run now creates a logged run.
7. Webhooks: `call_webhook`, inbound REST route, secrets CLI. Accept: signature verified by a test receiver; retries then a logged failure when the receiver is down, publish unaffected; inbound 401/413/415/429 and replay cases; 202 creates a run.
8. `sifg-config` (siforgood repo): automations JSON, fields `paused_on` (date, private) and `paused_from` (text, private), thank-you page, module enabled. Accept: owner runs the Local runbook cycle end to end; schema export re-imports with a clean diff (Phase 4 sign-off).

## Worked example: SI for Good automations (abridged)

`automations/sifg_project_approved.json`
```json
{ "format": 1, "key": "sifg_project_approved", "label": "Approval: verify and email the submitter",
  "trigger": { "type": "event", "event": "record.status_changed", "content_type": "sifg_project",
    "when": { "all": [ { "var": "trigger.old_status", "op": "eq", "value": "pending" },
                       { "var": "trigger.new_status", "op": "eq", "value": "publish" } ] } },
  "steps": [
    { "id": "verify", "type": "update_record", "values": { "last_verified": "{{today}}" } },
    { "id": "notify", "type": "send_email", "to": "{{record.contact_email}}",
      "subject": "{{record.name}} is live on {{site.name}}",
      "text": "Thanks for sharing your project. It is now live: {{record.permalink}}" } ] }
```

`automations/sifg_staleness_check.json`
```json
{ "format": 1, "key": "sifg_staleness_check", "label": "Yearly still-active check",
  "trigger": { "type": "schedule", "every": "day", "at": "09:00",
    "for_each": { "content_type": "sifg_project", "status": ["publish"], "where": { "all": [
      { "var": "record.last_verified", "op": "not_empty" },
      { "var": "record.last_verified", "op": "lte", "value": { "date": "today", "offset": "-P12M" } },
      { "var": "record.sifg_project_status", "op": "ne", "value": "paused" } ] } } },
  "idempotency": "{{record.id}}:{{record.last_verified}}",
  "steps": [
    { "id": "ask", "type": "send_email", "to": "{{record.contact_email}}",
      "subject": "Is {{record.name}} still active?",
      "text": "One click keeps your listing live: {{link.still_active}}" },
    { "id": "grace", "type": "wait", "for": "P30D" },
    { "id": "still_stale", "type": "condition",
      "when": { "var": "record.last_verified", "op": "lte", "value": { "date": "today", "offset": "-P12M" } } },
    { "id": "pause", "type": "update_record", "values": { "paused_from": "{{record.sifg_project_status}}",
      "paused_on": "{{today}}", "sifg_project_status": "paused" } },
    { "id": "tell", "type": "send_email", "to": "{{record.contact_email}}",
      "subject": "{{record.name}} is paused on {{site.name}}",
      "text": "We paused your listing. Reactivate it any time: {{link.still_active}}" } ],
  "links": { "still_active": { "expires": "P365D", "confirm": true, "button": "Yes, it is still active",
    "success_url": "/still-active-thanks/",
    "steps": [
      { "id": "verify", "type": "update_record", "values": { "last_verified": "{{today}}" } },
      { "id": "was_paused", "type": "condition",
        "when": { "var": "record.sifg_project_status", "op": "eq", "value": "paused" } },
      { "id": "restore", "type": "update_record", "values": {
        "sifg_project_status": "{{record.paused_from | default:\"active\"}}", "paused_from": "", "paused_on": "" } } ] } } }
```
Why the key works: it changes only when Last verified changes, so the daily sweep emails once per cycle; a paused Project leaves the sweep through the status filter until reactivated, which resets Last verified and starts the next cycle. Hiding the volunteer button for Paused is the theme's listing template (Phase 2), not the engine. Optional `sifg_project_published_webhook` (`call_webhook` to n8n on pending to publish) ships `enabled: false` and is switched on with `wp pegwork automation enable`.
