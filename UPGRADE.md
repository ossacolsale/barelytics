# FTP-only upgrades

1. Back up the private Barelytics data directory using FTP/SFTP or the hosting file manager. Pause site writes while copying the SQLite database; also preserve `analytics.sqlite-wal` and `analytics.sqlite-shm` when present.
2. Download and extract the newer Barelytics package locally.
3. Upload/replace the files from the new `public/barelytics/` directory into the installed Barelytics directory. Preserve the persistent data directory and any host-specific access rules. Do not overwrite or delete the SQLite database or setup state.
4. Open the Barelytics admin URL and sign in with the existing password.
5. If the schema version changed, the authenticated dashboard displays an upgrade action. Review the backup reminder, then apply the migration in the browser. Migrations are versioned and run in short SQLite transactions; the version marker is committed with each migration so a failed step can be retried.
6. Confirm the dashboard reports the new application/schema version and that existing aggregates remain. Optional dimensions default to Strict/off when no prior explicit setting exists; missing dimension settings stay off. Schema 3 explicitly adds daily automation classification counters in Strict under privacy contract revision 2, without cookies or identifiers. It does not rewrite legacy views.
7. Review **Privacy audit** for the effective profile, schema checks, configuration fingerprint, and migration history. Existing historical aggregates are preserved when collection is disabled; retained optional aggregates are listed separately and do not by themselves fail a Strict audit.

No shell, Composer, SQL console, or cron job is needed. Tracking pauses safely if it detects an older schema before the authenticated migration has completed. If an upload or migration fails, keep the data directory untouched, restore the matching application and database backup through the hosting file manager. Older PHP code pauses on a newer schema; do not delete the new aggregate table to force a downgrade.

For the current schema, see [docs/configuration.md](docs/configuration.md) and [docs/security.md](docs/security.md). See [CHANGELOG.md](CHANGELOG.md) for release changes.
