#!/usr/bin/env python3
"""Ensure package metadata agrees with a release tag before building archives."""
from __future__ import annotations

import json
import re
import sys
import tomllib
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def versions(root: Path = ROOT) -> dict[str, str]:
    node = json.loads((root / "packages/node/package.json").read_text())["version"]
    python = tomllib.loads((root / "packages/python/pyproject.toml").read_text())["project"]["version"]
    dotnet = ET.parse(root / "packages/dotnet/Barelytics/Barelytics.csproj").findtext(".//Version")
    java = ET.parse(root / "packages/java/pom.xml").findtext("{*}version")
    ruby_text = (root / "packages/ruby/barelytics.gemspec").read_text()
    ruby = re.search(r"spec\.version\s*=\s*['\"]([^'\"]+)", ruby_text)
    plugin_text = (root / "integrations/wordpress/barelytics/barelytics.php").read_text()
    wordpress = re.search(r"^ \* Version: (\S+)$", plugin_text, re.MULTILINE)
    values = {"Node": node, "Python": python, ".NET": dotnet, "Java": java,
              "Ruby": ruby.group(1) if ruby else None,
              "WordPress": wordpress.group(1) if wordpress else None}
    return {name: value for name, value in values.items() if value is not None}


def main(tag: str, root: Path = ROOT) -> None:
    version = tag.removeprefix("v")
    found = versions(root)
    mismatches = {name: value for name, value in found.items() if value != version}
    if mismatches:
        details = ", ".join(f"{name}={value}" for name, value in mismatches.items())
        raise ValueError(f"Tag {tag} does not match package versions ({details}); update package metadata first.")
    print(f"Release tag {tag} matches all {len(found)} package versions.")


if __name__ == "__main__":
    try:
        if len(sys.argv) not in (2, 3):
            raise ValueError("Usage: validate_release_versions.py vMAJOR.MINOR.PATCH [source-directory]")
        main(sys.argv[1], Path(sys.argv[2]).resolve() if len(sys.argv) == 3 else ROOT)
    except (OSError, ValueError, KeyError, ET.ParseError) as exc:
        print(f"Release version validation failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
