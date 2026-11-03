# Privacy and data inventory

Barelytics is designed to collect a small amount of aggregate traffic information. It is a technical privacy-minimization tool, not a legal compliance guarantee. Site owners must assess the rules that apply to their site and audience.

## Default profile: Strict

Fresh installations and upgraded installations default to Strict Mode. It records normalized aggregate page views and separate automation counters by UTC day/path and finite category/family. Privacy contract revision 2 explicitly permits these automation aggregates without adding identifiers or browser state. Query strings and fragments are not sent or stored, and private paths can be excluded from collection.

Optional aggregate dimensions are off by default and must be deliberately enabled by an administrator: country from a trusted server header, referrer hostname, coarse browser category, coarse device category, and coarse operating-system category. Enabling one changes the effective profile to Extended. Disabling a dimension stops future collection but does not erase historical aggregates.

## Collected

- Normalized same-site path, without query string or fragment; obvious email-like paths are rejected and long numeric/UUID record segments are collapsed to `:id`
- UTC day and an aggregate integer page-view count
- Separate classified automation request counts with finite category/family labels; declared identities are unverified
- Optional two-letter country code, or `XX` (off by default)
- Optional referrer hostname (off by default)
- Optional coarse browser, device, and operating-system categories (off by default)

These are aggregated on receipt in `pageviews_daily`, `referrers_daily`, `dimensions_daily` and `automation_daily`; there is no event-level history. Automation never contributes to ordinary page views or optional human dimensions.

## Not collected

- Persisted IP address, hashed IP, or individual location
- Full User-Agent or full Referer URL
- Query parameters or URL fragments
- Analytics cookies, persistent visitor IDs, or browser-storage identifiers
- Login identities, fingerprinting signals, advertising IDs, cross-site identifiers, or user histories
- Telemetry sent to the project author or any third party

The application does not read `REMOTE_ADDR`. For country statistics it reads only a configured server-provided country header, validates the two-letter value, and stores only that value. If no valid code is provided, the stored code is `XX`. Hosting infrastructure may separately process IP addresses in its own request logs; that behavior is outside this application's database and should be reviewed with the host.

The User-Agent is used transiently for best-effort bot classification and is not stored. When browser/device/OS categories are enabled, it is parsed in memory and only selected coarse categories are stored. Browser referrer collection requires both the server setting and an explicit tracker opt-in; the browser minimizes `document.referrer` to a hostname before sending protocol v2. Strict protocol v1 stays path-only and ignores the beacon Referer header. Native server-page integrations may minimize their real navigation referrer. Historical browser-beacon hostname rows cannot be corrected retrospectively. See the [collector contract](../spec/protocol.md). Retention defaults to 180 days and can be set to 30, 90, 180, or 365 days.

Whether consent or other notices are legally required depends on applicable ePrivacy rules, national implementation, the site's context, and other technologies in use. Barelytics describes technical behavior and does not certify legal compliance. Hosting infrastructure may separately process IP addresses and request metadata in access, proxy, CDN, or WAF logs; those systems require a separate assessment.
Daily Reach is unavailable following the [architecture and measurement review](decisions/daily-reach.md). No analytics cookie, reach table or distinct-browser metric has been introduced. A shared-value marker would still need an ePrivacy assessment and would change the effective privacy profile away from Strict.

## Administration interface

The shared administration UI loads only local HTML, CSS, and JavaScript and calls the site's same-origin API. It does not track administrator page views or send usage telemetry. Host application authentication cookies remain in the host application and are not written to analytics aggregates. See the [admin API contract](../spec/admin/http-api.md).
