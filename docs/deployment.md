# Deployment and hosting notes

## Direct package

Upload the contents of `public/barelytics/` as one directory on the site. The included PHP files, JavaScript, CSS, and server rules are already in runtime form; no build step or package manager is involved. The application runs from a same-origin subdirectory, including nested paths. Its tracker URL and admin session cookie path are derived from the deployed location.

The JavaScript asset is `track.js`; it resolves `track.php` beside itself and works on static HTML sites. Server-rendered PHP sites may call `Barelytics\track()` once per response. Choose one method per page. The generic tracker counts the initial page view only on a SPA.

## Data storage

When PHP can create a private sibling to the site document root, Barelytics uses it. Otherwise it uses a package `var/` directory and writes Apache denial rules if that directory is inside the document root. The installer can also use the `data/` fallback on Apache-compatible hosts. The installer shows the selected directory basename and mode, not its absolute path.

Nginx does not read `.htaccess`. Nginx-only deployments must use private data storage outside the document root; Barelytics refuses an in-webroot fallback when it cannot verify server protection. Plesk Nginx-proxy + Apache/PHP-FPM is supported when PHP runs through Apache or the data directory is external. If the proxy setup does not expose Apache to PHP, use external data storage.

The source lives under `src/` and has Apache denial rules. Nginx PHP hosting must execute PHP files rather than serve their source, which is a prerequisite for Barelytics itself.

## Runtime and maintenance

The only runtime extensions are PDO SQLite, JSON, and sessions. The supported PHP range is 8.1–8.5. Barelytics has no daemon, worker, queue, Redis, MySQL, external analytics service, or geolocation API.

Retention cleanup runs at most once every 24 hours. PHP-FPM deployments with `fastcgi_finish_request()` do a bounded batch after a tracking response. Other handlers run cleanup on an authenticated admin request. Each batch deletes at most 1,000 expired rows per aggregate table and can resume on a later day. Cron may be used as an optional optimization, never as a requirement.

## HTTPS and server rules

Use HTTPS for both the site and admin. The installer warns when the current request is not HTTPS. Apache protection files are defense in depth and must be verified on the actual host. Nginx and other servers need their own controls; do not infer their behavior from `.htaccess` files.

Review consent and privacy requirements separately before adding the tracker to a live site's templates. Barelytics does not decide whether a consent banner is needed.
