=== Pegwork ===
Contributors: brandonmead
Tags: custom post types, custom fields, forms, directory, automation
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Application builder for WordPress: content types, fields and taxonomies defined in versioned JSON, on one schema registry.

== Description ==

Pegwork builds applications on WordPress from a schema registry kept in JSON files under version control. Post types, fields, taxonomies and relationships use native WordPress storage, and fields are edited in the block editor.

Optional modules build on the same registry, all with standard blocks:

* Listings: field output on core blocks, a Show if block, and directory patterns on the Query Loop.
* Filters: a filters block for directories that also works without JavaScript.
* Forms: forms built in the block editor, an entries screen, confirmation email, file uploads and optional Cloudflare Turnstile.
* Automation: record-triggered and scheduled automations that send email, create or update records, wait, and call webhooks.

This is an early development release (pre-1.0). The public PHP API may change between minor versions.

== Installation ==

1. Upload the `pegwork` folder to `/wp-content/plugins/`, or upload the release zip from Plugins > Add New Plugin > Upload Plugin.
2. Activate Pegwork from the Plugins screen.
3. Turn on the modules you need under Pegwork > Modules.

== External services ==

Pegwork connects to an outside service only when a site owner sets one up.

**Cloudflare Turnstile** (spam protection for forms). Used only by forms that turn Turnstile on, and only when the site defines the `PEGWORK_TURNSTILE_SITE_KEY` and `PEGWORK_TURNSTILE_SECRET_KEY` constants. On pages with such a form, the visitor's browser loads Cloudflare's widget script from challenges.cloudflare.com, and Cloudflare receives information about the browser and visit. On submit, the site sends the widget's token, the site's secret key and the visitor's IP address to Cloudflare's siteverify endpoint to check it. See Cloudflare's [website terms](https://www.cloudflare.com/website-terms/) and [privacy policy](https://www.cloudflare.com/privacypolicy/).

**Webhooks** (automation). An automation step can send record data to a URL that a site owner enters. Nothing is sent unless an owner builds an automation with that step.

== Uninstall ==

Deleting Pegwork from the Plugins screen removes, on every site of a multisite network, its settings and secrets (every `pegwork_` option), its transients, its scheduled events, the automation run log tables and the rate limit table. Content (posts, fields, terms) is never deleted. Form entries (the `pegwork_entries` table) and files uploaded through forms (`wp-content/uploads/pegwork-forms/`) are also kept on purpose, so submissions are not lost by removing the plugin; delete them under Pegwork > Entries before uninstalling, or remove the table and folder afterwards.

== Frequently Asked Questions ==

= Automations run late or not at all. Why? =

WP-Cron only runs when someone visits the site. On a production site set `define( 'DISABLE_WP_CRON', true );` in wp-config.php and add a server cron that runs `wp cron event run --due-now` every 5 minutes. Tools > Site Health shows whether Pegwork's automations are on time (see docs/automation.md, Production scheduling).

== Changelog ==

= 0.2.0 =
* Registry sections for listings, options pages, forms and automations; modules Listings, Filters, Forms and Automation; public API 0.2.0.
* Security hardening from the October 2026 review: inbound webhook checks hold for any route spelling, failed requests count per client, and each signature is accepted once; rate limits are atomic; private fields cannot be stored in the title or content or decide whether a public field shows; form values bound to private fields stay private in automations; webhook URLs encode values; filtered listings leave out password-protected records; uninstall covers multisite and transients; Turnstile checks hostname and action; Site Health warns when development dependencies are installed.

= 0.1.0 =
* Initial scaffold: plugin bootstrap and PHP and WordPress version guard.
