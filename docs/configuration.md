# Configuration

Server environment variables are private deployment configuration. Do not place secrets in JavaScript or commit production values.

| Option | Default | Behavior and privacy effect |
|---|---|---|
| `BARELYTICS_DATABASE_PATH` | Auto-selected | Advanced hosting override for the SQLite file. Prefer `BARELYTICS_DATA_DIRECTORY` instead. Both are optional; ordinary FTP setup uses automatic private storage selection. |
| `BARELYTICS_DATA_DIRECTORY` | Auto-selected sibling data folder, then `var/` | Optional absolute directory override for hosting-panel configuration. Must be PHP-writable and outside the document root unless Apache denial rules are effective. |
| `BARELYTICS_DATA_MODE` | `auto` | Optional `private`, `apache`, or `auto` selection. The browser installer offers Apache fallback selection. Nginx-only in-webroot storage is refused. |
| `retention_days` | `180` | Dashboard setting; allowed values are 30, 90, 180, and 365 days. Cleanup removes older daily aggregates. |
| `country_collection` | Off | Optional dashboard setting. When enabled, reads only the configured trusted country header and stores a valid two-letter code, or `XX`. It never resolves or stores an IP address. |
| `BARELYTICS_COUNTRY_HEADER` | `HTTP_CF_IPCOUNTRY` | PHP `$_SERVER` key populated by a trusted proxy/platform. Ensure the proxy overwrites client-supplied values. If not trusted, disable country collection. |
| `referrer_collection` | Off | Dashboard setting. If enabled, stores a validated hostname from explicit Extended browser protocol v2 or a native server-page integration. The beacon Referer header is ignored; see [protocol](../spec/protocol.md). |
| `browser_collection` | Off | Optional dashboard setting. Stores only a coarse browser category; never the raw User-Agent. |
| `device_collection` | Off | Optional dashboard setting. Stores coarse `Mobile`, `Tablet`, `Desktop` or `Unknown` categories; historical PHP lowercase labels remain intact. |
| `os_collection` | Off | Optional dashboard setting. Stores only a coarse operating-system category. |
| `privacy_path_exclusions` | Conservative private-route list | Dashboard setting. Matching paths are ignored before any analytics writes. |
| `bot_patterns` | Built-in list | Dashboard setting for additional case-insensitive substrings, one per line. Matches go to separate daily automation aggregates and never to ordinary page views. Patterns apply only to transient User-Agent text. |
| Bootstrap hash file | Missing before setup | The browser installer accepts a SHA-256 hash created by the local helper. Upload `setup-token.php` to the package `data/` folder, or `setup.token` to the private data directory. The setup lock invalidates it after successful setup. `BARELYTICS_SETUP_TOKEN` remains an optional server-environment override for managed deployments. |
| `BARELYTICS_SETUP_TOKEN_FILE` | Private data `setup.token`, then package `data/setup-token.php` | Optional path override for the bootstrap hash file. |
| `reset.token` file | Missing | Optional FTP-installed SHA-256 recovery hash. It changes the existing admin password only, preserves statistics, and is deleted after use. It cannot create a second account. |
| Reporting day | UTC | Fixed UTC calendar days; daily database keys use `YYYY-MM-DD` UTC. |

The configuration toggles are stored in the private SQLite `settings` table. Admin password hashes are also stored there, never plaintext passwords. A fresh installation defaults to Strict Mode; upgrades preserve acknowledged settings and default new optional settings off: page views and separate finite automation counters are enabled. Optional dimensions remain off unless explicitly configured. PHP supports optional daily browser reach reports, disabled by default; WordPress and native adapters advertise it unavailable. No analytics cookie is issued. Enabling any optional dimension changes the effective profile to Extended. Visitor IDs, fingerprinting, query-string analytics, generic events and third-party analytics are unsupported. Strict does not access browser storage; the Extended storage exceptions are the site-wide Reach day marker and separately enabled bounded, expiring Page Reach hashes. No external geolocation calls are made. Timezone is fixed to UTC in the current schema.

| Dimension | Default | Strict Mode | Data minimization |
|---|---:|---|---|
| Aggregate page views | On | Allowed | Normalized path and UTC day aggregate |
| Classified automation | On | Allowed, contract revision 2 | Separate safe path/day/category/family request counters, no verified identity |
| Daily browser reach reports | Off; PHP only | Disabled | One transactional IndexedDB day marker, no identity; [protocol and limits](../spec/daily-reach.md) |
| Daily Page Reach reports | Off; standalone PHP only | Disabled | Up to 512 local expiring day/path hashes, never sent to PHP; only server day/path/report aggregates |
| Country | Off | Disabled | Trusted server header; two-letter category only |
| Referrer hostname | Off | Disabled | Hostname only; no path or query |
| Browser | Off | Disabled | Coarse category; raw User-Agent discarded |
| Device | Off | Disabled | Coarse mobile/tablet/desktop category |
| Operating system | Off | Disabled | Coarse category |
| Query parameters | Unsupported | Disabled | Never collected |
| Custom events | Unsupported | Disabled | No generic arbitrary event API |
| Visitor ID / fingerprinting | Never | Never | Not supported |

## Browser referrer opt-in

Enable **Referrer host** with the Extended acknowledgement, then explicitly opt
the script in to browser-side hostname minimization:

```html
<script defer src="/barelytics/track.js" data-referrer="hostname"></script>
```

For an application-native collector, serve that same script locally and set
`data-endpoint="/barelytics/track"` (or the actual same-origin mount). The default
PHP endpoint remains relative to the script directory. Protocol v2 is rejected
when the server setting is off. Remove the script attribute when returning to
Strict to stop Extended transmissions. Until then, the tracker retries a rejected
v2 request once with path-only v1 so ordinary views continue. Lost responses are
not retried. Native server-page integrations
already receive the navigation Referer and should not add a second browser
collector. Use one method per page to avoid double counting.

The shared tracker is shipped at `track.js` in the npm package, at
`barelytics/track.js` in the Python package, `contentFiles/any/any/track.js` in
NuGet, `/track.js` in the Java JAR, and `lib/barelytics/track.js` in the Ruby gem.
Expose that file through the host's static-file mechanism; administration routes
remain authenticated. The PHP package serves its existing `track.js` directly.

Page detail exposes all five dimension breakdowns with the same period filter.
Site-wide reports remain available by omitting the path filter. Disabled means
collection is off now, not that historical rows were deleted. Empty enabled
reports mean no observed data. `XX`/`Unknown` mean unavailable, not a fabricated
country, browser or direct source. Legacy browser-beacon referrer rows cannot be
repaired retrospectively.

## Daily browser reach reports (PHP)

Back up and apply PHP schema migration 4 through the authenticated dashboard.
Enable **Daily browser reach reports** with the Extended acknowledgement, after
assessing terminal-storage consent/exemption and hosting obligations. This is
independent of the five dimensions; reach alone makes the profile Extended.
Set `BARELYTICS_DAILY_REACH_ALLOWED=0` for a deployment-level kill switch.

Install the normal browser tracker once. Enable or disable Daily Reach centrally
in the PHP administration panel; no Daily Reach HTML attribute is required:

```html
<script defer src="/barelytics/track.js"></script>
```

Existing pages with this unchanged script automatically participate when the
server enables reach and any required host/CMP permission is granted. Existing
`data-daily-reach="true"` remains compatible; explicit `data-daily-reach="false"`
opts an individual page out without stopping its ordinary pageview.

The tracker records ordinary page views. Initial reach support requires this
browser collector: if the page already calls PHP `Barelytics\track()`, replace
that server-page integration rather than counting the same page twice. There
is no reach-only server-page or WordPress adapter in this revision. Its normal response must
advertise enabled reach before it prepares or touches IndexedDB. Both reach
operations omit credentials and referrers, use POST bodies and no-store, and
contain no individual identifier. Keep both PHP reach endpoint files beside
`track.php`. Managed wrappers can set `data-reach-prepare` and `data-reach-count`
to their equivalent same-origin routes. When enabled and permitted, each eligible
page adds a prepare request; a transaction winner adds one count attempt. Strict adds neither. Configure proxies/CDNs to bypass
caching for both POST endpoints. No server worker, cron or new service is needed. Use HTTPS in production; IndexedDB availability remains browser policy. With
TLS termination at a trusted proxy, configure PHP’s HTTPS status correctly so
Origin validation sees the public scheme.

Ordinary pageviews now use one same-origin fetch with `keepalive: true` to read
the authorization headers; the old `true` attribute was needed only to choose
this transport instead of beacon. There is no additional configuration request
in Strict and no IndexedDB access to discover settings. Without fetch, a single
path-only beacon can still collect a pageview, without reach or browser referrer
attribution. Fetch errors never trigger a second pageview; only an explicit
protocol-v2 400 rejection permits the existing path-only fallback. Keepalive
supports navigation/reload/closure but is subject to shared browser queue quotas.
CSP must allow the collector through `connect-src`. Older browsers can have
policy enforcement bugs; the pinned test Firefox 146 has a keepalive CSP bypass
(see the [transport assessment](decisions/daily-reach.md)). CSP is not a Reach
permission switch: use the central setting and host/CMP callback. Network
interruption, browser restrictions and mobile suspension/termination can lose observations;
there is no delivery or mobile-lifecycle equivalence guarantee with beacon.

Where consent is required, define an asynchronous host/CMP callback **before**
the deferred tracker, for example:

```html
<script>
  window.BARELYTICS_REACH_PERMISSION = async () => {
    return window.myConsentManager?.audienceMeasurementAllowed === true;
  };
</script>
```

Replace that illustrative host property with the actual CMP integration. Denial,
absence of a required grant, exceptions and unavailable storage send no reach.
Without a callback, central operator enablement authorizes the optional
measurement; that is not a consent exemption determination. If a grant arrives
after this page's denied attempt, measurement resumes on a later page load; no
retry loop is started.

On withdrawal return false from the callback and, if required, explicitly call
`await window.BarelyticsDailyReach?.clear()`. This deletes the analytics database
without opening/reading it. Disable the server setting or return to Strict;
no HTML change is needed.
Strict responses do not authorize reach preparation or storage. The cleanup API
remains available without automatically opening or reading IndexedDB. Previously
authorized in-flight work can finish a local claim; count checks current server policy again and refuses
new writes after disablement. A server cannot erase an inactive browser's state.
An intact marker suppresses another attempt after same-day re-enablement.

IndexedDB has no automatic midnight expiry. One origin-wide fixed-key `{day}`
record is overwritten by a later authoritative UTC day; no pages or histories
are stored locally. Manual clearing, eviction, private contexts or separate
profiles can create additional reports. Initial support assumes one collector
per origin. Review the dashboard's limitations and [ADR](decisions/daily-reach.md);
never treat these totals as exact people, all unique visitors or a conversion
ratio. WordPress/native adapters remain unavailable and must not enable the
client attribute for reach; their ordinary analytics remain supported.

## Daily Page Reach (standalone PHP)

After backing up, apply schema migration 5 in the authenticated administration
panel. Enable **Daily Page Reach — estimated distinct browsers per page and day**
with Extended acknowledgement and the required consent/exemption assessment.
No page HTML changes are needed when the normal browser tracker is installed.
Site-wide Daily Reach and Page Reach have independent switches; either uses the
existing CMP callback and both obey `BARELYTICS_DAILY_REACH_ALLOWED=0`. Explicit
`data-daily-reach="false"` excludes the page from both. Strict stops automatic
storage access and reach requests. PHP-only `track()` calls cannot deduplicate
browsers; choose the normal browser tracker without also counting server-side.

Pageviews count loads. Site-wide Reach counts first daily site reports. Page
Reach counts first daily reports for each normalized page. For one browser,
three loads of `/`, two of `/article-a` and one of `/contacts` can yield six
pageviews, one site report and one page report on each of the three pages.
Period Page Reach totals sum daily reports; neither they nor summed page totals
are site-wide unique browsers/people. Do not infer conversion percentages.

Before enabling, exclude sensitive or personal routes using **Private path
exclusions**. The existing normalizer removes common long identifiers and rejects
email/query/fragment/ambiguous paths, but cannot recognize every person's name
or high-entropy short slug. No extra geographic/browser/referrer breakdown is
attached to Page Reach. Missing permission, storage, Web Crypto/HTTPS or network
support can miss observations. Clearing/eviction, private contexts, multiple
profiles/devices and forged public requests can inflate them; shared browsers
can combine people. No universal consent exemption is claimed.

Only the browser hashes `UTC day + newline + server-normalized path` with
SHA-256. IndexedDB stores one compact current-day record with at most 512 hashes,
expiry timestamp and a closed-day flag; no raw paths or user identifiers. Hashes
are never sent to PHP and can still be guessed from known paths. They expire no
later than 24 hours after creation and within the prepared UTC period. Subsequent
authorized storage access lazily purges them, including global-only access. A
closed/inactive browser cannot physically erase data; Strict and denied
permission do not open storage merely to clear it. Use
`await window.BarelyticsDailyReach?.clear()` explicitly for consent withdrawal.
Expiry keeps a day tombstone so a clock jump cannot reopen same-day counting.
At the local 512-page limit, new page reports stop without discarding markers;
pageviews and site-wide Reach continue.

The PHP server stores only `(day, normalizedPath, reportCount)` historical
aggregates. It accepts at most 4,096 new normalized page paths per UTC day;
existing page aggregates can continue incrementing. The dashboard labels periods
including capped days as **Partial data**. This does not correct other sources
of missing observations. One existing prepare request serves both metrics; a
single combined count updates both in one transaction when the local decisions
win. Later new-page reports update only Page Reach. Ambiguous failures never
retry. Legacy global-only tracker requests retain version-1 semantics.

Pages, Daily statistics and Page detail show both metrics, including retained
reports while collection is disabled. **No observed reports** means unavailable
observations for that selection; no historical values are backfilled. Genuine
stored zeroes remain zero. Historical server aggregates follow the configured
30/90/180/365-day retention, not the 24-hour browser expiry. Cleanup is bounded,
resumes on later web requests without cron, and delete-all includes both reach
metrics. SQLite reuses freed pages; deletion does not promise a smaller file.
