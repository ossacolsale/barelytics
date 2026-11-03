# FAQ

## What is Barelytics?

Barelytics is self-hosted, first-party software that stores aggregate page views in a local SQLite database. It is not a hosted analytics service.

## Does it identify unique visitors?

No. Strict Mode has no visitor IDs, sessions, cookies, fingerprints, or IP storage. A page-view count is a count of requests, not a count of people. Optional Daily Reach was evaluated and is unavailable because its measurement gate was not met; see the [ADR](decisions/daily-reach.md).

## What does it store?

By default: UTC day, normalized page path, and aggregate count. Optional country code, referrer hostname, browser, device, and OS aggregates require separate opt-in. Full URL query strings, full referrer URLs, and full User-Agent values are not stored.

## Does it guarantee legal compliance?

No. Requirements depend on deployment and jurisdiction. Barelytics provides technical data minimization and audit information, not legal advice or a compliance guarantee.

## Can I run it on shared hosting?

The PHP reference package supports FTP/SFTP deployment when PHP PDO SQLite is enabled and a private writable data directory is available. It does not need SSH, Composer, npm, or mandatory cron. See [FTP installation](../INSTALL.md).

## Can I run it on serverless or the edge?

Only if the chosen runtime has a normal SQLite implementation and persistent local storage. Ephemeral local filesystems and edge runtimes are not supported as durable stores.

## Can middleware double-count pages?

Yes, if combined with another integration. Use exactly one method for a page response and exclude APIs, static files, health checks, feeds, and background tasks.

## How does bot filtering work?

It uses an ordered finite rule set with token boundaries, plus administrator-added literal substring patterns. Search crawlers, declared AI agents, social previews, monitoring, other and uncertain automation contribute to separate daily aggregates. No identity is verified. Unknown clients remain eligible for ordinary views. JavaScript cannot observe clients that never execute it; native integrations observe only eligible requests reaching their pipeline.
## Does every runtime include an admin dashboard?

Yes. PHP, Node.js, Python, .NET, Java, Ruby, and WordPress have native administration adapters over the shared Barelytics UI. Embedded adapters reuse the host application's authorization and CSRF system; only the FTP/shared-hosting PHP package maintains a standalone Barelytics password and session.
