# Daily Reach real-browser regression tests

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
On the local Ubuntu 26.04 host, Firefox ran with
`PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`; WebKit lacked required host
libraries and was not tested successfully. Engine versions/results are recorded
in the [ADR](../../docs/decisions/daily-reach.md).

`REACH_CASE_FILTER` runs one matching scenario for diagnosis.
`REACH_SCREENSHOT_DIRECTORY` optionally saves the synthetic reach dashboard panel.
No artifact is written to the source tree. The fixture clock affects only the
local test router; production collectors derive periods from actual server time.

The suite checks application-level attempt counts separately from committed SQL
reports. It deliberately drops requests/responses and deletes storage to show
both missing and duplicate observations, not exactly-once delivery. Two/ten tabs,
popup windows and normal browser close/relaunch exercise real IndexedDB.
A normal declared User-Agent exercises the eligible path because HeadlessChrome
is intentionally classified as automation. The bot scenario overrides the HTTP
header explicitly, independently of a browser engine's navigator UA behavior.
