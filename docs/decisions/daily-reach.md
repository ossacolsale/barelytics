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

The shared tracker requires explicit `data-daily-reach="true"`. Its normal
page-view response must additionally advertise reach enablement and a normalized
safe path before it makes
any reach request. A Strict server therefore causes no reach preparation,
storage access or count, including when an old script attribute remains. The
optional asynchronous `window.BARELYTICS_REACH_PERMISSION` callback must resolve
to true before prepare, storage access and send; hosts needing consent must
supply it and return false until consent. Reach transport omits credentials and
referrers. Ordinary page views remain independent of reach failures.

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
| Strict unchanged | No opt-in or a disabled server produces no prepare/count or IndexedDB access; reset also rejects in-flight count |
| Consistent UTC boundaries | Server-clock fixtures, new day, previous-day +119s accepted on its original day, +120s rejected; invalid/future/stale keys rejected |
| Honest administration | Real login/dashboard/reset tests and visual inspection show received reports, limitations, current disablement and retained history |

Actual browser configurations: Google Chrome 156.0.8078.47 and Playwright Firefox
146.0.1 on Linux, 22 scenarios each. Firefox used the Ubuntu 24.04 fallback build
on Ubuntu 26.04. Concurrency and restart use unmodified IndexedDB; denial, quota and missing/aborted
transaction cases use controlled fault injection in the real engines. The
harness uses an ordinary declared UA for eligible traffic;
a bot test supplies a synthetic bot header explicitly. Chrome headless's own UA
is correctly classified as automation by the existing rules.

WebKit 26.0 was downloaded but could not launch because required host libraries
(including libicu74, libxml2 and libmanette) are unavailable. No WebKit pass is
claimed. CI includes an Ubuntu 24.04 job installing all three engines and their
dependencies; that remote job and the full runtime/version matrix were not run
here. See [tests/browser/README.md](../../tests/browser/README.md) for reproduction.
PHP/HTTP/concurrent SQLite, nine design-model tests, existing native regression
suites and local packaging also passed; commands are in the [quality review](../quality-review.md).

This gate accepts observed partial browser reach reports, not exact people,
exactly-once delivery, a lower bound or a consent exemption. PHP alone advertises
availability. WordPress has no public reach endpoints in its package and native
adapters retain capability false; their completion is future work and cannot be
activated through this revision's shared UI. Operator enablement requires the
separate legal/consent assessment and explicit acknowledgement described above.
