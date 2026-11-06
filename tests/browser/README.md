# Administration and Daily Reach browser regression tests

These development-only tests launch a temporary PHP server and synthetic SQLite
store and browser profiles. They never use an existing personal profile and are
not packaged with runtime artifacts. PHP/PDO SQLite and Node 22+ are required.

```sh
npm ci --prefix tests/browser
cd tests/browser
npx playwright install --with-deps chromium firefox webkit
npm test
```

The default requires all three engines; unavailable dependencies fail explicitly.
To test installed engines only, set `REACH_BROWSERS=chromium,firefox`. For an
installed Chrome use `REACH_CHROME_EXECUTABLE=/path/to/chrome` and
`REACH_BROWSERS=chromium`. CI supplies Ubuntu 24.04 browser dependencies.
`PLAYWRIGHT_BROWSERS_PATH` can select a temporary browser download directory.
On the local Ubuntu 26.04 host, fallback builds used
`PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`. WebKit verification for 1.2.0
used verified Ubuntu 24.04 libraries isolated in the temporary Playwright bundle
and system GIO modules instead of incompatible inherited Snap modules. CI uses
the supported Ubuntu 24.04 host with `--with-deps`. Engine versions/results are recorded
in the [ADR](../../docs/decisions/daily-reach.md).

`REACH_CASE_FILTER` runs one matching scenario for diagnosis.
`REACH_SCREENSHOT_DIRECTORY` optionally saves the synthetic reach dashboard panel.
No artifact is written to the source tree. The fixture clock affects only the
local test router; production collectors derive periods from actual server time.

The default fixture emits exactly
`<script defer src="/barelytics/track.js"></script>`, without a Reach attribute.
The suite checks central activation from the actual administration panel,
three same-page reloads (four views, one report/attempt and unchanged day marker),
Strict storage isolation, legacy true/false attributes and CMP denial/errors.
Transport scenarios cover missing fetch, CSP blocking, loss before/after SQL,
fast reload/navigation/closure and the protocol-v2 path-only fallback. Mobile
suspension and OS process termination are not simulated. No equivalence of mobile
reliability with beacon is claimed. The CSP case reports the pinned Firefox
146.0.1 keepalive bypass (Mozilla bug 2012984) as a browser limitation, checks
that it sends no fallback, and does not claim CSP enforcement there. Chromium
requires zero deliveries under `connect-src none`.

The suite checks application-level attempt counts separately from committed SQL
reports. It deliberately drops requests/responses and deletes storage to show
both missing and duplicate observations, not exactly-once delivery. Two/ten tabs,
popup windows and normal browser close/relaunch exercise real IndexedDB.
A normal declared User-Agent exercises the eligible path because HeadlessChrome
is intentionally classified as automation. The bot scenario overrides the HTTP
header explicitly, independently of a browser engine's navigator UA behavior.

## Administration UI

`npm test` also runs `admin.mjs`; `npm run test:admin` runs it independently.
`ADMIN_BROWSERS` selects engines (defaults to `REACH_BROWSERS`, or all three).
`REACH_CHROME_EXECUTABLE` works in both harnesses.
`ADMIN_SCREENSHOTS` sets an output folder (default
`/tmp/barelytics-admin-screenshots`). Screenshots cover all reports plus account,
audit and diagnostics, with desktop/mobile captures and overview checks at
1440, 1280, 1024, 768, 390 and 320 CSS pixels.

The fixture contains 365 UTC days across year boundaries, a 512-byte normalized
path, Unicode/encoded paths, near-identical deeply nested paths, large counts,
dimensions, automation and Daily Reach aggregates. It tests both `/barelytics/`
and `/subdirectory/barelytics/`, backward-compatible routes, newest-first dates,
pagination boundaries, page detail/focus/history, lazy requests, unsaved settings,
Strict/Extended states, audit source/configuration separation, CSS status/type,
console errors, overflow, authorization and CSRF. These fixtures are temporary and
never contain real analytics data.

Text-resize regressions force navigation open and check 200%/400% text with the
default font and a generic monospace font. Overflow diagnostics include internal
scroll sizes as well as element boxes, because oversized text can escape a box
whose own bounds still fit the viewport.

Daily Page Reach scenarios use the same normal HTML with a separate PHP setting.
They inspect bounded local hash state and exact hash-free count payloads, combined
and independent counters, multiple/concurrent pages, secure-hash/storage faults,
expiry tombstones and cleanup, delayed/expired UTC prepare responses, CMP control,
restart and network loss. The administration fixture includes synthetic retained
Page Reach rows, zero-valued observations and a partial-day flag for responsive
Pages/Daily/Page detail checks. PHP `tests/daily-page-reach.php` measures realistic
aggregate growth and bounded cleanup as well as schema, privacy and cap behavior.

WebKit screenshots in Playwright 1.58 temporarily inject a `body {}` stylesheet
for animation synchronization, which the production `style-src 'self'` correctly
blocks. The administration harness records that exact diagnostic separately
only during screenshot capture, with a budget of one per capture. Additional
diagnostics, other console errors and the same CSP message outside capture still
fail. A deliberate inline-style probe verifies
that the real policy blocks styles and that the application-error collector
observes the violation; production CSP and the browser context remain unchanged.

Mobile scenarios use a 390×844 touch viewport and Safari `Version/26.6.1` or iPhone
WebKit without a Safari token. Each checks pageview, prepare and count attempts,
IndexedDB access and both SQL aggregates for permission true/false/throw/rejection,
Strict, explicit opt-out, unavailable storage and missing Web Crypto. Cache tests use a
frozen [1.1.0 tracker](fixtures/legacy-track.js): a fresh long-lived legacy copy
continues pageviews without Reach after server replacement, and a changed URL recovers
Page Reach. The synthetic router models HTTP validation; `python3 tests/apache-cache.py`
separately verifies the real shipped Apache policy. Desktop/headless WebKit is not
physical iOS Safari or WKWebView; no device or mobile process-suspension coverage is
claimed.
