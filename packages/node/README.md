# Barelytics for Node.js

Native Node.js SQLite analytics with the shared Barelytics privacy contract. This package is for persistent Node hosts; it does not call a remote PHP endpoint. It requires Node.js 22.13 or newer and uses the built-in `node:sqlite` module, so it has no runtime npm dependencies. Node currently marks this standard-library module experimental; pin and test the Node line you deploy.

## Install and use

```sh
npm install @barelytics/node
```

```js
import { Barelytics, createTrackHandler } from '@barelytics/node';

const analytics = new Barelytics({ dataDirectory: '/var/lib/my-site/barelytics' });
// Register POST /barelytics/track using createTrackHandler(analytics).
// In a route that represents a rendered HTML page, call this once instead:
analytics.trackPageView({ path: request.url, userAgent: request.headers['user-agent'] });
```

Use one collection method per page response. The native handler accepts only `{"path":"/page"}`, enforces the 8 KiB limit, returns 204/400/405/413, and never stores a request event. `trackPageView` should be called explicitly from HTML-page handlers, not global middleware that also sees APIs, static assets, or health checks.

## Storage and privacy

The database is created at `dataDirectory/analytics.sqlite`, outside the public web root by default when you choose a private directory. The package applies restrictive POSIX file modes where available, a one-second SQLite busy timeout, and WAL when supported. Back up the database before moving or upgrading it.

Strict Mode is the default: daily aggregate page views by normalized path, with no cookies, browser storage, visitor/session IDs, IP storage, fingerprinting, event history, or third-party requests. Country, referrer hostname, browser, device, and OS aggregates are off. `updatePrivacy` requires the explicit acknowledgement `yes` whenever an optional dimension is enabled; `returnToStrictMode()` disables all dimensions. The `audit()` method returns effective configuration, schema version, fingerprint, and technical checks; it is not a legal certification.

The library exposes `dashboard(periodDays)`, `cleanup()`, `audit()`, and privacy controls. Mount `createAdminHandler` only behind your application's administrator authentication and pass a CSRF verifier; both callbacks are mandatory. It does not create an administrator identity or public admin route for you.

## Framework adapters

Express, Fastify, NestJS, Next.js Node runtime, and plain Node HTTP can mount `createTrackHandler` at a same-origin route and use `trackPageView` from rendered-page handlers. Keep admin routes behind application authentication and CSRF checks. For Next.js, use the Node runtime and persistent local storage; edge and ephemeral serverless filesystems are unsupported. SPA route changes require an explicit router integration if each route should count as a page view.

## Tests

```sh
npm test
```

No automatic update checks, usage reports, crash uploads, or project-author telemetry are sent.
