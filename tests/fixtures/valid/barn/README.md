# BarnCRM proof fixture

The Automation v3 and v4 worked examples: a small donor CRM with contacts, gifts and follow-up tasks, plus volunteer intake. Package version 2.1.0 (`requires.pegwork >=0.4.0`). `tests/Integration/Automation/BarnFlowsGateTest.php` runs each flow as a timeline; `docs/automation.md` ("Trying the BarnCRM example flows") runs them on a Local site.

Types: `barn_contact`, `barn_gift`, `barn_task`, `barn_volunteer`. Relationships: `gift_donor` (gift to its contact) and `task_gift` (task to its gift). A gift's donor is read through `gift_donor` with a `get_record` step; there are no copied donor fields.

Automations:

- `barn_gift_thanks`: on a published gift, reads the donor, thanks them (a major gift also gets a call task), stamps the contact, starts `barn_gift_receipt` for the gift, then waits 30 days and sends a follow-up only if no second gift arrived.
- `barn_gift_receipt` (v4): a manual trigger ("Send receipt", editors, only for an amount over 0) that sends the gift's receipt. `barn_gift_thanks` starts it for every new gift; staff resend it from the gift or with bulk Run on the Gifts list.
- `barn_lifetime_milestone`: totals a donor's live gifts with `find_records` and thanks them once when the total reaches 1,000.
- `barn_tasks_digest`: a scheduled digest that loops over open tasks due within a week, reminds each assignee (`on_error: continue`) and sends one summary.
- `barn_task_due_reminder` (v4): on a new task, waits until 3 days before its due date at 09:00 (checked again when the date changes), then reminds the assignee if the task is still open and stamps `reminded_on`. A task due within 3 days (such as the call task `barn_gift_thanks` creates) is reminded at once.
- `barn_resend_thanks`: a manual trigger that resends a gift's thank-you.
- `barn_donation_inbound`: a signed webhook trigger that finds or creates the contact, then creates the gift and relates it.
- `barn_volunteer_intake`: volunteer intake from the signup form, with the options page settings (unchanged since Phase 6, the regression proof).
- `barn_volunteer_review` (v4): on a signup, emails the volunteer manager with every editor in Bcc (`{role:editor}`); the log shows `role:editor (2)`, never a member.
- `barn_volunteer_retention` (v4): every day at 03:30, moves up to 50 applications created more than two years ago and not placed to the trash, then reports the count. A restored application still matches the next day unless its stage changes to placed.

The v4 automations are byte-identical copies of `tests/fixtures/valid/v4/automations` (that package also holds the v4 shape automations, which are not worked examples).

`tests/e2e/packages/barn` holds the same types, relationships, form and options page without the automations, for the editor E2E `tests/e2e/specs/automation-barn-flows.e2e.js`, the v4 builder specs and the other builder specs (registered by `tests/e2e/mu-plugins/pegwork-e2e-barn.php`), which build automations by keyboard and compare each saved one with its file here. `FixturesTest` checks that the E2E package matches this fixture and that the automation copies in `tests/fixtures/valid/reads`, `engine-loops` and `v4` equal their files here.
