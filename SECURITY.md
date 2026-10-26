# Security policy

## Supported versions

Security fixes are provided for the latest published release. Until a first release exists, the current default branch is the supported development version. The declared runtime range is PHP 8.2–8.5; CI is configured to exercise each version. Native .NET support targets .NET 10 LTS.

## PHP runtime security advisory (2026-10-21)

On 2026-10-21, the PHP project published two critical remote-code-execution advisories affecting PHP installations serving web requests: [CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and [CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). These are vulnerabilities in the PHP runtime; this advisory is not evidence of a vulnerability in Barelytics application code.

Upgrade to a patched upstream PHP build: 8.2.35, 8.3.36, 8.4.27, or 8.5.12 for the corresponding supported branch. Linux distributions and long-term-support (LTS) vendors may backport the fixes without changing the upstream version string. Ask the OS or hosting vendor to confirm that both fixes are included; a version-string check alone cannot establish patch status.

Updating is the only complete mitigation. If an update is temporarily impossible, follow the PHP team's advisory for temporary, path-specific risk reduction. For example, disabling `session.upload_progress.enabled` blocks confirmed session-related paths for CVE-2026-109619, but does not block other variants or make the runtime safe. Restart PHP workers after changing runtime configuration and continue arranging an update.

PHP 8.2 reaches the end of upstream security support on 2026-12-31. Deployments on PHP 8.2 should move to a supported branch before that date; see the PHP [supported versions](https://www.php.net/supported-versions.php) page.

## Reporting a vulnerability

Please report suspected vulnerabilities privately to the repository maintainer using GitHub's **Report a vulnerability** feature (private vulnerability reporting), if enabled. Include the affected commit or version, impact, steps to reproduce, and a proposed mitigation if available. Do not include real visitor records, credentials, or other people's personal data.

Please allow the maintainer a reasonable opportunity to investigate and prepare a fix before public disclosure. The maintainer will acknowledge reports as soon as practical and coordinate a disclosure date with the reporter. Do not post exploit details publicly before coordination.
