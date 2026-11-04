# Administration HTTP contract, version 1

All routes are relative to the configured admin base path. JSON responses use `{ "ok": true, "data": ... }`; failures use `{ "ok": false, "data": null, "error": { "code": "...", "message": "..." } }`. Errors must be generic and must not expose SQL, stack traces, paths, or secrets. Responses containing admin state use `Cache-Control: no-store`.

Every request except the HTML and static asset routes must pass the host authorization callback. Cookie-authenticated POST requests must also pass the host CSRF callback. The standalone PHP adapter retains its existing password, session, and CSRF checks.

| Method and route | Meaning |
| --- | --- |
| `GET /api/session` | Authenticated state, CSRF token where needed, capabilities, retention |
| `GET /api/dashboard?period=&bucket=` | Totals, active paths, daily average, timeline, top paths |
| `GET /api/reach?period=` | PHP-only site-wide received reach total and UTC daily timeline; current enablement separate from historical rows |
| `GET /api/automation?period=&bucket=&page=&per_page=` | Observed automation total, timeline, finite categories/families and paginated path totals; identities are unverified |
| `GET /api/pages?period=&page=&per_page=` | Paginated page totals |
| `GET /api/daily?period=&page=&per_page=` | Paginated UTC day × path totals |
| `GET /api/page?path=&period=&bucket=` | One normalized path's total and timeline |
| `GET /api/dimensions?dimension=&period=&page=&per_page=&path=` | Dimension state and grouped totals, optionally filtered to one normalized page; disabled dimensions return `enabled: false` and no rows |
| `GET /api/privacy` | Effective settings, retention and profile |
| `POST /api/privacy` | Save settings; enabling dimensions requires `confirmation: "yes"` |
| `POST /api/strict` | Disable optional dimensions |
| `POST /api/cleanup` | Run bounded retention cleanup |
| `POST /api/delete-all` | Delete all aggregate tables; requires `confirmation: "DELETE"` |
| `POST /api/migrate` | Apply pending retry-safe schema migrations where the runtime exposes manual migration |
| `GET /api/system` | Runtime and schema/migration state without private paths |
| `GET /api/audit` | Privacy audit report |
| `GET /api/integration` | Runtime integration instructions and optional snippets |
| `POST /api/logout` | Log out only when supported by standalone authentication |

Period values are 7, 30, 90, 180, or 365 days and must not exceed the configured retention. Buckets are `day`, `week`, or `month`. Pagination is bounded to 50 rows per page maximum 10,000 pages. Path inputs must pass the same normalization contract as tracking. Unknown dimensions, malformed values, unsupported actions, and invalid confirmations are rejected. Delete and settings mutation responses include refreshed effective configuration or require the UI to refetch it.

Adapters may use framework-specific routes and CSRF transport, but must preserve these meanings and equivalent normalized data. Until a capability is implemented, its capability flag must be false and the UI must display an unavailable state rather than fabricate data.

Omitting `path` preserves site-wide reporting. Supplying an invalid path returns
`400`, even for a disabled dimension. Disabled state describes current
collection; history is retained. Enabled reports with no rows mean no observed
data, not a direct visit or a zero-valued inferred dimension. Country `XX` and
category `Unknown` mean unavailable/unknown (legacy `XX` can also reflect Strict
collection). No historical dimensions are backfilled. Audits expose
`historical_dimensions` separately from current settings.

PHP privacy saves accept boolean `daily_reach_enabled`, independently acknowledged
as Extended; `POST /api/strict` disables it and delete-all includes its aggregates.
The UI must check `daily_reach_available` before offering enablement or calling
`reach`; unsupported adapters keep that capability false.

The shared administration presents date-based tables newest-first and charts in
chronological order. It sorts complete, unpaginated timeline responses for display
without changing existing endpoint ordering. Rankings and paginated day/path
reports retain server ordering and deterministic ties. The PHP adapter clamps
paginated page/path/dimension requests to the final available page before querying
rows (page 1 for empty results). Session capabilities may include `account_url`,
`audit_url` and `diagnostics_url` for standalone PHP tools; host-authenticated
adapters omit tools they do not provide.
