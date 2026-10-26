# Pegwork

Pegwork is a WordPress plugin for building small applications on one schema registry. You describe content types, fields, taxonomies, relationships, forms and automations in JSON files kept in version control, and Pegwork registers them with native WordPress storage. Everything a visitor sees is made of standard blocks, so it works with any block theme.

What it does today:

- **Schema registry.** Content types, fields, taxonomies, relationships and options pages from JSON packages, validated on load, with export and import (`wp pegwork schema ...`). Fields are edited in a block editor panel.
- **Listings and filters.** Field values on core blocks through a block bindings source, a Show if block, generated directory and card patterns on the Query Loop, and a Filters block that also works without JavaScript.
- **Forms.** Forms built in the block editor, multi-step forms, file uploads, confirmation email, optional Cloudflare Turnstile, an entries screen, and personal data export and erasure.
- **Automation.** Record-triggered and scheduled automations with email, create and update record, wait and webhook steps, a run log, and signed links.

Modules (Listings, Filters, Forms, Automation) are off until you turn them on under Pegwork > Modules.

## Status

Version 0.4.0. Pre-1.0: the public PHP API (`Pegwork\Api`, `docs/extension-api.md`) and the JSON format may still change between minor versions. Pegwork is not on WordPress.org yet.

## Requirements

- PHP 8.2 or later (CI tests 8.2, 8.4 and 8.5)
- WordPress 6.7 or later (CI tests 6.7 and the latest release)

## Install

**Production sites:** install from a release zip, built with `bin/build-zip.sh` (below). Do not run a production site from a git checkout: a checkout set up for development has dev dependencies in `vendor/` and test tooling in the tree. Release builds exclude dev dependencies.

**From source, for development:**

```sh
git clone https://github.com/brandonmead/pegwork.git
cd pegwork
composer install
npm ci            # npm 11 is required, see CONTRIBUTING.md
npm run build
```

Then link or copy the folder to `wp-content/plugins/pegwork` and activate Pegwork.

**Build a release zip:** `bin/build-zip.sh` (or `npm run zip`) writes `dist/pegwork.zip` from the committed tree, with built assets and production dependencies only.

## Quick start with the example package

`examples/sifg` is a complete package (the SI for Good directory): two content types, three taxonomies, a relationship, an options page, two forms and two automations. On a development install:

1. Register the package with a small must-use plugin, `wp-content/mu-plugins/pegwork-example.php`:

   ```php
   <?php
   add_action( 'pegwork_register', static function ( $builder ) {
       $builder->add_package( PEGWORK_DIR . 'examples/sifg' );
   } );
   ```

2. Check it loaded: `wp pegwork schema status` (or Pegwork > Overview). Projects and Stories now appear in the admin menu.
3. Turn on modules: `wp pegwork module enable listings`, then `filters` and `forms` (or Pegwork > Modules).
4. Add a few Projects. A Project's description must be 50 to 300 words before it can be published; drafts are exempt.
5. Create a page and insert "Projects directory" from the block inserter (category Pegwork), then add a Filters block above it. View the page: a card grid of your published Projects that the filters narrow.
6. Insert a Form block on another page and choose "Submit a project" or "Request help". Submissions show under Pegwork > Entries.

Further reading: `docs/registry-reference.md` (the JSON format), `docs/display.md` (listings and filters), `docs/forms.md`, `docs/automation.md`, `docs/relationships.md`, `docs/options-pages.md`, `docs/export-import.md` and `docs/extension-api.md` (building on Pegwork from another plugin).

## Contributing

Bug reports, fixes and docs are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, the checks CI runs, and what a pull request needs. Report security issues privately, as described in [SECURITY.md](SECURITY.md), not in a public issue. This project follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
