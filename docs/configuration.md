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

The configuration toggles are stored in the private SQLite `settings` table. Admin password hashes are also stored there, never plaintext passwords. A fresh installation and upgrade default to Strict Mode: page views and separate finite automation counters are enabled. Optional dimensions remain off unless explicitly configured. Daily Reach is unavailable and never issues a cookie. Enabling any optional dimension changes the effective profile to Extended. Visitor IDs, fingerprinting, query-string analytics, generic events, browser storage, and third-party analytics are unsupported. No external geolocation calls are made. Timezone is fixed to UTC in the current schema.

| Dimension | Default | Strict Mode | Data minimization |
|---|---:|---|---|
| Aggregate page views | On | Allowed | Normalized path and UTC day aggregate |
| Classified automation | On | Allowed, contract revision 2 | Separate safe path/day/category/family request counters, no verified identity |
| Daily Reach | Unavailable | Disabled | See the [failed design gate](decisions/daily-reach.md) |
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
