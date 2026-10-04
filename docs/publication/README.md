# Releases and publication readiness

## Automatic GitHub Releases

Pushing a semantic version tag such as `v1.2.3` or `v1.3.0-rc.1` starts [the release workflow](../../.github/workflows/release.yml). It runs the supported runtime test matrix, builds the distributable packages, creates SHA-256 checksums, and publishes a GitHub Release with generated release notes. A failed validation or build prevents publication. Prerelease tags (those containing `-`, such as `v1.3.0-rc.1`) create prereleases.

Create and push a tag after its commit and changelog entry are ready:

```sh
git tag -a v1.2.3 -m "Barelytics v1.2.3"
git push origin v1.2.3
```

The release attaches the PHP and WordPress ZIP archives, npm tarball, Python wheel and source archive, .NET NuGet package, Java JAR and POM, Ruby gem, and `SHA256SUMS.txt`. The workflow only creates GitHub Releases; it does not publish to npm, PyPI, NuGet, Maven Central, or RubyGems, and it does not deploy the website. Its release job alone receives `contents: write`; validation and build jobs use read-only repository permissions.

Review the completed workflow and the release assets before sharing the release. To verify an asset after downloading it, run `sha256sum -c SHA256SUMS.txt` in the directory containing the files.

## Release package artifacts

- PHP remains the FTP/SFTP package at `public/barelytics/`; preserve the package's no-build deployment promise.
- npm: package from `packages/node/` with `npm pack --dry-run` before publishing.
- Python: build a wheel and source archive from `packages/python/` with `python -m build`.
- .NET: `dotnet pack` the library project for supported target frameworks.
- Java: `mvn package` and retain the POM, sources, and test results.
- Ruby: `gem build barelytics.gemspec`.
- WordPress: `php scripts/build-wordpress-plugin.php`; install the ZIP into a disposable WordPress instance before release.

Before tagging a release, update the changelog, review generated archives for secrets and unnecessary files, and confirm the release notes describe compatibility and migrations accurately. Package registry publishing remains a separate future task and must be configured independently.

## Repository metadata recommendations

- **Description:** Self-hosted, privacy-first aggregate web analytics with local SQLite storage and native PHP, Node, Python, .NET, Java, Ruby, and WordPress integrations.
- **Topics:** analytics, privacy, self-hosted, sqlite, php, wordpress, nodejs, python, dotnet, java, ruby, web-analytics.
- **Release notes:** identify runtime/package versions, compatibility ranges, schema changes, migration requirements, security fixes, and test matrix results. Avoid legal-compliance claims.

## Assets and structured data

No verified product screenshots are included yet. Capture screenshots from a clean demo database and mark synthetic data clearly before adding them; do not fabricate production usage or review quotes. The project facts in `../facts.md` and [`product-metadata.jsonld`](product-metadata.jsonld) are source material for future site copy and structured metadata.

## Search/citation facts

Lead with what is stored, how it is stored, and the absence of visitor identity in Strict Mode. Keep claims traceable to the contract and implementation. Describe bot filtering as heuristic and describe legal duties as deployment-specific.
