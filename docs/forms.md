# Pegwork forms

Module `forms` (`modules/forms`, namespace `PegworkForms\`): places registry forms on pages, handles submissions without JavaScript, stores entries, sends notifications and emits `form.submitted`. Design: `docs/phase-3-design.md`. Definitions: `docs/registry-reference.md#forms`.

Status (Phase 3 PR 2): entry-only forms. Record mapping and email confirmation (PR 3), Turnstile and uploads (PR 4), the front-end script (PR 5), the builder (PR 6) and the entries admin (PR 7) come later.

## Enable

Pegwork > Modules > Forms, or `wp pegwork module enable forms`. Disabled, the module's files are never included: the block is unregistered (saved blocks render nothing) and POSTs are ignored. Enabling installs the entries table.

## Place a form

```html
<!-- wp:pegwork/form {"form":"request_help"} /-->
```

In the editor, the Form block (category Pegwork) has a form picker and a server-rendered preview. Placement is by key, so patterns work on every site and for file forms.

Not shown to visitors (a notice for users with the forms capability, nothing for anyone else):

- an unknown key, or no key;
- a form this release cannot run: one with `target`, `confirm`, `captcha`, a bound item or a `file` item. Such forms fail closed until the PR that supports them; their POSTs are ignored too.

`access: { capability }` forms ask logged-out visitors to log in and tell users without the capability they lack permission.

## Markup

Server-rendered, works without JavaScript:

- `<form method="post" novalidate>` posting to the same URL. Field inputs are named `pegwork[<item key>]` (a bare `name` or `type` is a WordPress query var and would 404 the page). Control fields: `pegwork_form[key]`, `pegwork_form[token]` (signed time token), `pegwork_form[nonce]` (logged-in users only) and the honeypot `pegwork_form[url]`.
- Every control has a `<label>`; select items with `display: radio` are a `fieldset role="radiogroup"` with a `legend`; steps render as fieldsets with legends, all on one page with one submit button.
- Required items show "(required)" in the label and carry `aria-required="true"`. `autocomplete` and `placeholder` come from the definition.
- `show_when` items are always shown, with the condition as help text ("Only needed if "Needs volunteers" is checked."); the server ignores their values while the condition is false.
- Help text and the error message are linked from the control by `aria-describedby`; invalid controls get `aria-invalid="true"`.
- After errors: an error summary at the top (heading "There is a problem", one link per field, focused with `autofocus`), per-field messages prefixed "Error:" for screen readers, the posted values kept, and the document title prefixed "Error: ".
- Success: the form is replaced by `messages.success` (default "Thank you. Your message has been sent.") in a focused `role="status"` region. URLs carry no `#fragment`, because browsers skip `autofocus` when the URL targets an element.
- Stylesheet `modules/forms/blocks/form/style.css` (loaded only where the block renders): layout, visible focus, error colors. The honeypot is hidden with inline styles so it stays hidden without the stylesheet.

## Submission pipeline

`Submission::handle()` runs on `template_redirect` (priority 1) for POSTs that carry `pegwork_form[key]`:

1. Resolve the form by key from the registry. Unknown or unsupported: ignored. Target, status and fields come only from the definition; any other posted key is ignored.
2. `access` capability. Fail: "You do not have permission" re-render.
3. Logged-in users: nonce `pegwork_form_<key>`. Fail: "This page was open too long" re-render, nothing stored.
4. Time token. Missing, forged, for another form, or over 7 days old: the same "open too long" re-render, nothing stored. Under 3 seconds: spam reason `too_fast`.
5. IP rate limit. Over: "Too many submissions. Try again later." re-render, nothing stored.
6. Collect: each item's value through `pegwork_sanitize_field()`; items whose `show_when` is false are dropped. A value given but invalid for its type (bad email, non-http URL, impossible date, unknown option, not a number) is a format error; any value over 65,535 characters is too long.
7. Spam: honeypot filled (or missing) adds `honeypot`; filter `pegwork_forms_spam_check` may add reasons. Any reason: the entry is stored as `spam` with `meta.spam`, no mail, no event, and the visitor gets the normal success redirect.
8. Validate the collected items with `pegwork_validate_fields()` (required, `required_when`, `validate`; a required boolean must be checked). Errors: re-render, nothing stored.
9. Email rate limit (the confirm field, else the first email item).
10. Duplicate: the same fingerprint (sha256 of form key plus sorted values) stored as non-spam within 10 minutes returns the success redirect without a new entry (double clicks, back button).
11. Store the entry (`received`), send `submitted` notifications, store their results in `meta.notifications`, emit `form.submitted`.
12. Redirect 303 to the same URL plus `?pegwork_sent=<key>` (POST-redirect-GET).

### Rate limits

| Kind | Default | Counts |
|---|---|---|
| `ip` | 5 per form per 10 minutes | every stored entry, spam included (so a bot cannot fill the table) |
| `email` | 3 per form per hour | accepted entries |

Kept in transients `pegwork_forms_rl_<hmac>`, keyed by an HMAC of kind, form and subject (IP or lowercased address). No IP is stored anywhere. Filters: `pegwork_forms_rate_limits` (`array( 'ip' => array( 'max', 'window' ), 'email' => ... ), $form_key`; `max` 0 disables a limit) and `pegwork_forms_client_ip` (default `REMOTE_ADDR`; return a proxy header only when the origin accepts traffic from that proxy alone).

### Time token

`<render time>.<HMAC-SHA256("pegwork_form|<key>|<time>", wp_salt( 'nonce' ))>`. Page caches make the render time older, never newer, so they only weaken the 3-second floor; honeypot and rate limits carry the load.

## Notifications

`notifications[]` with `when: submitted` are sent through `wp_mail`, plain text, synchronously, after the entry is stored. Results per key: `sent`, `failed` or `skipped` (no valid recipient), in `meta.notifications`.

- `to`, `reply_to`: a literal address, `{admin_email}`, `{field:<email item>}` (sent only when the submitter gave a valid address) or `{option:<page>.<field>}`, read through `pegwork_get_option()` when the options pages feature provides it. An unset or invalid option address in `to` falls back to the site admin email.
- Merge tags in `subject` and `body`: `{field:key}` (booleans Yes/No, selects their option label), `{all_fields}` ("Label: value" per collected item, blank line between), `{form:label}`, `{form:key}`, `{entry:id}`, `{entry:admin_url}` (`admin.php?page=pegwork-entries&entry=<id>`, the entries screen of PR 7), `{post:edit_url}` and `{confirm_url}` (empty until PR 3), `{admin_email}`, `{site:name}`, `{option:page.field}`. Unknown tags render empty.
- Control characters (CR, LF and the rest) are stripped from subjects and addresses; an address that is not a single valid email is dropped. A body that renders empty is replaced by `{all_fields}` (wp_mail refuses empty bodies).

## Entries table

`{prefix}pegwork_entries`, created and upgraded with `dbDelta` on module boot when option `pegwork_forms_db_version` differs from `PegworkForms\Schema::VERSION` (`1`). Uninstall keeps the table (entries are content); core uninstall removes the version option, so a reinstall re-runs the idempotent `dbDelta`.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` AUTO_INCREMENT | primary key |
| `form_key` | `varchar(64)` | |
| `status` | `varchar(20)` | `received`, `spam`; PR 3 adds `unconfirmed`, `processing`, `failed` |
| `email` | `varchar(254)` default `''` | confirm field, else first email item, lowercased; for privacy tools |
| `post_id` | `bigint(20) unsigned` null | record created from the entry (PR 3) |
| `user_id` | `bigint(20) unsigned` null | submitter when logged in |
| `field_values` | `longtext` | JSON, item key => sanitized value |
| `meta` | `longtext` | JSON: `page_id`, `definition_hash`, `spam` (reasons), `notifications` (results) |
| `token_hash` | `char(64)` null | confirm token hash (PR 3) |
| `fingerprint` | `char(64)` | duplicate check |
| `created_at` | `datetime` | UTC |
| `confirmed_at` | `datetime` null | PR 3 |

Indexes: `form_status_created (form_key, status, created_at)`, `email (email(191))`, `post_id`, `fingerprint`.

## Event

`form.submitted` after a non-spam entry is stored (`docs/events.md`):

```php
array(
	'form'      => 'request_help',
	'entry_id'  => 12,
	'record_id' => null, // the created record once record mapping lands (PR 3)
	'values'    => array( 'name' => 'Ada', 'email' => 'ada@example.org', 'need' => '...' ),
)
```

## Extension points

| Hook | Purpose |
|---|---|
| `pegwork_forms_capability` | Capability that manages forms and sees manager notices (default `manage_options`). |
| `pegwork_forms_spam_check` | `( string[] $reasons, array $form, array $values )`: add reasons to store a submission as spam. |
| `pegwork_forms_rate_limits` | `( array $limits, string $form_key )`. |
| `pegwork_forms_client_ip` | `( string $ip )`. |
| `pegwork_event_form_submitted` | The event above. |

Seams for the later PRs: `Definition::unsupported()` is the single gate that keeps target, confirm, Turnstile and file forms closed (PR 3 and PR 4 narrow it); `Submission::process()` returns the outcome for each branch and is where record mapping (`post_id`, `record_id`), the confirm hold (`unconfirmed`, `token_hash`) and Turnstile slot in; `Notifications::send( $form, 'confirmed', $context )` already accepts `post_id` and `confirm_url`; `Entries` is the table access the entries admin (PR 7) builds on, and `Notifications::ENTRIES_PAGE` (`pegwork-entries`) is the admin slug `{entry:admin_url}` already links to.

## Testing

- PHPUnit: `tests/Integration/Forms/` (fixture package `tests/fixtures/valid/forms-runtime`). Mail through the WP test mailer (`tests_retrieve_phpmailer_instance()`).
- E2E: `tests/e2e/specs/forms-no-js.e2e.js`, JavaScript off. The test-only mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-forms.php` registers `tests/e2e/packages/forms` (form `e2e_contact`, with an item keyed `name`), logs mail to an option instead of sending it, and adds admin-only routes `pegwork-e2e/v1/mail`, `entries`, `reset` and `forms-module`. axe cannot inject into a JavaScript-off page, so the spec serves the page's exact HTML at the same URL to a JavaScript-on page and runs axe there.
