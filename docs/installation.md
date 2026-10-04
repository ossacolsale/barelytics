# FTP-only installation

## Requirements

- PHP 8.1 through 8.5
- PDO with the SQLite driver (`pdo_sqlite`)
- JSON and PHP sessions
- HTTPS before production credentials are submitted
- Apache `.htaccess` support for the in-webroot fallback, or writable private storage outside the document root

The installer checks PHP version, PDO, SQLite, data-directory write access, database read/write/delete, WAL/SHM sidecars, sessions, HTTPS, webroot placement, and setup state. It displays a hosting-control-panel action when a required extension is missing. Database diagnostics show a short sanitized error when a check fails; they do not show stack traces, credentials, tokens, or private storage paths.

## Upload and initialize

1. Download the repository archive and extract it on your computer.
2. Upload the contents of `public/barelytics/` to a directory on your site, for example `httpdocs/barelytics/`, using FTP/SFTP or the host's file manager. The package itself is the runtime; no build or dependency installation is needed.
3. Open `https://example.com/barelytics/install.php` (adjust for the directory you chose). For a nested install such as `/tools/barelytics/`, open that path instead. The installer derives the base URL from its own location.
4. Review the diagnostic table. Barelytics targets PHP 8.1–8.5, PDO SQLite, JSON, and sessions. In Plesk, use the domain's PHP selector/extension controls to enable SQLite and sessions, or ask the hosting provider. Reload the page after changing hosting settings.
5. The installer selects a writable private data directory outside the webroot when possible. It checks actual write access instead of assuming a permission number. If the private directory is under the webroot, Apache-compatible hosts receive a `Require all denied` rule. Confirm that the host honors `.htaccess`. Nginx-only hosts are refused for in-webroot storage; select/configure a private directory outside the webroot through hosting controls.
6. Create a high-entropy random token of at least 32 characters in a password manager. Open the package's `token-hash.html`, paste the token, and download `setup-token.php`. This helper hashes the token locally in the browser; it does not send the raw token to a server. Upload the file to the package's `data/` folder through FTP/SFTP. It contains only the SHA-256 hash and returns 404 if requested directly; Apache also denies requests to that folder. If your FTP account can access the private data directory, you may upload the file there renamed as `setup.token` instead.
7. Use HTTPS, reload the installer, enter the original token, and choose a password of at least 12 characters. The installer creates the SQLite schema, runs a database write/read/delete self-test, stores only a PHP password hash, and locks setup. It removes the bootstrap hash file when PHP has permission; the permanent lock invalidates it in all cases. If setup fails before completion, upload the same token file again and retry.
8. Sign in at `/barelytics/admin.php`. The dashboard displays a subdirectory-aware snippet. Add either the JavaScript integration or the PHP integration, once per page, not both.
9. Start with the Statistics overview. Choose a period and group the trend by day, week, or month. Use **All pages** to compare page totals, **Day × page** for daily counts across every page, or open a page's daily trend from its row. The table views are paginated. The Dimensions view reports only dimensions that are enabled in Privacy settings.
10. The admin menu links to integration, privacy, maintenance, and system sections. **Diagnostics** is available under System and requires an authenticated admin session; its normal view is read-only. The manual CRUD/WAL probe runs only after you submit its protected form. Change the admin password under **Account**; the form asks for the current password.

Do not use `chmod 777`. Use the hosting panel's file ownership/permissions controls and the least-permissive directory access that lets PHP create and update the database. WAL mode is enabled when available; a one-second busy timeout is used. If WAL is unsupported, Barelytics continues with SQLite's available journal mode.

## Integration

For HTML, static pages, and sites without a PHP framework, copy the exact snippet shown by the admin dashboard. It will look like:

```html
<script defer src="/barelytics/track.js"></script>
```

The script resolves `track.php` beside itself, so nested paths such as `/tools/barelytics/` work. It uses a same-origin `sendBeacon` request with a fetch keepalive fallback, creates no identifier, and fails silently. The site's Content Security Policy may need its Barelytics path in `script-src` and `connect-src`.

For server-rendered PHP, use the dashboard's path-adjusted example. The basic API is:

```php
require_once __DIR__ . '/barelytics/src/Barelytics.php';
\Barelytics\track();
```

Adapt the include path to the app's location. Call it once per document response. Do not also include the JavaScript tracker for the same request. The PHP integration strips the query string server-side and fails silently if analytics storage is unavailable.

The JavaScript endpoint counts the initial document view on a single-page app. It does not monitor SPA route changes automatically.

## Retention without cron

Cron is optional. At most once per 24 hours, a tracker request runs one bounded cleanup batch after the response when PHP-FPM provides `fastcgi_finish_request()`. On other PHP handlers, an authenticated admin request runs the batch. Each batch removes up to 1,000 expired rows from each aggregate table and stores its last-run time so later requests resume cleanup safely. The admin maintenance button can also run one bounded batch. An optional cron job may call `cleanup.php`, but it is not required for retention or reports.

## Verify

- Open a tracked page and check for a same-origin `POST` to the installation's `track.php`; successful requests return `204`.
- Confirm the page-view count appears in the authenticated dashboard.
- Confirm no analytics cookie, localStorage key, or sessionStorage key appears. The admin uses a separate necessary PHP session cookie.
- Confirm a query string is absent from stored paths (the endpoint rejects paths containing query delimiters).
- Try `/<package>/install.php` after setup; it must report setup locked and offer no password-creation form.
- Confirm HTTP access to the data file, `-wal`, `-shm`, setup/reset token files, and backups is denied on the actual host.

## Database, protection, and backup

The default data directory is a private sibling of the site document root when PHP can create it. The installer also supports an Apache-protected package `var/` directory and an Apache-only `data/` fallback. It displays the folder name and permission mode, but not the absolute path to unauthenticated visitors. `.htaccess` is defense in depth; it is not a security boundary on Nginx.

To back up over FTP/SFTP, use the hosting file manager to locate the private data folder and download the SQLite file. Stop or restrict site writes while copying so the database is quiescent; include `analytics.sqlite-wal` and `analytics.sqlite-shm` if present. Keep the backup private and preserve it while replacing application files. Never make the database downloadable over HTTP.

To restore, stop/restrict writes, replace the complete data directory from the backup, restore its PHP-writable permissions in the hosting panel, then reopen Barelytics. Do not restore only the main SQLite file while an active WAL sidecar exists.

## Uninstall

Remove the integration snippet/call, delete the uploaded `barelytics/` application directory, then delete the private data directory and its backups through FTP/SFTP or the hosting file manager. Existing web-server access logs are separate and remain under the host's retention policy.
