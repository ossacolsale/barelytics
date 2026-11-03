# Deployment and hosting notes

## Direct package

Upload the contents of `public/barelytics/` as one directory on the site. The included PHP files, JavaScript, CSS, shared admin UI, and server rules are already in runtime form; no build step or package manager is involved. The application runs from a same-origin subdirectory, including nested paths. Its tracker URL and admin session cookie path are derived from the deployed location. Native Node, Python, .NET, Java, Ruby, and WordPress adapters serve the same UI below their configured admin base path and delegate authentication to the host.

The JavaScript asset is `track.js`; it resolves `track.php` beside itself and works on static HTML sites. Server-rendered PHP sites may call `Barelytics\track()` once per response. Choose one method per page. The generic tracker counts the initial page view only on a SPA.

## Data storage

When PHP can create a private sibling to the site document root, Barelytics uses it. Otherwise it uses a package `var/` directory and writes Apache denial rules if that directory is inside the document root. The installer can also use the `data/` fallback on Apache-compatible hosts. The installer shows the selected directory basename and mode, not its absolute path.

Nginx does not read `.htaccess`. Nginx-only deployments must use private data storage outside the document root; Barelytics refuses an in-webroot fallback when it cannot verify server protection. Plesk Nginx-proxy + Apache/PHP-FPM is supported when PHP runs through Apache or the data directory is external. If the proxy setup does not expose Apache to PHP, use external data storage.

The source lives under `src/` and has Apache denial rules. Nginx PHP hosting must execute PHP files rather than serve their source, which is a prerequisite for Barelytics itself.

## Runtime and maintenance

The only runtime extensions are PDO SQLite, JSON, and sessions. The supported PHP range is 8.2–8.5. Barelytics has no daemon, worker, queue, Redis, MySQL, external analytics service, or geolocation API.

### PHP security updates

The PHP project disclosed two critical remote-code-execution vulnerabilities in web-serving PHP runtimes on 2026-10-21: CVE-2026-109618 and CVE-2026-109619. The fixed upstream builds are PHP 8.2.35, 8.3.36, 8.4.27, and 8.5.12 for their respective branches. Upgrade the runtime, or ask your OS/LTS or hosting vendor to confirm that both fixes have been backported. A version-string check cannot verify a vendor backport. These are PHP runtime issues, not evidence of a vulnerability in Barelytics application code. Updating is the only complete fix; temporary mitigations only reduce risk for specific paths. See [the PHP security policy](../SECURITY.md).

PHP 8.2 remains supported by this project through 2026-12-31, when upstream security support ends. Move PHP 8.2 deployments to a supported branch before that date. Barelytics plans to raise its minimum to PHP 8.3 in the first release on or after 2027-01-01, updating the runtime gate and CI/release matrices together.

Active collection triggers a due retention batch at least once per 24 hours, including PHP without FastCGI and WordPress. Each invocation deletes at most 1,000 expired rows per aggregate table, including automation. A full batch marks cleanup pending so subsequent requests resume immediately. PHP-FPM can finish its response before maintenance; other handlers perform bounded work during the request or shutdown. Administrator maintenance can also run a batch. Idle sites have no execution trigger: schedule maintenance if wall-clock deletion is required.

## HTTPS and server rules

Use HTTPS for both the site and admin. The installer warns when the current request is not HTTPS. Apache protection files are defense in depth and must be verified on the actual host. Nginx and other servers need their own controls; do not infer their behavior from `.htaccess` files.

Review consent and privacy requirements separately before adding the tracker to a live site's templates. Barelytics does not decide whether a consent banner is needed.
