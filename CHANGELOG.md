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

### Privacy

- No visitor identifiers, analytics cookies, stored IP addresses, or third-party telemetry.
