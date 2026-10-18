# Pegwork forms

Module `forms` (`modules/forms`, namespace `PegworkForms\`): places registry forms on pages, handles submissions without JavaScript, stores entries, creates or updates records of a content type, holds submissions for email confirmation, sends notifications and emits `form.submitted` and `form.confirmed`. Design: `docs/phase-3-design.md`. Definitions: `docs/registry-reference.md#forms`.

Status (Phase 3 PR 3 and PR 7): entry-only forms, record mapping (`target`, bound items), email confirmation (`confirm`), and the entries admin, CSV export, privacy tools and retention cleanup. Turnstile and uploads (PR 4), the front-end script (PR 5) and the builder (PR 6) come later.

## Enable

Pegwork > Modules > Forms, or `wp pegwork module enable forms`. Disabled, the module's files are never included: the block is unregistered (saved blocks render nothing) and POSTs are ignored. Enabling installs the entries table.

## Place a form

```html
<!-- wp:pegwork/form {"form":"request_help"} /-->
```

In the editor, the Form block (category Pegwork) has a form picker and a server-rendered preview. Placement is by key, so patterns work on every site and for file forms.

Not shown to visitors (a notice for users with the forms capability, nothing for anyone else):

- an unknown key, or no key;
- a form this release cannot run: one with `captcha` or a `file` item (or a `target` type missing from the registry). Such forms fail closed until the PR that supports them; their POSTs are ignored too.

`access: { capability }` forms ask logged-out visitors to log in and tell users without the capability they lack permission.

## Markup

Server-rendered, works without JavaScript:

- `<form method="post" novalidate>` posting to the same URL. Field inputs are named `pegwork[<item key>]` (a bare `name` or `type` is a WordPress query var and would 404 the page). Control fields: `pegwork_form[key]`, `pegwork_form[token]` (signed time token), `pegwork_form[nonce]` (logged-in users only) and the honeypot `pegwork_form[url]`.
- Every control has a `<label>`; select items with `display: radio` are a `fieldset role="radiogroup"` with a `legend`; multi-select taxonomies are a `fieldset` of checkboxes named `pegwork[<key>][]` (or a multiple `select` with `display: select`); steps render as fieldsets with legends, all on one page with one submit button.
- Required items show "(required)" in the label and carry `aria-required="true"`. `autocomplete` and `placeholder` come from the definition.
- `show_when` items are always shown, with the condition as help text ("Only needed if "Needs volunteers" is checked."); the server ignores their values while the condition is false.
- Help text and the error message are linked from the control by `aria-describedby`; invalid controls get `aria-invalid="true"`.
- After errors: an error summary at the top (heading "There is a problem", one link per field, focused with `autofocus`), per-field messages prefixed "Error:" for screen readers, the posted values kept, and the document title prefixed "Error: ".
- Success: the form is replaced by `messages.success` (default "Thank you. Your message has been sent.") in a focused `role="status"` region. URLs carry no `#fragment`, because browsers skip `autofocus` when the URL targets an element.
- Stylesheet `modules/forms/blocks/form/style.css` (loaded only where the block renders): layout, visible focus, error colors. The honeypot is hidden with inline styles so it stays hidden without the stylesheet.

## Submission pipeline

`Submission::handle()` runs on `template_redirect` (priority 1) for POSTs that carry `pegwork_form[key]`:

1. Resolve the form by key from the registry. Unknown or unsupported: ignored. Target, status and fields come only from the definition; any other posted key is ignored.
2. `access` capability. Fail: "You do not have permission" re-render. Update forms: the record in `pegwork_form[record]` must exist, have the target type and pass `edit_post` for the current user, else the same re-render.
3. Logged-in users: nonce `pegwork_form_<key>`. Fail: "This page was open too long" re-render, nothing stored.
4. Time token. Missing, forged, for another form, or over 7 days old: the same "open too long" re-render, nothing stored. Under 3 seconds: spam reason `too_fast`.
5. IP rate limit. Over: "Too many submissions. Try again later." re-render, nothing stored.
6. Collect: each item's value through `pegwork_sanitize_field()` (multi-select taxonomies: each value must be an option); items whose `show_when` is false are dropped. A value given but invalid for its type (bad email, non-http URL, impossible date, unknown option or term, not a number) is a format error; any value over 65,535 characters is too long.
7. Spam: honeypot filled (or missing) adds `honeypot`; filter `pegwork_forms_spam_check` may add reasons. Any reason: the entry is stored as `spam` with `meta.spam`, no mail, no event, and the visitor gets the normal success redirect.
8. Validate: unbound items with `pegwork_validate_fields()` (required, `required_when`, `validate`; a required boolean must be checked); bound items with `pegwork_validate_record()` on the target type (its rules, defined once in the registry; an update merges the record's stored values). Errors map to item keys; a record rule on a field the form does not show is listed in the summary without a link. Errors: re-render, nothing stored.
9. Email rate limit (the confirm field, else the first email item).
10. Duplicate: the same fingerprint (sha256 of form key plus sorted values) stored as non-spam within 10 minutes returns the success redirect without a new entry (double clicks, back button).
11. `confirm` forms: the confirmation email cap (below). Over: "Too many submissions" re-render, nothing stored.
12. Store the entry: `unconfirmed` for `confirm` forms (then the token and email, below), else `received`. With a `target` and no `confirm`, write the record now (below); on failure the entry becomes `failed`, the site admin is emailed, and the visitor sees "Your submission was received, but it could not be processed" (no notifications, no event).
13. Send `submitted` notifications, store their results in `meta.notifications`, emit `form.submitted` (`record_id` set when a record was written).
14. Redirect 303 to the same URL plus `?pegwork_sent=<key>` (POST-redirect-GET). The URL drops `pegwork_sent`, `pegwork_confirm` and `pegwork_confirmed` first.

## Record mapping

A form with `target` writes a record of a registry content type through `Pegwork\Api\Records` (which sanitizes, validates again and emits `record.created` or `record.updated`).

- Bound items (`{ "bind": { "field": k } }`, `{ "bind": { "taxonomy": k } }`) resolve against the target type (`Definition::resolve()`): type, label, `required`, `required_when`, `show_when`, `validate`, options and default come from the registry; the form sets only `label`, `help`, `placeholder`, `autocomplete`, `display`. A `rich_text` field is entered in a textarea as plain text. A taxonomy becomes a choice of its existing terms (registry term order first, then other terms by name; public input never creates terms): single taxonomies a `select` (or `display: radio`), multi taxonomies checkboxes (or `display: select`). Its label is the taxonomy's singular name (plural for multi).
- Only bound keys reach the record; unbound items (a consent box) stay on the entry. Posted `post_type`, `post_status`, `post_author`, meta keys and any other key are ignored: type and status come only from the definition.
- Create: status `target.status` (default `pending`), author the submitter (0 for anonymous). Update (`target.action: update`, needs `access.capability`): the page URL carries `?record=<id>`; the form renders prefilled from the record with a hidden `pegwork_form[record]`, and the record keeps its status (a `target.status` applies to creates only).
- A `post_content` field is written as `core/paragraph` blocks: blank lines split paragraphs, single line breaks become `<br>`, `<`, `>` and `&` are escaped.
- Writes run as the submitter (`wp_set_current_user` to the entry's user, 0 for anonymous, then restored), so author and content filters never come from whoever confirms.
- The entry's `post_id` holds the record; `meta.record` holds the record an update form targeted.

## Email confirmation

A form with `confirm` holds the entry as `unconfirmed`; the record is created only when the submitter confirms (`PegworkForms\Confirm`).

- Token: 32 random bytes, base64url. Only its sha256 is stored (`token_hash`), compared with `hash_equals()`. Expiry `confirm.expires_hours` (default 72) is fixed at submit time in `meta.confirm_expires` (Unix time).
- Email: `confirm.subject` and `confirm.body` with the usual merge tags, to the `confirm.field` address. `{confirm_url}` is `?pegwork_confirm=<entry id>.<token>` on the page that held the form when it is publicly viewable, else the home page. Result in `meta.confirm_mail` (`sent`, `failed`, `skipped`). With `confirm`, `submitted` notifications may only go to fixed addresses (registry rule `form_premature_mail`).
- Cap: at most 3 confirmation emails per address (lowercased) per 24 hours, across forms, counted from entries that hold a token. Spam never sends mail.
- The link (GET) never confirms, because mail security scanners prefetch links. It shows "Confirm your submission" and a "Confirm my submission" button that POSTs `pegwork_confirm[entry]` and `pegwork_confirm[token]` to the same URL. Every request carrying `pegwork_confirm` or `pegwork_confirmed` gets no-cache headers, `noindex` and `Referrer-Policy: no-referrer`.
- The page that receives the link shows the confirmation in its `pegwork/form` block for that form; when the main content lacks that block (the home fallback, or a form placed by a template), the block is added to the top of the main content (`the_content`, singular views).
- POST: token and expiry checked, then an atomic claim (`UPDATE ... SET status = 'processing' WHERE id = %d AND status = 'unconfirmed'`), so a second or concurrent confirm does nothing. Values are validated again against the current definition (`Submission::validate()`).
  - Valid: the record is written as above; the entry becomes `received` with `post_id` and `confirmed_at`; `confirmed` notifications go out (context `post_id`, so `{post:edit_url}` works); `form.confirmed` is emitted; redirect 303 to `?pegwork_confirmed=<key>`, which shows "Thank you. Your submission is confirmed."
  - Invalid (or the write fails): a create form makes the record as a draft instead (keys the draft still rejects are dropped); the entry becomes `failed` with `meta.errors` and `post_id` of the draft; the site admin gets "Form submission needs attention" (`meta.failure_notice`); no notifications, no event. The submitter still sees the thank-you: their part is done and nothing is lost. An update confirmed later also needs the submitter's `edit_post` at that moment.
- States of the link page: pending (the button), already confirmed ("This submission is already confirmed. There is nothing more to do."), expired ("This confirmation link has expired. Submit the form again below." plus the form), not valid (wrong token or a spam entry: "This confirmation link is not valid..." plus the form). The form below a notice posts to the URL without the token.
- `Confirm::complete( $entry, $now )` runs only from the confirm link. The entries admin's "Create post anyway" claims the same way (`EntryState::claim( $id, $status, 'processing' )`) but writes a Draft with `RecordWriter::draft()` instead of completing, so nothing is mailed to an unproven address (see Entries admin).

### Rate limits

| Kind | Default | Counts |
|---|---|---|
| `ip` | 5 per form per 10 minutes | every stored entry, spam included (so a bot cannot fill the table) |
| `email` | 3 per form per hour | accepted entries |
| confirmation emails | 3 per address per 24 hours, all forms | entries holding a token (`Confirm::MAX_MAILS`, from the entries table, not a transient; not filtered) |

Kept in transients `pegwork_forms_rl_<hmac>`, keyed by an HMAC of kind, form and subject (IP or lowercased address). No IP is stored anywhere. Filters: `pegwork_forms_rate_limits` (`array( 'ip' => array( 'max', 'window' ), 'email' => ... ), $form_key`; `max` 0 disables a limit) and `pegwork_forms_client_ip` (default `REMOTE_ADDR`; return a proxy header only when the origin accepts traffic from that proxy alone).

### Time token

`<render time>.<HMAC-SHA256("pegwork_form|<key>|<time>", wp_salt( 'nonce' ))>`. Page caches make the render time older, never newer, so they only weaken the 3-second floor; honeypot and rate limits carry the load.

## Notifications

`notifications[]` with `when: submitted` are sent through `wp_mail`, plain text, synchronously, after the entry is stored. Results per key: `sent`, `failed` or `skipped` (no valid recipient), in `meta.notifications`.

- `to`, `reply_to`: a literal address, `{admin_email}`, `{field:<email item>}` (sent only when the submitter gave a valid address) or `{option:<page>.<field>}`, read through `pegwork_get_option()` when the options pages feature provides it. An unset or invalid option address in `to` falls back to the site admin email.
- `when: confirmed` notifications are sent after email confirmation writes the record (see above).
- Merge tags in `subject` and `body`: `{field:key}` (booleans Yes/No, selects and taxonomies their option labels, several joined with ", "), `{all_fields}` ("Label: value" per collected item, blank line between), `{form:label}`, `{form:key}`, `{entry:id}`, `{entry:admin_url}` (`admin.php?page=pegwork-entries&entry=<id>`, the entries screen of PR 7), `{post:edit_url}` (the record written, empty without one), `{confirm_url}` (the confirmation email only), `{admin_email}`, `{site:name}`, `{option:page.field}`. Unknown tags render empty.
- Control characters (CR, LF and the rest) are stripped from subjects and addresses; an address that is not a single valid email is dropped. A body that renders empty is replaced by `{all_fields}` (wp_mail refuses empty bodies).

## Entries table

`{prefix}pegwork_entries`, created and upgraded with `dbDelta` on module boot when option `pegwork_forms_db_version` differs from `PegworkForms\Schema::VERSION` (`1`). Uninstall keeps the table (entries are content); core uninstall removes the version option, so a reinstall re-runs the idempotent `dbDelta`. `modules/forms/uninstall.php` only clears the `pegwork_forms_cleanup` event.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` AUTO_INCREMENT | primary key |
| `form_key` | `varchar(64)` | |
| `status` | `varchar(20)` | `received`, `spam`, `unconfirmed` (waiting for email confirmation), `processing` (claimed by a confirmation), `failed` (stored, but the record could not be written as defined) |
| `email` | `varchar(254)` default `''` | confirm field, else first email item, lowercased; for privacy tools and the confirmation email cap |
| `post_id` | `bigint(20) unsigned` null | record created or updated from the entry (a draft for `failed` confirmations) |
| `user_id` | `bigint(20) unsigned` null | submitter when logged in |
| `field_values` | `longtext` | JSON, item key => sanitized value (multi-select taxonomies: list of slugs) |
| `meta` | `longtext` | JSON: `page_id`, `definition_hash`, `spam` (reasons), `notifications` (results), `record` (update target), `confirm_expires`, `confirm_mail`, `errors` and `failure_notice` (failed) |
| `token_hash` | `char(64)` null | sha256 of the confirmation token; set only when a confirmation email was sent |
| `fingerprint` | `char(64)` | duplicate check |
| `created_at` | `datetime` | UTC |
| `confirmed_at` | `datetime` null | UTC, when the submitter confirmed |

Lifecycle columns are written by `EntryState` (`set()`, atomic `claim()`); reads stay in `Entries`.

Indexes: `form_status_created (form_key, status, created_at)`, `email (email(191))`, `post_id`, `fingerprint`.

## Entries admin

Pegwork > Entries (`admin.php?page=pegwork-entries`, submenu position 21). Every screen and action needs the forms capability (`pegwork_forms_capability`, default `manage_options`); others get a 403. Entries are personal data: widen the filter only for people who should read them.

- List (`WP_List_Table`): views All (every status but spam), Received, Unconfirmed, Failed, Spam (Processing only while an entry is in it), with counts for the current form and search; filter by form (registry forms, then keys only entries still use, shown as "<key> (deleted form)"); search by part of the email; sort by date (newest first); 20 per page. Columns: submitted (links to the entry; row actions View, Delete), email, form, summary (first two text values, shortened), status, linked post (title, edit link when allowed, post status). Empty states: "No entries yet..." and "No entries match these filters.".
- Bulk actions: Mark as spam (received, unconfirmed and failed entries; never `processing`, which a confirmation holds), Not spam (spam view; spam becomes `received`: it was never mailed and has no record), Delete permanently.
- Entry (`&entry=<id>`, the `{entry:admin_url}` link): every value with its label from the current definition (bound items use the field or taxonomy label), then keys the definition no longer has; booleans Yes/No, select labels, lists joined by commas, multi-line text kept; then form, status, email, submitted and confirmed times (site time), linked post, who used "Create post anyway", submitter account, page, notification results, spam reasons, errors. Actions: Create post anyway, Mark as spam or Not spam, Delete permanently.
- Delete (`&delete=<ids>`): always a confirmation screen listing the entries; only its POST deletes. Records created from entries are kept.
- Create post anyway: for an entry without a post, not spam or processing, whose form has a draft type: `target.content_type` of a `create` form, or for an entry-only form the `pegwork_forms_draft_type` filter (SI for Good maps Share a story to `sifg_story`). Needs the type's `create_posts` too. Creates a Draft through `Pegwork\Api\Records::create()`, authored by the admin: for a `create` form with `RecordWriter::draft()` (bound items, `post_content` as paragraph blocks), for an entry-only form from the values whose keys are fields or taxonomies of the type; values the type rejects are left out, so the admin always gets a draft to finish. No notifications are sent and no `form.confirmed` is emitted. The entry is claimed first (`EntryState::claim()` to `processing`), so a second click or the submitter's confirm link at the same moment cannot make a second post; afterwards it is `received`, linked by `post_id`, with `meta.post_created` (`by`, `at`, `from`). For held Submit a project entries this is the path for submitters who lost the confirmation email.
- Every action is one `admin-post.php?action=pegwork_entries` handler (`op` `spam|not_spam|delete|create_post`, `entry[]`, nonce `bulk-pegwork_entries`, the list table's own bulk nonce). The filter and search form is a separate GET form, so filter URLs carry no nonce. Results show as a notice on the next screen.

### CSV export

"Export CSV" (shown once a form is chosen) downloads the chosen form's entries in the current view and search: `admin-post.php?action=pegwork_entries_export&form=<key>&status=<view>&s=<search>` with nonce `pegwork_entries_export`. UTF-8 with a BOM, oldest first. Columns: Entry ID, Submitted (UTC), Status, Email, Post ID, then one per item of the current definition (its label), then any other key found in the exported entries. Values as on the entry screen. Every cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'` (spreadsheet formula injection), headers included.

### Privacy

- Tools > Export Personal Data and Erase Personal Data include "Pegwork form entries" (ID `pegwork-forms`), matched by the entry's `email` column (the confirm field, else the form's first email item, lowercased), 50 per page.
- The exporter lists form, entry ID, submitted, status, confirmed, the published record's URL when there is one, and every value.
- The eraser deletes the entries. Records created from them are reported as retained ("...was kept: edit or delete it yourself..."): they are directory records, and changing them is a person's decision.
- Settings > Privacy gets suggested policy text: what is stored (no IP address), the retention below, records created from submissions, and Turnstile's Cloudflare script.

### Retention

Daily WP-Cron event `pegwork_forms_cleanup` (scheduled on module boot when missing) deletes spam after 30 days and unconfirmed entries after 7 (filter `pegwork_forms_retention_days`, `array( 'spam' => 30, 'unconfirmed' => 7 )`; 0 keeps them), and received and failed entries after their form's `retention_days` when it sets one. Entries of deleted forms keep only the status limits. `processing` entries are never deleted by age. The admin delete, the eraser and the cleanup all go through `Entries::delete()`, where upload removal (PR 4) belongs.

## Events

`form.submitted` after a non-spam entry is stored, and `form.confirmed` after a confirmation wrote the record (`docs/events.md`), same payload:

```php
array(
	'form'      => 'submit_project',
	'entry_id'  => 12,
	'record_id' => 345, // null for entry-only forms, and on form.submitted of a confirm form
	'values'    => array( 'name' => 'River Watch', 'sifg_impact' => array( 'sdg-06', 'sdg-14' ), 'contact_email' => 'ada@example.org', '...' => '...' ),
)
```

## Extension points

| Hook | Purpose |
|---|---|
| `pegwork_forms_capability` | Capability that manages forms, reads and acts on entries, and sees manager notices (default `manage_options`). |
| `pegwork_forms_draft_type` | `( ?string $type, array $form )`: content type "Create post anyway" makes a Draft of; default `target.content_type` of `create` forms, null otherwise. Must be a registry type. |
| `pegwork_forms_retention_days` | `( array $days )`: days to keep `spam` (30) and `unconfirmed` (7) entries; 0 keeps them. |
| `pegwork_forms_spam_check` | `( string[] $reasons, array $form, array $values )`: add reasons to store a submission as spam. |
| `pegwork_forms_rate_limits` | `( array $limits, string $form_key )`. |
| `pegwork_forms_client_ip` | `( string $ip )`. |
| `pegwork_event_form_submitted`, `pegwork_event_form_confirmed` | The events above. |

Seams for the later PRs: `Definition::unsupported()` is the single gate that keeps Turnstile and file forms closed (PR 4 narrows it); `Submission::process()` returns the outcome for each branch and is where Turnstile slots in. "Create post anyway" claims with `EntryState::claim()` and writes with `RecordWriter::draft()`, not `Confirm::complete()`: completing would send `confirmed` notifications (including the submitter's) to an address nobody proved and emit `form.confirmed`. `Entries` holds the reads plus `insert()`, `update_meta()` and `delete()`; `EntryState` writes the lifecycle columns (the admin adds `EntryState::transition()` for bulk spam and not spam). `Notifications::ENTRIES_PAGE` (`pegwork-entries`) is the admin slug `{entry:admin_url}` links to. Uploads (PR 4) add file links to the entry screen and file removal to `Entries::delete()`.

## Testing

- PHPUnit: `tests/Integration/Forms/` (fixture packages `tests/fixtures/valid/forms-runtime`, and `tests/fixtures/forms-records`, which needs `examples/sifg` and so sits outside `valid/`: `fx_project` is `submit_project` without Turnstile). Mail through the WP test mailer (`tests_retrieve_phpmailer_instance()`). Entries admin, export, privacy and cleanup: `Entries*Test.php` on `tests/Support/EntriesTestCase.php`, which adds `tests/fixtures/valid/forms-entries` (types `fe_project`, `fe_story`; forms `fe_project` with a target, `fe_story` entry-only with `retention_days` 30).
- E2E entries admin: `tests/e2e/specs/forms-entries.e2e.js` (keyboard list, filter, views, entry, spam, CSV, delete confirmation, Tools > Export and Erase Personal Data; axe at 1280 and 782 px). Mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-entries.php` adds the admin-only route `pegwork-e2e/v1/entries/seed`.
- E2E, JavaScript off: `tests/e2e/specs/forms-no-js.e2e.js` (contact form) and `forms-confirm.e2e.js` (submit a project, read the link from the mail log, GET page, POST button, thank-you, Pending project with exact values and terms, "already confirmed", tampered link, record rule errors). The test-only mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-forms.php` registers `tests/e2e/packages/forms` (forms `e2e_contact`, with an item keyed `name`, and `e2e_project`, bound to `sifg_project`), logs mail to an option instead of sending it, and adds admin-only routes `pegwork-e2e/v1/mail`, `entries`, `reset` (mail, rate limits, both forms' entries) and `forms-module`. axe cannot inject into a JavaScript-off page, so the specs serve the page's exact HTML at the same URL to a JavaScript-on page and run axe there.
