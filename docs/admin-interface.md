# Administration interface

The authenticated PHP entry point is `barelytics/admin.php`. It serves the same
zero-build HTML/CSS/JavaScript dashboard as the native runtime adapters.
`admin.php?ui=1` remains an alias. Existing `view`, `period`, `bucket`, `path`,
`dimension` and `page_num` links are mapped to the shared navigation. Password
sign-in, CSRF checks and the explicit database-upgrade gate remain server-side.

The sidebar groups analytics and administration. A collapsible menu serves narrow
screens. Page links open a dedicated detail view, preserving the reporting period;
its selected dimension loads on demand. The URL fragment contains the section and
filters, so links can be copied and browser Back/Forward works. Account,
diagnostics and full audit/export tools use the same design system; their return
link carries the dashboard context without persistent browser storage.

Only session/configuration and the current report load initially. Report refreshes
do not reset unsaved privacy settings. Navigation cancels obsolete report requests;
failed sections expose a retry control. Administration responses remain no-store.
PHP asset links use local relative URLs with content hashes for cache invalidation,
including installations below a subdirectory. No remote fonts, frameworks or
telemetry are required. Upload the complete `public/barelytics` directory by FTP,
including `src/AdminPresentation.php` and `admin-ui/`.

Date tables display newest UTC dates first. Charts use chronological order from
left to right; rankings use descending counts and the server's deterministic
secondary ordering. Existing API timeline ordering is unchanged. PHP paginated
reports clamp the requested page before applying the SQL offset. Paths wrap inside
tables; overview paths are limited to two visible lines and retain their full
accessible link text and title. Page detail exposes the full path and a copy action.

Without JavaScript, `admin.php?fallback=1` provides a small summary and links to the
server-rendered account, audit and diagnostic tools. It is not a second dashboard.

## Development verification

Run the required PHP checks in [CONTRIBUTING.md](../CONTRIBUTING.md), stage shared
assets with `sh scripts/stage-admin-ui.sh`, and run native suites. The
[administration browser harness](../tests/browser/README.md) builds a temporary
synthetic SQLite database and tests root and nested mounts, section navigation,
long paths, date order, pagination, privacy, audit and responsive layouts. Screenshots
are development artifacts outside the source tree and must be inspected visually.

Daily Reach is controlled centrally by **Daily browser reach reports (Extended)**
in PHP Privacy settings. The ordinary browser integration snippet needs no
`data-daily-reach="true"` attribute. Required host/CMP permission remains separate
from administrator enablement; Strict prevents analytics browser-storage access.
PHP-only server tracking cannot perform browser reach deduplication. See
[configuration](configuration.md#daily-browser-reach-reports-php) for compatibility,
consent assessment and delivery/storage limitations. Other adapters keep the
setting unavailable.

Standalone PHP also offers a separate default-off **Daily Page Reach** setting.
Pages, Daily statistics and Page detail compare view counts and received daily
page reports, with retained/disabled/no-data states and explicit capped-period
notices. The site-wide Reach report remains independent. Period Page Reach
values sum daily reports rather than deduplicating across days. See the
[Page Reach configuration](configuration.md#daily-page-reach-standalone-php).
