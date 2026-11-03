# ADR: optional Daily Reach

Date: 2026-11-03. Status: design evaluated; production implementation deferred
because the measurement acceptance gate is not met. Strict remains the default;
Daily Reach is unavailable, with no cookie, setting or new reach table.

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
any actual browser data (five passing tests; see the [quality review](../quality-review.md)). There is no operator action
that enables Daily Reach in this revision; leaving Strict requires a separate,
reviewed implementation. This is the permitted failed-gate outcome, not a
partially deployed runtime capability.
