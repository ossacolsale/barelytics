#!/usr/bin/env python3
"""Check release archives include the shared UI and contain no obvious secret files."""
from __future__ import annotations

import io
import sys
import tarfile
import zipfile
from pathlib import Path

REQUIRED_UI = {"index.html", "app.js", "admin.css", "config.js"}
SECRET_SUFFIXES = (".sqlite", ".db", ".pem", ".key", ".p12", ".pfx", ".env")


def inspect_zip(path: Path) -> set[str]:
    with zipfile.ZipFile(path) as archive:
        names = set(archive.namelist())
    if path.name.startswith("barelytics-php-"):
        prefix = "barelytics/admin-ui/"
    elif path.name.startswith("barelytics-wordpress-"):
        prefix = "barelytics/barelytics-core/admin-ui/"
    elif path.suffix == ".nupkg":
        prefix = "contentFiles/any/any/admin-ui/"
    elif path.suffix == ".jar":
        prefix = "admin-ui/"
    else:
        prefix = "barelytics/admin-ui/"
    if not all(prefix + asset in names for asset in REQUIRED_UI):
        raise ValueError(f"{path.name}: shared administration assets are missing")
    return names


def inspect_targz(path: Path) -> set[str]:
    # RubyGems wraps its gzipped payloads in a plain outer tar; the other
    # supported source archives are gzip-compressed tarballs. Let tarfile
    # detect either outer container format.
    with tarfile.open(path, "r:*") as archive:
        names = set(archive.getnames())
        if path.suffix == ".gem":
            member = next((x for x in archive.getmembers() if x.name.endswith("data.tar.gz")), None)
            if member is None:
                raise ValueError(f"{path.name}: gem data archive is missing")
            payload = archive.extractfile(member)
            if payload is None:
                raise ValueError(f"{path.name}: gem data archive cannot be read")
            with tarfile.open(fileobj=io.BytesIO(payload.read()), mode="r:gz") as data:
                names |= set(data.getnames())
    expected = all(any(name.endswith(f"admin-ui/{asset}") for name in names) for asset in REQUIRED_UI)
    if not expected:
        raise ValueError(f"{path.name}: shared administration assets are missing")
    return names


def main(directory: Path) -> None:
    archives = sorted(p for p in directory.iterdir() if p.is_file() and p.name != "SHA256SUMS.txt")
    if not archives:
        raise ValueError("No release archives found")
    required_families = ("php", "wordpress", "node", "nupkg", "java", "gem")
    if not any(p.suffix == ".pom" and "java" in p.name for p in archives):
        raise ValueError("Missing Java Maven POM release artifact")
    names_by_archive: dict[str, set[str]] = {}
    for path in archives:
        if path.suffix == ".pom":
            continue
        if path.suffix in (".zip", ".jar", ".nupkg", ".whl"):
            names = inspect_zip(path)
        elif path.suffix in (".tgz", ".gem", ".gz"):
            names = inspect_targz(path)
        else:
            raise ValueError(f"Unexpected release asset: {path.name}")
        for name in names:
            lowered = name.lower()
            if lowered.endswith(SECRET_SUFFIXES) or "/.env" in lowered or "admin_password" in lowered:
                raise ValueError(f"{path.name}: sensitive or local data file found: {name}")
            is_native_runtime_archive = path.suffix in (".tgz", ".whl", ".nupkg", ".jar", ".gem")
            if is_native_runtime_archive and lowered.endswith(".php"):
                raise ValueError(f"{path.name}: PHP-only file in native runtime package: {name}")
        names_by_archive[path.name] = names
    all_names = "\n".join(names_by_archive)
    for family in required_families:
        if family not in all_names:
            raise ValueError(f"Missing expected {family} release artifact")
    if not any(p.suffix == ".whl" for p in archives) or not any(p.name.startswith("barelytics-") and p.name.endswith(".tar.gz") for p in archives):
        raise ValueError("Missing Python wheel or source distribution")
    print(f"Validated {len(archives)} release archives: shared UI present, no PHP files in native packages, no obvious secret/database files.")


if __name__ == "__main__":
    try:
        main(Path(sys.argv[1] if len(sys.argv) > 1 else "release-assets"))
    except (OSError, ValueError, tarfile.TarError, zipfile.BadZipFile) as exc:
        print(f"Release artifact validation failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
