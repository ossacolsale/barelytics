# Barelytics storage model

SQLite is the default local store. Ports use compatible logical tables and UTC-day aggregation:

- `pageviews_daily(day, path, country, views)` with an aggregate uniqueness key on `(day, path, country)`; Strict writes the fixed placeholder `XX`.
- `referrers_daily(day, path, referrer_host, views)` for opt-in host-only referrers.
- `dimensions_daily(day, path, dimension, value, views)` for opt-in browser/device/OS categories.
- `automation_daily(day, path, category, family, requests)` with a primary key on
  `(day, path, category, family)`, introduced by additive migration 3. Categories
  and families are finite classifier outputs; raw User-Agent is never stored.
- `settings(key, value)` for effective configuration and administrator credentials where the port owns authentication.
- `schema_migrations(version, applied_at)` and `privacy_configuration_history(...)` for repeatable migrations and audit history.

No per-request/event, visitor, session, fingerprint, user, or advertising-identity tables are permitted. Aggregate writes use parameterized upserts within a transaction. Configure a bounded busy timeout, enable WAL where supported, and continue safely if WAL cannot be enabled. Migrations must be idempotent and versioned. Prefer storage outside the web root; if impossible, prevent static serving and report limitations honestly.

Capture one event instant/day before writing: all dimensions from that event use
the same UTC day. Migration 3 preserves all previous totals/settings. Old PHP
collectors pause on newer schemas; restore the matching code/database backup for
rollback. Native old versions do not know the automation table, so rolling back
stops its writes/cleanup; do not run mixed versions against one store. No downgrade
should drop or reinterpret historical aggregates.
