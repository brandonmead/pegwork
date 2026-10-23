# BarnCRM proof fixture

The Automation v3 worked examples: a small donor CRM with contacts, gifts and follow-up tasks, plus volunteer intake. `tests/Integration/Automation/BarnFlowsGateTest.php` runs each flow as a timeline; `docs/automation.md` ("Trying the BarnCRM example flows") runs them on a Local site.

Types: `barn_contact`, `barn_gift`, `barn_task`, `barn_volunteer`. Relationships: `gift_donor` (gift to its contact) and `task_gift` (task to its gift). A gift's donor is read through `gift_donor` with a `get_record` step; there are no copied donor fields.

Automations:

- `barn_gift_thanks`: on a published gift, reads the donor, thanks them (a major gift also gets a call task), stamps the contact, then waits 30 days and sends a follow-up only if no second gift arrived.
- `barn_lifetime_milestone`: totals a donor's live gifts with `find_records` and thanks them once when the total reaches 1,000.
- `barn_tasks_digest`: a scheduled digest that loops over open tasks due within a week, reminds each assignee (`on_error: continue`) and sends one summary.
- `barn_resend_thanks`: a manual trigger that resends a gift's thank-you.
- `barn_donation_inbound`: a signed webhook trigger that finds or creates the contact, then creates the gift and relates it.
- `barn_volunteer_intake`: volunteer intake from the signup form, with the options page settings.

`tests/e2e/packages/barn` holds the same types, relationships, form and options page without the automations, for the editor E2E `tests/e2e/specs/automation-barn-flows.e2e.js` and the builder specs (registered by `tests/e2e/mu-plugins/pegwork-e2e-barn.php`), which build automations by keyboard and compare each saved one with its file here. `FixturesTest` checks that the E2E package matches this fixture and that the automation copies in `tests/fixtures/valid/reads` and `engine-loops` equal their files here.
