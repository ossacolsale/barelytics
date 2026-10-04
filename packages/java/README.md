# Barelytics for Java

Native Java 21 and Java 25 integration using SQLite JDBC. No PHP collector or remote Barelytics service is required. Spring Boot, Servlet, and Kotlin applications can use the Java API.

```java
var analytics = new BarelyticsStore(Path.of("/var/lib/my-site/barelytics"));
// On an HTML-producing route, call once; do not install this as a global all-request filter.
analytics.trackPageView(request.getRequestURI(), request.getHeader("User-Agent"), null, null);
```

Map `BarelyticsTrackServlet` to `POST /barelytics/track` for the browser collector, or register the same handler as a Spring MVC endpoint. It checks the method, 8 KiB limit, strict one-property JSON payload, path validation, and canonical status codes. Use one collector path per page response.

The database is `analytics.sqlite` under the configured data directory. Keep it outside the web root. The store uses a one-second SQLite busy timeout and WAL when available. Strict Mode is default. Optional dimensions are exposed through `configuration`, `updatePrivacy`, `returnToStrictMode`, and validated by `audit`; setup screens must require the explicit acknowledgement argument before enabling them. Dashboard aggregates are available via `dashboard`. `cleanup` is bounded to 1,000 rows per table. Host applications must protect admin routes with their authentication and CSRF facilities; do not expose the store API to anonymous users.

Spring Boot: inject a singleton `BarelyticsStore` bean and call it from selected `@Controller` methods returning HTML; map the servlet explicitly for browser tracking. Generic Servlet: register the servlet with a private storage path. Do not track API/assets/health requests. No visitor IDs, cookies, IP persistence, event-level storage, or project-author telemetry are used.

Tests use the shared repository contract vectors: `mvn test`. This runtime requires Java 21 or newer; CI tests Java 21 and 25 LTS.
