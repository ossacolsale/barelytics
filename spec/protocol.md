# Barelytics collector protocol

Contract version: `1`. The browser endpoint is same-origin and receives one JSON object: `{"path":"/article"}`. Its body limit is 8 KiB. A successful accepted request returns `204` with no body. Invalid JSON or a rejected path returns `400`; a body over the limit returns `413`; unsupported methods return `405` and an `Allow` header. Internal analytics failures must not turn a host page into an application failure.

Strict payloads contain exactly one property, `path`, whose value is an origin-form path. Ports must reject extra fields rather than accept identity or custom metadata accidentally. The collector never reads a client-supplied IP, user-agent, referrer, country, visitor/session identifier, event, query string, or fragment. The server may inspect request headers transiently for bot classification and explicitly enabled dimensions.

The browser script posts only `path`, uses a same-origin URL, does not persist browser state, and must fail silently. The endpoint may be mounted at a configurable base path; `/barelytics/track` is the conventional PHP/static-site URL. Framework adapters may expose a native server-side `trackPageView(path)` call that enters the same validation and aggregation pipeline.
