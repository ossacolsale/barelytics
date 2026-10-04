# Barelytics storage model

SQLite is the default local store. Ports use compatible logical tables and UTC-day aggregation:

- `pageviews_daily(day, path, country, views)` with an aggregate uniqueness key on `(day, path, country)`; Strict writes the fixed placeholder `XX`.
- `referrers_daily(day, path, referrer_host, views)` for opt-in host-only referrers.
- `dimensions_daily(day, path, dimension, value, views)` for opt-in browser/device/OS categories.
- `settings(key, value)` for effective configuration and administrator credentials where the port owns authentication.
- `schema_migrations(version, applied_at)` and `privacy_configuration_history(...)` for repeatable migrations and audit history.

No per-request/event, visitor, session, fingerprint, user, or advertising-identity tables are permitted. Aggregate writes use parameterized upserts within a transaction. Configure a bounded busy timeout, enable WAL where supported, and continue safely if WAL cannot be enabled. Migrations must be idempotent and versioned. Prefer storage outside the web root; if impossible, prevent static serving and report limitations honestly.
