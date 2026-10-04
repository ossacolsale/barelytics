#!/usr/bin/env python3
"""Inspect release archives for required UI files and accidental private data."""
from __future__ import annotations

import io
import fnmatch
import re
import sys
import tarfile
import zipfile
from pathlib import Path, PurePosixPath

UI_FILES = {"index.html", "app.js", "admin.css", "config.js"}
SENSITIVE_NAME = re.compile(
    r"(^|/)(\.env(?:\..*)?|\.git(?:/|$)|.*\.(?:sqlite3?|db|pem|key|p12|pfx)(?:-wal|-shm)?|"
    r"id_rsa|admin[_-]?password|credentials(?:\.json)?|secrets?\.ya?ml)(?:$|/)", re.IGNORECASE
)
SECRET_CONTENT = re.compile(
    rb"(?:-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|"
    rb"AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,})"
)
PHP_SUFFIXES = {".php", ".phtml", ".php3", ".php4", ".php5", ".phar"}
NATIVE_PREFIXES = ("barelytics-node-", "barelytics-", "barelytics.", "barelytics-java")


def _zip_names(data: bytes, label: str) -> list[tuple[str, bytes]]:
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            return [(item.filename, archive.read(item)) for item in archive.infolist() if not item.is_dir()]
    except (zipfile.BadZipFile, OSError, EOFError) as exc:
        raise ValueError(f"{label}: invalid ZIP archive: {exc}") from exc


def _tar_names(data: bytes, mode: str, label: str) -> list[tuple[str, bytes]]:
    try:
        with tarfile.open(fileobj=io.BytesIO(data), mode=mode) as archive:
            members: list[tuple[str, bytes]] = []
            for item in archive.getmembers():
                if not item.isfile():
                    continue
                stream = archive.extractfile(item)
                if stream is not None:
                    members.append((item.name, stream.read()))
            return members
    except (tarfile.TarError, OSError, EOFError) as exc:
        raise ValueError(f"{label}: invalid {mode} archive: {exc}") from exc


def archive_files(path: Path) -> list[tuple[str, bytes]]:
    """Return archive member names and bytes, with format-specific diagnostics."""
    suffix = path.name.lower()
    data = path.read_bytes()
    if suffix.endswith(".gem"):
        # A Ruby gem is a plain tar containing metadata.gz and data.tar.gz.
        outer = _tar_names(data, "r:", str(path))
        data_member = next((content for name, content in outer if PurePosixPath(name).name == "data.tar.gz"), None)
        if data_member is None:
            raise ValueError(f"{path}: invalid gem: outer tar has no data.tar.gz member")
        return _tar_names(data_member, "r:gz", f"{path} (data.tar.gz)")
    if suffix.endswith((".zip", ".jar", ".nupkg", ".whl")):
        return _zip_names(data, str(path))
    if suffix.endswith((".tar.gz", ".tgz")):
        return _tar_names(data, "r:gz", str(path))
    raise ValueError(f"{path}: unsupported release asset format")


def validate_archive(path: Path) -> None:
    files = archive_files(path)
    names = [name.replace("\\", "/") for name, _ in files]
    basenames = {PurePosixPath(name).name for name in names}
    missing_ui = sorted(UI_FILES - basenames)
    if missing_ui:
        raise ValueError(f"{path}: missing shared admin UI files: {', '.join(missing_ui)}")

    for (name, content), normalized in zip(files, names):
        if SENSITIVE_NAME.search(normalized):
            raise ValueError(f"{path}: contains sensitive/local data file: {name}")
        if SECRET_CONTENT.search(content):
            raise ValueError(f"{path}: possible secret found in file: {name}")
        asset_name = path.name.lower()
        is_native = asset_name.startswith(NATIVE_PREFIXES) and not asset_name.startswith(
            ("barelytics-php-", "barelytics-wordpress-")
        )
        if is_native and PurePosixPath(normalized).suffix.lower() in PHP_SUFFIXES:
            raise ValueError(f"{path}: native package contains prohibited PHP file: {name}")


def validate_directory(directory: Path) -> None:
    if not directory.is_dir():
        raise ValueError(f"Release asset directory does not exist: {directory}")
    assets = sorted(item for item in directory.iterdir() if item.is_file())
    if not assets:
        raise ValueError(f"No release assets found in {directory}")
    names = {item.name.lower() for item in assets}
    required_groups = {
        "PHP": ["barelytics-php-v*.zip"],
        "WordPress": ["barelytics-wordpress-v*.zip"],
        "Node": ["barelytics-node-*.tgz"],
        "Python": ["barelytics-*.whl", "barelytics-*.tar.gz"],
        ".NET": ["barelytics.*.nupkg"],
        "Java": ["barelytics-java-*.jar", "barelytics-java.pom"],
        "Ruby": ["barelytics-*.gem"],
    }
    missing = [
        f"{family} ({pattern})"
        for family, patterns in required_groups.items()
        for pattern in patterns
        if not any(fnmatch.fnmatch(name, pattern) for name in names)
    ]
    if missing:
        raise ValueError("Missing expected release assets: " + ", ".join(missing))
    for path in assets:
        if path.name == "SHA256SUMS.txt":
            continue
        if path.suffix.lower() == ".pom":
            try:
                contents = path.read_bytes()
            except OSError as exc:
                raise ValueError(f"{path}: cannot read Maven POM: {exc}") from exc
            if SECRET_CONTENT.search(contents) or SENSITIVE_NAME.search(path.name):
                raise ValueError(f"{path}: possible secret or sensitive data in Maven POM")
            continue
        validate_archive(path)
    print(f"Validated {len([p for p in assets if p.name != 'SHA256SUMS.txt'])} release archives.")


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("Usage: validate_release_assets.py RELEASE_ASSET_DIRECTORY")
        validate_directory(Path(sys.argv[1]))
    except (OSError, ValueError) as exc:
        print(f"Release artifact validation failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
