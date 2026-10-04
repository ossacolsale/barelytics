# Barelytics

> First-party analytics that counts pages without following people.

Barelytics provides native analytics and administration for PHP, Node.js, Python, .NET, Java, and Ruby, with a standalone PHP mode for FTP/shared-hosting deployments. It writes daily page-view aggregates to local SQLite storage. Strict Mode is on by default: Barelytics stores a normalized page path, UTC day, and count; it does not create a visitor identifier.

## What it collects

By default, Barelytics stores aggregate page views and normalized paths. It does **not** store IP addresses, full User-Agent strings, query strings, full referrer URLs, cookies, browser storage IDs, account IDs, or cross-site identifiers. Optional country, referrer-host, browser, device, and operating-system aggregates are off until an administrator explicitly enables them. Bot filtering is heuristic. Barelytics makes no legal-compliance guarantee; site owners assess their own obligations.

## Runtime support

| Runtime or integration | Supported baseline | Native admin | Host authentication | Standalone mode |
| --- | --- | --- | --- | --- |
| PHP | 8.2–8.5, PDO SQLite; FTP/SFTP deployment | Yes | Optional | Yes |
| Node.js / TypeScript | Node 22 and 24 LTS | Yes | Yes | No |
| Python | 3.11–3.14 | Yes | Yes | No |
| .NET / ASP.NET Core | .NET 10 LTS | Yes | Yes | No |
| Java | Java 21 and 25 | Yes | Yes | No |
| Ruby | Ruby 3.3, 3.4, and 4.0 | Yes | Yes | No |
| WordPress | Plugin ZIP assembled from the PHP reference core | Yes | WordPress administrator role | No |

The native runtimes share one dependency-free HTML/CSS/JavaScript administration UI and HTTP contract. Embedded adapters delegate identity, roles, sessions, and CSRF verification to the host application. PHP retains its standalone password/session mode for shared hosting; the WordPress adapter uses WordPress permissions and nonces.

All runtimes use a local SQLite database; non-PHP integrations do not call a remote Barelytics service. Persistent storage is required. SQLite on ephemeral serverless or edge filesystems is not supported.

## Choose an integration

- **PHP on shared hosting:** upload [`public/barelytics/`](public/barelytics/) by FTP/SFTP. No Composer, npm, SSH, or mandatory cron is required. Start with [FTP installation](INSTALL.md).
- **WordPress:** build the installable ZIP with `php scripts/build-wordpress-plugin.php`, then upload it in the WordPress Plugins screen. See [the plugin guide](integrations/wordpress/README.md).
- **Node, Python, .NET, Java, or Ruby:** install the package for your application and point it at a persistent private directory. See each package README and [framework recipes](docs/integrations/README.md).

Use one tracking method per page response to prevent double-counting. For server-side integrations, count successful document responses only; exclude APIs, health checks, static files, feeds, and background jobs.

## Privacy and operations

Administrators can review the effective settings, schema checks, and configuration fingerprint through the privacy audit. See the [privacy contract](spec/privacy-model.md), [audit model](spec/audit-model.md), [security notes](docs/security.md), and [FAQ](docs/faq.md).

Browse the [documentation index](docs/README.md) for integration, deployment, privacy, and product guides.

## Development

Run checks for the package you changed; CI runs the full runtime and version matrix. See [contributing](CONTRIBUTING.md), [contract test vectors](spec/test-vectors/), and the [publication checklist](docs/publication/README.md).

## Releases

Push a version tag such as `v1.2.3` to run the release workflow. After the runtime test matrix passes, it builds the PHP, WordPress, npm, Python, .NET, Java, and Ruby packages and attaches them with SHA-256 checksums to a GitHub Release. See [release instructions](docs/publication/README.md). Package registries are not published automatically.

## License

MIT. See [LICENSE](LICENSE).
