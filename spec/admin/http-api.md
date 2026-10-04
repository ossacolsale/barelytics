# Administration HTTP contract, version 1

All routes are relative to the configured admin base path. JSON responses use `{ "ok": true, "data": ... }`; failures use `{ "ok": false, "data": null, "error": { "code": "...", "message": "..." } }`. Errors must be generic and must not expose SQL, stack traces, paths, or secrets. Responses containing admin state use `Cache-Control: no-store`.

Every request except the HTML and static asset routes must pass the host authorization callback. Cookie-authenticated POST requests must also pass the host CSRF callback. The standalone PHP adapter retains its existing password, session, and CSRF checks.

| Method and route | Meaning |
| --- | --- |
| `GET /api/session` | Authenticated state, CSRF token where needed, capabilities, retention |
| `GET /api/dashboard?period=&bucket=` | Totals, active paths, daily average, timeline, top paths |
| `GET /api/pages?period=&page=&per_page=` | Paginated page totals |
| `GET /api/daily?period=&page=&per_page=` | Paginated UTC day × path totals |
| `GET /api/page?path=&period=&bucket=` | One normalized path's total and timeline |
| `GET /api/dimensions?dimension=&period=&page=&per_page=` | Dimension state and grouped totals; disabled dimensions return `enabled: false` and no rows |
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
