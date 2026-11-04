# Barelytics collector protocol

Contract version: `1`. The browser endpoint is same-origin and receives one JSON object: `{"path":"/article"}`. Its body limit is 8 KiB. A successful accepted request returns `204` with no body. Invalid JSON or a rejected path returns `400`; a body over the limit returns `413`; unsupported methods return `405` and an `Allow` header. Internal analytics failures must not turn a host page into an application failure.

Require `Content-Type: application/json` (case-insensitive, optional parameters);
missing or other media types return `415`. This corrects the undocumented PHP
plain-text extension. Check method, declared size, media type, then bounded actual
bytes and UTF-8 JSON. All collector responses use `Cache-Control: no-store`; no
collector response creates an administrator session or an analytics cookie.

Strict payloads contain exactly one property, `path`, whose value is an origin-form path. Ports must reject extra fields rather than accept identity or custom metadata accidentally. The collector never reads a client-supplied IP, user-agent, referrer, country, visitor/session identifier, event, query string, or fragment. The server may inspect request headers transiently for bot classification and explicitly enabled dimensions.

The browser script posts only `path`, uses a same-origin URL, does not persist browser state, and must fail silently. The endpoint may be mounted at a configurable base path; `/barelytics/track` is the conventional PHP/static-site URL. Framework adapters may expose a native server-side `trackPageView(path)` call that enters the same validation and aggregation pipeline.

## Opt-in Extended referrer protocol, version 2

Version 1 remains exactly `{"path":"/article"}`. It never derives navigation
attribution from the beacon request's HTTP Referer. The optional browser protocol
is exactly `{"version":2,"path":"/article","referrer_host":"source.example"}`;
`referrer_host` may instead be `null` when missing or withheld. No other fields,
URLs or internal referrer paths are accepted. Both the script's explicit
`data-referrer="hostname"` attribute and the server's acknowledged
`referrer_collection` setting must be enabled. A v2 request to a Strict collector
is rejected with `400`, without a write. Failure to load settings/storage is an
analytics failure and returns `204` without collecting.

The Extended tracker uses fetch to observe rejection: on an explicit `400` it
may retry once with only `path` through v1, preserving ordinary page views after
a server-side Strict reset. It never retries a lost response or other status.
Remove the explicit script attribute to stop Extended transmissions entirely.

Before transmission the script reads `document.referrer` only in that explicit
mode, parses HTTP(S), rejects credentials and IP literals, lowercases the DNS
hostname and strips one trailing dot. It transmits no URL, query, fragment, port
or path. The server independently validates a canonical ASCII hostname of at
most 253 bytes with DNS labels of at most 63 characters; numeric/IP-only names
are rejected. Same-origin sources may contribute only their hostname; internal
paths are deliberately not collected. Strict script requests remain path-only.

Native server-page integrations may still accept a real navigation referrer via
their existing in-process API and minimize it to a hostname. These APIs must not
be passed the beacon's Referer header. Old browser collectors did not reliably
attribute navigation sources; retained historical hostname rows cannot be
corrected retrospectively and are not evidence of a known acquisition source.

The optional PHP reach capability uses the separate operations in
[daily-reach.md](daily-reach.md). Ordinary collector payloads/statuses remain
unchanged; eligible enabled PHP responses additionally advertise
`Barelytics-Reach-Enabled: 1` and `Barelytics-Reach-Path` (percent-encoded safe
normalized path) for explicitly opted-in clients.
