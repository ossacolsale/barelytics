# Independent privacy verification

Barelytics' audit page is an aid for technical review, not the sole evidence of runtime behavior. This procedure lets an auditor compare browser behavior, deployed code, database structure, and the effective settings.

## Browser procedure

1. Open the site in a fresh private/incognito window and open browser Developer Tools.
2. In **Network**, load a normal page and filter for the Barelytics tracker endpoint (`track.php`).
3. Inspect the request body. In Strict Mode it should contain only a normalized `path`, for example `{"path":"/about"}`. Confirm there is no visitor/session ID, IP, User-Agent, screen information, country, referrer, query parameter, or arbitrary event property.
4. Inspect all analytics-related requests and confirm they go only to the same site's Barelytics endpoint. Barelytics does not configure third-party analytics or enrichment services.
5. In **Application/Storage**, inspect cookies, localStorage, sessionStorage, and IndexedDB. The tracker should create no analytics storage or identifiers. The authenticated admin interface uses an HttpOnly session cookie for sign-in. The browser may automatically send existing application/admin cookies on matching paths; the collector does not read or use them for analytics.
6. Repeat on a private route configured in the exclusion list. The browser may still send the path to the first-party collector; the server must exclude it before any database write, including automation writes. A server integration must likewise produce no write.

Strict JavaScript requests send the current pathname only, without query strings or fragments; normalization and exclusion happen on the server. Extra fields are rejected. Extended hostname attribution is explicitly opt-in on both client and server and sends only the validated hostname through protocol v2, never the original referrer URL.

## Database and configuration

1. Sign in to the Barelytics administration page and open **Privacy audit**. Review the effective profile and each explicitly listed collection dimension.
2. Run the privacy self-test. A mandatory strict-property or schema failure should result in `FAIL`, not a clean pass.
3. Review the SHA-256 configuration fingerprint and timestamped configuration history. History contains configuration metadata only; it must not contain visitor records, IP addresses, full User-Agents, or request payloads.
4. Export the JSON report if a review artifact is needed. It includes aggregate schema metadata and application file checksums, not analytics counts or visitor-level data.
5. Independently inspect the SQLite schema using a SQLite browser or another approved read-only tool. `pageviews_daily` stores aggregate day/path/count values (the legacy `country` field stays at `XX` unless country is enabled). `referrers_daily` and `dimensions_daily` contain aggregate dimensions, including retained history after collection is disabled. `automation_daily` contains only day/path/category/family/count aggregates. `historical_dimensions` identifies retained disabled dimensions separately from current collection; their presence alone does not fail a Strict audit. Search table names and columns for IP addresses, visitor/session identifiers, raw User-Agent, cookies, and event payloads.
6. Inspect the installed `track.php`, `track.js`, and `src/Barelytics.php` files and compare their SHA-256 values with the exported report or a trusted release artifact. The report hashes describe the files currently on disk; compare them with a trusted reference to establish release integrity.

## Path privacy and retention

Paths are limited to same-origin origin-form paths. Query strings and fragments are rejected by the endpoint. Long numeric identifiers, UUIDs, ULIDs, long hexadecimal/opaque path segments are normalized to `:id`; email-like paths are rejected. Configured wildcard exclusions are checked before collection. The audit page reports the number of patterns without displaying their values.

The report shows the configured aggregate retention period and the status of automatic web-triggered cleanup. Retention evidence should be checked against database dates and the hosting environment; audit reports do not export analytics rows.

## Server and hosting systems

A browser audit does not reveal transient request metadata or access logs handled outside Barelytics. Separately review:

- web server access logs;
- reverse proxy and load balancer logs;
- CDN, WAF, and hosting-provider logs;
- log retention and access controls;
- other applications receiving the same requests.

Barelytics does not control those systems, and they may process IP addresses, User-Agent strings, or full request URLs. Their behavior and retention need a separate assessment.

## Legal boundary

This guide describes technical checks. It does not establish legal compliance, determine whether consent is required, or replace jurisdiction-specific legal review. The site operator remains responsible for applicable legal basis, transparency, retention, third-party processing, server logging, and other obligations.

For site-wide PHP Reach alone, repeat the check with the same ordinary browser script
in Strict (no prepare/count requests or IndexedDB access) and after acknowledged
Extended enablement in administration, with host/CMP permission where required.
No reach script attribute is needed. Inspect only one IndexedDB `{day}` record,
no page/history or individual value. Reach requests omit cookies/referrers; SQL has only day/report totals.
Disable and verify counts stop while `historical_reach` remains visible until
retention/delete-all. An inactive browser marker requires later host/CMP cleanup;
server disablement cannot remotely delete it.

The HTML/JSON PHP audit distinguishes `tracker_source_capabilities` from
`effective_configuration` and retained historical aggregates. The presence of
IndexedDB support in `track.js` is a static capability, not evidence of storage
access in Strict Mode. Source checks still reject analytics cookies, localStorage
and sessionStorage; effective reach configuration and schema checks remain part
of the self-test. Actual browser access must be verified using the procedure above
and the behavior-based browser tests, rather than inferred from a source string.

For Daily Page Reach (PHP schema 5), enable its separate control with the normal
HTML and repeat four visits to one page: four pageviews, at most one page report
and one site report if enabled. Visit another page and verify only its page
report increases. Inspect the sole additional fixed IndexedDB `pages` record:
day, expiry timestamp, up to 512 SHA-256 hashes and a closed-day flag; never plain
paths or per-browser identifiers. Network count bodies contain only version/day/
normalized path and two booleans, never local hashes. Inspect SQLite for only
`daily_page_reach(day,path,reports)`, with no visitor/fingerprint associations or
optional dimensions. Audit reports `historical_page_reach` independently of
current enablement. Test expiry on subsequent execution, not physical deletion
while closed. Verify Strict/denial prevents hashing/storage/prepare/count, and
explicit cleanup removes both local markers. Check partial-data notices for
server-capped days; no observed report is not an inferred historical zero.
