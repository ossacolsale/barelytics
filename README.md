# Barelytics

> Analytics, stripped to the essentials.

Barelytics is a tiny, first-party analytics package for PHP websites. It uses SQLite to keep daily page-view aggregates and answers basic questions about pages, countries, and traffic over time. It is intended for personal and small websites, not as a replacement for a large analytics platform.

## Features

- Upload-and-run package: no SSH, Composer, npm, build step, or required cron
- Browser installer with environment diagnostics and one-time token setup
- Plain PHP and PDO SQLite, with browser-driven schema migrations
- No analytics cookies, persistent visitor IDs, browser storage IDs, or fingerprinting
- No stored IP addresses, full User-Agent strings, query strings, or full referrer URLs
- Daily aggregate page views, optional country codes, optional referrer hostnames
- Configurable 30–365 day retention with bounded no-cron cleanup
- Admin dashboard, CSRF protection, PHP password hashing, and secure sessions
- Framework-independent JavaScript and PHP integration
- No third-party analytics requests or hidden telemetry

Bot/crawler filtering is heuristic and may not identify every automated request. Barelytics is a technical privacy-minimization tool, not a legal compliance guarantee. Site owners must assess requirements for their audience and jurisdictions.

## Install with FTP/SFTP

The runtime package is [`public/barelytics/`](public/barelytics/). Upload that directory to your website, for example as `/barelytics/`, using FTP/SFTP or your hosting file manager. Open `/barelytics/install.php`, follow its environment checks, create the one-time setup token with the local browser helper, upload its PHP-wrapped hash file to the package `data/` folder, and set the admin password in the browser. Then copy the generated tracking snippet into your site.

No SSH, shell commands, Composer, npm, or cron is required. Barelytics needs PHP 8.1–8.5, PDO SQLite, JSON, and PHP sessions. The installer checks these requirements and explains failures. HTTPS is required before submitting credentials in production.

See [INSTALL.md](INSTALL.md) for the FTP-only setup, token helper, storage security, and verification steps. See [UPGRADE.md](UPGRADE.md) for FTP upload and browser migrations.

## Data and privacy

Barelytics counts page views, not unique people. It stores normalized same-site paths, UTC dates, counts, and optional country codes or referrer hostnames. It does not create a persistent visitor identifier and does not store IP addresses. Country codes come only from a trusted hosting header; absent or invalid values become `XX`.

## Repository development

The runtime package is complete and requires no build. Maintainers can run the regression checks and syntax checks with PHP tooling; these development commands are not part of installation:

```sh
php tests/run.php
bash tests/integration.sh
find public/barelytics -name '*.php' -print0 | xargs -0 -n1 php -l
```

The CI workflow targets PHP 8.1–8.5 and enables PDO SQLite for database and browser-flow checks. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
