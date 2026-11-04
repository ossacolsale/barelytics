# Daily browser reach reports (PHP reference capability)

This optional Extended metric counts first daily reports received from browsers
participating in measurement. It is not an exact count of people or all unique
visitors, a guaranteed lower bound, page uniques or a verified conversion metric.
The [ADR](../docs/decisions/daily-reach.md) retains the rejected cookie design and
specifies this alternative. Only PHP advertises availability initially; native
adapters and WordPress remain explicitly unavailable and must not enable it.

`daily_reach_enabled` defaults false, is independent of dimensions and requires
explicit Extended acknowledgement. Strict reset turns it off. Effective
`indexeddb` is true only with reach enabled; identifiers and cookies remain
unsupported. `BARELYTICS_DAILY_REACH_ALLOWED=0` is an operator kill switch.

A tracker must opt in with `data-daily-reach="true"`. It fetches the ordinary
page view first and proceeds only on a 204 with `Barelytics-Reach-Enabled: 1`.
`Barelytics-Reach-Path` supplies the percent-encoded normalized safe path for
prepare, so the browser does not resend raw identifier-bearing paths. Strict
responses omit both headers, so no prepare, IndexedDB access or count
occurs even if the script attribute remains. An optional asynchronous
`window.BARELYTICS_REACH_PERMISSION` callback must return exactly true before
prepare, storage and send. Permission exceptions/denials fail closed. Hosts
requiring consent must supply the callback; absence is permitted only under the
operator's documented assessment. Page views do not depend on reach success.

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
object store `state`, a fixed key `day`, and one value `{day:"YYYY-MM-DD"}`.
A single readwrite transaction reads and overwrites it only for a newer day.
Only successful transaction completion by a writer authorizes one send attempt;
abort/error/unavailable storage/timeout authorize none. There is no network
inside the transaction, fallback storage or retry after commit. A newer marker
cannot regress due to a late prepare response. Storage has no automatic midnight
expiry: overwrite on a future claim, explicit host/CMP
`window.BarelyticsDailyReach.clear()`, browser clearing or eviction removes it.
Strict performs no automatic storage access. Already authorized in-flight work
may finish a local claim; count rechecks current policy and rejects disabled writes. A server cannot clear inactive
browsers. No persistent-storage privilege is requested. Initially one collector
per origin is supported; multiple collectors would share the same gate.

PHP schema 4 adds only `daily_reach(day TEXT PRIMARY KEY, reports INTEGER NOT
NULL DEFAULT 0)`. Reporting contract 1 is UTC; historical page views are untouched.
Retention and delete-all include the table. Audit reports current enablement,
IndexedDB use and `historical_reach` separately; configuration history records
changes. The dashboard exposes a site-wide UTC timeline, not per-page reach.
Migrations are idempotent; rollback requires matching code and database backups.
Do not share a PHP schema-4 database with native schema-3 adapters.

Local coordination guarantees at most one application-level send per day with
intact cooperating storage. Delivery and SQL increments are different events:
network loss or closing a page after commit can lose observations; storage
reset/eviction, private contexts, separate profiles/devices, duplicated transport
and forged requests can inflate them. Shared browsers combine people.
JavaScript-disabled visits are absent. No correction factor or confidence
interval is justified. GDPR infrastructure and ePrivacy storage obligations
remain separate operator assessments; no automatic consent exemption is claimed.
