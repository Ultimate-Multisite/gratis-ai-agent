# Testing

## PHP unit tests without wp-env

`npm run test:php` runs PHPUnit against a shared WordPress core and
`wordpress-tests-lib` checkout instead of starting `wp-env`.

Default shared paths:

- WordPress core: `~/.cache/wordpress-phpunit/wordpress-trunk`
- WordPress tests: `~/.cache/wordpress-phpunit/wordpress-tests-lib-trunk`

Provision the shared files and local test database once:

```bash
npm run test:php:setup
```

Then run the suite from any checkout:

```bash
npm run test:php
```

Useful overrides:

```bash
WP_VERSION=7.0 npm run test:php:setup
WP_VERSION=7.0 npm run test:php

WP_TESTS_DB_NAME=my_plugin_tests \
WP_TESTS_DB_USER=root \
WP_TESTS_DB_PASS='your-password' \
WP_TESTS_DB_HOST=127.0.0.1 \
npm run test:php:setup

WP_PHPUNIT_CACHE_DIR=~/.cache/wp-tests npm run test:php
WP_TESTS_DIR=/path/to/wordpress-tests-lib WP_CORE_DIR=/path/to/wordpress npm run test:php
PHPUNIT_BIN=phpunit npm run test:php
```

`wp-env` is still available for browser and integration testing, but PHP unit
tests should not depend on the `tests-wordpress` container being started.
