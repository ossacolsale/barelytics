# Barelytics for WordPress

The installable plugin is built with the PHP CLI and the system `zip` utility: run `php scripts/build-wordpress-plugin.php`, then upload `build/barelytics-wordpress.zip` in **Plugins → Add New → Upload Plugin**. The ZIP bundles the repository's PHP runtime core so end users need no Composer or shell access. Rebuild the ZIP after updating `public/barelytics/`.

Settings, the shared statistics/admin dashboard, and technical Privacy Audit are restricted to WordPress administrators (`manage_options`). The dashboard uses the same canonical admin HTML/CSS/JavaScript as the other runtime adapters; its JSON routes verify a WordPress nonce on every request and do not create a second login. Settings changes also use a WordPress nonce. Tracking runs once for successful front-page, home, or singular GET responses; admin, feeds, previews, errors, and non-GET requests are skipped. Do not also add the browser snippet on those pages. Disable page-view aggregation in the plugin settings to turn tracking off. Strict Mode is the default; optional dimensions are individually opt-in.

The SQLite database is stored by default in a permission-restricted `.barelytics-data-<installation-hash>` directory beside the web document root. The plugin resolves real paths and symlinks before accepting a location. You can choose another writable path outside the effective document root by defining `BARELYTICS_WP_DATA_DIRECTORY` in `wp-config.php` before the plugin loads:

```php
define('BARELYTICS_WP_DATA_DIRECTORY', '/srv/private/wordpress-analytics');
```

Existing `wp-content/barelytics-private/analytics.sqlite` databases are copied with SQLite's consistent `VACUUM INTO` snapshot, including committed WAL data. The copy must pass `PRAGMA integrity_check` and table/row-count checks before it becomes active. The original database and sidecars remain in place for recovery. When the old directory is under the document root, Barelytics updates its recognized generated Apache rule and verifies denial with a short-lived random probe requested from the same site. If the probe is served, the generated rule cannot be written, or migration fails, tracking and database initialization stay paused and administrators see a non-sensitive notice. Configure the server to deny access, then resolve the migration before re-enabling tracking.

Apache 2.4 uses `Require all denied`; Apache 2.2's legacy `Deny from all` rule is recognized and upgraded. Barelytics does not overwrite other `.htaccess` contents. Nginx ignores `.htaccess`, and `index.php` does not protect direct SQLite, WAL/SHM, or backup-file requests. When the old data folder is in the served tree, adapt this inside the relevant `server` block to the actual public URL path:

```nginx
location ^~ /wp-content/barelytics-private/ {
    deny all;
    return 404;
}

location ~* ^/.*\.(?:sqlite(?:-(?:wal|shm))?|sqlite3|db|bak|backup)(?:\..*)?$ {
    deny all;
    return 404;
}
```

The directory rule blocks all direct requests below the legacy folder; the extension rule also covers database and backup files if they were placed elsewhere under the served site. Test the effective Nginx configuration after adapting paths. No WordPress user ID or login identity is sent to Barelytics. Deactivation preserves the data. Uninstall does not delete analytics data; remove the private directory manually only when you intend to erase it.
