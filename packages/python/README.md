# Barelytics for Python

Self-hosted, aggregate-only page-view analytics implemented with Python's standard library and SQLite. Requires Python 3.11–3.14; Django, Flask, and FastAPI are optional host frameworks, not package dependencies.

```python
from barelytics import Barelytics

analytics = Barelytics("/var/lib/my-site/barelytics")
# Call once only from a rendered HTML page handler, never from global middleware.
analytics.track_page_view(request.path, user_agent=request.headers.get("user-agent", ""))
```

`create_wsgi_app(analytics)` mounts the same-origin `/track` protocol under a WSGI router. ASGI applications can call `track_page_view` from an explicit page route or adapt the framework request to the same collector contract. Framework guides are in `docs/integrations/`.

Strict Mode is the default: normalized daily path aggregates, with optional dimensions off. No visitor/session IDs, cookies, browser storage, IP persistence, fingerprinting, event rows, or third-party analytics. `update_privacy` requires `confirmation=True` before enabling dimensions; `return_to_strict_mode` disables all. Use one browser or server-side method per page response.

The database is `analytics.sqlite` in the configured data directory. Choose a directory outside the public web root; the package applies a private mode, a one-second busy timeout, and WAL where available. `dashboard`, `cleanup`, and `audit` provide statistics, bounded retention, and machine-readable technical evidence. The WSGI admin route is disabled unless the host supplies both authorization and CSRF callbacks.

Run the dependency-free contract and storage tests with:

```sh
python -m unittest discover -s test
```

There are no update checks, usage reports, crash uploads, or project-author telemetry.
