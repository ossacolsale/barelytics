# Contributing

## Development

Barelytics supports PHP 8.2–8.5 with PDO SQLite and JSON enabled. There are no Composer dependencies. Run the dependency-free checks with:

PHP 8.2 is in upstream security-only support through 2026-12-31. The first release on or after 2027-01-01 is scheduled to raise the minimum to PHP 8.3; update the runtime guards and both CI/release matrices in the same change, and keep the upper guard aligned with the tested matrix.

```sh
php tests/run.php
php tests/daily-reach.php
php tests/wordpress.php
php tests/sqlite-concurrency.php
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
php tests/daily-reach.php
php tests/wordpress.php
php tests/sqlite-concurrency.php
bash tests/integration.sh
git diff --check
```

Run the database-backed checks with PDO SQLite enabled, as in the CI workflow. If the local environment lacks PDO SQLite, make a reasonable attempt to provide it. If it remains unavailable, report the skipped checks explicitly and do not treat that run as a full CI verification.

## Privacy and security changes

Contributors must not add new tracking, identifiers, third-party integrations, or data collection without documenting the privacy impact. Changes to storage, retention, authentication, request validation, or public endpoints should include relevant tests and update the security and privacy documentation.

Open an issue for substantial changes, then submit a focused pull request with a clear description and test results. Do not include databases, real analytics data, credentials, or setup tokens.

For shared contract/runtime changes, stage the local UI with
`sh scripts/stage-admin-ui.sh`, run each affected native suite from its package
README and run `python3 -m unittest discover -s scripts -p 'test_*.py' -v`.
Automation rule changes require `python3 scripts/generate-automation.py`; verify
parity with `--check`. The process-concurrency check requires PDO SQLite and
`pcntl`; report its explicit unavailable message if the environment lacks them.

Reach changes also require the real-browser harness: `npm ci --prefix tests/browser`,
then `npx playwright install --with-deps chromium firefox webkit` from that
folder, and `npm test --prefix tests/browser`. `REACH_BROWSERS` can select installed
engines; `REACH_CHROME_EXECUTABLE` selects a local Chrome executable. Report
actual engines and missing dependencies. The harness creates synthetic private
profiles/data under the temporary directory and never uses a personal profile.
No browser/testing dependency is shipped in a runtime package.
