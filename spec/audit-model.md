# Privacy audit model

Each port exposes its effective configuration, current schema/migration version, a deterministic SHA-256 configuration fingerprint, configuration history, and a machine-readable report. Checks cover Strict/Extended profile, disabled browser storage and identifiers, disabled optional dimensions, aggregate-only tables, absence of IP storage and third-party analytics, unsupported generic events, path normalization and exclusions, retention, schema validity, and a runtime storage self-test. Audit output is technical evidence, not a legal certificate. Never expose credentials, secrets, stack traces, or private absolute database paths.

Current collection is determined by effective settings, not by the presence of
retained aggregate rows. Reports list disabled dimensions with historical rows
under `historical_dimensions`; these rows alone do not fail a Strict audit.
Unexpected tables/columns remain structural failures. The audit cannot prove
how historical data were collected, verify UA declarations, or inspect hosting
logs/CDN configuration. PHP optionally permits an IndexedDB day marker only when Daily Reach is enabled
in Extended; its audit includes enablement, current storage use and
`historical_reach` separately. Strict has no reach storage access; retained reach
rows alone do not fail its audit. Other adapters report reach unavailable.
