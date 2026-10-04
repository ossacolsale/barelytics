# Barelytics fact sheet

- **Product:** self-hosted, first-party web page-view analytics.
- **Storage:** local SQLite in the application's data directory.
- **Strict Mode:** daily aggregate counts keyed by normalized path, with optional country key set to `XX` unless country collection is enabled and a trusted code exists.
- **Not collected by default:** IP address, visitor ID, session ID, fingerprint, cookies, full User-Agent, query string, full referrer URL, or third-party analytics events.
- **Optional aggregates:** country, referrer hostname, browser, device, operating system; each is disabled until explicitly enabled.
- **Runtimes:** PHP 8.1–8.5, Node 22/24, Python 3.11–3.14, .NET 8/10, Java 21/25, Ruby 3.3/3.4/4.0; WordPress plugin uses the PHP reference core.
- **Hosting:** PHP package supports FTP/SFTP when PDO SQLite is available. Native runtime ports require persistent SQLite storage.
- **Limits:** page views are not unique visitors; bot filtering is heuristic; no legal-compliance guarantee; not intended as a high-volume warehouse or identity analytics suite.
- **Network:** no remote Barelytics service and no hidden telemetry.
