# Pegwork: Phase 3 Forms Technical Design

Status: draft for owner review, 2026-10-18. Builds on `docs/phase-1-design.md` (approved) and the shared core event bus (`Pegwork\Api\Events`, first Phase 4 PR). Canonical product spec: BMemory `specification/wordpress-platform-plugin.md` (Form builder row; Phase 3 gate "two weeks of real submissions without defects"). Version claims are unverified; confirm in the PR that uses them.

## 0. Key decisions

- Definitions: forms are registry objects (`forms/<key>.json`, optional section in format 1), so files in git stay the source of truth and `wp pegwork schema export|import` carries them unchanged. The block editor is the drag-and-drop builder, editing a `pegwork_form` post whose blocks map 1:1 to the JSON. Pages place a form with `pegwork/form {"form":"<key>"}`.
- Rules defined once: a form field can bind to a content type field or taxonomy and inherits its type, label, rules and conditions from the registry; submissions go through the same `RecordValidator`.
- Storage: one custom table `{prefix}pegwork_entries` (the spec's allowed exception). Created posts are ordinary posts.
- Confirmation: Submit a project holds the entry as `unconfirmed`; the Pending post is created only when the submitter confirms (section 6).
- Spam: honeypot, signed time token, nonce (logged-in only), rate limits, duplicate fingerprint; optional Cloudflare Turnstile per form. No paid or metered API.
- Front end: server-rendered HTML that works without JS (multi-step degrades to one page of fieldsets), enhanced by one small view script. Zero custom REST endpoints, as in Phase 1.
- Module `forms` (switchable). Core gets the registry section and a few public API additions; everything else lives in `modules/forms/` and uses only `Pegwork\Api` and `pegwork_*` functions.

## 1. Traceability

| Element | Breaks without it | Source |
|---|---|---|
| `forms` registry section | Forms not exportable, not in git, no definition validation | Spec (JSON registry), export/import |
| Field binding to registry | Project rules defined twice and drift | Consumer: Submit a project |
| `pegwork_form` post plus blocks | No drag-and-drop builder | Spec capability |
| `pegwork/form` placement block | Theme patterns cannot place forms | Consumer pattern slots |
| Entries table | Contact forms have nowhere to store; personal data would sit in posts and REST | Spec capability, consumer admin |
| `unconfirmed` status and confirm page | Unverified emails reach the review queue | Consumer: email confirmation |
| Notifications with merge tags | Admin never learns of submissions | Consumer: notify admin and submitter |
| Spam layers, Turnstile | Bots fill entries and the Pending queue | Spec, consumer CAPTCHA |
| Steps, `show_when` | Long Project form, Volunteer link condition | Spec capability, Project schema |
| File field | Spec capability (no SI for Good form needs it at launch) | Spec |
| `action: update` | Spec "create or update any content type" | Spec |
| Retention cron, exporter, eraser | Personal data kept forever, no GDPR-style requests | Privacy |
| `form.submitted`, `form.confirmed` | Automation cannot react to forms | Shared contract |

## 2. Form definitions

### Storage options considered

- Block markup inline in pages (a `pegwork/form` block with field inner blocks in the page). Strongest authoring story: build where it shows. Fails on: the definition lives in arbitrary page content, so the submit handler must find and trust it there; any page author could define a form that creates posts of any type (privilege escalation through definition); the same form on two pages is two definitions sharing a key; theme patterns are copied into pages, so pattern fixes never reach live forms; export needs a content scan; no file source, so sifg-config cannot ship forms.
- `pegwork_form` post type only (the `core/navigation` plus `wp_navigation` pattern). One copy, builder for free, capability-gated. Fails on: forms are site content, not git config; needs its own export format and command; no JSON Schema or fixture validation; breaks the Phase 1 rule that files are the truth.
- Registry JSON (chosen), edited through a `pegwork_form` post. Keeps every Phase 1 property (files win, DB source via `pegwork_schema`, canonical export, idempotent import, CI fixtures, one validator) and gets drag-and-drop by treating the post as an editing surface. Cost: a mechanical JSON to blocks generator and blocks to JSON compiler (each block's attributes are the JSON item), proven lossless by a round-trip test.

The post is never a source. Truth is the effective registry: file packages, then `pegwork_schema['forms']`, file wins. Saving in the builder compiles and calls `pegwork_save_form()` (writes the DB source). Opening a form regenerates the post content when the definition hash differs from `_pegwork_form_hash` (covers import and file edits). File-defined forms open read-only with "Duplicate as site form"; the Phase 1 workflow applies: build on Local, export, commit to sifg-config.

### Shape (format 1, additive)

- Top level: `format`, `key` (`^[a-z][a-z0-9_]*$`, max 64, immutable), `label`, `target` (optional: `content_type`, `action` `create|update`, `status` `draft|pending|publish`), `access` (`anyone` default, or `{capability}`), `confirm` (optional: `field`, `subject`, `body`, `expires_hours` default 72), `captcha` (`turnstile` or absent), `fields[]` or `steps[]` (`key`, `label`, `description`, `fields[]`), `notifications[]`, `messages` (`success`, `submit_label`), `retention_days` (optional int).
- Bound item: `{ "bind": { "field": "website" } }` or `{ "bind": { "taxonomy": "sifg_impact" } }`. Its form key is the bound key. Overrides are presentational only: `label`, `help`, `placeholder`, `autocomplete`, `display` (`radio|checkboxes|select`). Type, `required`, `required_when`, `show_when`, `validate`, options come from the registry.
- Unbound item: `key`, `type` (Phase 1 types plus `file`), `label`, `required`, `required_when`, `show_when`, `validate`, `options`, `default`, presentational keys; file adds `accept` (extensions) and `max_size_mb`; a required `boolean` must be checked (consent). Conditions reference form keys and keep the Phase 1 `{field, equals}` shape.
- Notification: `key`, `when` (`submitted|confirmed`), `to` (literal address, `{admin_email}` or `{field:<email field>}`), optional `reply_to`, `subject`, `body` (plain text).

### Validator rules (PHP truth, JSON Schema mirror, `schema: false` for cross-object)

Unique keys across steps; bind targets exist on `target.content_type`; each target field or taxonomy bound at most once; for `create`, every always-required field and required taxonomy of the type is bound (otherwise every submission would fail); conditions reference fields in the same form and, for bound fields, the referenced field is bound too; `publish` or `update` require `access.capability`; `target.content_type` must be a registry type (not core `post`, which has no field definitions); `confirm.field` is a required email field; `{field:x}` in `to` or `reply_to` must be an email field; with `confirm`, no `submitted` notification may go to a `{field:...}` address (the confirm email is the only pre-confirmation mail); `accept` is an allowlist that never includes `php*`, `phar`, `html`, `htm`, `svg`, `js`, `exe`; `{confirm_url}` present in `confirm.body`; unknown keys rejected. New invalid fixtures per rule.

Registry API: `Registry::forms()`, `Registry::form( $key )`, `Registry::form_source( $key )` (`file:<package>` or `db`). Normalizer fills defaults and drops empty objects; Exporter writes `forms/`; Importer upserts by key; `--prune` covers DB forms. Forms are inert data when the module is off, so they always validate and export.

## 3. Builder (block editor)

- Admin menu "Forms" (Pegwork icon): list of registry forms (label, key, source, received and unconfirmed counts, Entries link, shortcut "Copy block"), "Add form". Visiting the list creates any missing `pegwork_form` posts.
- `pegwork_form` post type: `public => false`, `show_ui`, `show_in_rest` (needed by the editor), all caps mapped to `pegwork_manage_forms` (default `manage_options`, filter `pegwork_forms_capability`), status forced to `private` so core REST never serves it anonymously.
- Blocks (category "Pegwork", `parent`/`allowedBlocks` restrict them to `pegwork_form` posts): `pegwork/form-field` (one block; variations per type: Text, Email, Paragraph text, URL, Number, Date, Checkbox, Dropdown, File, plus one variation per field and taxonomy of the chosen target, for example "Project: Website") and `pegwork/form-step` (container of fields). Drag-and-drop, reordering and List View are core editor behavior. Only Pegwork blocks are allowed, so the mapping stays lossless.
- Field block: inline-editable label over a disabled preview control; sidebar for required, help, placeholder, autocomplete, validation, options, `show_when` (pick a field, pick a value). Bound fields show registry rules read-only ("From Project: required, 50 to 300 words").
- Form settings (document panel): key (from title on first save, then locked), target, access, confirmation, notifications (repeater with merge-tag help), messages, Turnstile toggle (disabled with a note when keys are missing), retention. "Add all fields from Project" inserts bound blocks for every field and taxonomy.
- Save: `rest_pre_insert_pegwork_form` compiles content plus settings meta and calls `pegwork_save_form()`; on `WP_Error` the save is refused (400, `pegwork_invalid_form`, `data.fields` by pointer) and messages show as editor notices, as the Phase 1 panel does. Trashing a form calls `pegwork_delete_form()`; its entries stay, listed as "(deleted form)".
- Placement: `pegwork/form` block in any post or page, `form` attribute picked from a combobox; server-rendered in the editor too. Patterns use `<!-- wp:pegwork/form {"form":"submit_project"} /-->`. Placement is by key, not post ID, so it works on every site and for file forms. Module off: block unregistered, renders nothing.

## 4. Front end

- Markup: `<form method="post" novalidate>` posting to the same URL, inputs named `pegwork[<key>]` (bare names such as `name` or `type` collide with WordPress query vars and turn the page into a 404), hidden `pegwork_form` key, signed token, honeypot. Every control has a `<label>`; radio and checkbox groups use `fieldset`/`legend`; required shown as text "(required)" plus `aria-required`; `autocomplete` tokens (WCAG 1.3.5).
- Errors (no JS and JS alike): an error summary at the top (heading "There is a problem", links to each field), focused on load or after submit; each field gets `aria-invalid="true"` and `aria-describedby` pointing at help and error text; the document title gets an "Error: " prefix on the no-JS re-render; values are kept.
- Steps: server renders every step as a fieldset with a legend, so without JS the form is one page with one submit. The view script shows one step at a time, announces "Step 2 of 3: Links" in an `aria-live="polite"` region, moves focus to the step heading, and checks the current step before Next (hints only). If the server returns errors, the form opens on the first step with an error and the summary links switch steps. Turnstile renders on the last step.
- `show_when`: without JS conditional fields show with their condition as help text ("Needed if Needs volunteers is checked"); the server ignores values whose condition is false. With JS they toggle in place, in DOM order, without moving focus.
- View script (`assets/src/forms-view/`, classic script with `wp-i18n` so `wp_set_script_translations` works; script modules have no translation loading in WP 6.7): steps, conditions, live word counter (`aria-live`, debounced, same `countWords()`), submitting state (button disabled, `aria-busy`, "Sending"). Conditions are evaluated by one rules table shared with PHP through a fixture.
- States and messages: success (form replaced by `messages.success` in a focused `role="status"` region, after a POST-redirect-GET to `?pegwork_sent=<key>#pegwork-<key>`); check your email ("We sent a link to `<address>`. It expires in 72 hours."); rate limited ("Too many submissions. Try again in 10 minutes."); old token ("This page was open too long. Check your answers and send again."); CAPTCHA failed; confirm page ("Confirm my submission" button); confirmed; already confirmed; link expired ("Submit the form again"). Spam caught by honeypot or time trap gets the normal success message. Unknown form key: nothing for visitors, a notice for users who can manage forms.
- i18n: all plugin strings in the `pegwork` text domain; form labels are site content (single language).

## 5. Submission pipeline

One service handles a POST on `template_redirect` (same-page handling keeps values for no-JS re-render):

1. Resolve the form by posted key from the registry; check `access`. Target type, action and status come only from the definition; posted `post_type`, `post_status`, unknown fields or meta keys are ignored.
2. Spam checks (section 7). Rate limit or bad token: re-render with a message, store nothing. Honeypot, time trap or CAPTCHA failure: store as `spam` with reasons, no mail, no events.
3. Collect values for fields whose `show_when` holds; sanitize with the Phase 1 per-type sanitizers (`pegwork_sanitize_field()`); taxonomy values must be existing term slugs (public input never creates terms).
4. Validate: unbound fields with `pegwork_validate_fields()`, bound values with `pegwork_validate_record( $type, $values )` (complete rules, as target status is pending). Errors map back to form keys.
5. Duplicate check: fingerprint (sha256 of form key plus canonical values) seen in the last 10 minutes returns the success state without a new entry (double clicks, back button). A per-render token cannot do this because cached pages share it.
6. Store the entry. With `confirm`: status `unconfirmed`, send the confirm email, stop. Without: create or update the post if there is a target, status `received`, send `submitted` notifications.
7. Emit `form.submitted`, then redirect (PRG).

Record write: `wp_insert_post( ..., true )` with `post_status` from the definition and `post_author` 0 for anonymous (current user otherwise); meta via `update_post_meta` on registered keys; terms via `wp_set_object_terms`; a `post_content` field becomes `core/paragraph` blocks (paragraphs split on blank lines, text escaped), inside the type's `allowed_blocks`. A failure after insert deletes the post and marks the entry `failed`. `action: update` reads `?record=<id>`, requires `edit_post` on it and a matching type, and prefills values.

## 6. Email confirmation and notifications

Decision: hold the entry, create the post on confirmation. Flagging an immediately created Pending post as unconfirmed was rejected: reviewer time is the scarce resource and the Pending queue should hold only reachable submitters; junk with fake addresses never becomes a post; the private contact email is proven before any record stores it. Cost: values are re-validated at confirm time (the registry may have changed); if that fails the post is created as `draft` and the entry marked `failed` with an admin notification, so nothing is lost.

- Token: 32 random bytes, base64url, sent as `?pegwork_confirm=<entry id>.<token>` on the page that held the form (page ID recorded at submit, checked to contain the form block, else home). Only its sha256 is stored. Expires after `expires_hours`.
- The link opens a page with a "Confirm my submission" button that POSTs; GET never confirms, because mail security scanners prefetch links.
- Claim is atomic: `UPDATE ... SET status='processing' WHERE id=%d AND status='unconfirmed'`; zero rows means already confirmed. Then create the post, set `received` and `confirmed_at`, send `confirmed` notifications, emit `form.confirmed`.
- Abuse limit: at most 3 confirm emails per address per 24 hours across forms; spam entries never send mail.
- Notifications: `wp_mail` plain text, synchronous (no Action Scheduler before Phase 4). Merge tags: `{field:key}`, `{all_fields}` (label: value, files by name), `{form:label}`, `{entry:id}`, `{entry:admin_url}`, `{post:edit_url}`, `{confirm_url}`, `{admin_email}`, `{site:name}`. CR and LF are stripped from subject, `to` and `reply_to` values; unknown tags render empty. Result per notification (`sent|failed`) stored in entry meta and shown on the entry screen. Post-approval mail and the yearly check belong to Automation.

## 7. Spam protection

- Honeypot: off-screen text input in a wrapper with `aria-hidden="true"`, `tabindex="-1"`, `autocomplete="off"`, label "Leave this empty".
- Time token: hidden `t` = render time plus form key, HMAC with `wp_hash`. Reject under 3 seconds (spam) or over 7 days (old token message). Page caches make the render time stale, so the floor is a weak layer; a token-refresh endpoint is not added, because honeypot, rate limit and Turnstile carry the load.
- Nonce: only for logged-in users. WordPress gives every anonymous visitor the same nonce, and cached pages would serve expired ones, so for anonymous forms it adds failures without protection.
- Rate limit: per form and HMAC of client IP, 5 accepted submissions per 10 minutes (transients). Client IP is `REMOTE_ADDR`; filter `pegwork_forms_client_ip` for trusted proxies (for example Cloudflare's header, only when the origin accepts traffic from Cloudflare alone). No IP is stored in entries.
- Turnstile (optional per form): free, usually no puzzle, which suits WCAG 2.2 3.3.8 (no cognitive test). Tradeoffs: needs JS, so a Turnstile form does not work without JS (it shows "This form needs JavaScript for its spam check" in a `noscript` notice); visitors' browsers contact Cloudflare (privacy policy text added); one free siteverify call per submission. Keys in wp-config constants `PEGWORK_TURNSTILE_SITE_KEY` and `PEGWORK_TURNSTILE_SECRET_KEY`, never in registry JSON or exports. Missing keys: fail closed plus an admin notice. Siteverify network error: entry stored as `spam` with reason `captcha_unverified`, so an admin can rescue it. hCaptcha free tier rejected: more visual puzzles (accessibility), heavier data use. Provider-neutral interface deferred until a second provider is needed.
- Filter `pegwork_forms_spam_check` lets add-ons add a check.

## 8. File uploads

`wp_handle_upload` with `test_form => false` and `mimes` limited to the field's `accept` intersected with `get_allowed_mime_types()`; `wp_check_filetype_and_ext` (content sniffing) must agree; size capped by `max_size_mb` and `wp_max_upload_size()`; double extensions (`x.php.jpg`) and the denylist in section 2 rejected. Stored outside the media library (attachments get public, guessable year/month URLs) under `uploads/pegwork-forms/<32 hex random>/<sanitized name>`, with `index.php` and a deny-all `.htaccess` in `pegwork-forms/` (nginx ignores it, so the random directory is the real guard). Served only through `admin-post.php?action=pegwork_forms_file` with capability and nonce, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`. On a validation error the file is discarded and the field says "Choose the file again". Files are deleted with their entry. One file per field.

## 9. Entries, admin, retention, privacy

```sql
CREATE TABLE {prefix}pegwork_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_key varchar(64) NOT NULL,
  status varchar(20) NOT NULL,             -- unconfirmed, processing, received, spam, failed
  email varchar(254) NOT NULL DEFAULT '',  -- confirm field, else first email field; for privacy tools
  post_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  field_values longtext NOT NULL,          -- JSON form key => value; files {path, name, size, mime}
  meta longtext NOT NULL,                  -- JSON page_id, spam reasons, notifications, definition hash, errors
  token_hash char(64) DEFAULT NULL,
  fingerprint char(64) NOT NULL,
  created_at datetime NOT NULL,            -- UTC
  confirmed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY form_status_created (form_key,status,created_at),
  KEY email (email(191)),
  KEY post_id (post_id),
  KEY fingerprint (fingerprint)
)
```

`dbDelta` on module boot when `pegwork_forms_db_version` differs. JSON columns, not one row per value: nothing queries by field value, and CSV export decodes per entry. Uninstall keeps the table (Phase 1 rule: never delete content).

- Admin (capability `pegwork_manage_forms`): Forms > Entries `WP_List_Table` filtered by form, views Received, Unconfirmed, Spam, Failed; columns date, email, summary, linked post with status. Bulk and row actions: mark spam, not spam, delete (confirm dialog), and for unconfirmed entries "Create post anyway" (same confirm code path, for submitters who lost the email). Entry screen: labeled, escaped values, file links, timestamps, post link, notification results, spam reasons. All actions are `admin-post.php` handlers with nonce and capability checks.
- CSV export: per form and current view, streamed `fputcsv`, UTF-8 BOM, columns from the current definition plus any extra keys found in entries; cells starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'` (formula injection).
- Retention: daily WP-Cron `pegwork_forms_cleanup` deletes unconfirmed after 7 days, spam after 30, received and failed after the form's `retention_days` when set, with their files.
- Privacy: `wp_privacy_personal_data_exporters` and `_erasers` by the `email` column. The eraser deletes entries and files and reports posts created from them as retained (they are directory records; editing them is a person's call). `wp_add_privacy_policy_content` describes entries, retention and Turnstile.

## 10. Events and API additions

- `Events::emit( 'form.submitted', [ 'form' => key, 'entry_id' => id, 'post_id' => int|null, 'values' => values ] )` after a non-spam entry is stored (post_id null when confirmation is pending); `form.confirmed` with the same payload after confirmation creates the post. Files appear as `{name, size, mime}`, never contents or paths. Requires the Events PR merged first.
- Public API (`Pegwork\Api` 0.2.0, additive): `Registry::forms|form|form_source`; `pegwork_sanitize_field( array $field, mixed $value )`; `pegwork_validate_fields( array $fields, array $values, bool $complete = true )` (RecordValidator refactored to share the field checks); `pegwork_save_form( array $definition ): true|WP_Error` (validates against the full registry, upserts the DB source, refuses file-defined keys); `pegwork_delete_form( string $key )`. Filters: `pegwork_forms_capability`, `pegwork_forms_client_ip`, `pegwork_forms_spam_check`.

## 11. Testing

- PHPUnit unit: definition validator per rule (both validators via fixtures), JSON to blocks to JSON byte-identical for every valid fixture, merge tags and header stripping, CSV escaping, time token, fingerprint, condition table (`tests/fixtures/forms/conditions.json`, also read by Vitest).
- PHPUnit integration: contact submit stores entry and mails (captured with `pre_wp_mail`); Project submit creates `unconfirmed` entry and no post; confirm creates a Pending `sifg_project` with exact meta and terms; second confirm and concurrent claim; expired token; posted `post_type`, `post_status`, extra meta and new term slugs ignored; anonymous REST GET of the project lacks `contact_email` and of `pegwork_form` is denied; update action needs `edit_post`; upload rejects `.php`, `.phtml`, `.php.jpg`, SVG, HTML, mime mismatch, oversize; file download needs capability; spam paths send no mail and emit no events; events payloads; exporter and eraser; cleanup with a fixed clock; every admin action rejects missing nonce and subscriber or editor roles; export/import gate extended with forms.
- JS unit (Vitest): conditions parity, step navigation and focus, word counter, config parsing.
- E2E: Playwright (`wp-scripts test-playwright`) with `@axe-core/playwright`, against `wp-env` in CI and siforgood.local locally (`WP_BASE_URL`); a test-only mu-plugin logs mail; Cloudflare's documented Turnstile test keys (always pass, always fail). Scenarios: (1) contact form with JS disabled: errors, summary focus, success; (2) Submit a project keyboard-only through 3 steps, conditional Volunteer link, word counter, Turnstile pass, confirm email, confirm page POST, Pending post visible to admin; (3) same form with JS disabled shows the JS-needed message (Turnstile on) and, with Turnstile off, submits as one page; (4) Turnstile fail; (5) rate limit; (6) honeypot filled shows success and lands in Spam; (7) build a contact form by dragging blocks, place it on a page, submit; (8) admin list, view, mark spam, CSV, delete; (9) axe clean on every state; 200% zoom and 320 px width (reflow) checks.
- Soak checklist (the real two-week window starts at launch, not in this build): daily for 14 days, (a) every `received` Submit a project entry has its post and every Pending post has an entry; (b) unconfirmed older than 72 hours expire and confirm emails arrived (ask one real submitter); (c) Spam view reviewed for false positives; (d) admin notifications arrived for every entry; (e) PHP error log has no Pegwork warnings; (f) defects logged with date. Gate passes after 14 consecutive days with real submissions and no defect; a defect that loses or corrupts data, leaks private data, or blocks a keyboard or screen reader user restarts the clock; cosmetic ones do not.

## 12. Security checklist

Definitions from the registry only (never from requests); builder and entries gated by `pegwork_manage_forms`; nonces on every admin action and on logged-in submissions; server-side sanitize and validate always (client checks are hints); public input never creates terms or chooses type, status or author; prefixed input names; mail header stripping; rate limits per IP and per address; uploads per section 8; CSV formula escaping; output escaped (`esc_html`, `esc_attr`, `esc_url`); `$wpdb->prepare` on every query; tokens stored hashed and compared with `hash_equals`; Turnstile secret in wp-config only; private `pegwork_form` posts. Third-party review before 1.0 (spec).

## 13. Non-goals, open questions, PRs

Non-goals: payments; save and continue or partial entries; condition groups (and/or), calculations, scoring quizzes (Nonprofits AI readiness assessment); repeaters, multi-file, image fields into the media library; editing entries; entry notes or stars; SMTP setup; REST endpoints for forms or entries; webhooks and post-approval mail (Phase 4); Divi or shortcode rendering; multilingual forms; reCAPTCHA or hCaptcha; user registration forms; deleting entries on uninstall.

Open questions for the owner (recommended answers):
1. Admin notification address: a shared role inbox written as a literal in sifg-config JSON (it ships in git, so not a personal address). Recommend yes.
2. Who reads entries: keep `manage_options` (entries are personal data); widen with the filter only if a non-admin reviewer joins.
3. Retention: 365 days for all five forms (the post keeps Project data), spam 30, unconfirmed 7.
4. Consent on Submit a project: add a required unbound checkbox "I have permission to share this project and agree it can be listed publicly", stored in the entry. Recommend yes.
5. Share a story creates a post: no at launch, entry only; add a `sifg_story` type later if stories get published regularly.
6. Turnstile on the four contact forms: off at launch so they work without JS; turn on per form if the soak shows spam.
7. Mail delivery: configure SMTP on the host (or a free transactional tier) before launch; the soak depends on it.

Decisions (2026-10-18, main session under Brandon's build goal; Brandon reviews at the end):
1. Notification recipients may be a literal address or a merge tag resolving an options page field (`{option:sifg_settings.moderator_email}`, single braces like the other form merge tags; content builder design), falling back to the site admin email. SI for Good ships the merge tag, so no personal address lands in git. The shared inbox itself is on Brandon's review list.
2. `manage_options`: yes. 3. Retention 365/30/7: yes. 4. Consent checkbox: yes. 6. Turnstile off on the contact forms: yes; on Local, use Cloudflare's published test keys for the Submit a project form. 7. SMTP is a launch item on Brandon's review list; on Local, Local's Mailpit catches mail for testing.
5. Share a story: entry only, plus the entries screen's "Create post anyway" targeting `sifg_story` (the type the content builder design adds), as a Draft.
8. API version stays 0.2.0 for every addition in this build cycle. The `forms` registry section is built in one shared registry PR together with `listing`, `automations`, and `options_pages`, so PR 1 here reduces to the public form API functions (moved into PR 2). E2E tooling (Playwright, axe, wp-env) comes from one shared harness PR, so PR 2 does not set it up.

PRs (each merges with CI green; PR 1 to 7 in pegwork, PR 8 in siforgood; Events PR from Phase 4 merges before PR 2):
1. Registry `forms` section: JSON Schema, Validator, Normalizer, Exporter, Importer, fixtures, `Registry` methods, public API functions, Api 0.2.0. Accept: valid fixture with two forms passes both validators; one invalid fixture per section 2 rule fails with the expected pointer; gate test round-trips forms byte-identical and second import reports 0 changes; `pegwork_save_form` refuses a file-defined key.
2. Module runtime without JS: manifest entry, entries table, `pegwork/form` block, renderer (single-step, all types but file), pipeline for entry-only forms, honeypot, time token, nonce, rate limit, fingerprint, notifications, `form.submitted`, PRG; Playwright, wp-env and axe in CI. Accept: E2E 1 passes with JS disabled; a field keyed `name` does not 404; axe clean; module disabled renders nothing and handles nothing.
3. Record mapping and confirmation: binding, create and update, paragraph conversion, confirm token, POST confirm page, atomic claim, re-validation, `failed`, per-address limit, `form.confirmed`. Accept: section 11 integration cases for Project submit, confirm and tampering pass; Pending post matches posted values exactly.
4. Turnstile and uploads. Accept: test-key pass and fail; network error stores spam; missing keys fail closed with notice; upload rejection list passes; download needs capability and nonce.
5. Front-end script: steps, conditions, word counter, error summary focus, progress announcement, submitting state. Accept: Vitest parity with the PHP condition fixture; E2E 2, 3, 5, 6 pass; axe clean on each step.
6. Builder: `pegwork_form` post type, field and step blocks with variations, settings panel, compile and generate, save through the API, Forms list, read-only file forms with Duplicate. Accept: E2E 7 passes; round-trip test passes for all valid fixtures; invalid save shows the field message; an editor-role user cannot open the builder.
7. Entries admin and privacy: list, views, entry screen, spam, delete, "Create post anyway", CSV, exporter, eraser, cleanup cron, policy text. Accept: E2E 8 passes; nonce and role tests pass; CSV escaping test passes; Tools > Export and Erase Personal Data find and remove a test entry.
8. siforgood: five forms in `sifg-config/schema/forms/`; patterns get `pegwork/form` blocks (Get involved: Submit a project, Share a story, plus new Volunteer and Sponsor slots; Nonprofits: Request help); soak checklist in the siforgood docs. Accept: `wp pegwork schema validate` passes; full E2E plan passes on siforgood.local; launch prerequisites (Turnstile keys in wp-config, SMTP, admin address) checked off.

## Worked example: `sifg-config/schema/forms/submit_project.json` (abridged)

```json
{
  "format": 1,
  "key": "submit_project",
  "label": "Submit a project",
  "target": { "content_type": "sifg_project", "action": "create", "status": "pending" },
  "confirm": { "field": "contact_email", "subject": "Confirm your SI for Good submission",
    "body": "Confirm \"{field:name}\" within 72 hours:\n{confirm_url}" },
  "captcha": "turnstile",
  "steps": [
    { "key": "project", "label": "Your project", "fields": [
      { "bind": { "field": "name" } },
      { "bind": { "field": "description" }, "help": "50 to 300 words." },
      { "bind": { "field": "who_for" } },
      { "bind": { "taxonomy": "sifg_project_type" }, "display": "radio" },
      { "bind": { "taxonomy": "sifg_project_status" }, "display": "radio" },
      { "bind": { "taxonomy": "sifg_impact" }, "display": "checkboxes" } ] },
    { "key": "links", "label": "Links", "fields": [
      { "bind": { "field": "website" } },
      { "bind": { "field": "github_link" } },
      { "bind": { "field": "needs_volunteers" } },
      { "bind": { "field": "volunteer_link" } } ] },
    { "key": "contact", "label": "Contact", "fields": [
      { "bind": { "field": "contact_email" }, "autocomplete": "email",
        "help": "Private. Used only to confirm and to reach you about this listing." },
      { "key": "consent", "type": "boolean", "required": true,
        "label": "I have permission to share this project and agree it can be listed publicly" } ] }
  ],
  "notifications": [
    { "key": "admin", "when": "confirmed", "to": "{admin_email}", "reply_to": "{field:contact_email}",
      "subject": "Project to review: {field:name}", "body": "{all_fields}\n\nReview: {post:edit_url}" },
    { "key": "submitter", "when": "confirmed", "to": "{field:contact_email}",
      "subject": "Your project is in review", "body": "Thanks. A person will review \"{field:name}\" before it goes live." }
  ],
  "messages": { "success": "Check your email to confirm your submission. The link expires in 72 hours." },
  "retention_days": 365
}
```

`last_verified` is not bound (reviewers and Automation set it). A contact form (`request_help`) has no `target` or `confirm`: unbound `name` (text, required, `autocomplete: name`), `email` (email, required), `organization` (text), `need` (textarea, required, `max_length` 5000), and one `submitted` notification to the admin address with `reply_to` `{field:email}`.
