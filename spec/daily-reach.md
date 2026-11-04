# Daily browser reach reports (PHP reference capability)

This optional Extended metric counts first daily reports received from browsers
participating in measurement. It is not an exact count of people or all unique
visitors, a guaranteed lower bound, page uniques or a verified conversion metric.
The [ADR](../docs/decisions/daily-reach.md) retains the rejected cookie design and
specifies this alternative. Only PHP advertises availability initially; native
adapters and WordPress remain explicitly unavailable and must not enable it.

`daily_reach_enabled` defaults false, is independent of dimensions and requires
explicit Extended acknowledgement. Strict reset turns it off. Effective
`indexeddb` is true only with either optional Reach metric enabled; identifiers
and cookies remain unsupported. `BARELYTICS_DAILY_REACH_ALLOWED=0` is an operator kill switch.

The normal `<script defer src="/barelytics/track.js"></script>` automatically
participates when the PHP administrator enables reach. The effective server
configuration is authoritative: the tracker fetches the ordinary page view with
`keepalive: true` and proceeds only on a 204 with `Barelytics-Reach-Enabled: 1`.
`Barelytics-Reach-Path` supplies the percent-encoded normalized safe path for
prepare. The client decodes and validates an origin-form path, bounded to 512
UTF-8 bytes, rejecting malformed encoding, controls, queries, fragments and
ambiguous routing. No separate configuration request is sent. Strict responses
omit both headers, so no prepare, IndexedDB access or count occurs.

`data-daily-reach="true"` remains compatible but is unnecessary and cannot
override server policy. Explicit `data-daily-reach="false"` opts that page out
of reach while retaining ordinary page views. The previous HTML opt-in selected
the inspectable fetch transport instead of beacon; fetching every ordinary
pageview removes that transport prerequisite. It does not replace permission.
An optional asynchronous `window.BARELYTICS_REACH_PERMISSION` callback must
return exactly true before prepare, storage and send. Permission exceptions,
rejected promises and denials fail closed. Hosts requiring consent must supply
the callback; absence is permitted only under the operator's documented
assessment. Page views do not depend on reach success.

Without fetch, one path-only beacon may collect a page view; reach and browser
referrer attribution are unavailable for that attempt. After a dispatched fetch
failure there is no fallback or retry, because an ambiguous failure may follow
a committed view. Protocol-v2's explicit 400 still permits its single path-only
fallback, since rejection has not written a view. Keepalive permits requests to
outlive navigation, reload and page closure, subject to browser limits; neither
transport guarantees delivery under network loss or mobile process termination.
Both require CSP `connect-src` authorization. A PHP-only `Barelytics\track()`
call cannot perform browser deduplication; choose the browser tracker instead,
without also counting that page server-side. Reach-only mode is not implemented.

Both operations use same-origin POST, UTF-8 application/json, at most 8 KiB,
exact fields, no-store responses and no cookies/sessions. Browser fetch uses
`credentials: "omit"` and `referrerPolicy: "no-referrer"`. Cross-site Fetch
Metadata and mismatched Origin are rejected as defense in depth; none of these
headers or User-Agent authenticates a visitor. No nonce or receipt is generated.

* `reach-prepare.php`: exact `{path}`; normalizes/excludes the path, checks
  configuration/operator policy and transient automation classification. Returns
  `{enabled, eligible, day, period_end, reporting_contract_version:1, path}`.
  The UTC day and ISO UTC period end come from one server instant; `path` is
  normalized or null. Prepare never increments an aggregate.
* `reach-count.php`: exact `{day,path}`; repeats validation/policy/eligibility
  under a bounded SQLite write transaction. Returns 204 on success (also on
  contained storage failure), 400 for malformed/stale days, 403 when disabled,
  excluded, automated or cross-site. A previous UTC day is accepted only during
  the first 120 seconds of the next day and is attributed to the supplied day.
  Other collector method/size/media errors use 405/413/415. No automatic retry.

One origin-wide IndexedDB database `barelytics-daily-reach`, version 1, has one
object store `state`; global-only collection uses a fixed key `day` and one value `{day:"YYYY-MM-DD"}`.
A single readwrite transaction reads and overwrites it only for a newer day.
Only successful transaction completion by a writer authorizes one send attempt;
abort/error/unavailable storage/timeout authorize none. There is no network
inside the transaction, fallback storage or retry after commit. A newer marker
cannot regress due to a late prepare response. Storage has no automatic midnight
expiry: overwrite on a future claim, explicit host/CMP
`window.BarelyticsDailyReach.clear()`, browser clearing or eviction removes it.
The explicit cleanup API is available even after disablement or per-page opt-out;
defining it performs no storage access. Strict performs no automatic storage
access. Already authorized in-flight work may finish a local claim; count rechecks current policy and rejects disabled writes. A server cannot clear inactive
browsers. No persistent-storage privilege is requested. Initially one collector
per origin is supported; multiple collectors would share the same gate.

PHP schema 4 adds only `daily_reach(day TEXT PRIMARY KEY, reports INTEGER NOT
NULL DEFAULT 0)`. Reporting contract 1 is UTC; historical page views are untouched.
Retention and delete-all include the table. Audit reports current enablement,
IndexedDB use and `historical_reach` separately; configuration history records
changes. The site-wide metric keeps its own UTC timeline; the separately enabled Page
Reach extension below provides page aggregates without deriving site reach.
Migrations are idempotent; rollback requires matching code and database backups.
Do not share a PHP schema-4/5 database with native schema-3 adapters.

Local coordination guarantees at most one application-level send per day with
intact cooperating storage. Delivery and SQL increments are different events:
network loss or closing a page after commit can lose observations; storage
reset/eviction, private contexts, separate profiles/devices, duplicated transport
and forged requests can inflate them. Shared browsers combine people.
JavaScript-disabled visits are absent. No correction factor or confidence
interval is justified. GDPR infrastructure and ePrivacy storage obligations
remain separate operator assessments; no automatic consent exemption is claimed.

## Daily Page Reach — PHP schema 5, privacy contract revision 4

These are three independent metrics: pageviews count eligible loads, site-wide
Daily Reach counts received first daily browser reports for the site, and Daily
Page Reach counts received first daily browser reports for each normalized page.
A browser visiting three pages can produce one site report and three page
reports. Neither metric identifies people; summing page reports cannot recover
site reach. Period totals sum daily reports, without deduplication across days.

`daily_page_reach_enabled` defaults false, including migration from schema 4.
It is independent of `daily_reach_enabled`, requires Extended acknowledgement,
and is unavailable in WordPress/native adapters. Either setting alone makes PHP
Extended and permits the relevant IndexedDB operations, after CMP permission.
Strict reset and `BARELYTICS_DAILY_REACH_ALLOWED=0` disable both. The ordinary
tracker needs no HTML change; `data-daily-reach="false"` opts out of both metrics.
The collector advertises `Barelytics-Page-Reach-Enabled: 1` separately from the
legacy global header and supplies the same encoded normalized safe path.

Protocol v1 remains unchanged. A page-capable tracker requests prepare with
exact `{version:2,path}`. The v2 response retains day, period_end, eligible and
safe path; enabled means either metric, with separate `global_enabled` and
`page_enabled` booleans, `server_time` (integer Unix seconds), and
`reporting_contract_version:2`. The prepared path/day and CMP permission are
validated before SHA-256 or IndexedDB access. Only the browser computes
`SHA-256(UTF8(day + "\n" + normalizedPath))`; the hash is never transmitted.
No raw path is persisted locally. A missing secure Web Crypto digest/TextEncoder
fails Page Reach closed while global Reach and pageviews continue. HTTPS (or a
browser-trusted local development origin) is required for Web Crypto. Hashing
known paths is minimization, not anonymization. Operators must exclude sensitive
or personal-data paths; normalization cannot recognize all names/short slugs.

The existing database version 1 and object store `state` are preserved. The
second fixed key `pages` contains `{day,expiresAt,hashes,closed}`: one day, one
local expiry timestamp, up to 512 SHA-256 hex strings, and a boolean tombstone.
No user identifier, salt, page list, raw path or cross-day hash history is stored.
The global `day` key remains `{day}`. One readwrite transaction reads both keys,
purges expired/older page hashes, and commits independent global/page decisions.
No network/await runs inside it. Only successful completion authorizes a send.
A newer page/global day blocks older asynchronous claims. Existing legacy
trackers can still open this same database/store and use the global key.

Expiry is at most 24 hours after local claim, additionally bounded by the
remaining authoritative UTC period, subtracting response/hash/permission delay.
An expired same-day record loses its hashes and becomes closed, preventing
fresh same-period claims after expiry or a forward clock adjustment. A newer
server day can replace that tombstone. New tracker storage access, including
global-only access, lazily purges expired hashes; Strict/denial never opens or
purges storage. An inactive browser or old tracker cannot physically delete it.
Clock manipulation and storage eviction remain limitations; no exact wall-clock
physical deletion is promised. Explicit `BarelyticsDailyReach.clear()` deletes
both keys/database for CMP withdrawal. At 512 hashes, new page claims fail
closed; existing markers are retained. Global Reach/pageviews remain independent.

A v2 count has exactly `{version:2,day,path,global,page}` with strict booleans
and at least one requested component. The server never accepts fingerprints,
tokens or visitor fields. It rechecks current policy, exclusions, bot rules and
UTC grace. Only currently enabled requested components are written, together in
one bounded SQLite transaction; disabled components are suppressed, with 403 if
none remain. Each winning page after the first sends only page=true. Failed or
lost counts never retry or roll back local claims. Both metrics share the single
existing prepare request; Strict makes none. Old clients only report global v1;
new clients use v1 against servers that advertise only global reach. Explicit
versions prevent mixed deployments silently reinterpreting payloads.

Schema 5 adds only
`daily_page_reach(day TEXT NOT NULL,path TEXT NOT NULL,reports INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path)) WITHOUT ROWID`.
No additional index, browser/fingerprint table or dimensional breakdown is
permitted. The PK is the storage and retention index. Repeated reports use one
key lookup/upsert. A new day/path additionally checks a covering-PK range bounded
to 4,096 keys; there is no unbounded full-table collector scan. The first 4,096
new normalized paths per UTC day (serialized transaction order) are accepted;
further new paths are refused, while existing paths and global reach continue.
A capped page-only count returns 429; a combined count can still return 204 for
its global increment. No automatic retry occurs in either case.

One bounded `settings.daily_page_reach_limited_days` JSON day list records
operational partial-data flags, never browser/page associations. It is pruned
with aggregate retention (at most retention+1 days, max 366). Admin reports
expose partial and limited_days, including historical disabled collection.
Retained aggregates follow configurable retention, not a 24-hour deletion rule.
Scheduled/manual cleanup deletes up to 1,000 rows per aggregate table per batch,
continues pending work on subsequent requests without cron, and prunes flags.
Delete-all removes both reach tables' contents and limitation metadata.

Pages, Daily statistics and Page detail compare view/report aggregates without
joining them to granular dimensions. Null means no observed reports, not an
inferred zero or historical backfill; a stored zero stays zero. Availability,
current enablement, retained data and partial periods are separate API states.
Storage clearing/eviction, profiles/devices/private contexts, JavaScript or CMP
blocking, local limits, network loss and public forgery can change counts.
No exact people, conversion percentage or universal consent exemption is claimed.
