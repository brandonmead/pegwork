# Security review, October 2026

Adversarial review of Pegwork at commit `c908502` (plugin 0.2.0), finished 2026-10-22. Code was read in full for every place untrusted input enters or privileged output leaves; suspected issues were then tried on a throwaway WordPress site (`bin/e2e-local-site.sh`, PHP 8.2, WordPress latest, the e2e mu-plugins and packages) with curl and small scripts. No plugin code was changed.

The repository is public, so findings rated medium or higher were listed by ID only until they were fixed. They were fixed in #49 (with the release zip from #41 for SR-03) and are described below.

## Summary

| Severity | Count | IDs |
|---|---|---|
| Critical | 0 | |
| High | 0 | |
| Medium | 3 | SR-01, SR-02, SR-03 |
| Low | 10 | L-01 to L-10 |
| Informational | 11 | I-01 to I-11 |

## Status

| ID | Severity | Finding | Status |
|---|---|---|---|
| SR-01 | Medium | Webhook pre-checks skipped by the letter case of the route | Fixed in #49 |
| SR-02 | Medium | Anyone can lock out a webhook sender and probe which keys exist | Fixed in #49 |
| SR-03 | Medium | A development checkout ships test-only files to the web | Fixed in #41 (release zip) and #49 (Site Health warning) |
| L-01 | Low | Rate limit counters are not atomic | Fixed in #49 |
| L-02 | Low | A captured signed webhook can be replayed | Fixed in #49 (single-use signatures) |
| L-03 | Low | A private field stored in `post_title` or `post_content` is public | Fixed in #49 |
| L-04 | Low | Form values bound to a private field are not private in automations | Fixed in #49 |
| L-05 | Low | Template values not encoded for URLs or confined in recipient lists | Fixed in #49 |
| L-06 | Low | Listing filters act as an oracle on password-protected records | Fixed in #49 |
| L-07 | Low | `show_when` may target a private field | Fixed in #49 |
| L-08 | Low | Uninstall ignores multisite and leaves transients | Fixed in #49 |
| L-09 | Low | A third party can use up a person's email limits | Documented in `docs/forms.md`: the limits protect inboxes from floods, and counting only confirmed entries would let a bot send unlimited confirmation emails |
| L-10 | Low | Turnstile answers not checked for hostname or action | Fixed in #49 |
| I-01 | Info | The time token can be reused for 7 days | Documented: by design, so cached pages keep working |
| I-02 | Info | A made-up confirm link reveals an entry's form | Fixed in #49 |
| I-03 | Info | Uploads on nginx rely on the random folder name | Documented: nginx deny rule in `docs/forms.md` |
| I-04 | Info | Rate limits behind a proxy share one address | Documented: `pegwork_client_ip` filter with a Cloudflare example in `docs/forms.md` |
| I-05 | Info | The duplicate check spans all submitters | Fixed in #49 |
| I-06 | Info | A submitter copy can mail any address | Documented in `docs/forms.md` |
| I-07 | Info | Notifications unescaped if mail is switched to HTML | Fixed in #49 |
| I-08 | Info | `update_record.record` from a webhook payload can name any record | Won't fix here: a declared `content_type` check on the step belongs with the Phase 6 registry changes to steps (`docs/phase-6-design.md`); the sender must hold the automation's secret |
| I-09 | Info | Relationship validation reveals which IDs exist | Won't fix: requiring `read_post` on each linked ID would break anonymous form writes and automation writes, which link records the writer cannot read; titles stay hidden and only contributors can probe |
| I-10 | Info | Privacy tools miss logged-in users' entries without an email item | Fixed in #49 |
| I-11 | Info | CI actions pinned by tag; `http://` in the test installer | Fixed in #41 |

## Medium

### SR-01 Webhook pre-checks skipped by changing the letter case of the route

- Files: `modules/automation/src/Webhooks/Inbound.php` (`key()`, used by the `rest_pre_dispatch` screen).
- Cause: `key()` matched the REST route with a case-sensitive regex. WordPress matches routes case-insensitively (`WP_REST_Server::match_request_to_handler()` uses `@i`), so `/Pegwork/v1/...` or `/.../WEBHOOK` reached the permission callback and the handler, while the screen got no key and let the request through unchecked.
- Skipped: the 30 per minute limit (429), the 64 KB raw body cap (413, which must run before WordPress decodes the JSON in `has_valid_params()`) and the `application/json` check (415). The signature check still ran, so no unauthorized run was created.
- Who: anyone, no secret needed.
- Impact: unbounded unauthenticated requests, each of which made WordPress parse the full JSON body and compute an HMAC over it, with bodies up to PHP `post_max_size`: a CPU and memory denial of service that the 413 and 429 answers exist to prevent. A secret holder could also exceed 30 per minute.
- Proof (throwaway site, after the e2e webhook reset): a 200 KB body to `?rest_route=/pegwork/v1/automations/e2e_hook/webhook` got 413, the same body to `?rest_route=/Pegwork/v1/automations/e2e_hook/webhook` got 401 (past the size gate); `text/plain` to `/Pegwork/v1/automations/e2e_hook/WEBHOOK` got 401, not 415; 35 unsigned POSTs to the mixed-case route got 35 answers of 401 and never 429; a correctly signed POST to it got 202.
- Fix (#49): `key()` matches case-insensitively, and the permission callback, which runs for this handler whatever the spelling, re-runs every pre-check for a request the screen did not mark, so no future route spelling skips them. Regression test `test_inbound_limits_hold_for_any_route_case` (413, 415 and 429 on upper-case namespace and segment) fails on `c908502` and passes now.

### SR-02 Anyone can lock out a webhook sender (and probe which webhook automations exist)

- File: `modules/automation/src/Webhooks/Inbound.php` (the rate count, before any signature check).
- Cause: every request counted against one bucket per automation key, unsigned ones included. Automation keys are not secret: they are in the URL, on the Automations list and in public packages such as `examples/sifg`.
- Who: anyone.
- Impact:
  - Lockout: 31 junk POSTs a minute kept the real sender (n8n or similar) on 429 for as long as the attacker continued. Senders retry a bounded number of times, so events were delayed or lost.
  - Existence oracle: unknown, disabled and non-webhook keys shared one bucket while each live key had its own, so after filling the shared bucket a live key still answered 401 and an unknown one 429, contrary to the promise that the endpoint does not reveal which keys exist.
- Proof: 31 unsigned POSTs to `/pegwork/v1/automations/e2e_hook/webhook` got 30 answers of 401, then 429; a correctly signed POST right after got 429. After 31 POSTs to `/automations/does_not_exist/webhook`, `/automations/another_missing/webhook` got 429 and `/automations/e2e_hook/webhook` got 401.
- Fix (#49): requests that fail the signature count against their client address (`Pegwork\Api\Throttle::client_ip()`: `REMOTE_ADDR`, filter `pegwork_client_ip`), and only signed requests count against the automation's quota. Every key, known or not, now answers alike. The limits use the atomic counter from L-01. Regression test `test_inbound_junk_cannot_lock_out_the_sender_or_reveal_keys` fails on `c908502` (429 for the signed sender) and passes now. Behind a proxy that the server does not resolve to the client address, all clients share one address until `pegwork_client_ip` is set (I-04).

### SR-03 A development checkout ships test-only files to the web

- Cause: there was no release build. Pegwork loads `vendor/autoload.php`, `vendor/` is not in git, and the README said `composer install`, which also installs the development dependencies. Installing Pegwork from a git checkout, or symlinking a development checkout into a site, put those packages (the WordPress PHPUnit test suite among them) under `wp-content/plugins/pegwork/vendor/`, where the web server serves their PHP files directly.
- Impact (depends on deployment): one of the test suite's helper scripts reflects a request parameter into an HTML page without escaping. A logged-in administrator who opened a crafted link would run script on the site's own origin, which can read nonces from wp-admin and act as that administrator. Other development files (`tests/`, `bin/`, `node_modules/`) were also reachable, and a few PHP files there had no direct-access guard.
- Who: anyone who can get an administrator of such a site to open a link.
- Proof: on the throwaway site, which mounts a development checkout, a GET to the helper script with a script tag in the parameter returned it unescaped as `text/html` (HTTP 200).
- Fix: #41 added `bin/build-zip.sh` and the release zip (`composer install --no-dev`, an export allowlist, and a check that fails when a development package or file would ship) and tells production installs to use it. #49 adds defence in depth: a Site Health test (`pegwork_dev_dependencies`, Security) that is critical when development dependencies are installed (`vendor/composer/installed.php`) on a site whose environment type is not `local` or `development`, plus direct-access guards on the e2e mu-plugins and `bin/check-schema-fixtures.php`. A site already installed from a checkout should be reinstalled from the release zip, or run `composer install --no-dev` in the plugin folder.

## Low

### L-01 Rate limit counters are not atomic

- Files: `modules/forms/src/RateLimit.php:120-129` (`hit()`), `modules/automation/src/Links/Handler.php:259-277`, `modules/automation/src/Webhooks/Inbound.php:249-270`.
- Scenario: an anonymous client sends many requests at once. Each counter is `get_transient()`, then `set_transient()`, so parallel requests read the same count and the limit is exceeded by about the number of PHP workers. For forms this lets a bot store several times the 5 entries per IP per form per 10 minutes. The link limiter also writes one transient per client IP for every request carrying `pegwork_link`, valid or not, which without a persistent object cache adds `wp_options` rows (an IPv6 client can vary its address).
- Proof: on a site served with 8 PHP workers, 24 parallel honeypot posts to one form from one IP stored 15 entries (limit 5): 15 answers 303, then 9 answers 200 "Too many submissions".
- Fix: use `wp_cache_add()` plus `wp_cache_incr()` when an object cache is present, else an atomic SQL counter (`INSERT ... ON DUPLICATE KEY UPDATE`) or a short `GET_LOCK`. For links, count per IP only after the token fails its HMAC check.

### L-02 A captured signed webhook can be replayed within the 5 minute window

- Files: `modules/automation/src/Webhooks/Signature.php:43-45` (signs `timestamp.body` only), `modules/automation/src/Webhooks/Inbound.php:189-207`, `modules/automation/src/Triggers.php:206-210`.
- Scenario: someone who sees one signed request (proxy or WAF log, the sender's execution log) resends it within 300 seconds with a new `X-Pegwork-Delivery` or with none. The delivery ID is the only replay guard and it is not signed, so each resend starts a new run (records created, emails sent, outbound calls made).
- Proof: one signed body and timestamp, sent four times: delivery `d1` gave 202 (run 6), `d1` again gave 200 duplicate, `d2` gave 202 (run 8), no header gave 202 twice (runs 9 and 10).
- Fix: sign `timestamp.delivery.body` and require the header, or, when no delivery ID is given, use `sha256(signature)` as the idempotency key (the unique `(automation, idempotency_key)` index makes that atomic; the 300 s window bounds storage). Document the change for senders.

### L-03 A private field stored in `post_title` or `post_content` is public

- File: `src/Registry/Validator.php:478-542` (field checks).
- Scenario: a schema author marks a field `visibility: private` and maps it with `wp.storage: post_title` or `post_content`. The Validator only enforces the protected meta key rule for private meta fields; core columns are returned in REST view context (`title.rendered`, `content.rendered`), feeds, search and the theme. The documented promise (`docs/registry-reference.md`, "private fields are edit-context only in REST and never leave the site") breaks silently.
- Proof: by code path; the combination passes `wp pegwork schema validate`.
- Fix: reject `visibility: private` together with `wp.storage` (new error code), with a fixture.

### L-04 Form values bound to a private field are not treated as private in automations

- Files: `modules/automation/src/Context.php:218-279` (`is_private()`, `path_key()` know only `record.<key>` and `trigger.old.<key>`), `src/Registry/Rules/AutomationRules.php:1152-1164` (`check_form_value()`).
- Scenario: a form item binds a private content type field (for example `contact_email`). A form-triggered automation that uses `{{trigger.values.contact_email}}` shows the value unmasked in the run log summary (admin and CLI), and the same tag in `call_webhook.url` passes validation and sends the value off site, while `{{record.contact_email}}` is rejected with `automation_private_field`. Needs an author mistake; a public submitter supplies the value.
- Fix: resolve `trigger.values.<key>` to the form item's bound field; reject private ones in public contexts in `check_form_value()` and mask them in log mode in `Context`.

### L-05 Template values are not encoded for URLs or confined in recipient lists

- Files: `modules/automation/src/Steps/CallWebhook.php:49`, `modules/automation/src/Steps/SendEmail.php:150-204`.
- Scenario: a `call_webhook.url` such as `https://hooks.example/n?name={{record.name}}` renders raw, so a submitted name can add query parameters or a `#` fragment, and any space makes the URL invalid, which fails the delivery permanently (`invalid_url`, not retried). In `send_email.to`, a text field value (not an email field, whose sanitizer strips commas) is split on `,` and `;` and can expand into several recipients. Both need an author to put submitter-controlled text there.
- Fix: a URL render mode that `rawurlencode()`s each tag value (except a tag that is the whole URL); a registry rule that `to` tags reference email fields only.

### L-06 Listing filters act as an oracle on password-protected records

- File: `modules/listings/src/Constraints.php:136-237` (`to_query_args()`).
- Scenario: bindings and Show if hide every value of a password-protected record, but the listing query does not exclude such records, so `?volunteers=1` against `?volunteers=0` (and the taxonomy filters) shows an anonymous visitor which branch a protected record falls in.
- Fix: add `'has_password' => false` when field or taxonomy constraints apply, or document that filter values of protected records are public.

### L-07 `show_when` may target a private field

- Files: `src/Registry/Validator.php:414-425`, `modules/listings/src/Fields.php:69-77` (`shown()`).
- Scenario: a public field with `show_when: { field: <private>, equals: X }` appears only when the private value equals X, which leaks the private value through bindings, Show if and card markup. Not present in `examples/sifg`.
- Fix: the Validator rejects a public field whose `show_when` or `required_when` targets a private field.

### L-08 Uninstall ignores multisite and leaves transients

- File: `uninstall.php`.
- Scenario: on multisite only the main site is cleaned; other sites keep their `pegwork_*` options (including the link and webhook secrets) and the runs tables. Transients (`_transient_pegwork_*`, `_transient_timeout_pegwork_*`) are left on every site. The forms entries table and `uploads/pegwork-forms/` are kept on purpose (documented). The option prefix match is sound (`esc_like`, prepared).
- Fix: loop over `get_sites()` with `switch_to_blog()`; delete transients by prefix; state in readme.txt what is kept.

### L-09 A third party can use up a person's email limits

- Files: `modules/forms/src/Submission.php:258-276`, `modules/forms/src/Confirm.php:95-97`.
- Scenario: anyone can submit a form with someone else's address. Three accepted entries per form per hour block that address on the form for an hour, and three confirmation emails per 24 hours (across all forms) block that person from every confirm form for a day, while sending them three unwanted emails.
- Fix: accept the trade-off and document it, or count the email limit only for confirmed entries and lower the per-IP limit for confirm forms; consider a per-address cap message that does not reveal the cap to others.

### L-10 Turnstile siteverify answers are not checked for hostname or action

- File: `modules/forms/src/Turnstile.php:161-195` (`verify()`).
- Scenario: Cloudflare binds a token to the site key, so a token from another site key fails, but Cloudflare recommends also checking `hostname` (and `action`, when set) in the siteverify answer; a token solved on another hostname allowed for the same widget, or for another form, is accepted. `remoteip` is not sent. Replays are refused by Cloudflare (`timeout-or-duplicate`), and network or site-side errors hold the entry as spam (`captcha_unverified`): the check fails closed.
- Fix: compare `hostname` with `wp_parse_url( home_url(), PHP_URL_HOST )`, set `data-action="<form key>"` on the widget and compare `action`, and send `remoteip` from `RateLimit::client_ip()`.

## Informational

- I-01 The form time token is a time trap, not a one-time token: a bot can fetch one page and reuse its token for 7 days (one token was reused for 4 accepted submissions in the proofs). Honeypot, rate limits and Turnstile carry the load, as `docs/forms.md` says.
- I-02 `?pegwork_confirm=<entry id>.<any 43 characters>` on any page replaces that page's content with the form of that entry and shows "This link is not valid", which reveals which form an entry ID belongs to and roughly how many entries exist. Consider loading the entry only after the token hash matches, and showing the problem notice without swapping the content.
- I-03 Uploads are stored in `uploads/pegwork-forms/<32 hex>/<name>`. On nginx (including Cloudways, where nginx serves static files before Apache) the deny-all `.htaccess` does not apply, so the 128-bit random directory is the only guard, as documented. Paths are never output (events and `{all_fields}` carry names only). Optional hardening for nginx sites: an nginx `location` rule in the docs, or an optional wp-config constant to store uploads outside the web root.
- I-04 Rate limits key on `REMOTE_ADDR`. Behind Cloudflare or another proxy that the server does not resolve to the client IP, every visitor shares one bucket per form (5 entries per 10 minutes for the whole site), so one visitor can block a form for everyone. Document the `pegwork_forms_client_ip` filter with a Cloudflare example (trust `CF-Connecting-IP` only from Cloudflare ranges). Full IPv6 addresses are used as keys; a /64 prefix would be stricter.
- I-05 The duplicate check (`Entries::fingerprint_seen()`) hashes form key and values only, across all submitters. Two different people who send identical values within 10 minutes (possible on a form without an email or name field) get the success message, but the second entry is dropped. Including the user ID or the IP HMAC in the fingerprint avoids that.
- I-06 A notification with `to: {field:email}` lets anyone send the site's mail, with their own text in `{all_fields}`, to any address (bounded by the rate limits). Mention this in the builder help for submitter copies.
- I-07 Notification and automation emails are plain text. A site that switches `wp_mail_content_type` to `text/html` globally would send submitted values unescaped in form notifications (`Notifications::render()` does not escape). Force `text/plain` for Pegwork mail or escape when the content type is HTML.
- I-08 `update_record.record` as a template (for example `{{trigger.payload.id}}` in a webhook automation) lets the sender pick any Pegwork record of any type (`modules/automation/src/Steps/UpdateRecord.php:31`). Consider requiring a declared `content_type` on the step and checking it.
- I-09 Relationship validation (`src/Content/RecordValidator.php:187-221`) answers differently for a non-existent ID and for another author's draft or private record, so a contributor saving `_rel_<key>` meta can learn which IDs exist and how they link (titles stay hidden). Requiring `read_post` on each linked ID closes it.
- I-10 The privacy exporter and eraser (`modules/forms/src/Privacy.php`) match the `email` column only, so entries from logged-in users on forms without an email field are not found. Also match `user_id` for the requester's account.
- I-11 CI (`.github/workflows/ci.yml`) is sound for a public repository (`pull_request`, not `pull_request_target`; `permissions: contents: read`; no secrets), but actions are pinned by tag (`shivammathur/setup-php@v2`, `actions/*@v4`); pin third-party actions by commit SHA. `bin/install-wp-tests.sh` fetches the version check over `http://`; use `https://`.

## Checked and found sound

So the fixer does not re-review these.

### Anonymous form submission

`modules/forms/src/Submission.php`, `Renderer.php`, `Definition.php`, `RecordWriter.php`, `src/Api/Records.php`.

- The form is resolved from the registry by a key matching `^[a-z][a-z0-9_]{0,63}$`, never from the request. Only items of the definition are read; extra posted keys (`post_status`, `post_type`, meta keys, unbound fields) are ignored. Type and status come from `target`; an update keeps the record's status and needs `edit_post` on render, on submit and again at confirmation. Proof: a post with `post_status=publish`, `post_type=page` and `last_verified` produced a `pending` Project with none of them.
- Terms: only existing terms are offered and accepted (`get_term_by( 'slug' )`); an unknown slug is a validation error, never a new term (proved with `brand-new-term`).
- Values are sanitized per field type; tags are stripped from text and textarea values, `url` fields need http or https (`javascript:` refused), select and multi-choice values must be options.
- Paragraph conversion escapes `<`, `>` and `&` without double encoding, so `<!-- wp:html -->` or `-->` in a submission stays text; anonymous writes also pass core kses. Proof: a description with a `wp:html` block, `<script>`, `-->` and entities was stored as `core/paragraph` blocks with the tags removed and the rest escaped text.
- Re-rendered values, help, labels, errors and notices are escaped (`esc_attr`, `esc_textarea`, `esc_html`); config JSON goes through `esc_attr( wp_json_encode() )`.
- Mail: subjects and addresses lose CR, LF and control characters, addresses must pass `is_email`, and Reply-To is one validated address, so values cannot add headers (a name with `{admin_email}` produced a literal subject). Merge tags are expanded in one `preg_replace_callback` pass, so user values containing `{field:x}` or `{{...}}` are never expanded.
- Logged-in submissions need the form nonce; the time token is an HMAC with `hash_equals`; the honeypot and the 3 second floor store spam without mail or events; spam and captcha failures count toward the IP limit.
- The client IP is `REMOTE_ADDR` only; `X-Forwarded-For`, `X-Real-IP` and `Client-IP` are ignored (proved: varied headers still hit the limit on the fifth entry). The filter is for site owners.
- Redirects use `wp_safe_redirect()` to the site origin plus the request path.

### Email confirmation

`Confirm.php`, `EntryState.php`.

- Token: 32 bytes from `random_bytes()`, base64url; only its sha256 is stored and compared with `hash_equals()`; strict link format; expiry fixed at submit time.
- GET never confirms; the POST claims atomically (`UPDATE ... WHERE status = 'unconfirmed'`); replay shows "Already confirmed" (proved); confirmation pages send no-cache, `noindex` and `Referrer-Policy: no-referrer`. CSRF is moot because the secret token is the credential.
- Confirm and continue links are built from `home_url()` plus the request path, so there is no open redirect.

### File uploads

`Uploads.php`, `Files.php`.

- `accept` is intersected with `get_allowed_mime_types()` and a denylist; every name part is checked, so `shell.php.txt`, `x.phar.pdf`, `x.svg.txt` are refused; names starting with a dot (`.htaccess`, `.user.ini`) are refused; null bytes are stripped and then refused by the part check; content sniffing must agree (`.txt` holding PHP or HTML refused); case is normalized; `../../../evil.txt` was stored as `evil.txt` in its random directory.
- Only files PHP received for this request (`is_uploaded_file()`) are used; stored paths must match `pegwork-forms/<32 hex>/<name>` and resolve under the folder (`realpath`).
- Downloads need the forms capability and a per-entry, per-field nonce, and are sent as attachments with `nosniff` and a `sandbox` CSP; logged-out requests never reach the handler.

### Signed links

`modules/automation/src/Links/`.

- `v1.<payload>.<sig>` at most 512 bytes, HMAC-SHA256 checked with `hash_equals()` before decoding, strict base64url, typed payload fields; the token binds automation, link and record; expiry on the engine clock.
- A tampered record ID gives "This link does not work"; a POST without the page nonce gives 403; the first POST acts and the second shows "Already done" (single use through the unique idempotency index); HEAD never acts. Redirect targets go through `wp_validate_redirect()` and `wp_safe_redirect()`. Pages escape output, use log mode, and send no-referrer, a strict CSP and `noindex`. Link steps are limited to `update_record` and `condition` on the link's own record.

### Webhooks, other than SR-01, SR-02 and L-02

- HMAC-SHA256 over `timestamp.body` with `hash_equals()`; timestamp within 300 s; unknown, disabled and non-webhook automations get the same 401 with an equal-cost throwaway secret; a missing secret fails closed; the payload is never echoed and reaches records only through author-fixed `values`; the decoded payload is capped at 15 KB and stored contexts at 16 KB.
- Outbound `call_webhook` uses `wp_safe_remote_post()` with `redirection => 0` (a 3xx is a failure, so no redirect to a private address); the URL is author configuration with an `http(s)://` rule; only public fields are sent; the secret is stripped from transport errors. DNS rebinding between validation and connect is the usual WordPress gap.
- Secrets (`pegwork_automation_secrets`, `pegwork_link_secret`) are options with autoload off, absent from registry export, REST, options pages (which cannot use the `pegwork_` prefix) and admin screens; only `wp pegwork automation secret <key>` prints one, by design.

### Automation templates and runs

`Templates.php`, `Context.php`, `Executor.php`, `Runs.php`.

- Single pass rendering (no re-expansion); HTML mode escapes each value; subjects collapse to one line; Reply-To must be an email.
- `{{option:...}}` refuses private fields at load; `record.*` private fields are masked in log mode and refused in public contexts.
- No loops or includes in templates; condition depth and size are capped at load; automation-triggered runs stop at depth 3; 100 step attempts per run; schedule sweeps capped by `max_runs`; run retention pruned daily.

### REST, admin and SQL

- The only REST route is the inbound webhook (above); there is no `__return_true` permission callback, no `wp_ajax_*` and no `admin_post_nopriv_*` handler.
- Private meta is registered with `context: edit` and `auth_callback` `edit_post`; anonymous `view`, `embed` and `_fields=meta` requests on a published Project returned no private meta and `context=edit` returned `rest_forbidden_context`; no revisions or autosaves of meta. `pegwork_form` posts map every capability to the forms capability and are forced private (anonymous listing returns `[]`).
- Admin handlers (modules, entries actions and delete confirmation, entries export, file download, automation toggle, run and retry, form duplicate, editor load) check capability, then a nonce bound to the object, unslash before sanitizing, allowlist operations and redirect with `wp_safe_redirect()`.
- Every `$wpdb` query is prepared; interpolated parts are table names from `$wpdb->prefix`, placeholder lists and literal `ASC`/`DESC`; `LIKE` uses `esc_like()`.
- List tables and entry, run log and options screens escape every cell; webhook payloads and run contexts are never rendered; inline JSON comes from `wp_json_encode()`. Submitted markup is stripped before storage, so the entries list showed tags as text.
- Entries CSV prefixes cells starting with `=`, `+`, `-`, `@`, tab or CR with `'` and uses RFC 4180 quoting.
- The privacy exporter and eraser match the confirmed address exactly, export no internal meta and add or delete only confined upload paths.
- Options pages save through core `options.php` with the page's capability and a sanitizer per field type; `show_in_rest` is false.

### Display

`modules/listings/`, `modules/filters/`, bindings.

- URL filter state reads only keys declared in the listing; values must be term slugs, options or `1`; at most 20 values; search capped at 100 characters; `orderby`, `meta_key`, `posts_per_page` and other query vars from the URL are ignored. Private fields are dropped from filter definitions and constraints; Query Loops and archives are forced to `publish`; counts use the same constrained query. No raw SQL filters.
- `pegwork/field` and `pegwork/option` read public fields only, from publicly viewable records without a password; `url` attributes accept http, https and mailto only (`javascript:` and `data:` render empty) and core escapes URL attributes again; text is `esc_html`, rich text `wp_kses_post`. A contributor's own content can only bind values they can already see; Pegwork meta keys start with `_`, so `core/post-meta` cannot bind them.

### Registry import and supply chain

Registry import: CLI only (`wp pegwork schema import`), packages come from PHP; JSON is read with `json_decode` (depth 512, exceptions); no `unserialize`, `eval` or `include` of data; package paths go through `realpath` and a one-level glob; export file names come from keys matching `^[a-z][a-z0-9_]*$`.

Supply chain: no production dependencies (`composer.json` requires only PHP; `package.json` has no `dependencies`). CI uses `pull_request` with read-only permissions and no secrets (see I-11 for pinning).
