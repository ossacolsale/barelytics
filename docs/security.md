# Security

Barelytics is designed and hardened to minimize attack surface and to treat all external input as hostile, but no software can be guaranteed invulnerable.

## Controls

- Tracker accepts only POST, only JSON or plain text, caps body size, validates same-site origin-form paths, rejects email-like paths, collapses common numeric/UUID record IDs, and uses prepared SQLite statements.
- SQL inputs are bound parameters. Database-derived strings are escaped with `htmlspecialchars` before HTML output.
- Admin state changes use session-bound CSRF tokens; destructive deletion also requires an explicit confirmation checkbox.
- Admin uses PHP password hashing/verification, a one-time setup token, session ID rotation on login, strict/HTTP-only/SameSite cookies, a failed-login delay, and a small per-session attempt limit.
- Admin responses send no-store, nosniff, no-referrer, and a restrictive Content Security Policy.
- Tracker does not persist IP, User-Agent, Referer URL, or request bodies. Errors fail closed without disclosing details to visitors.
- SQLite uses prepared queries, WAL mode when supported, and a one-second busy timeout. Retention cleanup is bounded and runs after a response on PHP-FPM or on authenticated admin requests; cron is optional.
- The preferred database location is outside the document root. Included Apache rules deny database-like files and disable listings as defense in depth.

## Deployment responsibilities

Use HTTPS; store database and setup credentials outside public paths; grant write access only to the runtime data directory; ensure the web server cannot serve PHP source or private files; configure trusted country headers to be overwritten by the trusted edge; protect backups; and validate the host's actual deny rules. `.htaccess` is ignored by some servers and is not a substitute for private data placement.

There are no third-party runtime dependencies. Review PHP/PDO SQLite updates and any host-level dependencies. SQLite permits a single writer at a time; the short busy timeout may drop a count during contention, while the public page remains unaffected. Backups contain aggregate data and should be access-controlled and deleted according to the owner's retention policy.

## Administrator password recovery

For a forgotten admin password, generate a fresh high-entropy token, use `token-hash.html` to create its one-way hash file, and upload it as `reset.token` to the private data directory via FTP/SFTP. Open `install.php` and use the FTP-authorized recovery form to change the existing account password. The recovery file is removed after use and does not reset statistics or create another admin. No URL parameter can reset the password. For a full reinstall instead, back up the data directory and remove the SQLite database plus its WAL/SHM sidecars through FTP before running setup again.

## Reporting

See [SECURITY.md](../SECURITY.md) for private vulnerability reporting and coordinated disclosure guidance.
## Administration authentication

Embedded Node, Python, .NET, Java, and Ruby adapters accept authorization and CSRF callbacks from the host application. Keep these routes behind an administrator role or policy and pass the host's CSRF token through the adapter. The shared UI is same-origin and sends no analytics events. Static UI files contain no credentials; API requests still require authorization. WordPress uses `manage_options` plus a WordPress nonce. Standalone PHP continues to use its own password hash, throttling, secure session cookies, and CSRF token.
