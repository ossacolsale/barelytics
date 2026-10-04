# Contributing

## Development

Barelytics supports PHP 8.2–8.5 with PDO SQLite and JSON enabled. There are no Composer dependencies. Run the dependency-free checks with:

```sh
php tests/run.php
bash tests/integration.sh
find public/barelytics -name '*.php' -print0 | xargs -0 -n1 php -l
```

Keep PHP readable, use prepared SQL statements, and escape dynamic HTML. Include a regression test for each bug fix when practical.

Follow the repository-wide requirements in [AGENTS.md](AGENTS.md): code changes require relevant regression coverage and the final checks below; review applicable GitHub Actions workflows and update related documentation and `CHANGELOG.md` when behavior changes.

## Required pre-review checks

Before handing off every code revision, inspect the complete diff and run the same checks used by CI:

```sh
find public/barelytics -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
bash tests/integration.sh
git diff --check
```

Run the database-backed checks with PDO SQLite enabled, as in the CI workflow. If the local environment lacks PDO SQLite, make a reasonable attempt to provide it. If it remains unavailable, report the skipped checks explicitly and do not treat that run as a full CI verification.

## Privacy and security changes

Contributors must not add new tracking, identifiers, third-party integrations, or data collection without documenting the privacy impact. Changes to storage, retention, authentication, request validation, or public endpoints should include relevant tests and update the security and privacy documentation.

Open an issue for substantial changes, then submit a focused pull request with a clear description and test results. Do not include databases, real analytics data, credentials, or setup tokens.
