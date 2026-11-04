# Barelytics privacy model

Strict is the default profile. It stores daily aggregate page views by normalized path. It creates no analytics cookies, browser-storage identifiers, visitor/session identities, fingerprints, per-request event history, or third-party requests. IP addresses are never persisted. User-Agent values are transient bot-filter inputs; referrer URLs are ignored unless hostname collection is explicitly enabled.

Contract revision 2 explicitly permits separate daily automation counts by safe
path and finite category/family in Strict. This extends the approved aggregate
structures without adding identity, raw headers or browser state. It is a
documented prospective collection change; legacy views are never reclassified.
The rejected cookie design is retained in the [ADR](../docs/decisions/daily-reach.md).
Optional PHP [daily browser reach reports](daily-reach.md) use a transactional
IndexedDB day marker only in explicitly enabled Extended measurement. Strict
retains all existing storage prohibitions. This documented exception is not a
visitor identifier, cookie or server-side visitor record.

Country, referrer hostname, coarse browser category, device category, and operating-system category are optional independent aggregate dimensions. Missing, malformed, or unknown settings disable a dimension. Enabling any dimension changes the effective profile to Extended and requires an explicit administrator acknowledgement. Disabling collection affects future writes only; historical aggregate rows remain intact. A strict-reset action turns every optional dimension off.

Ports must describe technical behavior without claiming legal compliance or universal consent exemptions. Operators remain responsible for reviewing their hosting, access logs, audience, and applicable law.
