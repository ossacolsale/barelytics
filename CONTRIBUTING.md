# Contributing

## Development

Barelytics supports PHP 8.2–8.5 with PDO SQLite and JSON enabled. There are no Composer dependencies. Run the dependency-free checks with:

PHP 8.2 is in upstream security-only support through 2026-12-31. The first release on or after 2027-01-01 is scheduled to raise the minimum to PHP 8.3; update the runtime guards and both CI/release matrices in the same change, and keep the upper guard aligned with the tested matrix.

```sh
php tests/run.php
php tests/daily-reach.php
php tests/daily-page-reach.php
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
php tests/daily-page-reach.php
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

## Versions and GitHub releases

Application releases use one version across PHP, Node, Python, .NET, Java, Ruby
and the WordPress plugin. Keep schema/privacy/protocol revisions separate.
Choose a patch for fixes, a minor for compatible features and a major for public
breaking changes. Advance from the latest published version once per release;
additional work for the same pending release reuses that version.

Update PHP `APPLICATION_VERSION`, package metadata in all five native packages,
the WordPress plugin header, Ruby's local-package lock entry and each native
runtime's configuration-history application version. Validate coherence with:

```sh
python3 scripts/validate_release_versions.py v1.2.0
```

Before publication, move the completed Unreleased notes into the target release
section of `CHANGELOG.md`, retaining an empty Unreleased section for future work.
Run the required PHP/native/package checks and the browser suite on Chromium,
Firefox and WebKit. Report environments that could not run. Review the complete
diff and ensure the release commit's GitHub CI is green before creating its tag.

Preparing versions/notes does not publish anything. When the user requests a
GitHub release, commit/push the validated change, then push a new immutable
`vMAJOR.MINOR.PATCH` tag at that exact commit. The tag starts
[Release](.github/workflows/release.yml), which validates versions, tests/builds
the tagged source, runs all three browser engines, verifies archives, and
publishes a GitHub Release with assets and SHA-256 checksums. Monitor its result
and confirm the release tag/assets. This does not deploy a website or publish to
npm/PyPI/NuGet/Maven/RubyGems. Do not overwrite published tags/assets.

For an interrupted release, the manual Release workflow accepts an existing tag
and rebuilds its exact immutable commit. Its tooling comes from the default
branch; use the existing safe-resume logic and report failures instead of
retargeting a release. A normal code task without a publication request should
end with the prepared version and any remaining release step stated clearly.
