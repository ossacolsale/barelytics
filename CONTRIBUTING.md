# Contributing

## Development

Barelytics requires PHP 8.1 or newer with PDO SQLite and JSON enabled. There are no Composer dependencies. Run the dependency-free checks with:

```sh
php tests/run.php
bash tests/integration.sh
find public/barelytics -name '*.php' -print0 | xargs -0 -n1 php -l
```

Keep PHP readable, use prepared SQL statements, and escape dynamic HTML. Include a regression test for each bug fix when practical.

## Privacy and security changes

Contributors must not add new tracking, identifiers, third-party integrations, or data collection without documenting the privacy impact. Changes to storage, retention, authentication, request validation, or public endpoints should include relevant tests and update the security and privacy documentation.

Open an issue for substantial changes, then submit a focused pull request with a clear description and test results. Do not include databases, real analytics data, credentials, or setup tokens.
