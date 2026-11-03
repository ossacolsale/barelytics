# Barelytics fact sheet

- **Product:** self-hosted, first-party web page-view analytics.
- **Storage:** local SQLite in the application's data directory.
- **Strict Mode:** daily aggregate counts keyed by normalized path, with optional country key set to `XX` unless country collection is enabled and a trusted code exists.
- **Not collected by default:** IP address, visitor ID, session ID, fingerprint, cookies, full User-Agent, query string, full referrer URL, or third-party analytics events.
- **Automation:** separate daily safe-path/category/family counters, permitted by Strict contract revision 2; JavaScript visibility is limited to clients executing it.
- **Optional aggregates:** country, referrer hostname, browser, device, operating system; each is disabled until explicitly enabled.
- **Runtimes:** PHP 8.2–8.5, Node 22/24, Python 3.11–3.14, .NET 10, Java 21/25, Ruby 3.3/3.4/4.0; WordPress plugin uses the PHP reference core.
- **PHP lifecycle:** PHP 8.2 is in upstream security-only support through 2026-12-31. Production deployments should move to a PHP branch with upstream security support. Barelytics plans to set PHP 8.3 as its minimum starting 2027-01-01, with the first release on or after that date updating its runtime guard, CI/release matrices, and installation requirements together.
- **Hosting:** PHP package supports FTP/SFTP when PDO SQLite is available. Native runtime ports require persistent SQLite storage.
- **Limits:** page views are not unique visitors; automation classification is heuristic and declared identities are unverified; no legal-compliance guarantee; not intended as a high-volume warehouse or identity analytics suite.
- **Network:** no remote Barelytics service and no hidden telemetry.
