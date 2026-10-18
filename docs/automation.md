# Automation module

Module `automation` (`modules/automation/`, namespace `PegworkAutomation\`): runs the registry's automations (`docs/registry-reference.md#automations`) in the background, logs every run, and adds `wp pegwork automation`. Design: `docs/phase-4-design.md` section 6 and its 2026-10-18 decisions. Uses only `Pegwork\Api` (Records, Module), `pegwork_registry()` and WordPress.

Status (Phase 4 PR 3): event triggers; steps `update_record`, `create_record`, `condition`; WP-Cron wake-ups; maintenance; CLI `list`, `log`, `enable`, `disable`. Not yet: `send_email`, `call_webhook`, `wait`, retries, schedule and webhook triggers, signed links, `run`/`tick`/`retry` CLI, admin screens. A run that reaches a step type this release does not run fails with `unsupported_step` (earlier steps keep their effect).

Enable: `wp pegwork module enable automation` (or Pegwork > Modules). Disabled, nothing is included: no listener, no tables touched, no runs.

## How a run happens

1. A record event fires (`docs/events.md`). For each automation with an `event` trigger on that name (narrowed by `content_type` or `form`) and effectively enabled, the listener evaluates `when` against the record and payload, synchronously (old values exist only now).
2. Loop cap: if the payload's `origin.depth` is 3 or more (filter `pegwork_automation_max_depth`), it inserts a `skipped` run with error `loop_depth: ...` and runs no steps.
3. Idempotency: with `idempotency`, the rendered key must be new for that automation (unique index on `automation, idempotency_key`; keys over 191 bytes are stored as `md5:<hash>`). A repeat creates nothing.
4. It inserts a `queued` run (trigger payload in `context`, capped at 16 KB) and schedules `pegwork_automation_run` [run id] for now. The saving request never runs steps.
5. On wake-up the executor claims the run atomically (`UPDATE ... SET status = 'running' WHERE id = %d AND (status = 'queued' OR (status = 'waiting' AND resume_at <= now))`, proceeding only on 1 row), so a duplicate WP-Cron firing or a re-entrant call does nothing. A removed automation cancels the run (`automation_removed`), a disabled one too (`automation_disabled`), a `current_step` missing from the definition fails it (`definition_changed`).
6. Steps run in order from `current_step`, the record re-read through `Records::get()`. Each attempt writes one `pegwork_run_steps` row. Writes go through `Pegwork\Api\Records` with origin `{ automation, run_id, depth: run depth + 1 }`, so they are validated and emit events that may start further runs.
7. Time budget: before each step after the first in a wake-up, if 20 s have passed (filter `pegwork_automation_time_budget`), the run goes back to `queued` at that step and is re-scheduled. At most 100 step attempts per run (`step_limit`).
8. End: `completed` (a false `condition` stores outcome `stopped_at:<id>` in `context.outcome`), or `failed` with the step's error (`<code>: <message>`, for example `pegwork_invalid_record: ...`).

Effective enabled state: option `pegwork_automation_overrides` (`key => on|off`, set by `enable`/`disable`) wins over the JSON `enabled`.

## Scheduler and clock

- `Scheduler` (`src/Scheduler/Scheduler.php`): `schedule( $hook, $args, $timestamp ): bool` (replaces a pending firing with the same hook and args), `unschedule( $hook, $args )`, `next( $hook, $args ): ?int`. It is only the wake-up transport; `pegwork_runs` is the source of truth.
- `WpCronScheduler` (default): WP-Cron single events. When it schedules something due now, the request calls `spawn_cron()` at shutdown (filter `pegwork_automation_spawn_cron`, skipped inside cron and with `DISABLE_WP_CRON`), so a run does not wait for the next visitor. On low-traffic sites prefer a system cron: `DISABLE_WP_CRON` plus `wp cron event run --due-now` every minute.
- Action Scheduler is not bundled (decision 8 in the design): an AS 4.2+ adapter implementing the same three methods is a dependency bump once the WordPress minimum is 6.9.
- `Clock` (`now(): DateTimeImmutable`, UTC): `SystemClock`, `FrozenClock` (`set()`, `advance( 'P12M' )` in site-time calendar units). Business time (`today`, `now`, date operands, stored timestamps, maintenance time) comes only from the clock; the time budget uses wall time. `PegworkAutomation\Engine::use( $clock, $scheduler )` swaps both (tests; later the `--now` flag).

## Maintenance

Daily `pegwork_automation_maintenance` at 03:00 site time (self-rescheduling; boot re-creates it if missing):
- re-schedules `queued` runs idle for 10 minutes and `waiting` runs past `resume_at` that have no pending wake-up (lost WP-Cron events, or events that fired while the module was disabled);
- marks `running` runs whose last claim is over 15 minutes old as `failed` (`interrupted: ...`), never repeating a side effect automatically;
- prunes `completed`, `skipped` and `cancelled` runs after 90 days and `failed` after 180 (option `pegwork_automation_retention_days` `{ done, failed }`, filter of the same name), with their steps. `queued`, `running` and `waiting` runs are never pruned.

## Tables

Installed and upgraded with `dbDelta` on boot when option `pegwork_automation_db_version` differs from `Schema::VERSION` (1). Dropped by `modules/automation/uninstall.php` (run by Pegwork's `uninstall.php`), which also clears both cron hooks.

`{prefix}pegwork_runs`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `automation` | varchar(64) | key |
| `trigger_type` | varchar(16) | `event` (later `schedule`, `webhook`, `link`) |
| `record_id` | bigint unsigned null | bound record |
| `status` | varchar(16) | `queued`, `running`, `waiting`, `completed`, `failed`, `cancelled`, `skipped` |
| `current_step` | varchar(64) | step running or next to run |
| `resume_at` | datetime null | UTC (waits, retries: PR 4) |
| `depth` | tinyint unsigned | trigger `origin.depth` |
| `idempotency_key` | varchar(191) null | |
| `definition_hash` | char(32) | md5 of the canonical definition at trigger time |
| `context` | longtext | JSON: `event`, `trigger` (payload), `steps` (outputs), `outcome` |
| `error` | text null | reason or `<code>: <message>` |
| `created_at`, `claimed_at`, `started_at`, `finished_at` | datetime | UTC; `claimed_at` is the last claim (stuck detection, orphan idle time) |

Indexes: `(status, resume_at)`, `(automation, created_at)`, `(record_id)`, unique `(automation, idempotency_key)`.

`{prefix}pegwork_run_steps`: `id` PK, `run_id`, `step_id` varchar(64), `step_type` varchar(32), `attempt` smallint, `status` (`ok`, `failed`; later `retrying`, `skipped`), `started_at`, `duration_ms`, `summary` (JSON: `record_id`, `keys`, `content_type`, `status`, `passed`; never field values), `error`. Index `(run_id)`.

Privacy: `context.trigger` may hold private values (`old.*`); it is never printed. The CLI shows statuses, summaries and errors only.

## Steps

`Step` interface (`src/Steps/Step.php`): `run( array $step, Context $context, array $run ): StepResult`. `StepResult::ok( $output, $summary )`, `::stop( $summary )` (ends the run as completed), `::fail( $code, $message, $summary )`. Handlers are registered in `Engine::__construct()` by type. PR 4 adds `send_email`, `wait` and retry results.

| Type | Does | Output |
|---|---|---|
| `update_record` | `Records::update( record or bound, values )`; refreshes the bound record | `record_id` |
| `create_record` | `Records::create( content_type, values, status )` | `record_id` |
| `condition` | continues when `when` holds, else stops (`stopped_at:<id>`) | none |

`values`: a non-string literal is passed as is; a string that is exactly one merge tag passes the raw value (`{{record.tags}}` stays a list, `{{record.urgent}}` a boolean); other strings are rendered. Taxonomy keys accept a list or a comma-separated string of slugs. The Records API sanitizes, validates and rejects (status changes are not possible through it).

Conditions (`Conditions::evaluate`): lists compare as sets (`eq` with a scalar means exactly that one slug; `in`/`not_in` any overlap; `contains` list membership); empty is null, '' or []; order operators are false on empty values; numbers compare numerically, booleans as booleans, `Y-m-d` and ISO datetimes as instants in site time, else strings. Templates render lists as comma-separated slugs, booleans as `1`/`0`; `{{link.*}}` renders empty until PR 5.

## WP-CLI

```
wp pegwork automation list [--format=<format>]
wp pegwork automation log [<key>] [--run=<id>] [--status=<status>] [--limit=<n>] [--format=<format>]
wp pegwork automation enable <key>
wp pegwork automation disable <key>
```

`list`: key, label, trigger, JSON `enabled`, site override, effective state, last run (status, UTC time), open runs (queued, running, waiting), failures in the last 7 days. `log --run=<id>`: the run, then each step attempt (status, duration, summary, error). `disable` stops new runs; queued runs are cancelled when they wake. CLI access implies admin; the admin screens (PR 6) will check `manage_options` and nonces.

## Testing

- Unit: `tests/Unit/Automation/ConditionsTest.php` (operators, operands, dates in site time, templates, `FrozenClock` across DST).
- Integration: `tests/Integration/Automation/` with fixture package `tests/fixtures/valid/engine` (type `job`, automations `job_approved`, `job_loop`, `job_spawn`, `job_disabled`, `job_verified`, `job_private`), `FrozenClock` and `tests/Support/FakeScheduler.php` (`drain( $until )`); `AutomationWpCronTest` runs the default engine on real WP-Cron events, firing them as `wp-cron.php` does.
- Local site check: `bin/e2e-local-site.sh start automation 8171`, an mu-plugin registering the fixture package, `wp pegwork module enable automation`, then publish a pending `job` and run `wp cron event run --due-now`; `wp pegwork automation log`.
