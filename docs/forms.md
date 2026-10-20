# Pegwork forms

Module `forms` (`modules/forms`, namespace `PegworkForms\`): places registry forms on pages, handles submissions without JavaScript, stores entries, creates or updates records of a content type, holds submissions for email confirmation, sends notifications and emits `form.submitted` and `form.confirmed`. Design: `docs/phase-3-design.md`. Definitions: `docs/registry-reference.md#forms`.

Status (Phase 3 PRs 3, 4, 6 and 7): entry-only forms, record mapping (`target`, bound items), email confirmation (`confirm`), Cloudflare Turnstile and file uploads, the block-editor form builder and Forms list, and the entries admin, CSV export, privacy tools and retention cleanup. The front-end script (PR 5) comes later.

## Enable

Pegwork > Modules > Forms, or `wp pegwork module enable forms`. Disabled, the module's files are never included: the block is unregistered (saved blocks render nothing) and POSTs are ignored. Enabling installs the entries table.

## Place a form

```html
<!-- wp:pegwork/form {"form":"request_help"} /-->
```

In the editor, the Form block (category Pegwork) has a form combobox (type to filter by label) and a server-rendered preview. Placement is by key, so patterns work on every site and for file forms. The Forms list's "Copy block" copies this markup.

Not shown to visitors (a notice for users with the forms capability, nothing for anyone else):

- an unknown key, or no key;
- a form this site cannot run: a Turnstile form without both wp-config keys (see Turnstile), or a `target` type missing from the registry. Such forms fail closed; their POSTs are ignored too.

`access: { capability }` forms ask logged-out visitors to log in and tell users without the capability they lack permission.

## Builder

The registry stays the source of truth; a `pegwork_form` post is only its editing surface (design section 2).

- Post type `pegwork_form`: not public, `show_ui`, `show_in_rest` (`rest_base` `pegwork-forms`), no menu of its own (`edit.php?post_type=pegwork_form` redirects to the Forms list). Every capability maps to the meta capability `pegwork_manage_forms`, which `map_meta_cap` turns into `pegwork_forms_capability` (default `manage_options`), so editors cannot open the builder, the list or the REST route. Saved posts are always `private` (`wp_insert_post_data`); new forms show Draft in the editor until the first save.
- Mapping, 1:1: each `pegwork/form-field` block's attributes are one item (`Builder::FIELD_ATTRIBUTES` order, the keys the registry accepts, `type: file`, `accept`, `max_size_mb` included); each `pegwork/form-step` block's attributes are one step and its inner blocks the step's items; the post title is `label`; everything else (`key`, `target`, `access`, `confirm`, `captcha`, `notifications`, `messages`, `retention_days`) is a JSON object in post meta `_pegwork_form_settings`. Meta `_pegwork_form_key` and `_pegwork_form_hash` link the post to its definition. `Builder::generate()` and `Builder::compile()` are inverses for every valid definition (`FormBuilderRoundTripTest`, byte-identical after `Normalizer::normalize()`).
- Blocks: both are allowed only in `pegwork_form` posts, and those posts allow only them (`allowed_block_types_all`). One variation per field type (Text is the default) and one per field and taxonomy of the target type (bound items); the field block shows an inline label over a disabled preview of the control, with required, help, placeholder, autocomplete, validation, options and show_when in the sidebar. Bound fields show the registry rules read-only ("From Project: Text, required, up to 200 characters"). Missing item and step keys follow the label. On the front end both blocks render nothing.
- Document settings (sidebar, Form tab): Form settings (key, locked after the first save; target type, action and status; access), Email confirmation, Notifications (repeater with merge-tag help), and Messages, spam and privacy (messages, Turnstile toggle, retention). The Turnstile toggle is disabled with a note until `PEGWORK_TURNSTILE_SITE_KEY` and `PEGWORK_TURNSTILE_SECRET_KEY` are both set in `wp-config.php`. "Add all fields from <type>" appends a bound field for each target field and taxonomy not yet in the form.
- Save: `rest_pre_insert_pegwork_form` compiles the post and calls `pegwork_save_form()`. Any problem refuses the save (`WP_Error` `pegwork_invalid_form`, status 400; 409 when the key belongs to a file form) with `data.fields`, messages keyed by JSON pointer (`/steps/0/fields/1/label`). The editor shows the first message in core's save notice and each one at its block or setting. Autosaves are not compiled. Trash calls `pegwork_delete_form()`; restoring saves the form again; a post deleted without the trash deletes its definition too.
- Sync: opening a form (`load-post.php`) regenerates the post when the registry definition's hash differs (a `wp pegwork form import`, a package update, a `pegwork_save_form()` call).
- File-defined forms open read-only: the template is locked, saving is disabled, and a notice offers "Duplicate as site form", which opens a new form prefilled from the definition under a free key `<key>_copy` (nothing is saved until you save). They cannot be trashed.

## Forms list

Pegwork > Forms (`admin.php?page=pegwork-forms`, menu position 20; Entries is 21). Visiting it creates a post for every registry form that has none. Columns: form (Edit, or View for file forms, plus "Duplicate as site form"), key, source ("Site" or "File: <package> (read-only)"), received and unconfirmed counts (one grouped query), Entries link, and the block markup with a "Copy block" button (announced in a polite live region; hidden without JavaScript, the markup stays selectable). "Add form" opens a new form.

## Markup

Server-rendered, works without JavaScript:

- `<form method="post" novalidate>` posting to the same URL, with `enctype="multipart/form-data"` only when the form has a `file` item. Field inputs are named `pegwork[<item key>]` (a bare `name` or `type` is a WordPress query var and would 404 the page). Control fields: `pegwork_form[key]`, `pegwork_form[token]` (signed time token), `pegwork_form[nonce]` (logged-in users only) and the honeypot `pegwork_form[url]`.
- Every control has a `<label>`; select items with `display: radio` are a `fieldset role="radiogroup"` with a `legend`; multi-select taxonomies are a `fieldset` of checkboxes named `pegwork[<key>][]` (or a multiple `select` with `display: select`); steps render as fieldsets with legends, all on one page with one submit button.
- Required items show "(required)" in the label and carry `aria-required="true"`. `autocomplete` and `placeholder` come from the definition.
- A `file` item is `<input type="file" name="pegwork[<key>]" accept=".pdf,.txt">` (its usable extensions, see File uploads) with help text stating them and the size limit ("PDF or TXT, up to 1 MB."). After a re-render with any problem it also says `Choose the file again. "<name>" was not kept.` (linked by `aria-describedby`): browsers cannot refill a file input, and the server keeps no file from a submission it did not store.
- Turnstile forms: a `noscript` notice before the form ("This form needs JavaScript for its spam check...") and the widget at the end of the last step (see Turnstile).
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
6. Collect: each item's value through `pegwork_sanitize_field()` (multi-select taxonomies: each value must be an option); items whose `show_when` is false are dropped. A value given but invalid for its type (bad email, non-http URL, impossible date, unknown option or term, not a number) is a format error; any value over 65,535 characters is too long. Uploads of the visible `file` items are checked (File uploads); a refused file is that item's error. Nothing is moved yet.
7. Spam: honeypot filled (or missing) adds `honeypot`; filter `pegwork_forms_spam_check` may add reasons. Any reason: the entry is stored as `spam` with `meta.spam`, no mail, no event, and the visitor gets the normal success redirect.
8. Validate: unbound items with `pegwork_validate_fields()` (required, `required_when`, `validate`; a required boolean must be checked); bound items with `pegwork_validate_record()` on the target type (its rules, defined once in the registry; an update merges the record's stored values). Errors map to item keys; a record rule on a field the form does not show is listed in the summary without a link. Errors: re-render, nothing stored.
9. Email rate limit (the confirm field, else the first email item).
10. Duplicate: the same fingerprint (sha256 of form key plus sorted values) stored as non-spam within 10 minutes returns the success redirect without a new entry (double clicks, back button).
11. `confirm` forms: the confirmation email cap (below). Over: "Too many submissions" re-render, nothing stored.
12. Turnstile forms: siteverify (see Turnstile). Last of the checks, so a token is spent only on a submission that can be stored, and a double click meets the duplicate check, not a reused-token failure. Failed: stored as `spam` (`captcha_failed`), re-render with a message. Unverified: uploads stored, entry stored as `spam` (`captcha_unverified`), redirect to the held message.
13. Uploads move into private storage. A failure deletes any file already moved: "could not be saved" re-render, nothing stored.
14. Store the entry: `unconfirmed` for `confirm` forms (then the token and email, below), else `received`. With a `target` and no `confirm`, write the record now (below); on failure the entry becomes `failed`, the site admin is emailed, and the visitor sees "Your submission was received, but it could not be processed" (no notifications, no event).
15. Send `submitted` notifications, store their results in `meta.notifications`, emit `form.submitted` (`record_id` set when a record was written).
16. Redirect 303 to the same URL plus `?pegwork_sent=<key>` (POST-redirect-GET), plus `&pegwork_held=1` for an entry held by Turnstile. The URL drops `pegwork_sent`, `pegwork_held`, `pegwork_confirm` and `pegwork_confirmed` first.

## Turnstile

`"captcha": "turnstile"` adds Cloudflare Turnstile to a form (`PegworkForms\Turnstile`). It is free and usually shows no puzzle (WCAG 2.2 3.3.8), but needs JavaScript, and visitors' browsers contact Cloudflare (the privacy policy text says so).

- Keys: only the wp-config constants `PEGWORK_TURNSTILE_SITE_KEY` and `PEGWORK_TURNSTILE_SECRET_KEY`; never registry JSON, options or exports. Without both, Turnstile forms fail closed (not shown to visitors, POSTs ignored, a notice for managers in the block) and users with the forms capability get an admin notice naming the forms and the constants.
- Widget: `<div class="cf-turnstile" data-sitekey="..." data-response-field-name="pegwork_form[captcha]">` at the end of the last step, so the token arrives with the other control fields. Cloudflare's `api.js` is enqueued (footer, `defer`, no version query: Cloudflare serves it from one fixed URL) only where such a form renders. Without JavaScript the form shows the `noscript` notice above it and cannot pass.
- Check: one `wp_safe_remote_post()` to `https://challenges.cloudflare.com/turnstile/v0/siteverify` with the secret and token (no IP sent), timeout 10 s, after the cheaper checks.
  - `success: true`: continue. The token is not stored.
  - No token, or `success: false` for the visitor's token (a bot, an expired or reused token, the always-fail test secret): entry stored as `spam` with reason `captcha_failed`, no mail, no events, no files kept; re-render with values and "The spam check did not pass. Wait for the check to finish, then send the form again."
  - Network error, non-200, unreadable answer, or a site-side error code (`missing-input-secret`, `invalid-input-secret`, `internal-error`): nobody can tell, so the entry (files included) is stored as `spam` with reason `captcha_unverified` for an admin to rescue with "Not spam"; no mail, no events; the visitor sees "Your submission was saved, but our spam check could not be completed. The site team will review it, so there is no need to send it again."
- Honeypot and time-trap spam is stored before siteverify runs, so bots do not spend calls.
- Testing and Local sites: Cloudflare publishes test keys (not secrets): site key `1x00000000000000000000AA` always passes, `2x00000000000000000000AB` always blocks; secret `1x0000000000000000000000000000000AA` always passes, `2x0000000000000000000000000000000AA` always fails. The widget then shows "For testing only". For Submit a project on siforgood.local (siforgood PR 8), add to that site's `wp-config.php`:

  ```php
  define( 'PEGWORK_TURNSTILE_SITE_KEY', '1x00000000000000000000AA' );
  define( 'PEGWORK_TURNSTILE_SECRET_KEY', '1x0000000000000000000000000000000AA' );
  ```

  Production gets real keys from the Cloudflare dashboard (Turnstile, add a site; the free plan is enough), in wp-config only. The four SI for Good contact forms keep Turnstile off (no `captcha`), so they work without JavaScript.

## File uploads

A `file` item (`accept`, optional `max_size_mb`; `docs/registry-reference.md#form-items`) takes one file (`PegworkForms\Uploads`, downloads `PegworkForms\Files`).

- Usable types: the item's `accept` extensions intersected with `get_allowed_mime_types()`, minus a runtime denylist that repeats the registry's (`php*`, `phtml`, `pht`, `phar`, `htm`, `html`, `xhtml`, `shtml`, `svg`, `svgz`, `js`, `mjs`, `exe`) plus `cgi`, `pl`, `py`, `sh`, `asp`, `aspx`, `jsp`, `bat`, `cmd`, `com`, so a definition that slipped past the validator still cannot accept them.
- Checks, in order (message names the item): PHP upload errors (too large: `upload_max_filesize`), empty file, size over `max_size_mb` or `wp_max_upload_size()` (the smaller), no extension or one not usable, a denylisted extension anywhere in the name (`photo.php.png`, after `sanitize_file_name()`), then content sniffing with `wp_check_filetype_and_ext()`: the bytes must be what the extension says ("does not match its file type"; a PNG named `.pdf` or PHP named `.pdf` is refused). Only files PHP received for this request count (`is_uploaded_file()` in `Uploads::from_request()`), so a posted path cannot point at a file already on the server.
- Storage: after every other check passes, `wp_handle_sideload()` (`test_form` off, the item's mimes; the same checks as `wp_handle_upload()`, whose `is_uploaded_file()` test already ran) moves the file to `wp-content/uploads/pegwork-forms/<32 hex random>/<sanitized name>`, outside the media library (attachments get public, guessable year/month URLs). `pegwork-forms/` gets `index.php` and a deny-all `.htaccess`; each random folder gets `index.php`. nginx ignores `.htaccess`, so the random folder name is the real guard.
- Stored value: `{ "path": "pegwork-forms/<hex>/<name>", "name", "size", "mime" }` (`path` relative to the uploads base directory). Spam entries (honeypot, time trap, `captcha_failed`) keep `{ name, size, mime }` only; no file is written. A visible file item without a file is `''`.
- Validation: `required` and `required_when` apply as to text (a file counts as given by its name).
- Download: `admin-post.php?action=pegwork_forms_file&entry=<id>&field=<key>&_wpnonce=<nonce>` (`Files::url()`; nonce action `pegwork_forms_file_<entry>_<key>`), forms capability required, else 403; logged-out requests get admin-post.php's 400 (no `nopriv` handler); a missing file 404. Headers: `Content-Disposition: attachment` (ASCII name plus `filename*` UTF-8), `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, no-cache, `Referrer-Policy: no-referrer`; the stored mime as `Content-Type`. The resolved path must be a file inside `uploads/pegwork-forms/` (`realpath`), whatever the entry says.
- Shown by name: the entry screen links the name to the download (size and type after it); CSV, notifications (`{field:key}`, `{all_fields}`), the exporter and events never carry the path.
- Deletion: `Entries::delete()` (admin delete, retention cleanup, privacy eraser, the E2E reset) deletes the rows, then their files and random folders.

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
| `field_values` | `longtext` | JSON, item key => sanitized value (multi-select taxonomies: list of slugs; files `{path, name, size, mime}`, see File uploads) |
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
- Entry (`&entry=<id>`, the `{entry:admin_url}` link): every value with its label from the current definition (bound items use the field or taxonomy label), then keys the definition no longer has; booleans Yes/No, select labels, lists joined by commas, multi-line text kept, a file as its name linked (underlined) to the private download with size and type, or "(the file was not kept)" for spam; then form, status, email, submitted and confirmed times (site time), linked post, who used "Create post anyway", submitter account, page, notification results, spam reasons, errors. Actions: Create post anyway, Mark as spam or Not spam, Delete permanently.
- Delete (`&delete=<ids>`): always a confirmation screen listing the entries; only its POST deletes. Records created from entries are kept.
- Create post anyway: for an entry without a post, not spam or processing, whose form has a draft type: `target.content_type` of a `create` form, or for an entry-only form the `pegwork_forms_draft_type` filter (SI for Good maps Share a story to `sifg_story`). Needs the type's `create_posts` too. Creates a Draft through `Pegwork\Api\Records::create()`, authored by the admin: for a `create` form with `RecordWriter::draft()` (bound items, `post_content` as paragraph blocks), for an entry-only form from the values whose keys are fields or taxonomies of the type; values the type rejects are left out, so the admin always gets a draft to finish. No notifications are sent and no `form.confirmed` is emitted. The entry is claimed first (`EntryState::claim()` to `processing`), so a second click or the submitter's confirm link at the same moment cannot make a second post; afterwards it is `received`, linked by `post_id`, with `meta.post_created` (`by`, `at`, `from`). For held Submit a project entries this is the path for submitters who lost the confirmation email.
- Every action is one `admin-post.php?action=pegwork_entries` handler (`op` `spam|not_spam|delete|create_post`, `entry[]`, nonce `bulk-pegwork_entries`, the list table's own bulk nonce). The filter and search form is a separate GET form, so filter URLs carry no nonce. Results show as a notice on the next screen.

### CSV export

"Export CSV" (shown once a form is chosen) downloads the chosen form's entries in the current view and search: `admin-post.php?action=pegwork_entries_export&form=<key>&status=<view>&s=<search>` with nonce `pegwork_entries_export`. UTF-8 with a BOM, oldest first. Columns: Entry ID, Submitted (UTC), Status, Email, Post ID, then one per item of the current definition (its label), then any other key found in the exported entries. Values as on the entry screen (files by name only). Every cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'` (spreadsheet formula injection), headers included.

### Privacy

- Tools > Export Personal Data and Erase Personal Data include "Pegwork form entries" (ID `pegwork-forms`), matched by the entry's `email` column (the confirm field, else the form's first email item, lowercased), 50 per page.
- The exporter lists form, entry ID, submitted, status, confirmed, the published record's URL when there is one, and every value; a stored file as "<name> (uploaded file, included in the pegwork-form-files folder of this export)". On `wp_privacy_personal_data_export_file_created`, `Privacy::add_files()` adds the address's files to the export zip as `pegwork-form-files/<entry>-<item>-<name>` (needs `ZipArchive`, which the export itself needs).
- The eraser deletes the entries and their files. Records created from them are reported as retained ("...was kept: edit or delete it yourself..."): they are directory records, and changing them is a person's decision.
- Settings > Privacy gets suggested policy text: what is stored (no IP address), the retention below, uploaded files (private, downloadable only by people who manage forms, deleted with the submission), records created from submissions, and Turnstile's Cloudflare script.

### Retention

Daily WP-Cron event `pegwork_forms_cleanup` (scheduled on module boot when missing) deletes spam after 30 days and unconfirmed entries after 7 (filter `pegwork_forms_retention_days`, `array( 'spam' => 30, 'unconfirmed' => 7 )`; 0 keeps them), and received and failed entries after their form's `retention_days` when it sets one. Entries of deleted forms keep only the status limits. `processing` entries are never deleted by age. The admin delete, the eraser and the cleanup all go through `Entries::delete()`, which also deletes the entries' uploaded files.

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

A file value appears as `{ name, size, mime }`, never its contents or storage path. Spam entries (Turnstile failures and held entries included) emit nothing.

## Extension points

| Hook | Purpose |
|---|---|
| `pegwork_forms_capability` | Capability that manages forms, reads and acts on entries, and sees manager notices (default `manage_options`). The `pegwork_form` post type's meta capability `pegwork_manage_forms` maps to it. |
| `pegwork_forms_draft_type` | `( ?string $type, array $form )`: content type "Create post anyway" makes a Draft of; default `target.content_type` of `create` forms, null otherwise. Must be a registry type. |
| `pegwork_forms_retention_days` | `( array $days )`: days to keep `spam` (30) and `unconfirmed` (7) entries; 0 keeps them. |
| `pegwork_forms_spam_check` | `( string[] $reasons, array $form, array $values )`: add reasons to store a submission as spam. |
| `pegwork_forms_rate_limits` | `( array $limits, string $form_key )`. |
| `pegwork_forms_client_ip` | `( string $ip )`. |
| `pegwork_event_form_submitted`, `pegwork_event_form_confirmed` | The events above. |

Seams for the later PRs: `Definition::unsupported()` is the single fail-closed gate (Turnstile without keys, a missing target type); `Submission::process()` returns the outcome for each branch (including `captcha_failed` and `held`), and its `$files` argument takes uploads from `Uploads::from_request()`. The PR 5 view script should keep the widget's step (the last) visible when it renders, since `.cf-turnstile` renders on page load. "Create post anyway" claims with `EntryState::claim()` and writes with `RecordWriter::draft()`, not `Confirm::complete()`: completing would send `confirmed` notifications (including the submitter's) to an address nobody proved and emit `form.confirmed`. `Entries` holds the reads plus `insert()`, `update_meta()` and `delete()`; `EntryState` writes the lifecycle columns (the admin adds `EntryState::transition()` for bulk spam and not spam). `Notifications::ENTRIES_PAGE` (`pegwork-entries`) is the admin slug `{entry:admin_url}` links to.

## Testing

- Builder: `tests/Integration/Forms/FormBuilderRoundTripTest.php` (generate then compile every valid fixture form, `tests/e2e/packages/*/forms` and `examples/*/forms`; markup shape; key filling; compile errors) and `FormBuilderTest.php` (post type and capabilities, REST save, refused saves with pointers, key lock and collisions, trash and restore, sync, Forms list, duplicate, editor denial). JavaScript units: `assets/src/forms-builder/test/logic.test.js` (`npm run test:unit`). E2E (case 7): `tests/e2e/specs/forms-builder.e2e.js` builds `e2e_built` by keyboard and drag and drop, sees a refused save, saves, places and submits it; Forms list by keyboard with axe at 1280 and 782 px; file form read-only and Duplicate; editor gets 403.
- PHPUnit: `tests/Integration/Forms/` (fixture packages `tests/fixtures/valid/forms-runtime`, and `tests/fixtures/forms-records`, which needs `examples/sifg` and so sits outside `valid/`: `fx_project` is `submit_project` without Turnstile). Mail through the WP test mailer (`tests_retrieve_phpmailer_instance()`). Entries admin, export, privacy and cleanup: `Entries*Test.php` on `tests/Support/EntriesTestCase.php`, which adds `tests/fixtures/valid/forms-entries` (types `fe_project`, `fe_story`; forms `fe_project` with a target, `fe_story` entry-only with `retention_days` 30). Turnstile and uploads: `FormsTurnstileTest`, `FormsTurnstileKeysTest`, `FormsUploadsTest` (forms `fr_turnstile`, two steps with a file item, and `fr_upload`, a required file accepting pdf, png and txt up to 0.01 MB). `FormsTestCase` defines the Turnstile constants with Cloudflare's always-pass test keys for every forms test (a defined constant cannot be undefined, so test order must not matter) and `without_turnstile_keys()` points `Turnstile`'s private constant names at undefined ones for the missing-keys cases; siteverify is mocked with `pre_http_request` (pass, Cloudflare's always-fail answer, network error, HTTP 500, unreadable body, wrong secret). Uploads go to a temporary uploads folder per test (`upload_dir` filter), removed in `tear_down()`.
- E2E Turnstile and uploads: `forms-turnstile.e2e.js` (Cloudflare's real widget and siteverify with the test keys: pass by keyboard, fail, blocking site key, siteverify unreachable, the `noscript` notice, missing keys and the admin notice) and `forms-uploads.e2e.js` (keyboard-only upload, refused file, "Choose the file again", entry screen link, download headers, bad nonce, subscriber 403, logged out 400, 404 after delete). Both need the site and browser to reach `challenges.cloudflare.com` (Turnstile only). Route `pegwork-e2e/v1/turnstile` (`mode`: `pass`, `fail`, `block`, `offline`, `none`) picks the keys the forms mu-plugin defines on the next request; `reset` puts it back to `pass`.
- E2E entries admin: `tests/e2e/specs/forms-entries.e2e.js` (keyboard list, filter, views, entry, spam, CSV, delete confirmation, Tools > Export and Erase Personal Data; axe at 1280 and 782 px). Mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-entries.php` adds the admin-only route `pegwork-e2e/v1/entries/seed`.
- E2E, JavaScript off: `tests/e2e/specs/forms-no-js.e2e.js` (contact form) and `forms-confirm.e2e.js` (submit a project, read the link from the mail log, GET page, POST button, thank-you, Pending project with exact values and terms, "already confirmed", tampered link, record rule errors). The test-only mu-plugin `tests/e2e/mu-plugins/pegwork-e2e-forms.php` registers `tests/e2e/packages/forms` (forms `e2e_contact`, with an item keyed `name`, `e2e_project`, bound to `sifg_project`, `e2e_upload` and `e2e_turnstile`), logs mail to an option instead of sending it, defines the Turnstile test keys per mode, and adds admin-only routes `pegwork-e2e/v1/mail`, `entries`, `reset` (mail, rate limits, the e2e forms' entries and files, Turnstile mode), `turnstile` and `forms-module`. axe cannot inject into a JavaScript-off page, so the specs serve the page's exact HTML at the same URL to a JavaScript-on page and run axe there.
