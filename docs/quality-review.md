# Quality and privacy review

Review date: 2026-11-03. Scope: the PHP core and WordPress adapter, Node.js,
Python, .NET, Java, Ruby, shared administration, and release assembly. This is
technical evidence, not a compliance certificate. No production data is used.

## Phase A: verified findings, now corrected

| Severity | Verified finding | Evidence and decision |
| --- | --- | --- |
| High | Encoded private paths can bypass exclusions; PHP `/admin/*` does not exclude `/admin` itself. | `normalizePath` and `pathIsExcluded` in `public/barelytics/src/Barelytics.php`; equivalent normalizers in `packages/*`. Reject ambiguous separators, dot segments and nested encoding; compare exclusions against decoded safe paths. Preserve normalized public path spelling. |
| High | Python shares a connection across threads without serializing transactions; a failed request can roll back another request's transaction. .NET opens transactions outside its failure handler. | `track_page_view` in `packages/python/barelytics/__init__.py`; `TrackPageView` in `packages/dotnet/Barelytics/BarelyticsStore.cs`. Serialize connection use and contain failures, including configuration reads and lock acquisition. |
| High | The WordPress builder recursively copies local core storage and updates an existing ZIP, allowing private or stale files into an installable archive. | `scripts/build-wordpress-plugin.php`. Replace traversal with an explicit source manifest and build a fresh archive; reject linked source files. Synthetic package tests cover databases, tokens and stale entries. |
| Medium | PHP collector accepts extra JSON properties and uses 2 KiB instead of the contracted 8 KiB; runtimes differ on media types and cache headers. | `public/barelytics/track.php`, native collector adapters, `spec/protocol.md`. Require JSON, exact permitted fields, bounded reads, UTF-8, no-store on every collector response. Preserve the documented minimal v1 payload. |
| Medium | One event can cross UTC midnight between aggregate writes. | `recordPageview` in the PHP core; `TrackPageView` in .NET; `trackPageView` in Java. Capture the UTC day once before storage work. |
| Medium | Automatic retention depends on FastCGI in PHP and is absent from WordPress and native collection. A full batch is marked complete for a whole day. | `track.php`, `track()` and `runScheduledCleanupIfDue()` in the PHP core; WordPress `template_redirect`; native track methods. Run one bounded opportunistic batch, contain failures and resume outstanding batches on subsequent requests. |
| Medium | PHP audit treats retained Extended aggregates as a current Strict violation. | `schemaAudit` in the PHP core versus `spec/privacy-model.md`. Report historical data separately from effective collection; retain structural failures. |
| Medium | Browser beacons cannot infer navigation source from their HTTP Referer. | `countRequest`, `track.js`, native HTTP collectors. Introduce an explicitly opt-in, versioned, hostname-only Extended payload; keep v1 path-only and ignore beacon Referer. Native server-page integrations may still minimize their actual navigation Referer. |
| Low | Node custom path exclusions reference a variable outside its scope. Python/Ruby constructors overwrite persisted operator settings on reopen. | Node `#migrate`, Python `__init__`, Ruby `initialize`. Preserve saved configuration unless an option is explicitly supplied. |

## Other mandatory investigations

PHP uses password hashing, strict administrator sessions and CSRF checks; native
administration delegates authorization and CSRF to the host. WordPress checks
capabilities and nonces. Existing tests cover these boundaries. Keep collectors
independent of administrator sessions. Do not add IP-based rate-limit keys.

The public collector is forgeable: neither Origin nor User-Agent authenticates a
client. Body/path limits, bounded SQLite waits, exclusions and parameterized
upserts limit individual operations, not total requests or path cardinality.
Operators need web-server request/body/time limits and storage quotas; disable
request-body/Cookie logging. No rate limiting based on visitor fingerprints is
proposed. JavaScript observation excludes crawlers that never run JavaScript.

Migrations use repeatable table creation and version markers. Extend storage
additively without rewriting legacy totals. PHP migration version and changes
must commit together. Interrupted aggregate transactions must leave no partial
dimension rows. SQLite busy timeouts bound contention; failed analytics may be
dropped, never retried without a bound or allowed to break the host application.

Storage confidentiality remains deployment-dependent. The existing WordPress
private-directory and legacy-snapshot checks must remain intact. PHP installer
checks and native file permissions cannot prove an Nginx/static-server deny
rule. Release archives must exclude databases, credentials and temporary files;
verify the current archive validator and WordPress builder locally. No release,
external service, or Dependabot PR is changed by this work.

## Reviewable implementation sequence

1. Correct current-contract failures with regression tests, preserving UTC days.
2. Revise the bot/storage/privacy contracts before adding daily automation
   counters and a shared classification rule set. Store finite categories and
   recognized families, never raw headers. Default Strict stays cookie-free.
3. Add optional path filters to existing dimension reports and page detail;
   introduce browser-minimized referrer protocol v2 without altering v1.
4. Validate [Daily Reach ADR](decisions/daily-reach.md). A failed measurement
   gate means no runtime cookie feature, flag, table or activation UI is shipped.

## Completed increments and compatibility

**A — correctness and existing-contract fixes.** All six stores and their
collectors now share bounded protocol/path vectors. Aggregate writes capture one
UTC event day and roll back together; shared connections are serialized where
needed. Opportunistic retention runs without FastCGI or cron, in batches of at
most 1,000 rows per aggregate table, resuming pending work on later eligible
requests. WordPress activation initializes fresh storage; callback tests preserve
private storage safeguards. Audits distinguish disabled historical dimensions
from current collection and validate approved table/column structures. Existing
authentication and CSRF controls remain covered by integration tests. These
corrections add no identifying abuse controls or background service.

**B — automation.** [Contract revision 2](../spec/privacy-model.md) explicitly
permits separate daily safe-path/category/family counters in Strict. Rules in
[`spec/automation-rules.json`](../spec/automation-rules.json) generate equivalent
classifiers for PHP, Node, Python, .NET, Java and Ruby. Bounded ASCII token
boundaries replace broad default substring matching; custom literal patterns
remain an operator choice. Unknown clients remain eligible for ordinary views;
recognized and uncertain automation contributes only to `automation_daily`.
The shared administration shows totals, trends, categories, families and paged
paths. Declarations are never verified identities. No raw UA or transport data
is persisted; no DNS/IP verification or external request is introduced.

**C — page dimensions and referrers.** Existing dimension endpoints accept an
optional normalized `path`, preserving site-wide queries. The shared page detail
shows all five dimensions with pagination and explicit disabled/missing/unknown
states; standalone PHP includes filtered report links. The same packaged tracker
supports opt-in protocol v2 (`data-referrer="hostname"`), minimizing navigation
referrers before transmission. Both browser and server settings must permit it.
Strict remains path-only; beacon HTTP Referer is ignored. Internal paths were
deliberately omitted: hostnames suffice without an additional route disclosure.
After a server Strict reset, an explicit v2 rejection permits one path-only
retry; lost responses do not trigger duplicate-prone retries.

**D — architecture and feasibility complete; production feature rejected.**
The [ADR](decisions/daily-reach.md) compares count-and-set and acknowledgement,
checks official guidance and publication status, and defines prospective day,
cookie, consent and rollback requirements. Five offline experiments verify
shared marker values, sequential/day rollover, rejection bias, concurrent/lost
responses, acknowledgement costs and Europe/Rome 23/25-hour days. The technical
gate fails. No cookie, reach table, enabling setting or activation UI exists;
all runtimes report `daily_reach_available=false`. The reach implementation
scenarios in the request are therefore inapplicable to this revision, rather
than untested production capabilities. There is no pending operator approval
that can enable them.

**Migration and rollback.** Schema 3 additively creates `automation_daily` and
records the version atomically. Synthetic v2 upgrades preserve totals, retention
and credential settings; repeated opens are idempotent and future schemas are
refused. Native stores migrate on open; PHP retains its authenticated migration
flow. No legacy views are reclassified or historical days relabelled. Retention
and delete-all include automation. Back up the private database with writes
paused before upgrade; restore matching code and database together for rollback,
as described in [UPGRADE.md](../UPGRADE.md). Do not remove tables to bypass a
version guard. Defaults preserve stored settings on reopen; .NET's existing
default-valued `RetentionDays=180` now preserves saved retention, so use
`SetRetention(180)` for an intentional reset on an existing database.

## Actual verification

All following checks passed locally on 2026-11-03 against synthetic data:

| Command (repository root unless stated) | Result |
| --- | --- |
| `php tests/run.php` | 320 checks |
| `php tests/wordpress.php` | Activation, collection, exclusions and retention callbacks |
| `php tests/sqlite-concurrency.php` | Four concurrent writers, atomic dimensions, busy-lock timeout/recovery and interrupted transaction rollback |
| `bash tests/integration.sh` | Real HTTP protocol vectors, authentication, CSRF, aggregation and retention |
| `find public/barelytics -name '*.php' -print0 \| xargs -0 -n1 php -l` | All PHP core files parse |
| `npm test --prefix packages/node` | 17 tests, including tracker VM tests |
| `PYTHONPATH=packages/python python3 -m unittest discover -s packages/python/test -v` | 8 tests, including concurrent shared-connection writes |
| `ruby -Ilib test/test_barelytics.rb` (in `packages/ruby`) | 7 tests, 307 assertions |
| `dotnet run --no-restore --project test/Barelytics.ContractTests --framework net10.0` (in `packages/dotnet`) | Contract, SQLite and ASP.NET TestServer checks |
| `mvn --batch-mode --offline test` (in `packages/java`, `JAVA_HOME` pointing to JDK 21) | 7 tests; JDK 25 also tested during package build |
| `python3 -m unittest discover -s scripts -p 'test_*.py' -v` | 16 tests, including five reach design experiments, generated-classifier parity and package exclusions |
| `python3 scripts/generate-automation.py --check` | All six generated classifiers match the rules |

Local runtimes: PHP 8.5.4, Node 22.22.1, Python 3.14.4, Ruby 3.3.8,
.NET SDK 10.0.114, JDK 21 and 25. .NET used a temporary writable
`DOTNET_CLI_HOME`; HTTP tests required local socket access. Ruby ran against the
installed minitest because the lockfile's minitest version was unavailable.
Python's `build` frontend was unavailable; wheel and source archives used pip
and the project's setuptools backend without dependency downloads.

Local packaging also passed: `sh scripts/stage-admin-ui.sh`,
`php scripts/build-wordpress-plugin.php`, `npm pack`, `python3 -m pip wheel
--no-build-isolation --no-deps .`, setuptools `build_sdist`, `dotnet pack`,
`mvn --offline package` and `gem build barelytics.gemspec` (native commands in
their package directories). A PHP ZIP was assembled from the same approved core
manifest. `validate_release_assets.validate_directory` accepted all nine files
(eight installable archives plus the Maven POM); every archive contains the
byte-identical shared tracker. Builds used temporary output/cache directories;
nothing was uploaded. PHP lint also passed for WordPress and build scripts;
`node --check` passed for both tracker and administration JavaScript.
Affected local Markdown links and `git diff --check` passed.

## Remaining assurance and measurement limits

The complete CI version matrix and a real WordPress installation were not run
locally. WordPress verification uses actual plugin callbacks under a minimal
host harness. The browser host was unavailable, so there is no visual or real
browser acceptance claim; tracker behavior was exercised in a Node VM.
Native audit reports inspect configuration and schema but do not run PHP's
on-demand storage-write self-test: this existing gap against the broad
[`spec/audit-model.md`](../spec/audit-model.md) requirement remains explicit;
file-backed transaction/failure behavior was verified by the regression suites.
Audits and static scans cannot certify anonymity, hosting logs, legal compliance
or availability under sustained abuse. Public path cardinality remains
unbounded; operators must set infrastructure quotas/limits. UA declarations can
be forged, JavaScript misses non-executing crawlers, and no visitor/session or
accurate distinct-browser inference is made. No external service or Dependabot
PR #1 was modified; no commit, push, publication or deployment was performed.
