# Framework integration recipes

## Native administration

Node, WSGI Python, ASP.NET Core, Java Servlet, Ruby Rack, and WordPress adapters serve the same files from `public/barelytics/admin-ui/`. The admin UI is plain HTML/CSS/JavaScript, makes same-origin requests only, and expects the semantic API described in [`../../spec/admin/http-api.md`](../../spec/admin/http-api.md). Mount the admin path below host authorization and provide the framework's CSRF token verifier. Never expose admin routes anonymously.

For .NET, configure ASP.NET Core antiforgery to accept the `RequestVerificationToken` header and use `MapBarelyticsAdmin` with an administrator authorization policy. Java applications register `BarelyticsAdminServlet` with request authorization, CSRF verification, and token callbacks. Rack uses `Barelytics::RackAdmin`; WSGI uses `create_wsgi_app` with authorization, CSRF verification, and a token callback. Node uses `createAdminHandler` with the same callbacks. WordPress uses `manage_options` and a WordPress nonce.

The route is relative to a configurable base path. Keep the trailing slash when opening the UI so its relative static and API URLs stay under the mounted route. PHP FTP installs can open `admin.php?ui=1` after signing into `admin.php`; existing PHP admin, audit, and account URLs remain available.

Every recipe uses the native package shown in the runtime README and a **persistent, non-public SQLite directory**. Initialize one store/application singleton at startup. Count only one successful document response per page load; choose middleware or an explicit call, never both. Keep Strict Mode enabled; page totals and normalized paths are recorded, while dimensions remain opt-in. Each native store exposes an aggregate dashboard and privacy audit. For PHP, the built-in `admin.php` is the dashboard/audit; protect it with a unique administrator password. Skip API, assets, feeds, health checks, previews, and background requests.

## Django

Install `packages/python` in the Django environment; set `BARELYTICS_DATA_DIRECTORY` to a private persistent directory and construct one `Barelytics` instance in `AppConfig.ready()`. Call `store.track_page_view(path=request.path, user_agent=request.META.get('HTTP_USER_AGENT'))` from a middleware after a successful HTML response. Skip non-HTML responses and `request.path.startswith('/api/')`. Do not also install the browser snippet. Use the package's dashboard/audit API behind Django authentication and an administrator-only URL; Strict Mode records aggregates only. Django middleware must not run for static files served outside Django.

## Flask

Install the Python package, construct one store in the app factory with a private persistent directory, then call tracking in an `after_request` hook only for successful HTML GET responses. Exclude `/api`, static assets, health endpoints, and generated reports; do not add a second browser tracking call. Put dashboard/audit routes behind Flask-Login and an administrator role. Flask's development server and temporary deployment disks are not persistent production storage.

## FastAPI

Install the Python package and construct one store in the lifespan handler. Add pure ASGI middleware (or an explicit call from document routes) and record only successful HTML document responses; avoid reading or buffering request bodies. Exclude API and health routes. Keep dashboard/audit routes under an authenticated admin router. ASGI middleware and a browser snippet must not both count the same response.

## Express

Install `packages/node`, create one `Barelytics` instance with a persistent private `dataDirectory`, then add middleware after routing that counts only successful HTML document responses. Exclude API, static, health, and background routes. Protect any dashboard/audit handler with application authentication and CSRF protection. Either use this middleware or the browser snippet, not both. Node's built-in SQLite is intended for persistent Node hosts.

## Fastify

Install `packages/node`, register one store during startup, and use an `onSend` hook for successful HTML document responses. Exclude routes with an API/health prefix and non-HTML content types. Keep dashboard/audit routes under an admin authentication and CSRF plugin. Do not combine a hook with the browser snippet on the same pages.

## NestJS

Install `packages/node`, register `Barelytics` as a singleton provider in a module, and use an interceptor or explicit controller call after successful HTML responses. Exclude APIs, health checks, static files, and background handlers. Guard the dashboard/audit controller with the application's administrator guard and CSRF strategy. Use a persistent filesystem; do not construct multiple store instances per request.

## Next.js (Node runtime)

Install `packages/node` in a Node-runtime route or server integration and store SQLite in a persistent private directory configured by the deployment. Track explicit document responses once; do not track in both layouts and route handlers. Put dashboard/audit access behind server-side administrator authentication and CSRF checks. SQLite requires a persistent host filesystem and normal Node runtime; Edge Runtime and ephemeral serverless filesystems are unsupported.

## ASP.NET Core MVC

Reference `packages/dotnet/Barelytics` and register `AddBarelytics` with a private persistent path. Track in a document-result filter for successful HTML GET responses; exclude API controllers, static files, health routes, and non-document content. Use ASP.NET Core authorization and antiforgery validation for dashboard/audit actions. Do not also include the browser snippet.

## ASP.NET Core Minimal API

Register one Barelytics store with `AddBarelytics` and inject it only into HTML page endpoints or a small endpoint filter. Exclude API/health endpoints explicitly. Protect dashboard/audit routes with authentication, an administrator policy, and antiforgery for state changes. Count once per response. The database path must survive restarts and deployments.

## Razor Pages

Register the .NET package in `Program.cs`, then use a page filter for successful HTML page responses and exclude API/health routes. Do not track during both a page filter and page handler. Secure dashboard/audit pages with an administrator authorization policy and antiforgery validation. Configure a persistent private SQLite path.

## Spring Boot

Add the Java module as a dependency, create a singleton `BarelyticsStore` bean on a persistent private directory, and use an MVC interceptor for successful HTML document handlers. Exclude API, static, health, and actuator endpoints. Use Spring Security and CSRF for dashboard/audit routes. Do not add the browser snippet to server-counted pages.

## Servlet

Add the Java module, construct one `BarelyticsStore` in a `ServletContextListener`, and close it at application shutdown. A filter may count successful HTML GET responses while excluding APIs, static resources, health checks, and error responses. Protect admin routes with container/application authentication and CSRF tokens. Keep storage persistent and private.

## Rails

Install the `packages/ruby` gem and create a singleton `Barelytics::Store` on a persistent private directory. Use an after-action for successful HTML GET responses; exclude API controllers, health routes, assets, and background jobs. Protect dashboard/audit pages with the app's admin authorization and CSRF defenses. Do not also use the browser snippet.

## Rack

Install the Ruby gem and wrap the Rack app in `Barelytics::Rack.new(app, store: store)` after excluding API/health/static routes at the router or with an outer exclusion middleware. It records successful GET/HEAD responses. Protect any dashboard/audit routes in the host app; Rack integration does not create an admin identity system. The filesystem must persist across restarts.

## Laravel

Use the PHP reference core (no separate PHP analytics package). Upload `public/barelytics/` by FTP/SFTP or deploy it with the application; configure a private `BARELYTICS_DATA_DIRECTORY`. Call `\Barelytics\track()` once from middleware after a successful document response, skipping APIs, assets, health routes, and non-HTML responses. Protect `admin.php` with Barelytics' own login; use Laravel auth for any wrapper route. Do not add the browser snippet as well.

## Symfony

Use the PHP core and configure its private storage directory. Add an event subscriber after controller response creation that calls `\Barelytics\track()` only for successful HTML document requests. Exclude APIs, assets, health checks, and console/worker jobs. Use Barelytics admin authentication or Symfony security around a wrapper; avoid duplicate browser tracking.

## Drupal

Deploy the PHP core beside the Drupal web root and point it at private persistent storage. Call `\Barelytics\track()` from a response subscriber only for successful HTML GET responses; exclude admin, API, asset, health, and cache-worker requests. Protect the Barelytics admin path with its own authentication and web-server access controls. Do not also include the browser snippet.

## WordPress

Build and upload the ZIP described in [`integrations/wordpress/README.md`](../../integrations/wordpress/README.md). The plugin stores the database under `wp-content/barelytics-private`, only counts successful document GETs, and provides settings, period-filtered page totals, and a technical Privacy Audit to users with `manage_options`. Do not add another tracker to plugin-counted pages; configure Nginx to deny the data directory.

## Plain PHP

Upload `public/barelytics/`, finish `install.php`, choose a private writable storage location, and call `require_once __DIR__ . '/barelytics/src/Barelytics.php'; \Barelytics\track();` once in the document template. Call it only for rendered HTML, not shared API/asset bootstraps. Use the browser snippet instead when PHP templates cannot be changed, but never both. Open `admin.php` for dashboard and audit.

## Static HTML

Upload the PHP package to the same site, complete its installer, and use the provided framework-independent browser snippet on document pages. Configure its base path for subdirectory installs and use it once per document. It sends a same-origin aggregate request; Strict Mode records only a normalized path and daily count. Open `/barelytics/admin.php` for dashboard and audit. Static hosting without a same-origin PHP endpoint needs a supported server-side runtime; no remote Barelytics service is used.
