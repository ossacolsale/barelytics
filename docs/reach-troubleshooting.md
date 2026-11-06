# Diagnosing missing Daily Page Reach (standalone PHP)

Pageviews, site-wide Daily Reach and Daily Page Reach are independent aggregates.
Several eligible pageviews with no Reach report are allowed by the
[measurement contract](../spec/daily-reach.md). Neither Reach metric counts
people exactly. Never increment Reach on storage failure or use pageviews as a
substitute for missing reports.

## What the November 6 observation establishes

The supplied production observation describes five `track.php` POSTs returning
204, one visible HTML GET and no matching prepare/count requests in the available
logs. Another browser successfully measured the same page. This supports
investigating the **pre-prepare** stage; it does not establish a broken path,
schema or deduplication algorithm. A collector 204 is deliberately silent even
on exclusions, declared automation or contained database failure; it alone does
not establish an ordinary pageview write. A Referer is not the JSON payload path.

Confirmed from execution analysis:

- `track.php` authorizes Reach only after a successful ordinary pageview write,
  when the relevant server setting is enabled. Strict, the operator kill switch,
  excluded paths and classified automation suppress authorization. Collector
  failures can also leave a 204 without authorization headers.
- The current tracker requires an inspectable 204 and at least one Reach enable
  header, a valid encoded safe path, no explicit client opt-out and CMP permission
  before prepare. Missing fetch uses a pageview-only beacon. Rejected/failed
  pageview fetches do not cause an ambiguous retry.
- SHA-256 and IndexedDB occur **after** successful prepare validation and renewed
  permission. Their failure cannot explain an absent prepare in this tracker.
- The shipped tracker previously had no dedicated Apache cache policy. The
  historical 1.1.0 tracker sends pageviews by beacon and never prepares Reach.
  Intermediate global-only trackers cannot measure Page Reach; versions requiring
  HTML opt-in cannot be enabled solely through today's central setting.
- Both supplied iPhone UA shapes remain presumed browser declarations, including
  `Version/26.6.1` and a WebKit UA without Safari/Version tokens. No Safari-version
  test controls the Reach path.

A cached obsolete script is a high-priority, reproducible hypothesis, **not a
confirmed cause of these production requests**. Other hypotheses are CMP denial,
throw/rejection or an unresolved permission promise, `data-daily-reach="false"`,
missing/stripped authorization headers, blocking extensions/CSP/network policy,
mobile interruption, or a script that posts directly without executing Reach.
Different UA strings and a shared IPv4 prefix cannot associate these requests
with one device/person. The available HTML GETs do not inventory cache use or
all navigation. Safari-like UAs can be forged; repeated requests or missing Reach
do not verify automation. No original browser, response headers, executed script
or CMP state was available to establish which hypothesis occurred.

## Local browser inspection

Use your own explicitly chosen debugging session. Open developer tools (Safari
Web Inspector on an attached iPhone where available), enable Network recording
and reload once. This produces the ordinary pageview attempt. Do not export HARs
or copy complete headers, personal paths or CMP internals into reports. No
production diagnostic mode, new network event, identifier or diagnostic SQLite
row is needed. Inspection does not require opening or clearing analytics storage.

1. Check the script element and the **executed** `track.js` source in Sources.
   Confirm it recognizes `Barelytics-Page-Reach-Enabled` and retains the permission
   checks. Inspect cache/source indicators; viewing a fresh URL in another tab
   does not prove which copy the original page executed. Check for duplicate
   scripts or custom integrations. The normal administration snippet is:

   ```html
   <script defer src="/barelytics/track.js"></script>
   ```

2. Inspect the actual pageview response: 204, `Barelytics-Page-Reach-Enabled: 1`
   (page), `Barelytics-Reach-Enabled: 1` (global), and percent-encoded
   `Barelytics-Reach-Path`. Each enable header is independent. Missing headers
   are **ambiguous**, not proof of `REACH_DISABLED_BY_SERVER` or automation.
   Check effective PHP Privacy settings, kill switch and exclusions; compare
   origin/proxy headers if necessary. Do not send extra synthetic POSTs to the
   production collector just to probe it: they can increment aggregates.
3. In Sources, set a breakpoint at `permission()` and reload in this local
   session. Step through the callback result inside its existing try/catch:
   exactly `true` allows progress; false, wrong type, throw or rejection denies;
   a pending promise delays progress indefinitely. A breakpoint at the start of
   `reportReach` distinguishes this from a missing authorization response. Do
   not invoke the CMP callback separately, replace it, grant consent for another
   client, or log its objects. Remove breakpoints when finished.

## Decision tree

| Observed stage | Checks and interpretation |
| --- | --- |
| Pageviews increase, no prepare | Confirm current executed script, inspectable response headers, safe path, explicit `false` opt-out, CMP permission and same-origin endpoint overrides. Check blockers and interruption. A beacon-only/custom POST has no Reach continuation. IndexedDB and SHA-256 have not run yet. |
| Prepare exists, no count | Inspect its real status and JSON: enabled/eligible flags, contract version, safe normalized path, UTC day/time. A failed/non-200/malformed response stops processing. Recheck permission. Then inspect secure Web Crypto capability and step through hash/transaction completion in Sources. Missing/denied/blocked storage, abort, quota or timeout authorize no send. A prior day/page claim, local cap or expired same-day tombstone also suppresses a new attempt. Do not infer a specific cause merely from the missing count. |
| Count exists, aggregate does not increase | An attempt is not a committed SQL write. Inspect status: 400 includes malformed/stale period, 403 policy/eligibility/origin rejection, 429 a capped page-only report. A combined 204 can increment global only if Page Reach was disabled or capped in flight. A 204 can also contain a storage failure. Check the enabled components, normalized path, UTC report day, report filters, partial-data notices and sanitized server/database diagnostics. Do not retry counts or clear committed local claims to recover loss. |
| Multiple pageviews, one Page Reach | Expected for cooperating storage and the same normalized page/server UTC day. Site-wide Reach has its own independent gate. Neither metric should proportionally match pageviews. |
| Obsolete tracker found | Follow the cache recovery procedure below, then inspect the newly executed source and requests. A current application version in administration does not prove every browser has the current tracker. |

Unavailable capability (for example missing Web Crypto), blocked measurement
(for example denied permission/storage) and declared or suspected automation are
different explanations. Only an observed branch justifies a diagnosis. Known bot
tokens are declared identities; generic automation matches are heuristic. Browser
and unknown classifications do not verify human traffic. The public collector
accepts forged requests. Existing Origin/Fetch Metadata defense remains limited
and preserves clients without those optional headers; no new pageview-origin
requirement or identity mechanism is introduced.

## Tracker cache policy and recovery

Ship `track.js`, PHP files and the packaged `.htaccess` together via FTP/SFTP,
preserving private data and merging host-specific protection rules. For **only**
`track.js`, Apache with `mod_headers` and permitted overrides sends
`Cache-Control: no-cache, must-revalidate`; scoped `ExpiresActive Off` prevents
inherited `mod_expires` freshness. Validators (ETag/Last-Modified) are retained,
so an unchanged script can return 304. Other assets retain the hosting policy.
This policy requires validation before reuse, with no build/CDN requirement and
no recurring HTML edits. See Apache's [header](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
and [expiry](https://httpd.apache.org/docs/2.4/mod/mod_expires.html) directives.

Verify the delivered header at the public URL, including through any proxy/CDN,
and on conditional responses. `.htaccess` cannot control an Nginx static-file
handler, disabled `mod_headers`, disallowed overrides, a service worker or a CDN
rule that overrides origin caching. Ask the hosting administrator to apply the
same **exact tracker-path** policy in that serving layer and to retain validators.
Do not disable caching site-wide. An FTP upload can preserve timestamps; if a
host's ETag and Last-Modified also remain unchanged despite changed bytes,
conditional validation can still return an obsolete copy. Ensure changed files
receive changed validators, and verify their actual contents.

New headers **cannot invalidate an already cached copy with an unexpired long
freshness lifetime**. For the administrator's browser, use developer tools'
Disable cache and a reload, then verify the executed source. Purge a controlled
CDN/proxy cache if present, but that alone does not clear browser caches. Avoid
clearing IndexedDB to repair script caching: it resets claims and can duplicate
Reach observations.

If immediate recovery for other clients is required, change the asset URL, for
example `/barelytics/track.js?v=1.2.1`, ensuring proxies include the query in their
cache key (otherwise use a distinct filename and an equivalent cache rule).
Preserve script attributes and CMP gating. This requires updating every affected
HTML page or its shared template, and delivering updated HTML through its own
caches; on hundreds of static pages it is a real deployment cost. Otherwise wait
for existing freshness to expire. A server cannot force inactive or uncontrolled
clients to execute new JavaScript. Subsequent deployments can keep the ordinary
unversioned snippet once its revalidation policy is effective.

## Verification limits

[Browser tests](../tests/browser/README.md) run Chromium, Firefox and Playwright
WebKit with synthetic storage, mobile viewport/touch and both iPhone UA shapes.
They separate pageview/prepare/count attempts, IndexedDB access and SQL results,
including permission true/false/errors, Strict, opt-out, storage/hash failures,
fresh/conditional tracker loads, a genuinely cached 1.1.0 beacon tracker and URL
recovery. The independent Apache test checks actual 200/304 cache headers and
scope against inherited hosting defaults, including absent optional modules.
Desktop/headless WebKit and a WebView-like UA do not reproduce all iOS Safari or
WKWebView storage, suspension or OS lifecycle behavior. No physical iOS device
or the affected production client was tested.
