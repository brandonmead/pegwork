# Automation module

Module `automation` (`modules/automation/`, namespace `PegworkAutomation\`): runs the registry's automations (`docs/registry-reference.md#automations`) in the background, logs every run, and adds `wp pegwork automation` and Pegwork > Automations. Design: `docs/phase-4-design.md` sections 6 and 8 and its 2026-10-18 decisions. Uses only `Pegwork\Api` (Records, Module, `ADMIN_MENU`), `pegwork_registry()` and WordPress.

What it does (Automation v3, plugin 0.3.0):
- Triggers: record and form events, schedules (`for_each` sweeps), inbound webhooks with per-automation secrets, and `manual` (staff start it from the record editor, the list's Run now or `run`; [Manual trigger](#manual-trigger)).
- Steps: `update_record`, `create_record`, `condition`, `decision` (branches run along the step tree; design `docs/phase-6-design.md` sections 1 to 3), `send_email`, `call_webhook` (with `record` for a record's public fields), `wait`, the reads `get_record` and `find_records`, `relate`, and `loop` ([Reads, relate and on_error](#reads-relate-and-on_error), [Loops](#loops); design `docs/automation-v3-design.md`). `on_error: continue` lets a run go on past a failed step.
- Templates: merge tags, the filters `| date`, `| default`, `| add` and `| number`, and `{{#each}}` blocks.
- Runs: retries, signed links (`{{link.*}}`, the public link handler, link runs), WP-Cron wake-ups, maintenance, per-item delivery IDs and no default retries in loop bodies, a step limit of 600, and Schema 2 (`pegwork_run_steps.iteration`). A run of a definition the module cannot run in full (a step type without a handler; `Engine::unsupported()`, which guards a definition from a newer registry) fails with `unsupported_step` before its first step, so nothing is half done (no retry).
- CLI `list`, `log`, `run`, `test`, `tick`, `retry`, `enable`, `disable` (`--now`, `--sync`), `link`, `link-key`, `secret`; admin screens (list, toggles, Run now, run log and detail grouped by branch and loop item, Retry) and the block editor ([Editor](#editor)) with Test run.

Automation v4 (in progress, `docs/automation-v4-design.md`): `trash_record` runs ([Trash](#trash)).

[Trying the BarnCRM example flows](#trying-the-barncrm-example-flows) runs every kind of flow on a test site.

Enable: `wp pegwork module enable automation` (or Pegwork > Modules). Disabled, nothing is included: no listener, no tables touched, no runs.

## How a run happens

1. A record event fires (`docs/events.md`). For each automation with an `event` trigger on that name (narrowed by `content_type` or `form`) and effectively enabled, the listener evaluates `when` against the record and payload, synchronously (old values exist only now). Bound record: a `record.*` event binds its payload `id`. A form event binds its payload `record_id` when the trigger binds its form's record (`Triggers::binds_form()`, the registry rule read against the current form: `form.confirmed`, or `form.submitted` of a form without `confirm`, of a form with a `target`); other form events run without a record, on `trigger.*` only.
2. Loop cap: if the payload's `origin.depth` is 3 or more (filter `pegwork_automation_max_depth`), it inserts a `skipped` run with error `loop_depth: ...` and runs no steps. Then a binding form event whose `record_id` is missing or null (the form wrote nothing) inserts a `skipped` run with error `no_record: ...`.
3. Idempotency: with `idempotency`, the rendered key must be new for that automation (unique index on `automation, idempotency_key`; keys over 191 bytes are stored as `md5:<hash>`). A repeat creates nothing.
4. It inserts a `queued` run (trigger payload in `context`, capped at 16 KB) and schedules `pegwork_automation_run` [run id] for now. The saving request never runs steps.
5. On wake-up the executor claims the run atomically (`UPDATE ... SET status = 'running' WHERE id = %d AND (status = 'queued' OR (status = 'waiting' AND resume_at <= now))`, proceeding only on 1 row), so a duplicate WP-Cron firing or a re-entrant call does nothing. A removed automation cancels the run (`automation_removed`), a disabled one too (`automation_disabled`), a `current_step` missing from the definition fails it (`definition_changed`).
6. Steps run from `current_step` along the step tree (`Plan`, below), the record re-read through `Records::get()`. Each attempt writes one `pegwork_run_steps` row. Writes go through `Pegwork\Api\Records` with origin `{ automation, run_id, depth: run depth + 1 }`, so they are validated and emit events that may start further runs.
7. Time budget: before each step after the first in a wake-up, if 20 s have passed (filter `pegwork_automation_time_budget`), the run goes back to `queued` at that step and is re-scheduled (inside a loop too: the stored `context.loops` resumes the same item). At most 600 step attempts per run (`step_limit`, filter `pegwork_automation_step_limit`; it was 100 before loops; the registry bounds a definition at 500 without retries, so the limit is a safety net for retries).
8. Waits and retries (one mechanism): the run becomes `waiting` with `resume_at` (UTC) and `current_step` (the step after a `wait` in the tree, which may be inside a branch or after the decision; the same step for a retry), and a wake-up is scheduled for `resume_at`. The claim only takes a waiting run once `resume_at` has passed, so an early or duplicate wake-up does nothing; the daily maintenance (or `tick`) re-schedules a waiting run whose wake-up was lost. A `wait` with nothing after it in the tree completes the run at once.
9. End: `completed` (a false `condition` stores outcome `stopped_at:<id>` in `context.outcome`), or `failed` with the step's error (`<code>: <message>`, for example `pegwork_invalid_record: ...`; after retries ` (after N attempts)` is appended).

## Decisions and the step tree

A `decision` (`docs/registry-reference.md#decisions`) holds ordered branches `{ label, when, steps }` and an optional `otherwise`. Its handler (`Steps/Decision.php`) evaluates each `when` in order with `Conditions::evaluate()`; the first that holds wins, else `otherwise`, else no branch. It returns `StepResult::branch( $index, $summary )` (index from 0, -1 for otherwise, null for no match) and has no output. Summary `{ branch, index }`: the label (`otherwise`, or null with no match) and the index.

`Plan` (`src/Plan.php`) is built from the step list each wake-up. Step ids are unique across the tree (registry rule), so `current_step` alone fixes the position and no column changed:
- `next( $id )`: the following sibling, or after the last step of a branch the next of the enclosing decision, or null at the end of the tree.
- `after_branch( $decision, $index )`: the branch's first step, or `next( $decision )` for an empty branch or no match.
- `depth()`, `location()` (enclosing decision, branch index and label, whether the step starts its branch), `ids()`, `Plan::types()` (every type in the tree, used by the webhook secret check), `Plan::for_run()` (the plan of a run under the current definition, for the run detail and `log --run`).

The executor is a cursor loop over the plan: run the step at the cursor, then move to `after_branch()` for a decision or `next()` otherwise. Everything else is unchanged and applies inside branches: the time budget re-queues at the next step in the tree, a wait or retry stores the step it resumes at, a false `condition` ends the whole run (`stopped_at:<id>`; inside a loop body it skips the item instead, see [Loops](#loops)), a decision attempt counts toward the step limit, and the decision is not evaluated again on resume (the stored step id fixes the path). A flat Phase 4 list is a tree of depth 0 and runs exactly as before.

Definition edits: a `current_step` that is no longer in the tree fails the run (`definition_changed`); a step that moved (for example into a branch) continues at its new position and then follows the new tree, without evaluating the decision it now sits under.

Outputs: a step inside a branch reads outputs of steps on its path (`steps.<id>.*`); the registry rejects references to steps that may not have run. Writes to the bound record (`update_record` refreshes it through `Context::refresh_record()`) are read by later steps in the same wake-up, inside or after the branch.

Effective enabled state: option `pegwork_automation_overrides` (`key => on|off`, set by `enable`/`disable`) wins over the JSON `enabled`.

## Schedule triggers

`{ "type": "schedule", "every": "hour|day|week", "at": "HH:MM", "for_each": { ... } }` (`docs/registry-reference.md#triggers`).

- One WP-Cron event `pegwork_automation_schedule` [key] per effectively enabled schedule automation, created on boot when missing (or pulled in when the pending one is later than the definition now gives). Times are site time and keep the wall clock across DST: `day` at `at` (default `00:00`), `hour` at the minute of `at` past each hour, `week` at `at` on the first day of the week (Settings > General, `start_of_week`). A firing reschedules itself first; a disabled or removed automation is not rescheduled (boot re-creates it once enabled).
- Without `for_each`: one run with no record per firing (`trigger_type` `schedule`, `trigger` empty). Its `idempotency` renders without a record (for example `digest:{{today}}`).
- With `for_each`: a sweep over posts of `content_type` with `status` (default `publish`) in ID order, 50 IDs per query. Each record is read through `Records::get()`, `where` is evaluated against it, and a match creates one run bound to it unless the idempotency key exists. Sweeps need an idempotency key such as `{{record.id}}:{{record.last_verified}}`: it changes only when the data changes, so a daily sweep emails once per cycle, and pruning old runs cannot cause a re-send while the data is unchanged. Without one, every firing re-runs every match.
- `max_runs` (default 200) caps the runs one sweep creates. The next match after the cap stops the sweep and is logged as a `skipped` run with error `max_runs: ...`; the rest are picked up next firing (records that already have a run are duplicates by key).
- Time budget (`pegwork_automation_time_budget`, 20 s): a sweep that uses it up continues in a chained event `pegwork_automation_sweep` [key, last ID, runs created so far].

## Steps: email, wait, retries

`send_email`: `to`, `subject`, `text` (required), `html`, `reply_to`; all templates.
- From the site's default sender via `wp_mail()`, always `text/plain` for the text part (a site-wide `wp_mail_content_type` filter cannot switch it to HTML). `to` is 1 to 5 addresses separated by commas or semicolons in the template (`too_many_recipients`); each part is an address (a literal or a field such as `{{record.contact_email}}`) or `{option:<page>.<field>}`, read with `pegwork_get_option()` (options pages, core). The template is split before rendering, so a rendered value is one recipient and a comma or semicolon in it fails `is_email()` instead of adding recipients. Option tags are taken from the template, never from rendered values, so record data cannot name one. Every address must pass `is_email()` (`invalid_recipient`); an empty list is `no_recipient`; a site without `pegwork_get_option()` gives `options_unavailable`. Duplicates are dropped case-insensitively.
- `subject` and `text` render as text: taxonomy keys show term names, lists join with ", ", and the HTML entities WordPress stores in titles and the site name are decoded (as core's own emails do). Line breaks are removed from the subject. Without `html` the mail is `text/plain; charset=UTF-8`.
- With `html`: sent as `text/html` with `text` as the plain-text alternative (PHPMailer `AltBody`, set by a `phpmailer_init` callback added and removed around this one call). Every merge-tag value is escaped: `esc_url` for `record.permalink`, `record.edit_url`, `site.url`, `link.*` and `url` fields, `esc_html` for everything else (no double encoding, so a stored `&amp;` renders "&"). The authored template is trusted (committed config, imported by CLI only).
- Privacy: private fields may appear in the email (it goes to the record's own contact by design) and nowhere else. The step summary holds `to` masked (`o***@example.org`), the subject rendered with private fields as `[private]`, and `html` (bool); never the body. Error messages have recipient addresses masked.
- `wp_mail()` returning false is retryable; the `wp_mail_failed` message becomes the error (`mail_failed: ...`). Recipient errors and a body that renders empty (`empty_body`; PHPMailer refuses it) are not retried.

`wait`: `for` (ISO 8601, `PT1H` to `P2Y`). Calendar units move in site time (`P30D` keeps 09:00 across a DST change). Summary `for`, `resume_at`. Or `until` a date field of the run's record (next section).

### Wait until a date

`wait` with `until: { var: "record.<date field>", offset, at }` (Automation v4, `docs/automation-v4-design.md` section 3.2; shape in `docs/registry-reference.md#date-waits`). The target is the date at `at` (default 09:00) in site time, moved by the signed `offset`: day, week, month and year units keep the wall-clock time across a DST change; hour, minute and second units are elapsed time. `-P3D` with `at` 09:00 is "3 days before, at 09:00".

The cursor stays on the wait step (`StepResult::wait( ..., stay: true )`, stored like a retry), so every wake-up reads the field again and decides again:

| Case | What happens |
|---|---|
| Date in the future | Waits until the target, at most 2 years per wait (a longer target wakes at 2 years and decides again). Summary `until`, `date`, `resume_at` |
| Target now or past | Continues at once. Summary `until`, `date`, `due: true`, `late` (the target had already passed at the first arrival). Authors who must not act on overdue records add a condition, as `barn_task_due_reminder` does (`record.due_on gte today`) |
| Date empty | Parks: waits for a date, at most 2 years from the first arrival (`context.until.<id>.since`), then stops (`stopped_at:<id>`, summary `no_date`). The run log shows "Waiting for Due on". Summary `until`, `waiting_for_date` |
| Record trashed or deleted | Stops (`stopped_at:<id>`, summary `no_record`) |
| Date moved later, earlier or cleared during the wait | The listener below wakes the run now and it decides again. Without an event (a direct `update_post_meta()`), the original wake-up decides again: a later date waits again, but a date moved earlier is only acted on at the old time (known limit) |

`date` in the summary is `[private]` for a private field. `context.until.<id>` holds `since` and `empty` while the cursor is at the wait and is removed when the step completes. Each decision is one logged attempt toward the step limit (600); the run detail labels the second and later ones "Checked the date again" (`Format::wait_label()`), and "Stopped at wait <id>" in the outcome.

Listener: `Waits::nudge()` on `pegwork_event_record_updated`. An index built once per registry maps content type to the date waits of every automation. When the event's type has none, its `changed` holds neither a waited field nor `status`, or every matching automation is disabled, it returns without a query. Otherwise one query on the `record_id` index finds the record's waiting runs, and each run waiting at a matching step gets `resume_at` set to now (atomically, only while it still waits at that step) and a wake-up now. No step runs in the saving request. A run waiting for a retry at another step and runs of disabled automations (waking them would cancel them early) are left alone.

Test run: "Wait before_due: until 3 days before Due on (March 20, 2027) at 09:00, that is March 17, 2027 9:00 am. When it wakes it checks the date again.", or "Due on is empty: it would wait for a date (up to 2 years)", or "That time has passed: it continues at once." The walk continues past it.

Retries: a failed attempt is retried when the failure is retryable and the step's policy has retries left: the run-step row is `retrying`, the run waits at the same step (`error` `retrying: <error>` meanwhile), and the attempt number counts up per step. Policy: the step's `retry` (`max`, `backoff`: one duration for every retry, or a list with the last entry repeating; `backoff` omitted means the type's default, else `PT15M`), else the type default: `send_email` 2 retries `PT15M` apart, `call_webhook` 3 after `PT1M`, `PT10M`, `PT1H` (4 attempts in all), others none. Configuration failures (`unsupported_step`, recipient errors, bad durations) are never retried. `wp pegwork automation retry <run>` resumes a failed run from its failed step with the policy counted afresh.

## Scheduler and clock

- `Scheduler` (`src/Scheduler/Scheduler.php`): `schedule( $hook, $args, $timestamp ): bool` (replaces a pending firing with the same hook and args), `unschedule( $hook, $args )`, `next( $hook, $args ): ?int`. It is only the wake-up transport; `pegwork_runs` is the source of truth.
- `WpCronScheduler` (default): WP-Cron single events. When it schedules something due now, the request calls `spawn_cron()` at shutdown (filter `pegwork_automation_spawn_cron`, skipped inside cron and with `DISABLE_WP_CRON`), so a run does not wait for the next visitor. Production sites run WP-Cron from a server cron ([Production scheduling](#production-scheduling)).
- No Action Scheduler (decision 8 in the design; decided 2026-10-22: the WordPress minimum stays 6.7 and there is no Action Scheduler adapter). WP-Cron plus a server cron is the production setup.
- `InlineScheduler` (CLI `--sync` and `--now`): holds `pegwork_automation_run` and `pegwork_automation_sweep` wake-ups in memory and fires the due ones in process, in time order, including the follow-ups they schedule; other hooks pass to the site scheduler, and what is still pending at the end (a wait or retry not yet due) is handed to it, replacing any pending firing for the same run.
- `Clock` (`now(): DateTimeImmutable`, UTC): `SystemClock`, `FrozenClock` (`set()`, `advance( 'P12M' )` in site-time calendar units). Business time (`today`, `now`, date operands, stored timestamps, maintenance time, schedule times, waits, backoff) comes only from the clock; the time budget uses wall time. `PegworkAutomation\Engine::use( $clock, $scheduler )` swaps both (tests, `--now`, `--sync`).

## Maintenance

Daily `pegwork_automation_maintenance` at 03:00 site time (self-rescheduling; boot re-creates it if missing):
- re-schedules `queued` runs idle for 10 minutes and `waiting` runs past `resume_at` that have no pending wake-up (lost WP-Cron events, or events that fired while the module was disabled); `tick` runs only this part, replacing pending wake-ups;
- marks `running` runs whose last claim is over 15 minutes old as `failed` (`interrupted: ...`), never repeating a side effect automatically;
- prunes `completed`, `skipped` and `cancelled` runs after 90 days and `failed` after 180 (option `pegwork_automation_retention_days` `{ done, failed }`, filter of the same name), with their steps. `queued`, `running` and `waiting` runs are never pruned.

## Production scheduling

Why a server cron matters: WP-Cron is not a clock. It runs due events only when a request comes in (a page view spawns `wp-cron.php` in the background). On a quiet site a due run (an approval email, the end of a 30-day wait, a retry, a daily sweep) can be hours or days late; with `DISABLE_WP_CRON` and no server cron nothing runs at all. Production sites run WP-Cron from a server cron.

Setup on any host:
1. In `wp-config.php`, above the "stop editing" line: `define( 'DISABLE_WP_CRON', true );` (page views stop spawning WP-Cron).
2. In the crontab of the site's system user:
   ```
   */5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
   ```
   This runs every due event: run wake-ups, waits and retries, schedule firings and sweep continuations, the daily maintenance, and core's own events. A run queued by a save waits up to 5 minutes (the shutdown spawn is skipped with `DISABLE_WP_CRON`); use `*/1` if that is too slow.
3. Check Tools > Site Health (below). The check passes once the first cron run has been seen.

Which command: `wp cron event run --due-now`, not `wp pegwork automation tick`. `tick` only re-schedules waits past `resume_at` and idle queued runs; it fires no schedule, sweep, maintenance or core event, and without `--sync` the wake-ups it creates still need WP-Cron. It stays a manual recovery and `--now` tool. There is no Pegwork-specific cron command because none is needed.

Cloudways (managed Apache plus nginx, WP-CLI available). From Cloudways' public docs on 2026-10-22; items marked "verify" are not confirmed there:
- Simplest: Cron Optimizer. Applications > the application > Application Settings, turn on Cron Optimizer (new Unified Experience panel). Cloudways then runs WordPress cron from the server with WP-CLI and logs to `/home/<hostname>/<application user>/logs/wp-cron.log`. It staggers jobs on busy servers (50+ applications). Verify: the interval, and whether it sets `DISABLE_WP_CRON` (if not, add it yourself).
- Manual: add the `define` to `public_html/wp-config.php` (SFTP or SSH as the master user). Then Applications > the application > Cron Job Management > Advanced, add the line above with the application path, for example `*/5 * * * * cd /home/master/applications/<app folder>/public_html && wp cron event run --due-now --quiet`, and Save Changes. The application path is the application's `public_html` (Cloudways' guide uses `cd applications/<app folder>/public_html/` from the master user's home; verify the absolute path in the panel's Application Details or with `pwd` over SSH). Verify that `wp` is on the cron job's `PATH`; if not, use the full path printed by `which wp` over SSH.
- Use one of the two, not both.

Reading the Site Health result (Tools > Site Health > Status, test "Pegwork automation scheduling", badge Pegwork; while the module is enabled):

| Result | Meaning | What to do |
|---|---|---|
| Passed: "Pegwork automations are running on time" | Nothing overdue, maintenance on schedule. With `DISABLE_WP_CRON` it shows when the last cron run was; without it, it notes that runs depend on visitors. | Nothing; without `DISABLE_WP_CRON` consider the setup above. |
| Critical: "N automation runs are overdue" | A `waiting` run more than 15 minutes past `resume_at`, or a `queued` run idle for 15 minutes. Cron is not running often enough. | Set up the server cron. The runs execute late once it runs (nothing is lost). The action links to the run log. |
| Critical: "DISABLE_WP_CRON is set, but ..." | No cron run seen for 2 hours (or ever). | Add or fix the server cron. |
| Should be improved: "The daily maintenance job is not scheduled" or "has not run for over 26 hours" | Lost wake-ups are not being recovered. Boot re-creates a missing job, so "not scheduled" means scheduling itself fails. | Fix cron; check for a plugin blocking `wp_schedule_single_event`. |

The cron run time: every cron process (`wp-cron.php`, or `wp cron event run` that ran at least one event) writes option `pegwork_automation_last_tick` (Unix time, autoload off) at shutdown, at most once a minute. Core schedules an hourly event, so a working server cron refreshes it at least hourly. The same problems show as one notice on Pegwork screens to users who can manage options, linking to Site Health when they can view it (`src/Health.php`).

What recovers on its own: runs live in `pegwork_runs`, so nothing is lost while cron is down; their wake-ups stay pending and fire on the next cron run. The daily maintenance re-schedules runs whose wake-up was lost and marks runs interrupted mid-step as failed (never repeating a side effect); a schedule that missed several slots fires once when cron resumes, then keeps its regular times. Maintenance cannot make anything punctual: it also runs only when cron runs.

## Tables

Installed and upgraded with `dbDelta` on boot when option `pegwork_automation_db_version` differs from `Schema::VERSION` (2; version 1 was Phase 4, 2 adds `pegwork_run_steps.iteration`, and the upgrade only adds that nullable column, so runs and step rows, waiting runs included, are kept and resume as before). Dropped by `modules/automation/uninstall.php` (run by Pegwork's `uninstall.php`), which also clears both cron hooks.

`{prefix}pegwork_runs`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `automation` | varchar(64) | key |
| `trigger_type` | varchar(16) | `event`, `schedule`, `manual` (a `manual` trigger started by staff, or `run --record` on an event automation), `link` (a signed link click; runs the link's steps), `webhook` (a verified inbound request) |
| `record_id` | bigint unsigned null | bound record |
| `status` | varchar(16) | `queued`, `running`, `waiting`, `completed`, `failed`, `cancelled`, `skipped` |
| `current_step` | varchar(64) | step running or next to run (a step id anywhere in the tree) |
| `resume_at` | datetime null | UTC; when a `waiting` run (wait or retry) may resume |
| `depth` | tinyint unsigned | trigger `origin.depth` |
| `idempotency_key` | varchar(191) null | |
| `definition_hash` | char(32) | md5 of the canonical definition at trigger time |
| `context` | longtext | JSON: `event`, `trigger` (payload), `steps` (outputs), `outcome`, `retries` (step ID => automatic retries made), `link` (link runs: the link whose steps run), `reads` (records read so far), `loops` (the running loop's `ids` and `index`, only while it runs) |
| `error` | text null | reason or `<code>: <message>` |
| `created_at`, `claimed_at`, `started_at`, `finished_at` | datetime | UTC; `claimed_at` is the last claim (stuck detection, orphan idle time) |

Indexes: `(status, resume_at)`, `(automation, created_at)`, `(record_id)`, unique `(automation, idempotency_key)`.

`{prefix}pegwork_run_steps`: `id` PK, `run_id`, `step_id` varchar(64), `step_type` varchar(32), `attempt` smallint (per step, counting retries; inside a loop per step and item), `iteration` smallint null (Schema 2: the 1-based loop item of a body row, null elsewhere), `status` (`ok`, `failed`, `retrying`), `started_at`, `duration_ms`, `summary` (JSON: `record_id`, `keys`, `content_type`, `status`, `passed`, `for`, `resume_at`, decision `branch` and `index`, email `to` masked and `subject` with private fields hidden; never field values of private fields), `error`. Index `(run_id)`.

Privacy: `context.trigger` may hold private values (`old.*`); it is never printed. The CLI shows statuses, summaries and errors only.

## Steps

`Step` interface (`src/Steps/Step.php`): `run( array $step, Context $context, array $run ): StepResult`. `StepResult::ok( $output, $summary )`, `::stop( $summary )` (ends the run as completed), `::wait( $resume_at, $summary )` (the run waits, then continues with the next step), `::branch( $index, $summary )` (decisions: the branch to take, -1 for otherwise, null for none), `::fail( $code, $message, $summary, $retryable = true )` (retried per policy when retryable). Handlers are registered in `Engine::__construct()` by type; the executor decides retries (`Retry::delay()`).

| Type | Does | Output |
|---|---|---|
| `update_record` | `Records::update( record or bound, values )`; later steps read the written values (the record is dropped from the read cache, the bound record re-read) | `record_id` |
| `create_record` | `Records::create( content_type, values, status )` | `record_id` |
| `get_record` | one record of `content_type`, by `record` or the first of `related` and `filter` (below) | `found`, `record_id`; `steps.<id>.record.<key>` |
| `find_records` | up to `limit` records plus `count` and `sum` over every match (below) | `count`, `sum.<field>`; `steps.<id>.records` for `{{#each}}` and loops |
| `loop` | runs its body once per record of a find (no handler: the executor's cursor, see [Loops](#loops)) | in the body `item.<key>` and `index`; after it `count` |
| `relate` | `Records::relate()` or `unrelate()` of one link (below) | none |
| `condition` | continues when `when` holds, else stops (`stopped_at:<id>`) | none |
| `send_email` | `wp_mail()`, see above | none |
| `call_webhook` | signed `wp_safe_remote_post()`, see Webhooks | `status` |
| `wait` | waits `for` | none |
| `decision` | takes the first branch whose `when` holds, else `otherwise`, else continues after itself (see above) | none |

`values`: a non-string literal is passed as is; a string that is exactly one merge tag passes the raw value (`{{record.tags}}` stays a list, `{{record.urgent}}` a boolean); other strings are rendered. Taxonomy keys accept a list or a comma-separated string of slugs; a list written to a field (no field type holds one) becomes comma-separated text, so `"paused_from": "{{record.sifg_project_status}}"` stores `active`. Relationship keys whose "from" is the written type take a list of IDs, one ID or comma-separated IDs (`"task_gift": "{{record.id}}"`) and replace the record's links; an empty value clears them. The Records API sanitizes, validates and rejects (status changes are not possible through it). In a link run, `update_record` may only write the link's own record: a `record` template naming another fails with `link_scope` (no retry).

Conditions (`Conditions::evaluate`): lists compare as sets (`eq` with a scalar means exactly that one slug; `in`/`not_in` any overlap; `contains` list membership); empty is null, '' or []; order operators are false on empty values; numbers compare numerically, booleans as booleans, `Y-m-d` and ISO datetimes as instants in site time, else strings.

Templates (`Templates::render( $template, $context, $mode )`): `raw` (values, idempotency keys: lists as comma-separated slugs, booleans `1`/`0`), `text` and `html` (email, above), `url` (`call_webhook.url`, through `Templates::render_url()`: a tag at the start of the template renders raw, so `{{option:...}}` can hold a whole base URL; every other tag value is `rawurlencode()`d, so a value cannot add query parameters or a fragment, and a space no longer breaks the URL), `log` (private fields as `[private]`, signed links as `[link]`, never minted). `Context::is_private()`, `field_type()` and `taxonomy()` answer for `record.<key>`, `trigger.old.<key>`, read record `steps.<id>.record.<key>`, `{{#each}}` `item.<key>` and `steps.<id>.sum.<field>` paths (the last two through the run's `Plan`, which knows each read step's `content_type`); `is_private()` also treats `trigger.values.<key>` as private when the form item `<key>` binds a private field of the form's target type (masked in the log, rejected in public contexts with `automation_private_field`). `{{link.<name>}}` mints a signed URL for the run's record (see below); `Context` takes the automation key as its sixth argument for this, and without a record or key it renders empty.

Tag syntax: `{{ <path> [| add:"<signed duration>"] [| date | number[:<0-4>]] [| default:"text"] }}`, filters in that order (the registry checks each filter against the path's type).

- `| add:"P2D"`, `| add:"-PT6H"`: moves a `Y-m-d` date (result `Y-m-d`) or an ISO datetime (result ISO in site time) by a signed ISO 8601 duration in site time with calendar units (`Templates::add()`, through `Conditions::offset()`), so `P1D` across a DST change keeps the wall-clock time. On `today`, `now`, date fields and date option fields. `{{today | add:"P2D"}}` on 2026-11-02 gives `2026-11-04`; `{{record.received_on | add:"P2D" | date}}` gives "November 3, 2026" for 2026-11-01.
- `| number`, `| number:2`: `number_format_i18n()` in the site locale (`Templates::number()`): with the decimals given, else whole numbers bare and others with 2 decimals (`1000` gives `1,000`, `1234.5` gives `1,234.50`). On number fields and number outputs (`count`, `sum.*`, `record_id`). Non-numeric values pass through.

- `| date`: a `Y-m-d` value or an ISO datetime becomes the site's date format (Settings > General, `get_option( 'date_format' )`) in the site time zone, through `wp_date()` (`Templates::date()`); anything else is left as it is. `{{record.last_verified | date}}` gives "November 2, 2026". The registry allows it only on `date` fields (`record.<field>`, `trigger.old.<field>`, a date option field); on other paths, including `record.id`, `site.*`, `run.*`, `link.*`, `status`, `type`, `permalink` and `edit_url`, it is `invalid_template`.
- `{{option:<page>.<field>}}`: a field of an options page, read with `pegwork_get_option()` (it renders empty without the options pages feature). Only public fields: a `private` field is `automation_private_field` in every template, the same rule as private record fields in public contexts. Unknown page or field: `unknown_options_page` or `unknown_field`. Field types apply as for record fields (a `url` field is escaped as a URL in HTML, `log` masks nothing because the field is public). The tag also works in `to` and `reply_to` (for example `"reply_to": "{{option:sifg_settings.contact_email}}"`), alongside the `{option:...}` recipient form above.
- `| default:"text"`: used when the value is empty; no other filter applies to it.
- Block `{{#each steps.<id>.records}} ... {{item.<key>}} ... {{else}} ... {{/each}}` (only in `send_email` `text` and `html`, not nested): the body renders once per record of the find, in its order (at most 50), with `item.*` reading that record and every other path still in scope; `{{else}}` renders when there is none. A record deleted or trashed since the find is skipped. HTML escaping is per value as elsewhere, and values are never rendered again (a title that looks like a tag stays text). A template with a block renders at most 64 KB (`Templates::MAX_BYTES`), else the step fails `template_too_large` (no retry).

## Reads, relate and on_error

Design: `docs/automation-v3-design.md` sections 3 and 4 (registry shapes in `docs/registry-reference.md`).

- Query model (`src/Steps/Reads.php`): `related { relationship, of }` (`of` renders a record ID), `filter { all|any: [ { field, op, value } ] }` (`value` a literal, `{ var }` or `{ date, offset }`, resolved here, so core's `Records::find()` gets literals), `status` (default `private` and `publish`; trash never), `sort` (default `id` asc). An operand that resolves empty matches nothing (summary `empty_operand: <field>`, or `related` / `record` when `of` or `record` rendered empty): a lookup by an email the payload lacks never matches the contacts with no email. `ne` and `not_in` also match records with no stored value.
- `get_record` (`src/Steps/GetRecord.php`): by `record` (an ID; a record of another type, another status or in the trash is not found) or the first match. Not finding a record is not a failure: `found: false`, `record_id` null. Summary `content_type`, `found`, `record_id`.
- `find_records` (`src/Steps/FindRecords.php`): `limit` (0 to 50, default 10; 0 loads no IDs) by `sort`, plus `count` and `sum` (number fields, 0 with no matches) from SQL over every match, never from the capped list. Summary `content_type`, `count`, `returned`, the IDs and the sums of public fields only (a sum over a private field stays out of the log). A query core refuses fails `read_invalid` (no retry).
- Outputs are references (`Context`): the run context stores `found`, `record_id`, `count`, `ids` and `sum`, never field values. `steps.<id>.record.<key>` and `{{#each}}` items re-read the record by ID through a per-wake-up cache (`Context::record_by_id()`), which `update_record`, `create_record` and `relate` invalidate by ID (`Context::forget()`). So a step after a write reads the written value of any record, and a step after a wait reads current values. Private fields of read records follow the bound record's rules: allowed in conditions, emails, `values` and idempotency; `[private]` in the log; rejected by the registry in webhooks and link pages.
- Read limit: the records reads return (and later loop items) count per run in `context.reads`, at most 500 (filter `pegwork_automation_read_limit`). A read past it fails with `read_limit`, no retry (`on_error` applies).
- `relate` (`src/Steps/Relate.php`): `action` `add` (default) or `remove`; `from` defaults to the bound record when it is of the relationship's "from" type. Validated by core as a save of the "from" record (types, `one_to_many`, `required`), which emits one `record.updated` on it with the relationship key in `changed` and origin depth = run depth + 1 (so automation loops stay capped at depth 3). A link that already existed or was already absent is `changed: false`, not a failure. A missing ID fails `invalid_id`; validation failures are not retried. Summary `relationship`, `action`, `from`, `to`, `changed`.
- `call_webhook.record` with `content_type`: the body's `record` is the record the template names (public fields only, the same rules as the bound record); a missing record fails `pegwork_record_not_found` and one of another type `record_type`, neither retried.
- `"on_error": "continue"` (on `update_record`, `create_record`, `send_email`, `call_webhook`, `relate`, `get_record`, `find_records`): after its retries, a failure is logged `failed`, the step outputs `failed: true` and `error: "<code>"`, and the run continues with the next step. On success the step outputs `failed: false`. The run completes with outcome `completed_with_errors:<ids>` (comma-separated step IDs; it wins over a later `stopped_at:<id>`), shown as "Completed with errors in <ids>" and counted in the list's "failed in the last 7 days".

## Trash

Design: `docs/automation-v4-design.md` sections 2.2, 3.3 and 3.8 (shape in `docs/registry-reference.md#trash`).

- `trash_record` (`src/Steps/TrashRecord.php`) moves one record of `content_type` to the trash through core `Records::trash()` (never a permanent delete). Target: the bound record, or the typed reference `record` renders (`{{record.id}}`, a read's `record_id`, a loop item's `id`). Summary `content_type`, `record_id`, `changed` (false when the record was already in the trash; not a failure). No output.
- Events: `record.updated` (`changed` = `status`) and `record.status_changed` to `trash`, origin depth = run depth + 1.
- Failures, none retried (`on_error: continue` applies): `pegwork_record_not_found` (no ID, missing or auto-draft), `record_type` (another type), `trash_disabled` (`EMPTY_TRASH_DAYS` 0; the post stays), `trash_limit`, `trash_in_sweep`. `pegwork_trash_failed` (another plugin stopped it through `pre_trash_post`) follows the step's `retry`; the type default is none.
- Cap: `context.trashed` counts the records the run moved (stored across wake-ups). At most 50 per run (filter `pegwork_automation_trash_limit`, capped at 200); the next one fails `trash_limit`. A daily retention schedule therefore moves at most 50 a day.
- Sweeps: never in a schedule with `for_each`. The registry refuses it; the handler refuses any schedule run that binds a record (`trash_in_sweep`) before acting, for a definition from a newer registry.
- After a trash, the run treats the record as gone: the read cache forgets it, so loop items in the trash are skipped, `get_record` by its ID is not found, and `update_record` and `relate` on any record in the trash fail `pegwork_record_not_found` with no retry. Templates still render a trashed bound record's values (`record.status` reads `trash`). This is engine behaviour: core `Records::get()` and `update()` still read and write trashed records, because record events on the way to the trash (and `record.status_changed` to `trash` triggers) bind them.
- Editors: starting a manual automation that can trash (directly or through an automation it starts, `Manual::trashes()`) also needs `delete_post` on the record ([Manual trigger](#manual-trigger)).
- Restore: the record is restorable from its type's Trash view for `EMPTY_TRASH_DAYS`; run summaries list IDs, never titles.

## Manual trigger

Design: `docs/automation-v3-design.md` sections 3.3, 6 and 7 (shape in `docs/registry-reference.md`): `{ "type": "manual", "content_type"?, "button", "allow": "admins"|"editors", "when"? }`. Nothing but staff starts it; it matches no event and has no schedule.

- Where: with `content_type`, a button on that type's block editor ("Run an automation", below); without it, Run now on Pegwork > Automations. `wp pegwork automation run` starts either (below).
- Start (`Manual::start()`, shared by all three): refuses unknown, non-manual and disabled automations (`automation_disabled`), a missing, unknown, trashed or other-type record when the trigger has a `content_type` (`pegwork_no_record`, `pegwork_record_not_found`, `pegwork_record_type`), a record when it has none, and a false `when` ("This automation does not apply to this record", `automation_not_applicable`). `when` is checked against the record as it is saved. The run (`trigger_type` `manual`, depth 0) binds the record and its payload is `trigger.user_id` and `trigger.source` (`editor`, `list` or `cli`).
- REST (`StartRoute`): `POST /wp-json/pegwork/v1/automations/<key>/start { record_id, request }`. The permission callback needs the REST cookie nonce (`X-WP-Nonce`, action `wp_rest`; 403 `rest_cookie_invalid_nonce`), a logged-in user (401), and the capability `allow` names: `manage_options` for `admins` (the default), or `edit_post` on that record for `editors`, plus `delete_post` on it when the automation can trash (`Manual::trashes()`: a `trash_record` at any depth, in it or in any automation it starts, followed transitively; the record editor does not list it otherwise); administrators pass both. Unknown keys get the same 403 as a refused user. Then 10 starts per user per minute (`Pegwork\Api\Throttle`; 429 `pegwork_rate_limited` with Retry-After). `request` (8 to 64 letters, digits and hyphens; one random ID per dialog) is the idempotency key `manual:<user>:<request>`, so a double submit starts one run: 202 `{ run, duplicate: false, message, url }`, then 200 with `duplicate: true` and the first run. `url` (the run detail) is set for administrators only. Without a request ID (CLI) the automation's own `idempotency` applies. Editors start only what the administrator wrote: nothing in the request changes the steps.
- Record editor (`Editor\RecordActions`, script `assets/src/automation-actions/index.js`, at most 3 KB gzipped, `npm run check:size`): enqueued only on the block editor of a record whose type has an enabled manual automation the user may start, with `window.pegworkAutomationActions = { automations: [ { key, button, path } ] }`. It fills slot `pegwork-editor-panel-after` at the end of the Details panel (`docs/editor-panel.md`) with the heading "Run an automation" and one secondary button per automation (its `button` text). A new, unsaved record shows "Save this record first to run an automation on it." instead. A button opens core `ConfirmDialog` ("Resend thank-you for Gift from Ada?", confirm button named like the button, Cancel; with unsaved changes it adds "It uses the last saved version; unsaved changes are not included."). Confirming posts once with a new request ID; the result is announced with `speak()` (assertive for errors) and shown as a notice, with a "View run" link for administrators. Focus returns to the button.
- List: manual automations show "Started by staff: "<button>" on each <type>" or "... (Run now)", "Editors can run" or "Administrators can run", and next run "When staff start it". Run now (no `content_type` only) posts with a request ID per page view (source `list`) and redirects to the run.
- Screenshots: `docs/screenshots/automation-record-actions.png` (the section and the notice), `automation-record-actions-confirm.png` (the dialog), `automation-run-now-manual.png` (the list row).

![Run an automation on a gift](screenshots/automation-record-actions.png)

## Signed links

An automation's `links.<name>` (`docs/registry-reference.md#signed-links`) is a one-click action on one record. `{{link.<name>}}` in an email renders `home_url( '/?pegwork_link=<token>' )`; opening it runs only that link's steps (`update_record`, `condition`), only on that record, as a run with `trigger_type` `link`. The owner needs no account.

Token (`src/Links/Token.php`): `v1.<base64url(json {a: automation, l: link, r: record ID, e: expiry})>.<base64url(HMAC-SHA256(key, "v1." + payload))>`. Stateless: verification needs no table. The signature is checked with `hash_equals()` before the payload is read; at most 512 bytes are read. Expiry is minted as the link's `expires` after the render time (engine clock), so every email carries a link valid for the full lifetime from its own send (`P365D` in SI for Good: the "still active?" link and the pause notice's fresh link both reactivate). One token per link per run render, so an email's text and HTML parts carry the same link.

Key (`src/Links/Key.php`): constant `PEGWORK_LINK_SECRET` (wp-config.php) when defined and non-empty, else option `pegwork_link_secret` (32 random bytes, base64, autoload off), generated the first time a link is minted (verification never creates one). Not `wp_salt()`, whose rotation would silently kill every outstanding link. Never printed, logged or exported (export covers the registry only). `wp pegwork automation link-key` says which source is in use (and warns when the constant is shorter than 32 characters); `--rotate` replaces the option, invalidating every link sent so far (refused while the constant is set).

Handler (`src/Links/Handler.php`, `template_redirect` priority 0, module enabled only):
- URL: a query variable on the home URL, not a pretty path such as `/pegwork-link/<token>/`: no rewrite rule to add and flush when the module is switched on or off, and it works on every permalink setting, plain included. The confirm form POSTs `pegwork_link` and `_wpnonce` to `home_url( '/' )`, so the token is not in the POST's URL.
- `GET` with `confirm: true` (default): a confirm page with one button. Mail scanners that prefetch links therefore cannot act. `GET` with `confirm: false` acts at once; `HEAD` never acts; other methods get 405.
- The confirm POST carries a WordPress nonce for action `pegwork_link_<sha256 of the token>`. Nonces work for visitors who are not logged in (user 0) and last 12 to 24 hours; a missing or stale one shows the confirm page again (403) with a notice, and nothing changes.
- Acting: the run is inserted with idempotency key `link:<sha256 of the token>` and executed in the request (`Engine::links()->run()`, then the executor). The steps' writes go through the Records API (validated, events with origin depth 1). Then a 303 redirect to `success_url` (a site path under the home URL, or an absolute URL that `wp_validate_redirect()` allows), else a thank-you page with `success_message` (default "Your response has been recorded.").
- Each token acts once: a second click finds its run and shows "Already done" (or "Something went wrong" when that run failed; `wp pegwork automation retry <run>` resumes it). The stored key is a hash, so the token cannot be rebuilt from the log.
- Refusals, all changing nothing and starting no run: not valid (malformed, tampered, other key: 403; nothing in the token is trusted); expired (410, or 303 to the link's `expired_url`); unavailable (404: automation, link or record gone, automation disabled, record in the trash or of another type); too many requests (429 with `Retry-After`).
- Rate limit: 30 invalid tokens per address per 10 minutes (`Pegwork\Api\Throttle`, bucket `link|<ip>`; filter `pegwork_automation_link_rate_limit`, 0 turns it off). Only tokens that fail the HMAC check count, so valid clicks never fill the bucket; a full bucket refuses every request from that address with 429 until it has room. The address is `Throttle::client_ip()`: `REMOTE_ADDR`, then the `pegwork_client_ip` filter (see `docs/forms.md` for a proxy example). Forwarded headers are not trusted by default, so behind a proxy every visitor may share one address until that filter is set.
- Pages (`src/Links/Page.php`): standalone HTML (site name, heading, message, the record title when its field is public, one button or a home link), no scripts or external resources, works with JavaScript off, `lang` and `dir` from the site, AA contrast and a visible focus ring. Page texts (`button`, `success_message`, URLs) render in `log` mode, so private fields (already rejected at load time) and links never appear. Headers: `nocache_headers()` (`no-store`), `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer` (the token does not leak to a redirect target), `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, and `DONOTCACHEPAGE` for page cache plugins.

Executor step-list source: `Executor::step_list( $definition, $run )` gives a `link` run the steps of `links[ context.link ]` and every other run the automation's `steps`. A link removed from the definition fails a waiting or queued link run with `definition_changed`.

## Webhooks

Design: `docs/phase-4-design.md` sections 7, 9 and 10. Code: `src/Steps/CallWebhook.php`, `src/Webhooks/` (`Secrets`, `Signature`, `Inbound`).

Secrets (`Secrets`): one per automation that has a webhook trigger or a `call_webhook` step, used for both directions. Option `pegwork_automation_secrets` (`key => 64 hex characters`, 32 random bytes, autoload off), generated on first use (first delivery, first inbound request for that automation, or `wp pegwork automation secret <key>`). Never in the registry JSON, schema exports (they cover the registry only), the run log, error messages or admin screens; removed on uninstall with the other `pegwork_` options. `wp pegwork automation secret <key>` prints it (the only place it is printed); `--rotate` replaces it after a confirmation (`--yes` skips it) and prints the new one: signatures made with the old one stop verifying, in both directions.

Signature (`Signature`), the same both ways: `X-Pegwork-Timestamp: <Unix seconds>`, `X-Pegwork-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" keyed with the secret>`, compared with `hash_equals()`; timestamps more than 300 s from now either way are refused (replay window). Inbound, each signature is accepted once: a request whose signature was already accepted is answered as a duplicate, whatever its `X-Pegwork-Delivery` (see Inbound).

Outbound (`call_webhook`: `url` template, `fields`, `timeout` at most 15 s, default 10): a step of a background run, so the save that triggered it never waits for or depends on the receiver.
- `wp_safe_remote_post()` (no private or loopback addresses, ports 80, 443 and 8080 only), `redirection` 0 (a 3xx is a failed attempt), `Content-Type: application/json; charset=utf-8`, User-Agent `Pegwork/<version>; <home URL>`.
- Body: `{ "event", "automation", "run_id", "delivered_at" (UTC ISO 8601 of this attempt), "record": { "id", "type", "status", "permalink", "fields": {...} } }`. `event` is the event name, or the trigger type (`schedule`, `webhook`, `manual`) for other runs. `record` is null when the run has no record. `fields` holds the step's `fields` keys, or every public field and taxonomy (slug lists) of the type when it has none. Private fields are never sent: the registry rejects them in `fields` (`automation_private_field`) and the step skips them too. The `url` template is a public context (private fields rejected at load time) and renders in `url` mode: tag values are URL-encoded except a tag at the very start (see Templates).
- Headers: `X-Pegwork-Delivery: <run id>-<step id>` (inside a loop `<run id>-<step id>-<iteration>`, one per item), stable across retries (receivers dedupe on it), plus a fresh timestamp and signature per attempt.
- A 2xx answer succeeds (output `status`). Any other status or a transport error fails the attempt with `webhook_failed: <host> answered HTTP <n>.` or `webhook_failed: Could not reach <host>: <transport message>`, retryable: 3 retries after `PT1M`, `PT10M`, `PT1H` (step `retry` overrides), then the run fails with ` (after 4 attempts)` and shows in the run log. A URL that does not render to `http(s)://` fails with `invalid_url`, not retried.
- Run log summary: `host`, `delivery`, `status` (when there was an answer); the step row has the duration. Never the secret, the signature, the URL path or a body.

Inbound: `POST /wp-json/pegwork/v1/automations/<key>/webhook` (also `/?rest_route=/pegwork/v1/automations/<key>/webhook`) starts a run of an enabled automation with `{ "type": "webhook" }`.
- Checked in `rest_pre_dispatch`, before WordPress decodes the body, for any letter case of the route (WordPress matches routes case-insensitively), in this order: a body (or `Content-Length`) over 64 KB: 413. A media type other than `application/json` (parameters such as `charset` allowed): 415. A client address whose failure bucket is full: 429. Then the signature: a request that fails it (bad, missing or stale signature, or a key that is not an enabled webhook automation) counts against its client address (bucket `webhook|client|<ip>`) and gets 401, or 429 once that bucket is full; it never touches an automation's quota, so junk cannot lock a sender out and every key, known or not, answers alike. A signed request counts against its automation (bucket `webhook|automation|<key>`): 429 once full. Both limits are 30 per minute, sliding (`Pegwork\Api\Throttle` on the engine clock), with `Retry-After`; filter `pegwork_automation_webhook_rate` (`$limit, $key`; `$key` is `''` for the per-address failure bucket; 0 turns a limit off). The address is `Throttle::client_ip()` (`REMOTE_ADDR`, filter `pegwork_client_ip`).
- Permission callback: signature and replay window as above. A bad, missing or stale signature and an unknown, disabled or non-webhook automation all get the same 401 (`pegwork_webhook_unauthorized`); unknown keys are checked against a throwaway key, so no secret is created for them. A request that `rest_pre_dispatch` did not screen (a route spelling it did not recognise) gets every check above here, so no spelling skips them.
- Callback: a body that is not a JSON object or array is 400 (invalid JSON gets WordPress's own `rest_invalid_json` 400); a decoded payload over 15 KB (it is stored in the run context, at most 16 KB) is 413. Each signature is accepted once while its timestamp is fresh (a single-use marker in the throttle table that expires with the 300 s window): a replay of an accepted request, with the same, a new or no `X-Pegwork-Delivery`, answers `200 { "duplicate": true }` and starts nothing. `X-Pegwork-Delivery` (1 to 128 of `A-Z a-z 0-9 . _ : -`, else 400) becomes the idempotency key `delivery:<id>`, taking the place of the automation's `idempotency` template; without it the template applies, if any. Answers `202 { "run_id", "status": "queued" }`, or `200 { "run_id" (the first run with that delivery ID, else null), "duplicate": true }` for a repeated delivery or signature. The payload is never echoed.
- Compatibility (security review L-02): the signature base string is unchanged (`<timestamp>.<raw body>`) and the delivery ID stays optional and unsigned, so existing senders need no change. The one difference: two requests with the same timestamp and identical bytes are one delivery, so a sender that resends the same body within a second must wait a second or vary the body (a retry normally gets a fresh timestamp and so a fresh signature, which is accepted and then deduplicated by its delivery ID).
- The run: `trigger_type` `webhook`, no record, depth 0, `trigger.payload.*` in templates and conditions (the only trigger paths the registry allows). Payload values reach records only through `create_record`/`update_record`, so the Records API sanitizes and validates them; a value a field rejects fails the run (`pegwork_invalid_record`) and writes nothing.
- The Automations list shows the inbound URL of each webhook automation and the `secret` command, never the secret.

### Verifying Pegwork webhooks

A receiver of `call_webhook` deliveries (and a sender to the inbound endpoint) uses the automation's secret from `wp pegwork automation secret <key>`. Sign and verify the raw body bytes exactly as sent; re-encoding parsed JSON changes them.

PHP:

```php
$body      = file_get_contents( 'php://input' );
$timestamp = $_SERVER['HTTP_X_PEGWORK_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_PEGWORK_SIGNATURE'] ?? '';
$expected  = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, PEGWORK_WEBHOOK_SECRET );

if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || ! hash_equals( $expected, $signature ) ) {
	http_response_code( 401 );
	exit;
}
$delivery = $_SERVER['HTTP_X_PEGWORK_DELIVERY'] ?? ''; // Seen before? Answer 2xx and do nothing.
$event    = json_decode( $body, true );
```

n8n, as pseudocode for a Code node ("Run Once for Each Item") after a Webhook node with the "Raw Body" option on, so the exact bytes arrive as binary data; `crypto` must be allowed (`NODE_FUNCTION_ALLOW_BUILTIN=crypto`) and the secret kept in an n8n variable or credential, not in the workflow:

```js
const crypto = require( 'crypto' );
const secret = $env.PEGWORK_WEBHOOK_SECRET;
const headers = $input.item.json.headers;
const raw = Buffer.from( $input.item.binary.data.data, 'base64' ).toString( 'utf8' );
const timestamp = headers[ 'x-pegwork-timestamp' ];
const expected = 'sha256=' + crypto.createHmac( 'sha256', secret ).update( `${ timestamp }.${ raw }` ).digest( 'hex' );
const given = headers[ 'x-pegwork-signature' ] || '';
const fresh = Math.abs( Date.now() / 1000 - Number( timestamp ) ) <= 300;
if ( ! fresh || given.length !== expected.length || ! crypto.timingSafeEqual( Buffer.from( given ), Buffer.from( expected ) ) ) {
	throw new Error( 'Bad Pegwork signature' ); // Respond 401 from an error branch.
}
return { json: { delivery: headers[ 'x-pegwork-delivery' ], event: JSON.parse( raw ) } };
```

Sending to the inbound endpoint (n8n HTTP Request node or any client): serialize the body once, then sign that string:

```text
body      = JSON string of the payload, for example {"title":"From n8n"}
timestamp = current Unix time in seconds
headers   = Content-Type: application/json
            X-Pegwork-Timestamp: <timestamp>
            X-Pegwork-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + body)>
            X-Pegwork-Delivery: <your unique ID, optional; repeats are ignored>
POST body to https://<site>/wp-json/pegwork/v1/automations/<key>/webhook
expect 202 {"run_id": n, "status": "queued"} (or 200 with "duplicate": true for a repeated delivery ID or a resent signature)
```

![A webhook automation on the Automations list](screenshots/automation-webhook-list.png)

![A delivery waiting to retry while the receiver is down](screenshots/automation-webhook-run.png)

## Loops

Design: `docs/automation-v3-design.md` sections 3.5, 4.2 and 4.3 (registry shape and rules in `docs/registry-reference.md`). A `loop` iterates the `records` of a `find_records` (`"over": "steps.due.records"`, at most 50) and runs its `steps` once per record. It has no handler: the executor's cursor runs it.

- Plan: body steps are nodes of the tree with `loop` set to the enclosing loop; the last body step (also the end of a branch at the end of the body) has `next()` = the loop id; `body_first( $loop )`, `loop_of( $id )`, `is_loop()`, `over()`. Loops count as a nesting level (depth 1 inside a top-level loop).
- Cursor: `current_step` stays a plain step id, plus `context.loops` `{ "<loop>": { "ids": [ 41, 38, 52 ], "index": 1 } }` (0-based current item) while the loop runs. The cursor arriving at a loop with no state starts it: the collection's IDs are snapshotted (and count toward the read limit, so a find of 50 plus its loop reads 100), one row `{ items, ids }` is logged (an attempt), and the first item runs from `body_first()`. With state, the loop advances: index + 1, no row and no attempt. After the last item the state is removed, the loop outputs `count` (items in the snapshot) and the cursor continues at `next( loop )`. An empty collection logs the row with 0 items and continues after the loop (`count` 0). A find that failed with `on_error` loops over nothing.
- Items are read fresh by ID (`steps.<loop>.item.<key>`, through the read cache, which writes invalidate), so a stamp written to the item in one step is seen by the next. `steps.<loop>.index` is the 1-based item number. An item trashed or deleted since the snapshot is skipped with a loop row `{ item, skipped_item: true }` in its iteration.
- A false `condition` in a body skips the rest of that item (summary `passed: false, skipped_item: true`) and the loop goes on; the run is not stopped. Decisions inside a body work as anywhere.
- Rows: body rows carry `iteration` (1-based item number); attempts are numbered per step and iteration. Outside loops `iteration` is null.
- Retries: inside a body the type defaults are off (`Retry::policy( $step, true )`), so a mail outage fails the item (or the run) instead of stretching one run over many backoffs; an explicit `retry` on the step applies. A retry, a time-budget requeue and Retry from the log store and resume `current_step` plus `context.loops`, so all of them continue at the same item; earlier items are not repeated.
- Webhooks: the delivery ID inside a loop is `<run id>-<step id>-<iteration>` (stable across retries of that item).
- `definition_changed` mid-loop: the cursor's step is gone (as before), the cursor is now inside a loop it has no state for, the state is for a loop that is no longer around the cursor, or the loop's `over` step is gone. Nothing runs.
- Limits: the registry's static bound is 500 attempts without retries (`automation_too_large`). Every step outside a loop body counts once, the loop step itself too, a decision adds its longest branch, and every body step counts 50 times. Work out the room as: steps outside bodies + 50 x body steps <= 500. So with one step before a 50-item loop (the find), the body can hold 9 steps: 1 + 1 + 9 x 50 = 452; 10 would be 502. A 10-step body (the registry's body maximum) never fits with a 50-item bound; 9 is the practical maximum, and 48 more steps fit outside it. The executor's own limit is 600 attempts (`pegwork_automation_step_limit`), which leaves room for retries.
- Time: a 50-item digest wake-up (worked example c: find, loop of email plus stamp, summary; 103 rows) took about 0.1 s on a local test site against the design's 5 s budget, so the 20 s wake-up budget is not near; `AutomationLoopTest` asserts under 5 s.
- Run detail and `log --run`: body rows are grouped under "Item 2 of 12: Task #38 (Call Ada)" (`Format::item_headings()`, from the loop's start row); the title shows only when the viewer can read the record (`read_post`), so `log --run` shows titles with `--user=<admin>` only. In table output the heading is a row in the step column and body ids are indented one more level; other formats add `iteration` to every row instead. The run detail also names the records each read found ("Read: Contact #12 (Ada)", at most 10), live and only for readers; the log never stores titles.

![A run through a loop of three projects, the second skipped by its condition](screenshots/automation-run-loop.png)

## Preview and test run

Checking a flow before turning it on, without sending anything (`src/DryRun.php`, `src/Editor/PreviewRoutes.php`). The editor's Preview and Test run panels call these routes ([Editor](#editor)); the CLI is the same report.

- Routes under `pegwork/v1/automation-editor/`, `POST` only: `pegwork_manage_automations`, the post type's capability, which maps to `manage_options` (no filter to lower it), with the REST cookie nonce (core turns a cookie request without one into no user: 401); a body that is not `application/json` is 415 before parameters are checked; every response, refusals included, sends `Cache-Control: no-store`. Previews and tests are never logged.
- `preview { template, html, trigger, record_id?, payload?, steps? }` returns `{ rendered }`: the template through `Templates::render()` in mode `preview` (as `text`) or `preview_html` (as `html`), except that `{{link.*}}` renders `[signed link]` and is never looked up, and `{{steps.<id>.*}}` (and an `{{#each}}` over it) renders `[from step <id>]` when that step has no output, which in a preview is always: the preview runs no read. With `steps` (the unsaved step list, as the editor sends it), a field of a read step's type renders `[from step <id>: <Field label>]` (`record.<key>`, `sum.<field>`). `trigger` is the (unsaved) trigger being edited.
- `test { key, record_id?, payload? }` dry-runs the saved automation and returns the report plus its text `lines`, exactly what `wp pegwork automation test --format=json` prints.
- Sample: when the trigger binds a record (a record event or sweep's `content_type`, or the target of a form whose events bind the record), `record_id` is required and must be of that type (400 otherwise); a record event without `content_type` takes any record. The trigger payload is synthesized from it: `old.*` holds the current values and `changed` is empty (`record.updated`), `old_status` and `new_status` are the current status, form `values` are the record's values of the form's bound fields. Webhook triggers and custom events take the pasted `payload` (at most the inbound webhook size) instead.
- The walk follows the executor's `Plan` with no step handler: the trigger's `when` (or the sweep's statuses and `where`) is reported first ("A real trigger would not start a run for this record. The steps are tested anyway."); decisions and conditions are evaluated and a false condition "stops here"; emails show `to` masked as in the log (`a***@example.org`), the subject, text, HTML and Reply-To in preview mode; record writes show the target and rendered values and are not applied, so later steps see the record as it is now; webhooks show the host and the field keys; waits show "waits P30D" and are passed over. Reads run for real through their handlers (read-only, the same read limit): `get_record` reports the record found by type label, ID and title ("Get record donor: found Contact 12 "Ada"."), `find_records` the count, the first 3 records and the sums; their outputs feed later steps, so emails show real read values and `{{#each}}` lists real records. The first read after a step that would write says once that reads see the site as it is now; a `get_record` by an ID that a would-be step creates is "not found in a test run". A `relate` shows the link it would add or remove (not applied); a `trash_record` shows the record it would move ("Move to trash remove: Volunteer 88 "Sam" (not applied).", or "is already in the trash"), counts toward the per-run trash cap (so items past it would fail `trash_limit`), and is a would-be write for the reads-see-the-site note; a webhook's `record` shows its public field keys. A step with `on_error: continue` that would fail is reported, gets `failed` and `error` outputs and the walk continues. Loops iterate every item of the real find with the executor's loop state, so item paths read real records: the loop line says "Loop each: repeats its steps for 6 Tasks.", the first 3 items are shown in full under "Item 1 of 6: Task 41 "Ann":" headings (each entry carries `item` `{ index, count, id, title, label }` on the first entry of an item, else null), then "and 3 more items, same steps."; a later item's failure is still shown with its heading; a false condition in a body "skips this item" (`skips`). Outcome `completed`, `completed_with_errors:<ids>`, `stopped_at:<id>`, `failed_at:<id>` (a recipient that would fail, a read past the limit) or `step_limit`.
- No side effects: no `wp_mail()`, no HTTP, no `pegwork_event`, no record write or link, no run or step row, and no link key or webhook secret is created (the context has no automation key, so nothing can mint a link). Administrators see private values in previews and reports, as on the edit screen.

## WP-CLI

```
wp pegwork automation list [--format=<format>]
wp pegwork automation log [<key>] [--run=<id>] [--status=<status>] [--limit=<n>] [--format=<format>]
wp pegwork automation run <key> [--record=<id>] [--now=<datetime|offset>] [--sync]
wp pegwork automation test <key> [--record=<id>] [--payload=<json>] [--format=text|json]
wp pegwork automation tick [--now=<datetime|offset>] [--sync]
wp pegwork automation retry <run> [--now=<datetime|offset>] [--sync]
wp pegwork automation enable <key>
wp pegwork automation disable <key>
wp pegwork automation link-key [--rotate] [--yes]
wp pegwork automation link <automation> <link> <record> [--now=<datetime|offset>]
wp pegwork automation secret <key> [--rotate] [--yes]
```

- `secret`: prints the automation's webhook secret, generating it on first use (only for automations with a webhook trigger or a `call_webhook` step); `--rotate` replaces it after a confirmation and prints the new one. Printed with `WP_CLI::line()` only when asked; nothing else prints or logs it.

- `link-key`: where the link key comes from (constant, option, or not generated yet), never the key; `--rotate` replaces the option after a confirmation (`--yes` skips it).
- `link`: prints a signed URL for a link on a record, exactly as an email would carry it, and its expiry; for testing (anyone with the URL can use it). The record must be of the automation's bound type. `--now` mints as of another time (refused in production), for example `--now=-400days` for an expired link.

`list`: key, label, trigger, JSON `enabled`, site override, effective state, last run (status, UTC time), open runs (queued, running, waiting), failures in the last 7 days, next schedule firing (site time). `log --run=<id>`: the run, then each step attempt (status, duration, summary, error); in table output, step ids inside branches and loops are indented two spaces per level, steps no longer in the automation are marked "(no longer in this automation)", and each loop item's rows follow an "Item 2 of 12: Task #38" line (other formats keep plain ids and add `iteration`; see [Loops](#loops)). `disable` stops new runs; queued runs are cancelled when they wake. CLI access implies admin; the admin screens check `manage_options` and nonces (below).

- `run`: a schedule automation fires once now (sweeping its records, or only `--record`, filters still applied) without moving its regular schedule; an event automation starts one run against `--record` (`trigger_type` `manual`, payload `id`, `type`, `status`, origin source `cli`; `when` is not evaluated, the idempotency key applies); a manual automation starts one run (against `--record` when its trigger has a `content_type`; `when` is checked; payload `trigger.user_id` is the `--user`, `trigger.source` `cli`; [Manual trigger](#manual-trigger)). Disabled automations are refused.
- `test`: the dry run of [Preview and test run](#preview-and-test-run): one line for the trigger check, one per step on the path (indented by branch level, with an "If <label>:" or "Otherwise:" line where a branch starts), then the outcome. `--format=json` prints the REST `test` body. Sends, writes and mints nothing; unlike the other commands it shows rendered templates, which may hold private values.
- `tick`: resumes waiting runs whose `resume_at` has passed and queued runs idle for 10 minutes, replacing pending wake-ups. It never prunes or interrupts runs.
- `retry <run>`: failed runs only; resumes at the failed step (earlier steps are not repeated), retry policy counted afresh.
- `--sync`: executes the runs and everything they trigger in this process (`InlineScheduler`); later wake-ups go to WP-Cron. Without it, runs are queued for WP-Cron.
- `--now`: sets the clock for this process only and implies `--sync`: a site-time datetime (`"2027-11-02 09:00"`) or an offset from now (`+12months`, `"+13 months"`, `+P30D`, `-PT1H`). Refused when `wp_get_environment_type()` is `production` (WordPress's default when `WP_ENVIRONMENT_TYPE` is unset), so set `WP_ENVIRONMENT_TYPE` to `local`, `development` or `staging` on test sites. Waits created under `--now` are stored on that timeline (`resume_at` in the future of the real clock), so resume them with a later `tick --now`; `tick --now` also runs queued runs that look idle on the moved clock, with that clock.

## Local runbook: one SI for Good staleness cycle

On a test site with the `sifg` package (siforgood.local with `sifg-config`, or `bin/e2e-local-site.sh start <name> <port>`, which registers `examples/sifg`), `WP_ENVIRONMENT_TYPE` set to `local` (`wp config set WP_ENVIRONMENT_TYPE local`) and a way to read mail (Local's Mailpit; on an E2E site the forms mu-plugin logs mail to option `pegwork_e2e_mail`). Time travel is per process: `--now` moves the clock for one command, and the link is clicked on the real clock afterwards.

1. Enable and check the key: `wp pegwork module enable automation`, `wp pegwork automation link-key` (no key until the first link is minted).
2. Submit and approve a Project:
   ```sh
   ID=$(wp post create --post_type=sifg_project --post_status=pending --post_title="Runbook Garden" \
     --post_content="$(printf 'Neighbours share tools, seeds and time to grow food together. %.0s' 1 2 3 4 5 6)" \
     --meta_input='{"_sifg_project_contact_email":"owner@example.org","_sifg_project_needs_volunteers":"1","_sifg_project_volunteer_link":"https://example.org/volunteer"}' \
     --user=admin --porcelain)
   wp post term set $ID sifg_project_type nonprofit --user=admin
   wp post term set $ID sifg_project_status active --user=admin
   wp post update $ID --post_status=publish --user=admin
   wp cron event run --due-now            # or let WP-Cron run it
   wp pegwork automation log sifg_project_approved --limit=1
   ```
   Expect a `completed` run, Last verified today (`wp post meta get $ID _sifg_project_last_verified`), and the email "Runbook Garden is live on ..." with the permalink and "Last verified: <today>".
3. Twelve months later: `wp pegwork automation run sifg_staleness_check --now=+12months`. Expect `Matched 1, created 1 run(s)`, the run `waiting` at `still_stale`, and the email "Is Runbook Garden still active?" with a `?pegwork_link=v1...` line. Running it again (`--now="+12 months +1 day"`) creates nothing: the idempotency key `{{record.id}}:{{record.last_verified}}` already has a run.
4. No reply: `wp pegwork automation tick --now=+14months` (14, not 13: twelve months plus 30 days can pass 13 months, for example from late January). Expect `Resumed 1 run(s)` and the run `completed` at `tell`; Status `paused` (`wp post term list $ID sifg_project_status --field=slug`); private `_sifg_project_paused_on`, `_paused_from` (`active`) and `_paused_volunteers` (`1`) set, Needs volunteers off; the email "Runbook Garden is paused on ..." with a fresh link; the public REST view (`/?rest_route=/wp/v2/projects/<ID>`) shows the paused term and no contact email or paused fields.
5. Reactivate: open the link from the first email in a browser (JavaScript on or off). Expect "Please confirm" with the Project's name and the button "Yes, it is still active"; press it; expect "Thank you. Your listing stays live on ...". Then Status `active`, Needs volunteers back on, the paused fields empty, Last verified today (real clock). `wp pegwork automation log sifg_staleness_check` shows a `link` run `completed` at `restore`; `log --run=<id>` lists `verify`, `was_paused` (`passed: true`), `restore`. Opening the link again says "Already done".
6. Refusals: change a character of the link (expect "This link does not work", 403); mint an expired one with `wp pegwork automation link sifg_staleness_check still_active $ID --now=-400days` and open it (expect "This link has expired", 410). Neither changes the record.

To repeat the cycle use a new Project: the reactivation sets Last verified to the real today, which may equal the value the sweep already keyed.

## Trying the BarnCRM example flows

The worked examples of `docs/automation-v3-design.md` ("Worked examples: BarnCRM flows with reads") live in `tests/fixtures/valid/barn` (`README.md` there lists them), so this needs a dev checkout of the plugin (the release zip has no `tests/`): a throwaway site from `bin/e2e-local-site.sh start <name> <port>`, or any Local site whose `wp-content/plugins/pegwork` is a checkout. On an E2E site, first remove the `pegwork-e2e-barn.php` and `pegwork-e2e-manual.php` links from `wp-content/mu-plugins/` (they register the same `barn_*` types as a file package, so the import below would conflict). Mail: Local's Mailpit, or on an E2E site the forms mu-plugin's option `pegwork_e2e_mail` (`wp option get pegwork_e2e_mail --format=json`). The outputs quoted below are from a run on 2026-10-23 (site time UTC); IDs and dates differ on your site.

1. Set up:
   ```sh
   wp config set WP_ENVIRONMENT_TYPE local --type=constant   # --now is refused in production
   wp pegwork module enable automation forms
   wp pegwork schema import "$(wp plugin path pegwork --dir)/tests/fixtures/valid/barn"
   wp pegwork options set barn_settings development_email development@example.org
   wp pegwork options set barn_settings events_coordinator events@example.org
   wp pegwork options set barn_settings garden_coordinator garden@example.org
   wp pegwork options set barn_settings volunteer_manager manager@example.org
   wp pegwork options set barn_settings donate_url https://example.org/donate
   ```
   Expect `Success: 15 change(s): 15 added` (manifest, four types, `gift_donor` and `task_gift`, the form, six automations, the options page) and `wp pegwork automation list` showing `barn_donation_inbound` (webhook), `barn_gift_thanks` and `barn_lifetime_milestone` (record.created on barn_gift), `barn_resend_thanks` (manual, editors), `barn_tasks_digest` (every week at 08:00, next firing on the first day of the week) and `barn_volunteer_intake` enabled.
2. A first gift, linked to its donor as the gift editor's Donor picker would (the Records API takes the relationship key in the same write):
   ```sh
   C=$(wp eval 'echo Pegwork\Api\Records::create( "barn_contact", array( "name" => "Ada Lovelace", "email" => "ada@example.org" ), "private" );')
   G=$(wp eval "echo Pegwork\Api\Records::create( 'barn_gift', array( 'name' => 'Gift from Ada', 'amount' => 300, 'received_on' => wp_date( 'Y-m-d' ), 'gift_donor' => $C ), 'private' );")
   wp cron event run --due-now            # or let WP-Cron run it
   wp pegwork automation log barn_gift_thanks
   wp pegwork automation log barn_lifetime_milestone
   ```
   Expect the `barn_gift_thanks` run `waiting` at `gifts_later` (30 days), the `barn_lifetime_milestone` run `completed` with `stopped_at:reached`, the email "Thank you for your generous gift" to ada@example.org ("Dear Ada Lovelace, Thank you for your gift of $300 on October 23, 2026. ...") with Reply-To development@example.org, one published `barn_task` "Thank-you call: Ada Lovelace" due in two days (`wp post list --post_type=barn_task --post_status=any`), and the contact's `last_gift_on` today:
   ```
   id  automation               trigger               record  status     depth  step         outcome
   4   barn_gift_thanks         event record.created  34      waiting    0      gifts_later
   5   barn_lifetime_milestone  event record.created  34      completed  0      reached      stopped_at:reached
   ```
3. Lifetime giving: a second donor gives 600, 300 and 100.
   ```sh
   M=$(wp eval 'echo Pegwork\Api\Records::create( "barn_contact", array( "name" => "Mo Ibrahim", "email" => "mo@example.org" ), "private" );')
   for A in 600 300 100; do wp eval "echo Pegwork\Api\Records::create( 'barn_gift', array( 'name' => 'Gift from Mo', 'amount' => $A, 'received_on' => wp_date( 'Y-m-d' ), 'gift_donor' => $M ), 'private' );"; done
   wp cron event run --due-now
   wp pegwork automation log barn_lifetime_milestone
   ```
   Expect the first two milestone runs `stopped_at:reached`, the third `completed` at `celebrate` with "Thank you for 3 gifts to <site>" to mo@example.org ("Your gifts to <site> now total $1,000.00."), and Mo's `milestone_1000_on` today. Each gift is thanked too; Mo's second and third thank-you runs stop at `first_gift` (he has given before), his first waits.
4. Staff resend a thank-you (the gift editor's "Resend thank-you" button does the same):
   ```sh
   wp pegwork automation run barn_resend_thanks --record=$G --sync
   ```
   Expect `Matched 1, created 1 run(s)`, the run `completed` at `thank`, and "Thank you for your gift" to ada@example.org ("Thank you again for your gift of $300 ...").
5. A donation platform posts a gift. Sign the body as [Verifying Pegwork webhooks](#verifying-pegwork-webhooks) describes (the secret stays in a variable):
   ```sh
   SECRET=$(wp pegwork automation secret barn_donation_inbound)
   BODY='{"email":"nia@example.org","name":"Nia Long","amount":120}'
   TS=$(date +%s); SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
   curl -s -X POST "$(wp option get siteurl)/wp-json/pegwork/v1/automations/barn_donation_inbound/webhook" \
     -H 'Content-Type: application/json' -H "X-Pegwork-Timestamp: $TS" -H "X-Pegwork-Signature: sha256=$SIG" \
     -H 'X-Pegwork-Delivery: runbook-1' --data "$BODY"; unset SECRET
   wp cron event run --due-now
   wp pegwork automation log --run=<the run_id printed>
   ```
   Expect `{"run_id":13,"status":"queued"}`, then the run `completed` at `add_gift` with `existing` `"found":false`, the `Not in the CRM yet` branch creating the contact, `contact` `"found":true` and `add_gift` writing `amount, gift_donor, name, received_on`. The new gift's `barn_gift_thanks` run has depth 1 and thanks nia@example.org ("Thank you for your gift of $120 ..."), then waits. Posting the same email again (a new delivery ID) reuses the contact; an empty `email` stops at `has_email`.
6. The tasks digest, fired once now on a moved clock (`run` does not move its weekly schedule). Add an overdue task with no assignee first:
   ```sh
   wp eval 'echo Pegwork\Api\Records::create( "barn_task", array( "name" => "Thank Bo", "due_on" => wp_date( "Y-m-d", time() - DAY_IN_SECONDS ) ), "publish" );'
   wp pegwork automation run barn_tasks_digest --now=+1day
   wp pegwork automation run barn_tasks_digest --now=+1day
   wp pegwork automation log --run=<the digest run>
   ```
   Expect `Clock for this process: <tomorrow> (site time)` and the run `completed` with `completed_with_errors:remind`; the second command `Matched 1, created 0 run(s), 1 already had a run for their idempotency key` (`tasks:{{today}}`). One reminder per task with an assignee ("Due October 25, 2026: Thank-you call: Ada Lovelace" to development@example.org, with the edit URL), and the summary "4 tasks due by October 31, 2026" listing "- Thank Bo, due October 22, 2026, no assignee" first. `log --run` groups the loop by item:
   ```
   step         type           attempt  status  summary
   due          find_records   1        ok      {"content_type":"barn_task","count":4,"returned":4,"ids":[46,35,38,40]}
   each_task    loop           1        ok      {"items":4,"ids":[46,35,38,40]}
   Item 1 of 4: Task #46
     remind     send_email     1        failed  {"subject":"Due October 22, 2026: Thank Bo"}   no_recipient: The recipient list rendered empty.
     stamp      update_record  1        ok      {"record_id":46,"keys":["reminded_on"]}
   Item 2 of 4: Task #35
     remind     send_email     1        ok      {"to":["d***@example.org"],"subject":"Due October 25, 2026: Thank-you call: Ada Lovelace","html":false}
     stamp      update_record  1        ok      {"record_id":35,"keys":["reminded_on"]}
   ...
   summary      send_email     1        ok      {"to":["d***@example.org"],"subject":"4 tasks due by October 31, 2026","html":false}
   ```
7. Two volunteers. The form path is what the site uses (`form.submitted` binds the record the form created); on the command line `run --record` starts the same flow on a record you create, as a `manual` run:
   ```sh
   V1=$(wp post create --post_type=barn_volunteer --post_status=pending --post_title="Vera Garden" \
     --meta_input='{"_barn_volunteer_email":"vera@example.org","_barn_volunteer_interest":"garden","_barn_volunteer_stage":"new"}' --user=admin --porcelain)
   V2=$(wp post create --post_type=barn_volunteer --post_status=pending --post_title="Evan Events" \
     --meta_input='{"_barn_volunteer_email":"evan@example.org","_barn_volunteer_interest":"events","_barn_volunteer_stage":"new"}' --user=admin --porcelain)
   wp pegwork automation run barn_volunteer_intake --record=$V1 --sync
   wp pegwork automation run barn_volunteer_intake --record=$V2 --sync
   ```
   Expect each `Matched 1, created 1 run(s)` with the run `waiting` at `check`, coordinator emails garden@example.org and events@example.org in the records, and the notices "New volunteer: Vera Garden" to garden@example.org (Reply-To vera@example.org) and "New volunteer: Evan Events" to events@example.org.
8. Evan's coordinator reaches out: set his Stage to Contacted on the edit screen, or `wp post meta update $V2 _barn_volunteer_stage contacted`.
9. Two and five days later. Each `--now` is an offset from the real clock, and a wait created under `--now` is stored on that timeline, so the second wait (three days from `+3days`) needs `+7days`:
   ```sh
   wp pegwork automation tick --now=+3days
   wp pegwork automation tick --now=+7days
   ```
   Expect `Resumed 2 run(s)`: Evan's run `completed` at `check` with no email, Vera's `waiting` at `still_new` after "Reminder: Vera Garden is waiting to hear from you" to garden@example.org. Then `Resumed 1 run(s)`: Vera's run `completed` at `escalate`, "Volunteer not contacted after five days: Vera Garden" to manager@example.org.
10. A month later: `wp pegwork automation tick --now=+31days`. The donor runs re-count each donor's gifts after the wait:
    ```
    Resumed 3 run(s).
    id  automation        trigger               record  status     step            outcome
    4   barn_gift_thanks  event record.created  34      completed  follow_up
    6   barn_gift_thanks  event record.created  37      completed  no_second_gift  stopped_at:no_second_gift
    14  barn_gift_thanks  event record.created  43      completed  follow_up
    ```
    Ada (one gift) and Nia get "What your gift made possible" ending with "If you would like to give again: https://example.org/donate"; Mo's first run stops at `no_second_gift` because he gave again. The donor is read again after the wait, so an email corrected in the meantime is the one used.
11. The paths: `wp pegwork automation log --run=4` (Ada) lists `donor`, `has_donor`, `size` (`{"branch":"Major gift (250 or more)","index":0}`), `  thank_major`, `  call_task` (`keys` `assignee_email, due_on, name, task_gift`), `stamp_donor`, `gifts` (`"count":1`), `first_gift`, `grace` (`"for":"P30D"`), `gifts_later` (`"count":1`), `no_second_gift`, `follow_up`, with branch steps indented two spaces. `log --run=<Vera's run>`: `route` (`{"branch":"Garden","index":1}`), `  to_garden`, `notify`, `grace`, `check` (`{"branch":"Not contacted yet","index":0}`), `  remind`, `  grace_2`, `  still_new` (`{"passed":true}`), `  escalate`; Evan's run ends at `check`. Pegwork > Automations > View run log shows the same runs; each run's detail has the read lines ("Read: Contact #33 (Ada Lovelace)"), branch label rows and loop item rows.

`tests/Integration/Automation/BarnFlowsGateTest.php` runs every flow on a frozen clock, through the real form for volunteers and the signed REST route for the donation platform. `tests/e2e/specs/automation-barn-flows.e2e.js`, `automation-builder-reads.e2e.js` and `automation-builder-loops.e2e.js` build the donor, milestone, digest and volunteer automations in the editor by keyboard and check they save identical to the fixture files ([Testing](#testing)).

![barn_gift_thanks built in the editor](screenshots/automation-barn-gift-thanks-1280.png)

![barn_volunteer_intake built in the editor](screenshots/automation-barn-volunteer-intake-1280.png)

![Test run of barn_gift_thanks with a gift of 300](screenshots/automation-barn-test-run-1280.png)

The run log after the steps above, and the detail of one run per flow:

![The run log](screenshots/automation-barn-run-log-1280.png)

![Donor: thanked, counted, waited, followed up](screenshots/automation-barn-run-donor-1280.png)

![Lifetime milestone reached](screenshots/automation-barn-run-milestone-1280.png)

![Tasks digest grouped by item, the failed reminder continuing](screenshots/automation-barn-run-digest-1280.png)

![Resend thank-you started by staff](screenshots/automation-barn-run-manual-1280.png)

![Donation platform: contact created, gift linked](screenshots/automation-barn-run-inbound-1280.png)

![The inbound gift thanked at depth 1](screenshots/automation-barn-run-inbound-thanks-1280.png)

![Volunteer intake escalated](screenshots/automation-barn-run-volunteer-1280.png)

## Admin screens

Pegwork > Automations (`modules/automation/src/Admin/`, slug `pegwork-automations`, submenu position 30, added on `admin_menu` at the default priority while the module is enabled). Capability `manage_options` on every view and action: other users get 403 from WordPress on the views and from the handlers on the actions. Standard markup: `wrap`, `h1` with a `page-title-action` link, notices, `widefat striped` tables with captions and row headers (not `WP_List_Table`, whose small-screen CSS stacks cells without their headers, as on Modules). No JavaScript: every action is a form, so the screens are keyboard operable as they are. Times show in site time inside `<time datetime>`.

- List (`admin.php?page=pegwork-automations`): page-title actions "Add automation" and "View run log"; per automation, label, key and source (`Registry::automation_source()`: file package id or "Site automation (database)"); trigger summary (for a webhook trigger also its inbound URL and the `secret` command, never the secret); enabled state as a badge with the JSON default and the site override; last run (status, time), open runs, failures in the last 7 days (`Runs::summary()`); next schedule firing (`Scheduler::next()`); actions Edit (site automations) or View and "Duplicate as site automation" (file automations), then Enable/Disable, Run now (schedule triggers and manual triggers without a `content_type`; `aria-disabled` with a visible reason while disabled) and View runs. Visiting the list creates the missing `pegwork_automation` posts (below). Empty state when no automation is registered.
- Run log (`&view=log`): GET filters by automation (removed automations keep their runs) and status, 20 runs per page newest first (`Runs::page()`, `Runs::count()`), page links in a labelled `nav`. Columns: run (link to detail), automation, trigger (type and event name), record (ID and title, linked to its edit screen when the user may edit it), status, outcome (stopped condition or error), created. Empty states for no runs and no matching runs.
- Run detail (`&view=run&run=<id>`): the run (automation, status, trigger, record, current step, outcome or error, resume time while waiting, depth, created, started, finished), Retry for failed runs, then one row per step attempt: step ID and type, attempt, status, started, duration, summary (key and value list) and error. With decisions, rows inside a branch are indented by depth (`pegwork-depth-1` to `-3`), the first row of a branch is preceded by a label row ("Branch **High** of `route`", or "Otherwise branch of `route`"), a decision's summary reads "Took branch **High**", "Took Otherwise" or "No branch matched", and a step no longer in the automation shows flat with "(no longer in this automation)". Flat runs render as before.

Actions post to `admin-post.php` with a nonce per row (`pegwork_automation_toggle_<key>`, `pegwork_automation_run_<key>`, `pegwork_automation_retry_<run id>`), then redirect with a one-time notice (transient per user). They call what the CLI calls, so both apply identical rules:
- Enable/Disable: `Overrides::toggle()` (as `enable|disable`; unknown keys refused).
- Run now: `Manual::run( $key )` without `--sync`, so a sweep's runs are queued for WP-Cron (spawned at shutdown) and the regular schedule does not move; idempotency keys apply; disabled automations are refused. Event automations have no Run now (they need a record). Redirects to the run log filtered to the automation. For a manual automation without a `content_type`, `Manual::start()` with source `list` and the form's request ID (a double submit starts one run); redirects to the run.
- Retry: `Manual::retry( $run )` (failed runs only; resumes at the failed step, retry policy counted afresh), queued for WP-Cron. Redirects to the run.

Privacy: the views never read `context.trigger`. They show statuses, trigger type and event name, record ID and post title, `context.outcome` or the error, and step summaries, which already hold only keys, IDs, masked recipients and subjects with private fields as `[private]`.

![Automations list](screenshots/automation-list.png)

![A failed run with Retry](screenshots/automation-run-failed.png)

![A run through two nested decisions](screenshots/automation-run-branches.png)

## Editing surface

Design: `docs/phase-6-design.md` section 6, "Surface". The registry stays the source; a post is only where the block editor edits it, as for forms (`docs/forms.md#builder`). The editing UI is described under [Editor](#editor).

- Save API (core, `docs/extension-api.md`): `pegwork_save_automation()` and `pegwork_delete_automation()`, which share `Pegwork\Registry\SiteDefinitions` with the form functions.
- Post type `pegwork_automation` (`modules/automation/src/Editor/AutomationPost.php`): not public, `show_ui`, REST base `pegwork-automations`, no menu of its own (`edit.php?post_type=pegwork_automation` redirects to the list), status forced `private`, no revisions. Every post type capability maps to `pegwork_manage_automations`, which maps to `manage_options`; there is no filter to lower it. File automations cannot be deleted (`delete_post` is `do_not_allow`).
- Meta: `_pegwork_automation_settings` (JSON, in REST) holds everything except `format`, `label` (the post title) and `steps`: key, description, enabled, trigger, idempotency, and `links` and `ui` as they are. `_pegwork_automation_key` (locked after the first save) and `_pegwork_automation_hash` (sha256 of the canonical definition the post was saved or generated from).
- Blocks (`modules/automation/blocks/`, category Pegwork, the only blocks allowed in this post type, rendering nothing elsewhere): `pegwork/automation-step` (attributes are the step object), `pegwork/automation-decision` (attribute `id`; inner blocks are branches only), `pegwork/automation-branch` (attributes `label`, `when`, and editor-only `otherwise`; parent is a decision; inner blocks are steps and decisions). The otherwise branch is the last branch with `otherwise: true`.
- `Editor\Builder::generate()` and `compile()` are mechanical inverses: no registry lookups, no generated ids, an empty `otherwise` omitted. `compile()` reports misplaced blocks by JSON pointer (`/steps` for content that is not a step or decision or a branch outside a decision, `/steps/0/branches` for a step directly in a decision, `/steps/0/otherwise` for a second or non-final otherwise).
- Save: `rest_pre_insert_pegwork_automation` compiles the post and calls `pegwork_save_automation()`. A refused definition refuses the save: `pegwork_invalid_automation` with messages by pointer in `data.fields`, status 400, or 409 for a key a file package defines. A second post cannot take an existing site key (400 at `/key`). A first save without `enabled` in the settings stores `enabled: false`. Without `unfiltered_html`, every email `html` template, branches included, passes through `wp_kses_post()`. Autosaves are not compiled. Trash calls `pegwork_delete_automation()`; restoring saves it again; deleting without the trash deletes the definition too.
- Sync by hash: opening a post (`load-post.php`) regenerates title and blocks when the registry definition's hash differs (import, API save, file edit), keeping the modified date.
- File automations open read-only: `templateLock: all`, saving locked (`lockPostSaving`), the inserter hidden (body class `pegwork-automation-readonly`), and a notice with "Duplicate as site automation".
- "Add automation" starts the settings with `enabled: false`. "Duplicate as site automation" (nonce `pegwork_automation_duplicate`, `manage_options`) prefills a new post from the definition under the first free key from `<key>_copy`, with `enabled: false`; nothing is saved until the user saves.
- Script `assets/src/automation-builder/` (built `automation-builder.js` and `.css`), loaded only on this post type; styles on `enqueue_block_assets` for the iframed canvas.

![The editor with a decision, its branch and Otherwise](screenshots/automation-editor-placeholder.png)

![A file automation, read-only, with Duplicate](screenshots/automation-editor-read-only.png)

## Editor

Design: `docs/phase-6-design.md` section 6 and `docs/automation-v3-design.md` section 6. Script `assets/src/automation-builder/`, config from `Editor\BuilderEditor::config()` (`window.pegworkAutomationBuilder`): the post's locked key, read-only state and package, the Duplicate and list URLs, the webhook URL, the site override, the number of open runs, REST bases by content type, and `registry_config()` (content types with fields, options, privacy and taxonomies; forms with target, confirm and fields; options pages).

- Steps: the inserter offers Send email, Update record, Create record, Call webhook, Wait (one day to start) and "Continue only if" as variations of `pegwork/automation-step`, and decisions as "If / Otherwise" (one branch and Otherwise) and "Choose a path" (two branches and Otherwise). List View labels read "Email email_2", "Decision route", "Branch Named site". The otherwise branch is locked last (`lock: { move, remove }`, set by `Builder::generate()` and by the editor); the decision toolbar has Add otherwise and Remove otherwise.
- Canvas cards: icon, type, id and a one-line summary ("Email to desk@example.org: Done"). Branch headings read "If <condition in words>" or "Otherwise", with the editable branch name beside them. Nesting is an indent, a left rule (dashed for Otherwise) and the heading text, never colour alone; "Then continue below" closes each decision. Appenders are named "Add step to <branch>" and "Add branch to <decision>".
- Step ids: a new, pasted or duplicated block takes the first free id of its type (`email`, `email_2`, `decision`). The inspector's Advanced section holds the id, with "Runs waiting at this step fail if you change its ID.", and the retry policy.
- Sidebar: Automation (label, key locked after the first save, description, Active), Trigger ("Starts on": record event, form event, schedule with an optional sweep, webhook with its URL and `wp pegwork automation secret <key>`, other event; "Only when"; "Run once per"; signed links listed read-only) and Test run.
- Conditions: All, Any, "Not all" and "None" groups as fieldsets with a legend, rows of field, operator and value. Operators follow the validator: order operators for number and date fields, `contains` for multi-select taxonomies, `changed` only for `record.*` on `record.updated`, every operator for a path of unknown type ("Another path"). At most 5 levels and 50 parts. Remove buttons say what they remove ("Remove condition 1: Site name is not empty").
- Merge tags: "Insert merge tag" lists what the validator accepts at that template: the bound record's fields (private ones only in private contexts: email, record values, Run once per), the trigger payload, outputs of steps that ran on every path before, site, time, run, public options fields and signed links. The shared fixture `tests/fixtures/automation/scope.json` holds the script and `AutomationRules` to the same lists.
- Move: core's movers and drag, and the toolbar's "Move to" (every step list that keeps the 3-level limit; loop rules below).
- Errors: a refused save shows each message at its step card and its control (`aria-invalid`, `aria-describedby`), settings messages in their panel, and a notice with "Go to first error" (settings first, then blocks in document order). Open runs: a warning while there are unsaved changes, and in the Automation panel.
- Preview (Send email steps, after "Show preview") and Test run call the routes under [Preview and test run](#preview-and-test-run), with one sample record or payload for the whole editor. The Test run result heading takes focus.
- File automations: every control disabled, no appenders or inserter, the title read-only, "Duplicate as site automation" in the notice and the Automation panel.
- Size: at most 26.5 KB gzipped (27,136 bytes, `npm run check:size`; the budget was 25 KB before loops and was raised by the minimum they needed); every dependency is a WordPress script.

Reads, relate, `on_error` and the manual trigger:

- Steps: "Get a record" (`get_record`, id `get`), "Find records" (`find_records`, id `find`) and "Link records" (`relate`, id `link`). Card summaries: "Get Contact by relationship Donor of this Gift", "Find Gifts linked to donor: total Amount", "Link Task to Gift (add)".
- Read inspector (`read-edit.js`, pure helpers in `reads.js`): Content type (changing it clears sums, sort and filter rows); "Find the record(s) by": Its ID (get only, `record`), A relationship, Matching fields, or A relationship and matching fields; "Linked by" with a Relationship combobox (relationships with the type at either end, "Donor (Gift to Contact)") and "Of" (help names the other end's type); "Matching fields" as one flat All or Any group of 1 to 10 rows ("Add filter"; no nesting, no Not) over the type's filterable fields (not rich text, textarea or post content), with "Another value" offering the condition fields in scope; Find only: Limit (0 to 50, empty for 10) and "Also total" checkboxes over number fields (at most 5). "More options": Sort by (ID, text, number, date, select, email and title fields) and Order, "Include records that are" (statuses). Defaults (sort by ID ascending, private and published) are not written.
- Link inspector: Relationship, Link (Add, Remove), "From (a Task ID)" (help "Empty: this Gift." when the trigger binds the relationship's "from" type) and "To (a Gift ID)".
- Advanced, every step but conditions: "If this step fails after retries": "Stop the run" or "Continue" (`on_error: continue`; "Later steps can check Failed."). The card then says "Continues if it fails".
- Trigger: "Started by staff" (`manual`): Content type ("None: Run now on the Automations list"), Button text (40 characters) and "Who can run it" (Administrators, or "Anyone who can edit the record" with a content type, which warns that it runs with the automation's full access); "Only when" applies.
- Scope: typed read outputs follow the validator: `found` (boolean), `record_id` and `count` and `sum.<field>` (numbers), `record.<key>` by the read type's field types (private fields only in private contexts), `failed` and `error` after a step with `on_error`; `records` is never a tag or condition field. A manual trigger offers `trigger.user_id` (number) and `trigger.source`. `update_record` with a `record` of a known type (`{{steps.<id>.record_id}}`) picks that type's fields.
- Values rows of Create record and Update record (on a known type) list the type's fields, then the relationships it is the "from" end of, by label ("Gift" writes `task_gift`); the value is the linked record's ID, such as `{{record.id}}`.
- Variable picker groups: "This Gift", "Trigger", "Donor (from donor)" (a get's relationship label, else its type), "Totals (from giving)" (a find), "Time", "Options", "Links", "Site". Date tags offer "Format as a date" and "Plus or minus a duration" (`| add:"P2D"`), number tags "Format the number" (`| number`, `| number:0` to `4`).
- Preview: the editor sends its steps, so `{{steps.<id>.record.<key>}}` and `sum.<field>` show `[from step <id>: <Field label>]`.

Loops and lists:

- Block `pegwork/automation-loop` ("Repeat for each", id `each`, attributes `id` and `over`; `Builder` compiles it to `{ id, type: "loop", over, steps }` and generates it back). The card reads "Repeat for each `<id>`" with "For each Task from due (at most 50)" (or "Choose the records to repeat for."), the body is indented under a double left rule, its appender is "Add step for each item" and "Then continue below" closes it. The inspector's "For each" lists the `records` of every Find records step before it ("Tasks from due").
- Body rules: 1 to 10 steps or decisions. The inserter offers neither Wait nor another loop inside a body (the `blockEditor.__unstableCanInsertBlockType` filter refuses a loop; core has no per-variation filter, so `index.js` unregisters the Wait variation while the selection is a loop or inside one, and registers it back after). A Wait or loop pasted in anyway is refused at its card ("A loop cannot hold a wait ...", the validator's message, focused when selected) and on save.
- Move to: targets are "Main steps", each branch ("route: Major gift") and each loop body ("Repeat each_task"), filtered by `moveTargets()` in `logic.js`: the 3-level limit counts loops, and a block that is or holds a loop or a wait is not offered any loop body. List View labels read "Repeat each_task".
- Scope inside a body: `steps.<loop>.item.<key>` by the find's type ("Each Task (in each_task)" picker group, plus `permalink` and `edit_url`), `steps.<loop>.index` ("Item number"), and record ID fields typed for `update_record` (`{{steps.each_task.item.id}}` picks Task fields); after the loop, `steps.<loop>.count` ("Items repeated"). A condition step in a body is labelled "Continue with this item only if".
- "Insert list of records" beside "Insert merge tag" on email bodies: choose a list ("Tasks from due") and it inserts `{{#each steps.due.records}}- {{item.name}}\n{{/each}}` (the first field; HTML bodies get `<ul>` and `<li>`).
- Test run: loop body lines sit under item headings `Item 1 of 2: Task 20 "Call Ada"`; a skipped item or "and N more" closes its group.
- Run detail (Admin): step ids in the Step column no longer break mid-word at 782 px (core's `word-break: break-word` is reset for row-header codes).

![Repeat for each with its body](screenshots/automation-loop-1280.png)

![Insert list of records](screenshots/automation-loop-list-1280.png)

![A Wait pasted into a loop body, refused](screenshots/automation-loop-refused-1280.png)

![Test run item headings](screenshots/automation-loop-test-run-1280.png)

![Get a record by relationship](screenshots/automation-read-get-1280.png)

![Find records with a total](screenshots/automation-read-find-1280.png)

![Link records continuing if it fails](screenshots/automation-relate-1280.png)

![The Started by staff trigger](screenshots/automation-trigger-manual-1280.png)

![Test run of barn_lifetime_milestone](screenshots/automation-read-test-run-1280.png)

![The canvas: a three-way decision with a nested If](screenshots/automation-builder-canvas-1280.png)

![A branch's condition in the inspector](screenshots/automation-builder-condition-1280.png)

![A refused save: the message at the step and its field](screenshots/automation-builder-error-1280.png)

![The Test run report](screenshots/automation-builder-test-run-1280.png)

![The merge tag preview of an email step](screenshots/automation-builder-preview-1280.png)

## Testing

- Unit: `tests/Unit/Automation/ConditionsTest.php` (operators, operands, dates in site time, templates, `FrozenClock` across DST); `SchedulesTest.php` (next firing times including DST and week start, durations, retry policy, `InlineScheduler`); `TokenTest.php` (round trip, every kind of tampering, foreign key, malformed tokens).
- Gate: `tests/Integration/StalenessCycleGateTest.php` runs `examples/sifg` on a frozen clock from 2026-11-02 (approval email with permalink and Last verified; nothing on 2027-11-01; one "still active?" email per Project on 2027-11-02, none the next day; a Project that confirms during the wait stops at `still_stale`; the other is paused on 2027-12-02 with `paused_from`, `paused_volunteers`, a fresh link, and anonymous REST showing `paused` and no private field; the original link reactivates it on 2028-01-15; tampered and expired tokens change nothing; every run completes, no token in the log). Clicks go through `Handler::respond()` as user 0.
- BarnCRM gate: `tests/Integration/Automation/BarnFlowsGateTest.php` runs `tests/fixtures/valid/barn` (`docs/automation-v3-design.md` section 8 timelines) on a frozen clock in site time, with the site in New York so the donor's 30-day wait crosses the start of daylight saving time and still resumes at 09:00 site time. Gifts are linked to their donor by `gift_donor` in the same write. Donor (`barn_gift_thanks`): on 2027-03-01 a gift of 300 takes the major branch (email A with Reply-To the development email, one call task linked to the gift by `task_gift`, due two days later, the donor's `last_gift_on` stamped) and waits at `gifts_later` until 2027-03-31 09:00 (13:00 UTC); 40 takes otherwise (email B, no task) and waits; a donor who gives exactly 250 and then 20 on the same day gets the major branch on the first and a stop at `first_gift` on the second (two gifts counted); on 2027-03-10 a later gift stops at `first_gift`; a corrected email during the wait is the one the follow-up goes to; nothing on 2027-03-30 or before 09:00 on 2027-03-31; then both repeat donors' first runs stop at `no_second_gift` and the one first-time donor gets the follow-up; a gift with no donor link stops at `has_donor` with no email; `log --run` rows and the run detail (the read line, the indented branch, no private value). Milestone (`barn_lifetime_milestone`): gifts of 600, 300 and 100 on three days send one "Thank you for 3 gifts" email "now total $1,000.00" and stamp `milestone_1000_on`; a further gift stops at `not_yet`; a trashed and a draft gift of 500 are not counted. Digest (`barn_tasks_digest`, Monday 2027-06-07 08:00): the open tasks due within 7 days in due order (an overdue one without an assignee, then one due 06-08; a done one left out), the first reminder failing `no_recipient` and the loop going on, both stamped `reminded_on`, the summary "2 tasks due by June 14, 2027" listing both, outcome `completed_with_errors:remind`; a second firing the same day creates nothing; on 2027-06-14 a third task is due too. Inbound (`barn_donation_inbound`, signed through the REST route): a new email creates a private contact and a gift linked to it, whose thank-you runs at depth 1; the same email again reuses the contact; an empty email stops at `has_email` and creates nothing. Manual (`barn_resend_thanks`): an editor resends the thank-you from a gift. Volunteer (unchanged from Phase 6): three sign-ups through `Submission::process()` as visitors on 2027-05-03 (Garden, Events, Otherwise: coordinator from `barn_settings`, one notice each with Reply-To the volunteer and the edit URL); a contacted volunteer completes at `check` with no reminder; reminders on 2027-05-05; on 2027-05-08 one escalation to the volunteer manager and a stop at `still_new`; the escalated run's path and branch summaries, `log --run` rows and the run detail markup indented by depth. `FixturesTest` checks `tests/e2e/packages/barn` holds the same types, relationships, form and options page, and that the BarnCRM automations copied into the `reads` and `engine-loops` packages equal the fixture's; `ExportImportGateTest` round-trips the package.
- BarnCRM flows in the editor: E2E `tests/e2e/specs/automation-barn-flows.e2e.js`, keyboard only (no mouse calls; long templates go in with `keyboard.insertText()`), on package `tests/e2e/packages/barn` registered by mu-plugin `pegwork-e2e-barn.php`. From Pegwork > Automations > Add automation it builds `barn_gift_thanks` (Get a record by the Donor relationship, conditions on read outputs, the size decision with a Create record whose values include the `task_gift` relationship, Update record on the typed reference `{{steps.donor.record_id}}`, two Find records with Limit 0, a Wait of 30 days, the follow-up) and `barn_volunteer_intake` (form event, decisions with options, waits in days). `automation-builder-reads.e2e.js` builds `barn_lifetime_milestone` and `automation-builder-loops.e2e.js` builds `barn_tasks_digest` against the same fixture. The texts typed come from the fixture files; the structure is entered through the controls. After each save, `GET /pegwork-e2e/v1/barn/compare?key=<key>` returns the saved definition and `tests/fixtures/valid/barn/automations/<key>.json`, both through `Normalizer::normalize()` and pretty JSON as `AutomationBuilderRoundTripTest` does, without `enabled`; the spec asserts the two strings are equal, the source is `db` and `enabled` is `false` (new site automations start off). Test run on the saved `barn_gift_thanks` with sample gifts of 300 and 40 (`POST /pegwork-e2e/v1/barn/gift` creates each with its own linked donor; picked in the Sample record combobox by keyboard) shows "Get record donor: found Contact", "Find records gifts: 1 Gift", "If Major gift (250 or more)" with `call_task` (then "Otherwise" without it) and the follow-up after the wait. Axe on the Pegwork parts and no horizontal scroll with the finished flow at 1280 and 782 px (the inspector reopened at 782). `POST /pegwork-e2e/v1/barn/reset` (before and after) deletes the BarnCRM site automations, unsaved automation posts, their runs, and every gift, task and contact. Keyboard helpers (`readByDonor()`, `relationship()`, `radio()` and the rest) are shared in `tests/e2e/utils/builder.js`. Screenshots `docs/screenshots/automation-barn-*.png`.
- Links: `tests/Integration/Automation/AutomationLinksTest.php` with fixture `tests/fixtures/valid/links` (`ticket_links`: links `confirmed`, `instant`, `elsewhere`): one token per link per render, key option autoload off, `[link]` in the log, `confirm: false` acts on GET but not HEAD, nonce required with confirm, `success_url` and `expired_url` redirects, disabled automation, trashed record and rotated key refused, `link_scope`, `definition_changed`, rate limit.
- Browser: `tests/e2e/specs/automation-links.e2e.js` (JavaScript off, axe on a JavaScript-on copy of each page) with mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-links.php` (admin-only routes: Project, record read, link mint with the code `wp pegwork automation link` uses; link rate limit raised) and the module switch in `pegwork-e2e-automation.php`.
- Integration: `tests/Integration/Automation/` with fixture package `tests/fixtures/valid/engine` (type `job` with private `note` and `contact_email`, `url` field `website`; automations `job_approved` (stamp and email), `job_loop`, `job_spawn`, `job_disabled`, `job_verified`, `job_private` (closes, then `call_webhook`; the engine test removes that handler to prove `unsupported_step`), `job_hook` (on publish with marker `hook`: two `call_webhook` steps, one with `fields`), `job_inbound` and `job_inbound_off` (webhook triggers creating a draft job), `job_mail` (HTML email with a private field), `job_sweep` (daily sweep, wait P30D, condition, close, email; `max_runs` 3), `job_digest` (hourly, no record, custom retry); options page `job_settings` for `{option:...}` recipients), `FrozenClock` and `tests/Support/FakeScheduler.php` (`drain( $until, $hooks )`); emails through the WP test suite's mock PHPMailer, failures through `pre_wp_mail`. `AutomationEmailTest` (recipients, escaping, privacy of the log, retries, `retry`), `AutomationScheduleTest` (one email per record per cycle, wait resumes after 30 days and not before, `max_runs`, chained sweep, `--now` refusal, `run`/`tick` with time travel). `AutomationWpCronTest` runs the default engine on real WP-Cron events, firing them as `wp-cron.php` does (approval, schedule firing, wait wake-up 30 days out, a wait inside a branch resuming inside it and then after the decision).
- Date waits (v4 PR 3): unit `tests/Unit/Automation/WaitUntilTest.php` (target from date, `at` and offset, DST in New York, negative and time-unit offsets, the 2-year clamp and horizon, wording). `tests/Integration/Automation/AutomationWaitUntilTest.php` on `tests/fixtures/valid/v4` (`barn_task_due_reminder`, `barn_v4_shapes` off): every rule of design section 3.2 (on time, moved earlier and later, direct meta write, empty then set, cleared then the 2-year stop, past, overdue import, done, trashed, deleted), the listener (unrelated fields, zero queries, one query, disabled automation, retry-waiting run), a time-budget requeue at the wait, a private date masked, the step limit under many edits, row labels and outcomes, dry run lines with no side effects. `AutomationWpCronTest::test_date_wait_wakes_on_wp_cron` moves the real WP-Cron event to now on a date change. E2E `tests/e2e/specs/automation-wait-until.e2e.js` saves the v4 fixture's `barn_task_due_reminder` as a site automation (routes `/barn/v4-automation` and `/barn/task-update` in `pegwork-e2e-barn.php`): "Waiting for Due on" in the log, the "Waits until" and "Checked the date again" row labels in the run detail, axe and no horizontal scroll at 1280 and 782 px. Screenshots `docs/screenshots/automation-wait-until-*.png`.
- Decisions: unit `tests/Unit/Automation/PlanTest.php` (next pointers across nesting, branch first ids, empty branches, unknown ids, flat lists, types) and `DecisionTest.php` (first match wins, otherwise, no match, summaries). `tests/Integration/Automation/AutomationBranchTest.php` with fixture `tests/fixtures/valid/branching` (`br_paths`, `br_wait`, `br_deep`, `br_hook`, `br_signup`, `br_confirmed`, `br_note`, `br_flat`): each path runs only its steps, three levels with a record created inside a branch, waits inside and last in a branch, a stop inside a branch and no match, a retry inside a branch, unique delivery ids and the secret check, a zero time budget, the step limit, removed and moved steps, a Phase 4 shaped waiting run, form binding (submitted, confirmed, entry-only, `no_record`, `run --record` type check), a write read back in the same wake-up, the run detail markup and `log --run` rows. E2E `tests/e2e/specs/automation-branches.e2e.js` with `e2e_branch` in `tests/e2e/packages/automation` (daily, two nested decisions on `site.name` and `site.url`): Run now and the run by keyboard, label rows, decision summaries, indentation by depth, axe and no horizontal scroll at 1280 and 782 px. Screenshots `docs/screenshots/automation-run-branches*.png`.
- Admin: `AutomationAdminTest` (submenu, list cells, empty states, toggle writes the same option as `Overrides::toggle()`, Run now creates a logged run and keeps the schedule, refusals for event and disabled automations, run detail and Retry to completion, log filters and pages, no private value or run context in any view, 403 for editor and subscriber on every view and action, bad or other-row nonces refused). E2E `tests/e2e/specs/automation-admin.e2e.js` with mu-plugin `pegwork-e2e-automation.php` and package `tests/e2e/packages/automation` (`e2e_digest`, daily, one email, `retry.max` 0): Disable and Enable, Run now while mail fails, open the run, Retry once mail works, filter the log, all by keyboard; axe and no horizontal scroll at 1280 and 782 px; an editor gets 403. Screenshots `docs/screenshots/automation-*.png`.
- Scheduling health: `AutomationHealthTest` on `FrozenClock` (the WP test suite defines `DISABLE_WP_CRON`): healthy state passes and is registered as a direct `site_status_tests` test; a waiting run 15 minutes past `resume_at` or a queued run idle 15 minutes is overdue (critical), finished runs never; a missing maintenance event, or one 2 hours past its slot, is flagged (recommended); no tick or a tick over 2 hours old is flagged (critical); the tick is written only in cron processes, autoload off, at most once a minute; the notice shows once to administrators on Pegwork screens only (not core screens, other plugin pages, editors, authors or subscribers), with the fix inline when the user cannot view Site Health. E2E `tests/e2e/specs/automation-health.e2e.js` (route `POST /pegwork-e2e/v1/automation/overdue` adds a waiting run of the unregistered key `e2e_health`): the test under Passed, then Critical with the run, opened by keyboard, axe at 1280 and 782 px; one notice on Automations, none on the Dashboard. Screenshots `docs/screenshots/automation-site-health.png`, `automation-health-notice.png`.
- Editing surface: `AutomationBuilderRoundTripTest` (every valid automation in `tests/fixtures`, the E2E packages and `examples`, canonical and as written: `normalize(compile(generate(d)))` equals `normalize(d)` byte for byte, the markup is stable, misplaced blocks reported by pointer); `AutomationBuilderTest` (the save API in canonical form, in place, 404, 400 by pointer at any depth, 409 for file keys; post type and capability mapping; REST save with `enabled: false` on first save and the key locked; 400 with pointers, 409 on a new post and on a file automation's post; 403 for editor and subscriber on REST read, create, update and delete and on the list; kses without `unfiltered_html`; trash and restore; sync by hash keeping the modified date; list links, read-only locks and config; Add and Duplicate prefills). E2E `tests/e2e/specs/automation-editor-surface.e2e.js` (routes `GET /pegwork-e2e/v1/automation/definition`, `POST /pegwork-e2e/v1/automation/site-reset`): REST 400 then 201, the list links by keyboard with axe and no horizontal scroll at 1280 and 782 px, the editor showing the decision tree and saving it back with identical steps after a keyboard title edit, a file automation read-only, its duplicate saved disabled. Screenshots `docs/screenshots/automation-list-edit-links.png`, `automation-editor-placeholder.png`, `automation-editor-read-only.png`.
- Editor: Vitest `assets/src/automation-builder/test/` (every condition in the valid fixtures, the E2E packages and `examples` through the condition model and back, byte for byte; the scope fixture's merge tags, condition fields and operators; compile, pointers, error owners, ids, durations, the report tree). `AutomationBuilderScopeTest` checks the same fixture against the PHP rules (each offered tag and field validates, each refused one is refused, exactly the offered operators validate) and that `registry_config()` matches it. E2E `tests/e2e/specs/automation-builder.e2e.js`, keyboard only: a three-way decision with a nested If built from the inserters, saved, reloaded and read back through `/pegwork-e2e/v1/automation/definition`; a bad merge tag refused at its step and reached with "Go to first error"; Move to; the Test run report and a merge tag preview; a file automation read-only and duplicated; an unselected branch's quick inserter staying open under a 6x CPU slowdown (the remounting appender regression); axe on the Pegwork parts (cards, panels, inspector) and no horizontal scroll at 1280 and 782 px. Screenshots `docs/screenshots/automation-builder-*.png`.
- Editor reads: Vitest `reads.test.js` takes every valid fixture with a read, relate, `on_error`, manual trigger or loop: block attributes parsed per `block.json` and compiled equal the steps byte for byte (canonical key order), every read property is a block attribute, each filter round trips through the condition rows; plus the `reads.js` helpers, summaries, picker group names and the values rows' relationships (`valueFields()`). The scope fixture's `reads` package (`tests/fixtures/valid/reads`) adds tag, field and operator cases for typed read outputs, `failed`/`error` and the manual payload; operator cases may name their condition (`at`). `AutomationReadsTest::test_preview_names_read_fields` (the preview route with `steps`). E2E `tests/e2e/specs/automation-builder-reads.e2e.js`, keyboard only, on package `tests/e2e/packages/barn`: builds `barn_lifetime_milestone` and compares it with the BarnCRM fixture, inserts a `| number:2` total through the grouped picker, runs Test run on a gift with a linked donor (the read lines), then sets up "Started by staff" and a Link records step that continues on failure; axe and no horizontal scroll at 1280 and 782 px. Screenshots `docs/screenshots/automation-read-*.png`, `automation-relate-*.png`, `automation-trigger-manual-1280.png`, `automation-barn-lifetime-milestone-1280.png`.
- Editor loops: Vitest `reads.test.js` round trips the loop fixtures (`barn_tasks_digest`, `reads_manual_list` and the other valid definitions with a loop) through `pegwork/automation-loop` blocks and checks every loop property is a block attribute; `logic.test.js` covers compile and pointers through loop bodies, `heightOf()`, the `moveTargets()` rules (no loop or wait into a body, the level limit) and the report tree's item headings. The scope fixture adds tag, field and operator cases inside and after a loop (`item.*`, `index`, `count`, an `{{#each}}` template). `AutomationBuilderRoundTripTest::test_loop_blocks` (generate, block names and attributes, a branch block directly in a loop refused at `/steps/0/steps`). E2E `tests/e2e/specs/automation-builder-loops.e2e.js`, keyboard only, on package `tests/e2e/packages/barn`: builds `barn_tasks_digest` (Find records with two filters, sort and status, Repeat for each, the body's quick inserter offering neither Wait nor Repeat for each, the "Each Task (in each_task)" picker group, Update record by the item ID, Move to naming the loop body, "Insert list of records") and compares it with the fixture through `/pegwork-e2e/v1/barn/compare`; a Wait copied with Ctrl+C and pasted over a body step with Ctrl+V is refused at its card with focus on the message; Test run over two sample tasks (`POST pegwork-e2e/v1/barn/task`) shows "Item 1 of 2: Task ..." headings; axe and no horizontal scroll at 1280 and 782 px. `automation-loops.e2e.js` also checks a step id stays on one line at 782 px. Screenshots `docs/screenshots/automation-loop-*.png`, `automation-run-loop*.png`.
- Webhooks: `AutomationWebhookTest` with a test receiver on `pre_http_request` that verifies the signature as documented above: signed delivery, body with public fields only, stable delivery ID, nothing sent during the save; receiver down: retries after 1 minute, 10 minutes, 1 hour, then a logged failure with the publish unaffected; non-2xx; rotation invalidating the old signature both ways; secrets autoload off and outside the registry; inbound 202 with a run, duplicate delivery, Records validation, the indistinguishable 401s (bad, missing, replayed and future signatures, unknown, disabled and event automations), 413 (body, `Content-Length`, decoded payload), 415, 400, 429 and the sliding window; security review regressions: a replayed signature is a duplicate with a new or no delivery ID (L-02), 413, 415 and 429 hold for any letter case of the route (SR-01), and unsigned junk neither locks out the signed sender nor tells known keys from unknown ones (SR-02); the list shows the URL, not the secret. E2E `tests/e2e/specs/automation-webhooks.e2e.js` with mu-plugin `pegwork-e2e-webhooks.php` (package `tests/e2e/packages/webhooks` with `e2e_hook`, an in-process receiver for `receiver.e2e.test`, a fixed test secret on reset, receiver down switch): real HTTP to the endpoint signed with Node's `crypto` (202, duplicate, 401s, 413, 415, 429 with `Retry-After`), the delivery verified by the receiver, the list and a retrying run by keyboard, axe and no horizontal scroll at 1280 and 782 px.
- Preview and dry run: `tests/Integration/Automation/AutomationDryRunTest.php` with fixture `tests/fixtures/valid/dryrun` (type `dr_case` with private `email`, form `dr_intake`, automations `dr_flow` (decision, email with a link, webhook, create with a step output, wait, update, condition), `dr_inbound` (webhook trigger), `dr_intake_notice` (form binding)): counters on `pre_wp_mail`, `pre_http_request` and `pegwork_event` stay 0, run and step rows, posts, post meta, sent mail and the options `pegwork_link_secret` and `pegwork_automation_secrets` unchanged; report shape and text lines for the Big, Otherwise and stop paths; webhook and form payloads; sample record errors; preview placeholders; REST bodies equal `AutomationCommand::test_result()`; 403 for editor and subscriber, 401 for no user or a missing nonce, core's 403 for a bad nonce, 415, no-store. Unit `ConditionsTest::test_templates_preview_mode`.
- Reads: `tests/Integration/Automation/AutomationReadsTest.php` with fixture `tests/fixtures/valid/engine-reads` (the reads fixture's BarnCRM types and relationships; automations `er_gift` (get the donor, stamp, email with `| number`, `| add` and the stamped date, a task linked through `task_gift`, wait P30D, find gifts with a sum, `{{#each}}` in text and HTML), `er_link` (webhook: get by ID, relate twice, an `on_error` unlink with no ID, `call_webhook.record` of a gift and a contact, an `on_error` webhook of the wrong type, a report email), `er_limits` (empty operand, three finds against the read limit)): references in the context and the summaries, read-after-write, fresh values after the wait (a new address, a later gift), relate `changed` and its one event at depth 1, public fields only, `completed_with_errors:unlink,wrong` counted as a failure, `read_limit` with and without `on_error`, `| add` across DST, `| number`, `{{#each}}` escaping, trashed items, `{{else}}` and the 64 KB cap, `[private]` for read paths in the log, and a dry run of both flows with the mail, HTTP and event counters at 0 and run, step, post, meta and `_rel_` rows unchanged. `AutomationV3GuardTest`: every fixture runs (loops included), and a step type without a handler is refused.
- Loops: fixture `tests/fixtures/valid/engine-loops` (the BarnCRM types, options page `barn_settings`, `barn_tasks_digest` as in worked example c, and `lp_items`: a webhook run over open tasks with a condition, a read, a decision with a `call_webhook.record` or a retried email, and a stamp), base class `tests/Support/LoopsTestCase.php`. `tests/Unit/Automation/PlanTest.php` (loop next pointers, `body_first`, `loop_of`, loops inside branches), `tests/Unit/Automation/LoopCursorTest.php` (zero budget, one step per wake-up: start, advance with no row, empty, a skip on a false condition, trashed and deleted items), `tests/Integration/Automation/AutomationLoopTest.php` (the digest timeline with idempotency and the next week, the same emails under a zero budget, default retries off and Retry from the log resuming the item, an explicit retry, per-item delivery IDs, read and step limits, `definition_changed` mid-loop, run detail and CLI item grouping, the dry-run loop report with no side effects, the 50-item digest under 5 s, and a Schema 1 site upgrading with its waiting run intact), and `AutomationWpCronTest` (a loop resuming after time-budget requeues on real WP-Cron). E2E `tests/e2e/specs/automation-loops.e2e.js` with `e2e_loop` in `tests/e2e/packages/automation` (daily, a find over draft projects with "Who it's for" set to "E2E loop", a loop with a condition on the website and an email, a summary email; the package now requires Pegwork 0.3.0): Run now and the run by keyboard over three projects, item heading rows with titles, the read record titles, the skipped item, indentation, axe and no horizontal scroll at 1280 and 782 px. Screenshots `docs/screenshots/automation-run-loop*.png`.
- Trash: `tests/Integration/Automation/AutomationTrashTest.php` with fixture `tests/fixtures/valid/engine-trash` (types `tr_volunteer` and `tr_gift`, relationship `tr_volunteer_gift`; manual automations `tr_bound` (trash the bound record, again, read it, stamp and link it with `on_error`, email), `tr_read` (trash a `get_record` result), `tr_loop` (trash up to 50 found in a loop, then 10 more with `on_error`)): bound, read and item targets, `record_type`, `trash_disabled` with the post left, `changed: false`, the 51st `trash_limit` with and without `on_error`, the filter capped at 200, events at depth 1, later reads, `update_record` and `relate` on the trashed record, restore, the sweep guard, and a dry run with the mail, HTTP and event counters at 0 and rows unchanged. `tests/Unit/Automation/ManualTrashesTest.php`: the trash closure through start chains. `AutomationManualTest`: `can_run()` needs `delete_post` for an automation that trashes. The JS builder does not know `trash_record` yet, so `reads.test.js` skips the package (Editor PR 9).
- Manual trigger: `tests/Integration/Automation/AutomationManualTest.php` with fixture `tests/fixtures/valid/manual` (types `man_gift`, `man_note`; `man_thanks` (gift, `editors`, `when` amount > 0, one email), `man_receipt` (gift, `admins`), `man_report` (no record)): 403 for subscribers and for editors on `admins` and no-record automations, 401 logged out; an editor allowed on an editable gift (202, payload, one email) and refused on another type and on a gift they cannot edit; the run link for administrators only; nonce; the throttle (10 a minute per user, Retry-After, per user, window); double-submit dedupe per user; the `when` refusal; disabled and unknown keys; what the record editor lists per role; the list's Run now (markup, source `list`, dedupe, redirect); CLI `run` (source `cli`, record type, `when`). E2E `tests/e2e/specs/automation-manual.e2e.js` with package `tests/e2e/packages/manual` (`e2e_manual_thanks` on the BarnCRM gift, `allow: editors`, emailing the gift's donor read by `gift_donor`; `e2e_manual_report`, no record) and mu-plugin `pegwork-e2e-manual.php`: by keyboard, an editor and an administrator run "Resend thank-you" from a gift (dialog, notice, `speak()`, focus return; the administrator follows "View run"), and Run now on the list; axe and no horizontal scroll at 1280 and 782 px.
- Local site check: `bin/e2e-local-site.sh start <name> <port>`, mu-plugins registering the fixture package and logging `wp_mail()` to a file (`pre_wp_mail`), `wp pegwork module enable automation`; publish a pending `job` and let WP-Cron run; `wp pegwork automation run job_sweep --now=+12months`, `tick --now=+14months`, `log`.
