# Automation module

Module `automation` (`modules/automation/`, namespace `PegworkAutomation\`): runs the registry's automations (`docs/registry-reference.md#automations`) in the background, logs every run, and adds `wp pegwork automation`. Design: `docs/phase-4-design.md` section 6 and its 2026-10-18 decisions. Uses only `Pegwork\Api` (Records, Module), `pegwork_registry()` and WordPress.

Status (Phase 4 PR 4): event and schedule triggers (`for_each` sweeps); steps `update_record`, `create_record`, `condition`, `send_email`, `wait`; retries; WP-Cron wake-ups; maintenance; CLI `list`, `log`, `run`, `tick`, `retry`, `enable`, `disable` (`--now`, `--sync`). Not yet: `call_webhook`, webhook triggers, signed links (`{{link.*}}` renders empty), admin screens. A run that reaches a step type this release does not run fails with `unsupported_step` (earlier steps keep their effect; no retry).

Enable: `wp pegwork module enable automation` (or Pegwork > Modules). Disabled, nothing is included: no listener, no tables touched, no runs.

## How a run happens

1. A record event fires (`docs/events.md`). For each automation with an `event` trigger on that name (narrowed by `content_type` or `form`) and effectively enabled, the listener evaluates `when` against the record and payload, synchronously (old values exist only now).
2. Loop cap: if the payload's `origin.depth` is 3 or more (filter `pegwork_automation_max_depth`), it inserts a `skipped` run with error `loop_depth: ...` and runs no steps.
3. Idempotency: with `idempotency`, the rendered key must be new for that automation (unique index on `automation, idempotency_key`; keys over 191 bytes are stored as `md5:<hash>`). A repeat creates nothing.
4. It inserts a `queued` run (trigger payload in `context`, capped at 16 KB) and schedules `pegwork_automation_run` [run id] for now. The saving request never runs steps.
5. On wake-up the executor claims the run atomically (`UPDATE ... SET status = 'running' WHERE id = %d AND (status = 'queued' OR (status = 'waiting' AND resume_at <= now))`, proceeding only on 1 row), so a duplicate WP-Cron firing or a re-entrant call does nothing. A removed automation cancels the run (`automation_removed`), a disabled one too (`automation_disabled`), a `current_step` missing from the definition fails it (`definition_changed`).
6. Steps run in order from `current_step`, the record re-read through `Records::get()`. Each attempt writes one `pegwork_run_steps` row. Writes go through `Pegwork\Api\Records` with origin `{ automation, run_id, depth: run depth + 1 }`, so they are validated and emit events that may start further runs.
7. Time budget: before each step after the first in a wake-up, if 20 s have passed (filter `pegwork_automation_time_budget`), the run goes back to `queued` at that step and is re-scheduled. At most 100 step attempts per run (`step_limit`).
8. Waits and retries (one mechanism): the run becomes `waiting` with `resume_at` (UTC) and `current_step` (the step after a `wait`; the same step for a retry), and a wake-up is scheduled for `resume_at`. The claim only takes a waiting run once `resume_at` has passed, so an early or duplicate wake-up does nothing; the daily maintenance (or `tick`) re-schedules a waiting run whose wake-up was lost. A trailing `wait` completes the run at once.
9. End: `completed` (a false `condition` stores outcome `stopped_at:<id>` in `context.outcome`), or `failed` with the step's error (`<code>: <message>`, for example `pegwork_invalid_record: ...`; after retries ` (after N attempts)` is appended).

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
- From the site's default sender via `wp_mail()`. `to` renders to 1 to 5 addresses separated by commas or semicolons (`too_many_recipients`); each part is an address (a literal or a field such as `{{record.contact_email}}`) or `{option:<page>.<field>}`, read with `pegwork_get_option()` (options pages, core). Option tags are taken from the template, never from rendered values, so record data cannot name one. Every address must pass `is_email()` (`invalid_recipient`); an empty list is `no_recipient`; a site without `pegwork_get_option()` gives `options_unavailable`. Duplicates are dropped case-insensitively.
- `subject` and `text` render as text: taxonomy keys show term names, lists join with ", ", and the HTML entities WordPress stores in titles and the site name are decoded (as core's own emails do). Line breaks are removed from the subject. Without `html` the mail is `text/plain; charset=UTF-8`.
- With `html`: sent as `text/html` with `text` as the plain-text alternative (PHPMailer `AltBody`, set by a `phpmailer_init` callback added and removed around this one call). Every merge-tag value is escaped: `esc_url` for `record.permalink`, `record.edit_url`, `site.url`, `link.*` and `url` fields, `esc_html` for everything else (no double encoding, so a stored `&amp;` renders "&"). The authored template is trusted (committed config, imported by CLI only).
- Privacy: private fields may appear in the email (it goes to the record's own contact by design) and nowhere else. The step summary holds `to` masked (`o***@example.org`), the subject rendered with private fields as `[private]`, and `html` (bool); never the body. Error messages have recipient addresses masked.
- `wp_mail()` returning false is retryable; the `wp_mail_failed` message becomes the error (`mail_failed: ...`). Recipient errors and a body that renders empty (`empty_body`; PHPMailer refuses it) are not retried.

`wait`: `for` (ISO 8601, `PT1H` to `P2Y`). Calendar units move in site time (`P30D` keeps 09:00 across a DST change). Summary `for`, `resume_at`.

Retries: a failed attempt is retried when the failure is retryable and the step's policy has retries left: the run-step row is `retrying`, the run waits at the same step (`error` `retrying: <error>` meanwhile), and the attempt number counts up per step. Policy: the step's `retry` (`max`, `backoff`: one duration for every retry, or a list with the last entry repeating; `backoff` omitted means the type's default, else `PT15M`), else the type default: `send_email` 2 retries `PT15M` apart, `call_webhook` 3 after `PT1M`, `PT10M`, `PT1H` (PR 7), others none. Configuration failures (`unsupported_step`, recipient errors, bad durations) are never retried. `wp pegwork automation retry <run>` resumes a failed run from its failed step with the policy counted afresh.

## Scheduler and clock

- `Scheduler` (`src/Scheduler/Scheduler.php`): `schedule( $hook, $args, $timestamp ): bool` (replaces a pending firing with the same hook and args), `unschedule( $hook, $args )`, `next( $hook, $args ): ?int`. It is only the wake-up transport; `pegwork_runs` is the source of truth.
- `WpCronScheduler` (default): WP-Cron single events. When it schedules something due now, the request calls `spawn_cron()` at shutdown (filter `pegwork_automation_spawn_cron`, skipped inside cron and with `DISABLE_WP_CRON`), so a run does not wait for the next visitor. On low-traffic sites prefer a system cron: `DISABLE_WP_CRON` plus `wp cron event run --due-now` every minute.
- Action Scheduler is not bundled (decision 8 in the design): an AS 4.2+ adapter implementing the same three methods is a dependency bump once the WordPress minimum is 6.9.
- `InlineScheduler` (CLI `--sync` and `--now`): holds `pegwork_automation_run` and `pegwork_automation_sweep` wake-ups in memory and fires the due ones in process, in time order, including the follow-ups they schedule; other hooks pass to the site scheduler, and what is still pending at the end (a wait or retry not yet due) is handed to it, replacing any pending firing for the same run.
- `Clock` (`now(): DateTimeImmutable`, UTC): `SystemClock`, `FrozenClock` (`set()`, `advance( 'P12M' )` in site-time calendar units). Business time (`today`, `now`, date operands, stored timestamps, maintenance time, schedule times, waits, backoff) comes only from the clock; the time budget uses wall time. `PegworkAutomation\Engine::use( $clock, $scheduler )` swaps both (tests, `--now`, `--sync`).

## Maintenance

Daily `pegwork_automation_maintenance` at 03:00 site time (self-rescheduling; boot re-creates it if missing):
- re-schedules `queued` runs idle for 10 minutes and `waiting` runs past `resume_at` that have no pending wake-up (lost WP-Cron events, or events that fired while the module was disabled); `tick` runs only this part, replacing pending wake-ups;
- marks `running` runs whose last claim is over 15 minutes old as `failed` (`interrupted: ...`), never repeating a side effect automatically;
- prunes `completed`, `skipped` and `cancelled` runs after 90 days and `failed` after 180 (option `pegwork_automation_retention_days` `{ done, failed }`, filter of the same name), with their steps. `queued`, `running` and `waiting` runs are never pruned.

## Tables

Installed and upgraded with `dbDelta` on boot when option `pegwork_automation_db_version` differs from `Schema::VERSION` (1). Dropped by `modules/automation/uninstall.php` (run by Pegwork's `uninstall.php`), which also clears both cron hooks.

`{prefix}pegwork_runs`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `automation` | varchar(64) | key |
| `trigger_type` | varchar(16) | `event`, `schedule`, `manual` (`run --record` on an event automation); later `webhook`, `link` |
| `record_id` | bigint unsigned null | bound record |
| `status` | varchar(16) | `queued`, `running`, `waiting`, `completed`, `failed`, `cancelled`, `skipped` |
| `current_step` | varchar(64) | step running or next to run |
| `resume_at` | datetime null | UTC; when a `waiting` run (wait or retry) may resume |
| `depth` | tinyint unsigned | trigger `origin.depth` |
| `idempotency_key` | varchar(191) null | |
| `definition_hash` | char(32) | md5 of the canonical definition at trigger time |
| `context` | longtext | JSON: `event`, `trigger` (payload), `steps` (outputs), `outcome`, `retries` (step ID => automatic retries made) |
| `error` | text null | reason or `<code>: <message>` |
| `created_at`, `claimed_at`, `started_at`, `finished_at` | datetime | UTC; `claimed_at` is the last claim (stuck detection, orphan idle time) |

Indexes: `(status, resume_at)`, `(automation, created_at)`, `(record_id)`, unique `(automation, idempotency_key)`.

`{prefix}pegwork_run_steps`: `id` PK, `run_id`, `step_id` varchar(64), `step_type` varchar(32), `attempt` smallint (per step, counting retries), `status` (`ok`, `failed`, `retrying`), `started_at`, `duration_ms`, `summary` (JSON: `record_id`, `keys`, `content_type`, `status`, `passed`, `for`, `resume_at`, email `to` masked and `subject` with private fields hidden; never field values of private fields), `error`. Index `(run_id)`.

Privacy: `context.trigger` may hold private values (`old.*`); it is never printed. The CLI shows statuses, summaries and errors only.

## Steps

`Step` interface (`src/Steps/Step.php`): `run( array $step, Context $context, array $run ): StepResult`. `StepResult::ok( $output, $summary )`, `::stop( $summary )` (ends the run as completed), `::wait( $resume_at, $summary )` (the run waits, then continues with the next step), `::fail( $code, $message, $summary, $retryable = true )` (retried per policy when retryable). Handlers are registered in `Engine::__construct()` by type; the executor decides retries (`Retry::delay()`).

| Type | Does | Output |
|---|---|---|
| `update_record` | `Records::update( record or bound, values )`; refreshes the bound record | `record_id` |
| `create_record` | `Records::create( content_type, values, status )` | `record_id` |
| `condition` | continues when `when` holds, else stops (`stopped_at:<id>`) | none |
| `send_email` | `wp_mail()`, see above | none |
| `wait` | waits `for` | none |

`values`: a non-string literal is passed as is; a string that is exactly one merge tag passes the raw value (`{{record.tags}}` stays a list, `{{record.urgent}}` a boolean); other strings are rendered. Taxonomy keys accept a list or a comma-separated string of slugs. The Records API sanitizes, validates and rejects (status changes are not possible through it).

Conditions (`Conditions::evaluate`): lists compare as sets (`eq` with a scalar means exactly that one slug; `in`/`not_in` any overlap; `contains` list membership); empty is null, '' or []; order operators are false on empty values; numbers compare numerically, booleans as booleans, `Y-m-d` and ISO datetimes as instants in site time, else strings.

Templates (`Templates::render( $template, $context, $mode )`): `raw` (values, idempotency keys: lists as comma-separated slugs, booleans `1`/`0`), `text` and `html` (email, above), `log` (private fields as `[private]`). `Context::is_private()`, `field_type()` and `taxonomy()` answer for `record.<key>` and `trigger.old.<key>` paths. `{{link.*}}` renders empty until PR 5.

## WP-CLI

```
wp pegwork automation list [--format=<format>]
wp pegwork automation log [<key>] [--run=<id>] [--status=<status>] [--limit=<n>] [--format=<format>]
wp pegwork automation run <key> [--record=<id>] [--now=<datetime|offset>] [--sync]
wp pegwork automation tick [--now=<datetime|offset>] [--sync]
wp pegwork automation retry <run> [--now=<datetime|offset>] [--sync]
wp pegwork automation enable <key>
wp pegwork automation disable <key>
```

`list`: key, label, trigger, JSON `enabled`, site override, effective state, last run (status, UTC time), open runs (queued, running, waiting), failures in the last 7 days, next schedule firing (site time). `log --run=<id>`: the run, then each step attempt (status, duration, summary, error). `disable` stops new runs; queued runs are cancelled when they wake. CLI access implies admin; the admin screens (PR 6) will check `manage_options` and nonces.

- `run`: a schedule automation fires once now (sweeping its records, or only `--record`, filters still applied) without moving its regular schedule; an event automation starts one run against `--record` (`trigger_type` `manual`, payload `id`, `type`, `status`, origin source `cli`; `when` is not evaluated, the idempotency key applies). Disabled automations are refused.
- `tick`: resumes waiting runs whose `resume_at` has passed and queued runs idle for 10 minutes, replacing pending wake-ups. It never prunes or interrupts runs.
- `retry <run>`: failed runs only; resumes at the failed step (earlier steps are not repeated), retry policy counted afresh.
- `--sync`: executes the runs and everything they trigger in this process (`InlineScheduler`); later wake-ups go to WP-Cron. Without it, runs are queued for WP-Cron.
- `--now`: sets the clock for this process only and implies `--sync`: a site-time datetime (`"2027-11-02 09:00"`) or an offset from now (`+12months`, `"+13 months"`, `+P30D`, `-PT1H`). Refused when `wp_get_environment_type()` is `production` (WordPress's default when `WP_ENVIRONMENT_TYPE` is unset), so set `WP_ENVIRONMENT_TYPE` to `local`, `development` or `staging` on test sites. Waits created under `--now` are stored on that timeline (`resume_at` in the future of the real clock), so resume them with a later `tick --now`; `tick --now` also runs queued runs that look idle on the moved clock, with that clock.

Local runbook (one staleness cycle): `wp pegwork automation run <sweep> --now=+12months`, check the email, `wp pegwork automation tick --now=+13months`, check the record, `wp pegwork automation log <sweep>`.

## Testing

- Unit: `tests/Unit/Automation/ConditionsTest.php` (operators, operands, dates in site time, templates, `FrozenClock` across DST); `SchedulesTest.php` (next firing times including DST and week start, durations, retry policy, `InlineScheduler`).
- Integration: `tests/Integration/Automation/` with fixture package `tests/fixtures/valid/engine` (type `job` with private `note` and `contact_email`, `url` field `website`; automations `job_approved` (stamp and email), `job_loop`, `job_spawn`, `job_disabled`, `job_verified`, `job_private` (unsupported `call_webhook`), `job_mail` (HTML email with a private field), `job_sweep` (daily sweep, wait P30D, condition, close, email; `max_runs` 3), `job_digest` (hourly, no record, custom retry); options page `job_settings` for `{option:...}` recipients), `FrozenClock` and `tests/Support/FakeScheduler.php` (`drain( $until, $hooks )`); emails through the WP test suite's mock PHPMailer, failures through `pre_wp_mail`. `AutomationEmailTest` (recipients, escaping, privacy of the log, retries, `retry`), `AutomationScheduleTest` (one email per record per cycle, wait resumes after 30 days and not before, `max_runs`, chained sweep, `--now` refusal, `run`/`tick` with time travel). `AutomationWpCronTest` runs the default engine on real WP-Cron events, firing them as `wp-cron.php` does (approval, schedule firing, wait wake-up 30 days out).
- Local site check: `bin/e2e-local-site.sh start <name> <port>`, mu-plugins registering the fixture package and logging `wp_mail()` to a file (`pre_wp_mail`), `wp pegwork module enable automation`; publish a pending `job` and let WP-Cron run; `wp pegwork automation run job_sweep --now=+12months`, `tick --now=+14months`, `log`.
