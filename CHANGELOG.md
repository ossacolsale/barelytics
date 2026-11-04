# Changelog

All notable changes to Barelytics are documented here.

## Unreleased

### Security

- Reject ambiguous encoded paths and decoded private-route exclusions across all runtimes; align collectors on bounded 8 KiB UTF-8 JSON requests, permitted fields and no-store responses.
- Build WordPress ZIPs from an explicit source manifest, reject linked entries, remove stale archive members, and reject bootstrap/recovery tokens in release validation.
- Store WordPress SQLite data outside the effective document root by default, validate custom paths through real paths and symlinks, and pause tracking when private storage or a protected legacy migration cannot be verified.
- Migrate legacy WordPress databases through a consistent SQLite snapshot while retaining the original, use Apache 2.4 access rules, and document Nginx denial rules.
- Pin third-party PHP and Ruby GitHub Actions to verified upstream commit SHAs and enable weekly Dependabot update proposals.

### Added

- Optional standalone PHP Daily Page Reach, centrally enabled with separate Extended acknowledgement: browser-local bounded expiring SHA-256 day/path claims, combined version-2 Reach transport, aggregate-only schema 5, capped new-page cardinality with partial-data notices, retention/deletion/audit and view/report comparisons. Existing global Reach and v1 requests remain independent.

- Consolidate PHP administration on the shared dashboard, with grouped responsive navigation, shared account/audit/diagnostic styling, contextual page detail, on-demand reports and a small non-JavaScript fallback. Preserve legacy entry points and FTP installation.

- Optional PHP daily browser reach reports, disabled by default: a shared tracker IndexedDB transaction authorizes one daily send attempt, with no identifier, cookie or server browser record. Includes bounded prepare/count endpoints, UTC boundary grace, additive schema 4, settings, audit, retention, deletion and shared dashboard.
- Real-browser reach regression harness for Chrome/Chromium, Firefox and WebKit where available; other runtime adapters explicitly retain reach unavailable.

- Separate daily automation aggregates and an administration dashboard for declared search/AI crawlers, social previews, monitoring, other and uncertain automation, using shared classification rules and tests across runtimes.
- Page-filtered country, referrer, browser, device and OS reports, plus page-detail links and breakdowns.
- Explicit opt-in browser referrer protocol v2 that sends only a minimized hostname; Strict requests remain path-only and ignore the beacon Referer header.

### Fixed

- Keep administration labels, navigation and report controls within the viewport at 400% text size across system fonts.
- Control PHP Daily Reach centrally from administration with the ordinary tracker, retaining per-page `false` exclusion and host/CMP permission checks. Use inspectable keepalive pageviews without retrying ambiguous network failures; fall back to a path-only beacon only when fetch is unavailable.
- Constrain long administration paths, display date tables newest-first, bound PHP pagination, preserve navigation/filter context and unsaved privacy settings, and version local PHP administration assets.
- Distinguish optional IndexedDB source capability from effective Strict behavior in PHP privacy audits while retaining forbidden-storage source checks.

- Use one canonical UTC event day for aggregate writes, serialize shared SQLite connection operations, roll back interrupted writes, preserve existing settings on reopen and refuse future schemas.
- Run bounded automatic retention in PHP without FastCGI, WordPress and native integrations, resuming pending batches on subsequent requests; initialize fresh WordPress storage on activation.
- Distinguish historical Extended aggregates from current collection in privacy audits and reject unapproved native schema tables/columns.

### Privacy

- Explicitly revise the Strict aggregate contract to permit safe-path/day automation category/family counters in additive schema migration 3. No raw User-Agent, IP, browser identifier or analytics cookie is stored; declared identities remain unverified.
- The shared-cookie Daily Reach design remains rejected. The client-gated alternative is explicitly limited to observed daily browser reach reports; IndexedDB access requires an independent ePrivacy assessment and no exact-visitor, lower-bound or universal consent-exemption claim is made.

## 1.1.0

### Added

- PHP deployment guidance for the 2026-10-21 critical runtime advisories, patched builds, verified vendor backports, and PHP 8.2's 2026-12-31 security-support cutoff.
- Default fresh installs and upgrades to Strict privacy mode with optional dimensions disabled.
- Add opt-in country, referrer-host, browser, device, and operating-system aggregates with private-path exclusions.
- Add authenticated privacy audit, runtime self-tests, schema verification, SHA-256 configuration fingerprint, configuration history, and HTML/JSON report exports.
- Add independent browser, database, and server-log verification documentation.
- Official Barelytics brand mark on the README and shared administration pages across supported runtimes.
- Manual, tag-based GitHub Release reruns that rebuild the tag's exact commit, safely resume draft releases, and test release archive validation in CI.
- FTP/SFTP-deployable PHP + SQLite runtime package with browser environment checks and first-admin setup.
- One-time hashed bootstrap and recovery token files, permanent setup lock, and browser password recovery.
- Versioned browser migrations and bounded retention cleanup without a cron requirement.
- Subdirectory-aware JavaScript and generic PHP integrations for static and server-rendered sites.
- PHP/SQLite/Plesk/Nginx diagnostics, FTP backup/upgrade guides, and manual host acceptance matrix.
- Dependency-free regression/integration checks and PHP 8.2–8.5 CI matrix.
- Independent SQLite CRUD and WAL/SHM installer probes with isolated diagnostics and sanitized failure messages.
- Authenticated read-only database diagnostics with an explicit CRUD/WAL probe, plus administrator password changes from the account page.
- Statistics-first admin navigation with period filters, day/week/month grouping, page totals, per-day page detail, per-page trends, and optional dimension breakdowns.
- Shared runtime-native administration UI/API across all supported runtimes, with host authorization and CSRF integration.
- Release validation that checks runtime package and WordPress plugin versions against the requested release tag.
- Language-neutral privacy, storage, protocol, path, bot-filtering, retention, audit, and configuration contracts with shared test vectors.
- Native Node.js, Python, .NET, Java, and Ruby packages with SQLite aggregate stores and runtime contract tests.
- WordPress plugin source and a reproducible ZIP builder that bundles the PHP reference core.
- Framework integration recipes, a runtime support matrix, FAQ, comparison, shared-hosting guide, product facts, use cases, and publication-readiness notes.
- CI matrices for supported Node.js, Python, .NET, Java, and Ruby versions, plus WordPress package assembly.
- Upgraded GitHub Actions runtime dependencies to current Node.js 24-compatible action releases and limited workflow token permissions to repository contents read access.
- Version-tagged GitHub Release pipeline that validates SemVer tags, runs the runtime test matrix, builds distributable packages, and attaches SHA-256 checksums with generated release notes.
- Shared native admin UI and matching host-authenticated JSON adapters for PHP, Node.js, Python, .NET, Java, Ruby, and WordPress, including per-page and per-day aggregation, privacy controls, maintenance, diagnostics, and audit views.

### Fixed

- Keep administrator password changes compatible with PDO SQLite transaction handling on PHP 8.2–8.3.
- Resolve ASP.NET Core admin root and trailing-slash requests through one route to prevent ambiguous endpoint matches.
- Read RubyGems release archives as their outer tar container when validating bundled admin assets.
- Diagnose release archive format errors and refuse to overwrite or remove mismatched release assets during a rerun.
- Drop end-of-life PHP 8.1 and the approaching end-of-support .NET 8 target from supported runtimes.

### Privacy

- No visitor identifiers, analytics cookies, stored IP addresses, or third-party telemetry.
