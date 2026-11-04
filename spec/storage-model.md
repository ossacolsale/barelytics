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

PHP migration 4 adds only `daily_reach(day PRIMARY KEY, reports)`, as specified
in [daily-reach.md](daily-reach.md). Native runtimes retain schema 3 and advertise
reach unavailable; shared database upgrades across these versions are unsupported.

PHP migration 5 adds
`daily_page_reach(day TEXT NOT NULL,path TEXT NOT NULL,reports INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path)) WITHOUT ROWID`.
Only that composite aggregate key is indexed; no server page fingerprints or
visitor association exists. The new setting defaults off on upgrade. A finite
4,096-new-paths/day cap bounds growth; existing day/path keys still increment.
Operational capped days are a bounded JSON list in settings, pruned alongside
retention and cleared by delete-all. New rows follow configurable historical
retention; only local deduplication hashes have a 24-hour maximum logical age.
See [Daily Page Reach](daily-reach.md#daily-page-reach--php-schema-5-privacy-contract-revision-4).
Migration preserves all legacy counts/credentials/settings. Native stores stay
at schema 3, with both reach capabilities unavailable; WordPress uses PHP schema
5 but neither public reach capability. Do not share these databases across
versions or remove new tables to bypass guards. Cleanup frees reusable SQLite
pages; it need not shrink the physical file without a separate vacuum.
