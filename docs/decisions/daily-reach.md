# ADR: optional Daily Reach

Date: 2026-11-03. Status: shared-cookie design rejected; client-gated PHP
alternative implemented and verified for the limited report metric below,
disabled by default. Strict remains the default.

The sections preceding **Alternative** retain the original cookie proposal and
its rejection. They do not describe the later client-gated implementation.

## Privacy and legal gate

The proposed marker is a common site/day value, for example
`bl_seen_day=2026-11-03`. Every browser gets the same value; it has no random,
IP-derived, User-Agent-derived or client timestamp component. By itself it
cannot single out a browser. It does disclose membership of a daily cohort and
must never be joined to account/session data, access logs or another site.
This is a design assessment, not proof of anonymisation of the surrounding
processing.

Reading/writing terminal information still requires an ePrivacy assessment even
when the information is non-personal; see the final [EDPB 2/2023, version 2.0,
16 October 2024](https://www.edpb.europa.eu/documents/guideline/guidelines-22023-on-technical-scope-of-art-53-of-eprivacy-directive_en).
Transport necessarily exposes metadata to the hosting infrastructure. IPs,
logs, proxies, hosting processors, purposes, retention, security and transfers
still require the operator's GDPR assessment. Barelytics would not persist
those transport values.

Pseudonymisation retains the possibility of attribution using additional
information; it is not anonymisation. Aggregate statistics describe groups,
but small cells, paths and surrounding data can still expose information.
Anonymity must be assessed in context, including singling out and linkability.
The official [EDPB 01/2025 page](https://www.edpb.europa.eu/documents/guideline/guidelines-012025-on-pseudonymisation_en)
currently labels version 1 as public consultation closed, not final. The
[EDPB 02/2026 page](https://www.edpb.europa.eu/documents/guideline/guidelines-022026-on-anonymisation_en)
also labels version 1 as public consultation closed, not final. Both publication
statuses were checked on the review date; neither draft is relied on as binding
final guidance.

In Italy the [Garante's 10 June 2021 guidance, section 7.2](https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9677876)
describes conditional treatment of audience-measurement cookies as technical:
minimized aggregate measurement within the relevant site/app scope, preventing
singling out and cross-site navigation tracking, with restrictions on third-party
combination and onward disclosure. The [cookie FAQ, question 4](https://www.garanteprivacy.it/faq/Cookie)
addresses direct publisher use for site optimization and aggregate statistics.
An operator must assess the actual implementation, purpose, notice and hosting
arrangements; the shared marker alone does not establish an exemption.

The [CNIL audience-measurement guidance, 4 July 2025](https://www.cnil.fr/fr/cookies-solutions-pour-les-outils-de-mesure-daudience)
is French guidance, useful for comparison, not an Italian approval. It limits
the exemption to publisher audience measurement with anonymous statistics and
no cross-site tracking or combination with other processing. It is not a
certification of Barelytics. No universal consent exemption is claimed.

Before any future enablement, an operator would review these conditions, the
audience/jurisdictions, notices, lawful basis, consent or exemption assessment,
retention, infrastructure logs and proxy/CDN behavior. Enablement must be
explicit, independent of dimensions, acknowledged and recorded in configuration
history; effective profile must become Extended even with all dimensions off.
Where consent is required, an external CMP/host callback must authorize cookie
access before collection, stop access on withdrawal and trigger deletion.
No feature may automatically activate on upgrade.

## Proposed transport, storage and day semantics

Only eligible ordinary page requests count. Validate paths, exclusions and
automation first; page views continue independently of reach. A future cookie
would be host-only, Secure, HttpOnly, SameSite=Strict, without Domain, scoped to
the collector's actual path. Multiple collectors and server-page integrations
would need a documented common path or dedicated endpoint; a wide `/` cookie
must not be introduced silently. Require HTTPS and a first-party same-origin
context, bypass CDN caching and use `Cache-Control: no-store`. Never put the
marker in a URL/body or application logs; redact Cookie/Set-Cookie at proxies.

Use one captured event instant for all counters. Retain UTC 00:00–00:00 as
reporting-day version 1. Expire at the next absolute boundary without renewal
on repeated views. A future IANA timezone/local-boundary option would require
a prospective versioned dataset, resolving local calendar boundaries with DST
rules, not adding 86,400 seconds. Europe/Rome 04:00–04:00 has 23/25-hour days
around DST; it reduces some midnight effects, provides no session semantics
and still counts a browser on both sides of a boundary. Never relabel historical
UTC rows or compare differently defined daily counters as equivalent.

The proposed minimal `daily_reach(day PRIMARY KEY, browsers)` could work only
for fixed UTC version 1. Multiple day definitions would need an explicit
reporting-version key. Upsert the site-wide count in the same transaction as
page views; no per-browser observations, marker table or identifier is allowed.
Include reach in bounded retention, delete-all, audits and history. On disable,
ignore and stop issuing the marker, expire it at known path scopes where
possible, retain historical aggregates under normal retention, and explain
that an absent browser cannot be remotely cleared. Rollback would disable the
feature and preserve aggregate tables for safe forward recovery.

## Technical gate: count-and-set versus acknowledgement

With count-and-set, every missing marker increments the counter and sets the
common day. Accepted sequential cookies give one count per browser/day. A
browser rejecting cookies produces one count per view: 20 views become 20
estimated browsers. This bias has no finite per-browser bound. Concurrent first
tabs, lost Set-Cookie responses and retries also overcount. SQL atomicity
protects the integer, not browser deduplication. A database failure must not set
a marker that would suppress a later successful count.

An acknowledgement protocol could issue a common pending-day marker, count only
when it returns, then replace it with a common counted-day marker. This filters
cookie rejection but misses single-page visitors unless an extra acknowledgement
request runs; this increases traffic and requires a new protocol. Simultaneous
pending acknowledgements and lost counted-marker responses can still count
twice. A single common value does not tell whether a returning marker has
already contributed. A persistent per-browser token would solve a different
problem and is rejected. No automatic correction factor is justified.

Both designs estimate browser contexts, never people, visits/sessions or
page-level uniques. Clearing cookies/private browsing inflate counts; several
browsers/devices split one person; shared browsers combine people. Blocking,
failed requests and browser limits undercount; forged/removed markers can bias
either direction. A cached collector response can suppress writes or replay a
marker; cache bypass is mandatory. Changing days does not identify a new visit.

Decision: do not ship a distinct-browser metric whose cookie-rejection bias is
unbounded, and do not present acknowledgement counts as accurate uniques. A
future iteration requires an explicit accepted error model and a reviewed
acknowledgement protocol with browser/CMP tests across all ports. Feasibility
experiments in [`scripts/test_daily_reach_design.py`](../../scripts/test_daily_reach_design.py)
demonstrate rejected-cookie, concurrency, retry and DST cases without retaining
any actual browser data (five cookie-design tests; see the [quality review](../quality-review.md)).
That decision shipped no enabling action or runtime capability. The alternative
below uses a different metric and gate; it does not rehabilitate count-and-set.

## Alternative: client-gated daily browser reach reports

Decision accepted for the PHP reference capability, 2026-11-03. The rejection above remains valid for
server-side shared-cookie counting. The revised metric has different acceptance
criteria: first daily reports received from cooperating browser contexts, with
possible missing and duplicate observations explicitly accepted. It is neither
exact unique visitors nor a guaranteed lower bound.

The PHP reference implementation exposes two bounded, no-store, same-origin
POST operations. Prepare validates enablement, operator policy, safe path and
transient automation classification, then returns the authoritative UTC day,
period end, normalized path and reporting contract version 1. Count accepts only
that day and path, revalidates policy/eligibility, and atomically increments
`daily_reach(day PRIMARY KEY, reports)`. A previous-day report is accepted only
in the first 120 seconds after UTC midnight, attributed to that supplied day.
No cookie, nonce, individual identifier, receipt or server deduplication record
is used. Direct endpoint abuse and transport duplication remain possible.

The PHP administration setting centrally controls the normal browser tracker;
`<script defer src="/barelytics/track.js"></script>` needs no per-page opt-in.
The earlier `data-daily-reach="true"` selected fetch rather than beacon so the
client could inspect the response. Every ordinary pageview now uses a single
same-origin fetch with keepalive, and its response must advertise reach
activation and a validated normalized safe path before any reach request.
There is no separate discovery request or storage read. A Strict server causes
no prepare, storage access or count, even when the old `true` attribute remains.
Explicit `false` is preserved as a per-page privacy opt-out. Native and WordPress
adapters do not advertise activation and remain unavailable.

The optional asynchronous `window.BARELYTICS_REACH_PERMISSION` callback must
resolve to true before prepare, storage access and send; hosts needing consent
must supply it and return false until consent. Central enablement cannot replace
end-user permission. Reach transport omits credentials and referrers. Explicit
cleanup remains callable after disablement, without automatic storage access.
PHP-only server-page calls cannot participate in browser deduplication; use one
browser tracker instead of also counting server-side. A separate reach-only
mode is deferred because it would require another discovery transport and is
unnecessary for the ordinary tracker objective.

Transport assessment: the [Fetch standard](https://fetch.spec.whatwg.org/#request-keepalive-flag)
allows keepalive work to outlive its environment and limits in-flight keepalive
body bytes to 64 KiB. [Beacon](https://www.w3.org/TR/beacon/#sec-processing-model)
also uses keepalive, with browser scheduling/coalescing that can improve mobile
energy usage. The small existing pageview payload fits the quota on its own,
but shares it with other pending requests. Fetch lets the client inspect the
server signal; it cannot guarantee response processing after document closure,
so a delivered view may have no reach report. No mobile background, OS process
kill, offline durability or universal reliability parity is claimed. Both
transports are subject to CSP connect-src in conforming browsers. The pinned
Firefox 146.0.1 bypasses connect-src for keepalive fetch: the local test observed
one view/report despite connect-src none, while ordinary fetch was blocked.
This matches [Mozilla bug 2012984](https://bugzilla.mozilla.org/show_bug.cgi?id=2012984);
its enforcement is not a passing guarantee on that engine. Chromium 145 blocked
the request. Use server policy and the CMP callback for Reach authorization,
not CSP as a consent switch. No browser-version workaround is added to runtime
code. When fetch is missing, only one path-only beacon is attempted and reach/referrer attribution are unavailable.
A restricted/throwing fetch or lost response never triggers a beacon or another
fetch: the server may already have counted the view. Only protocol-v2's explicit
400 rejection retains its path-only fallback. No unload listener or retry loop
is added. Browser regressions exercise fast reload/navigation/closure and
network failure separately from SQL delivery.

One origin-wide IndexedDB database has one object store and one fixed-key record
containing only `{day}`. A single readwrite transaction reads and, if necessary,
overwrites that record; only its successful complete event authorizes one send.
Concurrent tabs/windows share the transaction ordering. A newer day is never
overwritten by an older prepare response. No network request runs inside the
transaction. Abort, unavailable storage, permission denial and timeout authorize
no send. A committed marker survives a failed send; no automatic retry, marker
rollback or storage fallback is permitted. A closed browser before send can
therefore lose that day's observation. Browser restart preserves an intact
marker; private contexts, storage clearing/eviction and separate profiles can
permit new observations. Multiple devices split people and shared browsers
combine them. JavaScript-disabled visits are absent, and server-page views may
observe a different population. Do not infer page uniques or verified conversion
rates from these totals.

The setting defaults off and independently requires the Extended acknowledgement.
Strict reset disables it. Schema 4 adds only the site-wide aggregate table;
retention, deletion, audit and configuration history include it. Prior reach rows
are historical data even when collection is now off. PHP is the initial supported
backend; WordPress and other adapters must advertise unavailable until their
transport integration is verified. The shared UI must honor that capability.

IndexedDB access remains terminal-storage access for ePrivacy purposes; absence
of an identifier does not establish consent exemption. The GDPR/infrastructure
assessment above still applies. No automatic midnight expiry exists in IndexedDB:
the next claimed day overwrites the record; an explicit host/CMP clear operation
can delete the database. Server disablement cannot clear an inactive browser.
Do not request persistent-storage privileges. One collector per origin is the
initial scope; independent collectors on one origin would share the gate.
UTC reporting version 1 preserves legacy data. Future IANA calendars need a
separately versioned design, including DST; they do not block this prototype.

Acceptance evidence distinguishes one application-level send attempt from
network delivery and committed SQL increments. The ten revised engineering
criteria pass for the PHP reference implementation, with the following evidence.

| Criterion | Verified evidence |
| --- | --- |
| No individual identifier | Browser tests inspect the sole `{day}` record and exact `{day,path}` request; no nonce/random value is generated |
| No server visitor record | Schema 4 and audit allow only `daily_reach(day,reports)` |
| Unavailable storage sends nothing | Real browser storage-denied, transaction-missing/abort and quota-error scenarios; ordinary views continue |
| Concurrent tabs coordinate | Two and ten simultaneous tabs/navigations and popup windows produce one application-level attempt |
| Intact marker suppresses repeats | Sequential visits and persistent profile close/relaunch produce one attempt |
| Network loss never retries | A dropped request yields zero SQL rows; a dropped response after commit yields one row; subsequent visits send neither again |
| Independent page views | Storage/permission/network/SQL failures leave ordinary view collection operational |
| Strict storage isolation | A disabled server with normal or legacy HTML produces no prepare/count or IndexedDB access; reset also rejects in-flight count |
| Consistent UTC boundaries | Server-clock fixtures, new day, previous-day +119s accepted on its original day, +120s rejected; invalid/future/stale keys rejected |
| Honest administration | Real login/dashboard/reset tests and visual inspection show received reports, limitations, current disablement and retained history |

The central-activation revision passed 36 Reach scenarios per engine (72 total)
on Chromium/Chrome Headless Shell 145.0.7632.6 and Playwright Firefox 146.0.1
on Linux. The CSP case verifies no fallback while reporting Firefox’s policy
enforcement limitation separately. Both use the Ubuntu 24.04
fallback builds on Ubuntu 26.04. The earlier implementation also ran on Google
Chrome 156.0.8078.47. Concurrency and restart use unmodified IndexedDB; denial,
quota and missing/aborted transaction cases use controlled fault injection in
the real engines. The harness uses an ordinary declared UA for eligible traffic;
a bot test supplies a synthetic bot header explicitly. HeadlessChrome's own UA
is correctly classified as automation by the existing rules.

Coverage now includes normal HTML without a reach attribute, three reloads with
four views/one report/one count attempt and unchanged marker, enabling/resetting
through the actual administration panel without changing HTML, legacy true/false
attributes, denied/thrown/rejected CMP permission, missing fetch, CSP restrictions,
lost pageview requests/responses with no duplicate fallback, fast reload,
navigation and closure, and protocol-v2 rejection followed by one accepted view.
The administration regression covers both installation paths and six viewport
widths, including 200%/400% text with default and alternate fonts. Before the CSS
fix, alternate-font text overflowed its boxes even though their bounding boxes
stayed inside the viewport; wrapping text and giving report filters a useful
flex basis fixes the failure without suppressing horizontal overflow globally.

WebKit 26.0 was downloaded but could not launch because required host libraries
(including libicu74, libxml2, libevent, libmanette and libwoff) are unavailable.
No WebKit pass is claimed. Mobile devices, background suspension/process kills,
the remote GitHub job and the full runtime/version matrix were not run here.
CI retains its Ubuntu 24.04 job installing all three engines and dependencies.
See [tests/browser/README.md](../../tests/browser/README.md) for reproduction.
PHP/HTTP/concurrent SQLite, design-model, Node, Python, .NET, Java 21 and Ruby
regressions passed locally; commands are in [CONTRIBUTING](../../CONTRIBUTING.md).

This gate accepts observed partial browser reach reports, not exact people,
exactly-once delivery, a lower bound or a consent exemption. PHP alone advertises
availability. WordPress has no public reach endpoints in its package and native
adapters retain capability false; their completion is future work and cannot be
activated through this revision's shared UI. Operator enablement requires the
separate legal/consent assessment and explicit acknowledgement described above.

## Accepted extension: Daily Page Reach

PHP contract revision 4/schema 5 adds optional, independently acknowledged page
reports while retaining the global metric and centralized activation. The server
stores only `daily_page_reach(day,path,reports)` with its composite primary key,
without rowid/secondary indexes. Browser SHA-256 day/path hashes never cross the
network. No visitor tokens, server fingerprints, salts, idempotency receipts or
browsing histories are introduced. Version-2 prepare/count explicitly carries
separate global/page decisions; version 1 keeps its prior meaning. Revalidation
can suppress a disabled component while committing the other in the same bounded
transaction. The unchanged IndexedDB version/store permits legacy global clients
to keep using the original key, with an independent fixed pages key for new code.

A shared page-record expiry and 512-hash limit minimize local state. Expiry uses
the remaining authoritative UTC period capped at 24 hours, subtracting work since
prepare. On next authorized access, old/expired hashes are purged. A closed-day
tombstone prevents expiration or local clock advancement from authorizing fresh
same-day claims; a newer server day can replace it. JavaScript cannot guarantee
physical deletion while closed, suspended, disabled or running an old tracker.
The explicit CMP cleanup deletes all local Reach state. Hashing guessable paths
is minimization, not anonymization; sensitive route exclusions and consent/legal
assessment remain essential. The complete contract is [specified here](../../spec/daily-reach.md).

An indexed, bounded 4,096-key day range check on new aggregate paths prevents
unbounded new-table cardinality. Existing keys need only a lookup/upsert.
First arrivals under SQLite transaction ordering fill the finite slots; capped
new paths fail closed. A bounded day-only settings list records partial days
for the dashboard, without extra analytics tables. This cap bounds only the new
Page Reach table; it does not retrofit caps onto existing pageview/dimension
metrics. Do not treat a capped period as complete. The public collector remains
forgeable, so counts remain observations rather than proof of browsers.

A synthetic SQLite growth test uses 500 safe paths across 30 days: 15,000 rows,
1,052,672 bytes (about 1.00 MiB including schema/settings), independent of repeated
report volume. Updating reports by +1,000 preserves row count. With retention 30,
16 bounded batches remove the synthetic expired rows and leave 241 reusable pages.
Physical file shrinkage is not promised. Actual size depends on path length,
SQLite page size and retained days; at retention 180 the cap permits up to 737,280
Page Reach rows, excluding transient midnight grace. No new worker or dependency
is needed. Sensitive paths or unique normalized slugs in existing metrics still
need operator exclusions and hosting/storage assessment.

Regression coverage includes same/multiple pages, 2/10 same-page tabs,
concurrent different pages, restart, normalized IDs, expiry and tombstones,
delayed/expired prepare, UTC rollover, CMP denial/withdrawal/cleanup, unavailable
storage/hash/transactions, ambiguous network loss, independent server disablement,
SQLite atomicity/locking, caps, bounded retention, audit and delete-all. Pages,
Daily statistics and Page detail compare daily counts with view counts without
joining optional dimensions or deriving global Reach. The secure hash API must
be available; otherwise Page Reach alone fails closed.

Local verification of this extension: 126 real-browser Reach scenarios passed
(63 each on Chromium 145.0.7632.6 and Firefox 146.0.1), including local read/write
faults without host-page errors. Administration regression passed both root and
nested installations, six viewport widths and 200%/400% text; table comparisons
were visually inspected on desktop/mobile. PHP 8.5/PDO SQLite checks, HTTP/API,
SQLite process concurrency, the new growth/privacy suite, Node, Python, .NET 10,
Java 21, Ruby and 20 packaging/design checks passed. CI/release workflows include
the new PHP suite. WebKit 26 still cannot launch on this host because of missing
system libraries; no pass is claimed. Mobile OS suspension/process termination,
the remote GitHub workflows and the full runtime-version matrix were not run.
The pinned Firefox CSP limitation described above remains explicitly reported.
