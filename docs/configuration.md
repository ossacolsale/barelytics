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
| `referrer_collection` | Off | Dashboard setting. If enabled, stores only the validated hostname from the browser Referer header. |
| `browser_collection` | Off | Optional dashboard setting. Stores only a coarse browser category; never the raw User-Agent. |
| `device_collection` | Off | Optional dashboard setting. Stores only `mobile`, `tablet`, or `desktop`. |
| `os_collection` | Off | Optional dashboard setting. Stores only a coarse operating-system category. |
| `privacy_path_exclusions` | Conservative private-route list | Dashboard setting. Matching paths are ignored before any analytics writes. |
| `bot_patterns` | Built-in list | Dashboard setting for additional case-insensitive substrings, one per line. Matched requests are discarded before counting. Patterns apply only to transient User-Agent text. |
| Bootstrap hash file | Missing before setup | The browser installer accepts a SHA-256 hash created by the local helper. Upload `setup-token.php` to the package `data/` folder, or `setup.token` to the private data directory. The setup lock invalidates it after successful setup. `BARELYTICS_SETUP_TOKEN` remains an optional server-environment override for managed deployments. |
| `BARELYTICS_SETUP_TOKEN_FILE` | Private data `setup.token`, then package `data/setup-token.php` | Optional path override for the bootstrap hash file. |
| `reset.token` file | Missing | Optional FTP-installed SHA-256 recovery hash. It changes the existing admin password only, preserves statistics, and is deleted after use. It cannot create a second account. |
| `analytics_timezone` | UTC | Fixed to UTC in version 1; daily database keys use `YYYY-MM-DD` UTC. |

The configuration toggles are stored in the private SQLite `settings` table. Admin password hashes are also stored there, never plaintext passwords. A fresh installation and upgrade default to Strict Mode: only aggregate page views are enabled. Enabling any optional dimension changes the effective profile to Extended. Visitor IDs, fingerprinting, query-string analytics, generic events, browser storage, and third-party analytics are unsupported. No external geolocation calls are made. Timezone is fixed to UTC in the current schema.

| Dimension | Default | Strict Mode | Data minimization |
|---|---:|---|---|
| Aggregate page views | On | Allowed | Normalized path and UTC day aggregate |
| Country | Off | Disabled | Trusted server header; two-letter category only |
| Referrer hostname | Off | Disabled | Hostname only; no path or query |
| Browser | Off | Disabled | Coarse category; raw User-Agent discarded |
| Device | Off | Disabled | Coarse mobile/tablet/desktop category |
| Operating system | Off | Disabled | Coarse category |
| Query parameters | Unsupported | Disabled | Never collected |
| Custom events | Unsupported | Disabled | No generic arbitrary event API |
| Visitor ID / fingerprinting | Never | Never | Not supported |
