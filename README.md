# Pegwork

Pegwork Platform for WordPress: an application builder on one schema registry. GPL-2.0-or-later.

Requires PHP 8.2 and WordPress 6.7 or later. Design: `docs/phase-1-design.md`.

## Development

```
composer install
composer lint
composer test
```

`composer test` needs a WordPress test database, emptied on every run. CI installs the suite with `bin/install-wp-tests.sh` and sets `WP_TESTS_DIR`. Locally, without `WP_TESTS_DIR`, the `wp-phpunit` package is used with `tests/wp-tests-config.php`; set `WP_CORE_DIR` (a WordPress core copy) and the `WP_TESTS_DB_*` variables listed there.
