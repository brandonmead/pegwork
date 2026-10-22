=== Pegwork Platform for WordPress ===
Contributors: brandonmead
Tags: custom post types, custom fields, taxonomies, application builder
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Application builder for WordPress: content types, fields and taxonomies defined in versioned JSON, on one schema registry.

== Description ==

Pegwork builds applications on WordPress from a schema registry kept in JSON files under version control. Post types, fields and taxonomies use native WordPress storage, and fields are edited in the block editor.

This is an early development release.

== Installation ==

1. Upload the `pegwork` folder to `/wp-content/plugins/`.
2. Activate Pegwork from the Plugins screen.

== Frequently Asked Questions ==

= Automations run late or not at all. Why? =

WP-Cron only runs when someone visits the site. On a production site set `define( 'DISABLE_WP_CRON', true );` in wp-config.php and add a server cron that runs `wp cron event run --due-now` every 5 minutes. Tools > Site Health shows whether Pegwork's automations are on time (see docs/automation.md, Production scheduling).

== Changelog ==

= 0.2.0 =
* Registry sections for listings, options pages, forms and automations; modules Listings, Filters, Forms and Automation; public API 0.2.0.

= 0.1.0 =
* Initial scaffold: plugin bootstrap and PHP and WordPress version guard.
