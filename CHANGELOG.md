# Changelog

## 1.1.0

- Default fresh installs and upgrades to Strict privacy mode with optional dimensions disabled.
- Add opt-in country, referrer-host, browser, device, and operating-system aggregates with private-path exclusions.
- Add authenticated privacy audit, runtime self-tests, schema verification, SHA-256 configuration fingerprint, configuration history, and HTML/JSON report exports.
- Add independent browser, database, and server-log verification documentation.

All notable changes to Barelytics are documented here.

## Unreleased

### Added

- FTP/SFTP-deployable PHP + SQLite runtime package with browser environment checks and first-admin setup.
- One-time hashed bootstrap and recovery token files, permanent setup lock, and browser password recovery.
- Versioned browser migrations and bounded retention cleanup without a cron requirement.
- Subdirectory-aware JavaScript and generic PHP integrations for static and server-rendered sites.
- PHP/SQLite/Plesk/Nginx diagnostics, FTP backup/upgrade guides, and manual host acceptance matrix.
- Dependency-free regression/integration checks and PHP 8.1–8.5 CI matrix.
- Independent SQLite CRUD and WAL/SHM installer probes with isolated diagnostics and sanitized failure messages.
- Authenticated read-only database diagnostics with an explicit CRUD/WAL probe, plus administrator password changes from the account page.
- Statistics-first admin navigation with period filters, day/week/month grouping, page totals, per-day page detail, per-page trends, and optional dimension breakdowns.
- Shared runtime-native administration UI/API across all supported runtimes, with host authorization and CSRF integration.
- Release validation that checks runtime package and WordPress plugin versions against the requested release tag.

### Fixed

- Keep administrator password changes compatible with PDO SQLite transaction handling on PHP 8.1–8.3.
- Resolve ASP.NET Core admin root and trailing-slash requests through one route to prevent ambiguous endpoint matches.

### Privacy

- No visitor identifiers, analytics cookies, stored IP addresses, or third-party telemetry.

### Added

- Language-neutral privacy, storage, protocol, path, bot-filtering, retention, audit, and configuration contracts with shared test vectors.
- Native Node.js, Python, .NET, Java, and Ruby packages with SQLite aggregate stores and runtime contract tests.
- WordPress plugin source and a reproducible ZIP builder that bundles the PHP reference core.
- Framework integration recipes, a runtime support matrix, FAQ, comparison, shared-hosting guide, product facts, use cases, and publication-readiness notes.
- CI matrices for supported Node.js, Python, .NET, Java, and Ruby versions, plus WordPress package assembly.
- Upgraded GitHub Actions runtime dependencies to current Node.js 24-compatible action releases and limited workflow token permissions to repository contents read access.
- Added a version-tagged GitHub Release pipeline that validates SemVer tags, runs the runtime test matrix, builds distributable packages, and attaches SHA-256 checksums with generated release notes.
- Added one shared native admin UI and matching host-authenticated JSON adapters for PHP, Node.js, Python, .NET, Java, Ruby, and WordPress, including per-page and per-day aggregation, privacy controls, maintenance, diagnostics, and audit views.
