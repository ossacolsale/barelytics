# Manual FTP acceptance checklist

This checklist is for a real hosting account. It is not a substitute for CI, and a passing local PHP development-server test does not verify a provider's access rules.

## Fresh installation

- [ ] Upload the contents of `public/barelytics/` using FTP/SFTP only.
- [ ] Do not use SSH, a shell, Composer, npm, or cron.
- [ ] Open the install URL and inspect every environment check.
- [ ] Confirm the database CRUD and WAL/SHM checks run independently; a sidecar-check failure must not change the CRUD result.
- [ ] Confirm SQLite/PDO SQLite is reported clearly when enabled and gives the hosting-control-panel action when disabled.
- [ ] Create the token with the included local hash helper and upload `setup-token.php` into the package `data/` folder.
- [ ] Complete admin setup in the browser; confirm the bootstrap file is removed or remains unusable behind the setup lock.
- [ ] Confirm setup is locked on refresh and after `?reset=1` is added.
- [ ] Add the generated snippet and confirm page views appear.
- [ ] Confirm no analytics cookie or browser-storage identifier is created.

## Subdirectory, static pages, and CSP

- [ ] Deploy at `/tools/barelytics/` and use the generated snippet.
- [ ] Confirm its request resolves to `/tools/barelytics/track.php`.
- [ ] Serve a static HTML page from the same origin and confirm it is counted.
- [ ] Check the site's `script-src` and `connect-src` rules without adding inline script or `eval`.
- [ ] On a PHP-rendered page, test `Barelytics\track()` separately and ensure the JS snippet is not also installed there.

## Hosting and data protection

- [ ] Test Apache + PHP-FPM and Plesk Apache + PHP-FPM.
- [ ] Test Plesk Nginx proxy + Apache/PHP-FPM with data outside the webroot.
- [ ] Test Nginx-only with external data, then confirm in-webroot mode is refused.
- [ ] Test a missing PDO SQLite extension and a non-writable data directory for clear diagnostics.
- [ ] Attempt HTTP access to the database, WAL, SHM, setup/reset token, backups, and source-private files.
- [ ] Verify the host actually applies `.htaccess` before selecting an Apache fallback.

## No-cron retention and upgrades

- [ ] Disable cron, choose short retention in a staging install, create expired test aggregates, and verify bounded cleanup runs after tracking or during an authenticated admin request.
- [ ] Upload a newer package through FTP while preserving the data directory.
- [ ] Sign in and apply a pending migration in the browser.
- [ ] Verify the schema version advances and previous aggregate rows remain.
- [ ] Interrupt a migration in staging and verify it can be retried without marking an incomplete schema as current.

## Matrix to record per release

| Environment | Result | Notes |
|---|---|---|
| Apache + PHP-FPM + PDO SQLite | Pending | |
| Plesk + Apache + PHP-FPM | Pending | |
| Plesk Nginx proxy + Apache/PHP-FPM + external data | Pending | |
| Static HTML frontend + PHP backend | Pending | |
| No cron / no Composer / no Node | Pending | |
| Missing PDO SQLite | Pending | |
| Non-writable data directory | Pending | |
| Nginx-only + unsafe webroot data | Pending | |
