# BarnCRM proof fixture

The Phase 6 worked examples from `docs/phase-6-design.md` ("Worked examples: BarnCRM flows"): donor acknowledgment (`barn_gift_thanks`, `barn_gift_followup`) and volunteer intake (`barn_volunteer_intake`), with the types, form and options page they need. `tests/Integration/Automation/BarnFlowsGateTest.php` runs both timelines of design section 11; `docs/automation.md` ("Trying the BarnCRM example flows") runs them on a Local site.

Phase 5 stand-ins: on `barn_gift`, `donor_id` (number holding the contact's post ID), `donor_name` and `donor_email` (private) stand in for a gift-to-contact relationship. BarnCRM does not exist yet and a run reads only its bound record (design open questions 2 and 3), so the gift carries copies of the contact's name and email, and `stamp_donor` writes the contact by ID. Phase 5 replaces these three fields with the relationship and Records link reads.

`tests/e2e/packages/barn` holds the same types, form and options page without the automations, for the editor E2E that builds both flows by keyboard; `FixturesTest` checks the two copies stay identical.
