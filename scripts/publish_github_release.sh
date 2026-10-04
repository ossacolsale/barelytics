#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: publish_github_release.sh TAG EXPECTED_COMMIT RELEASE_ASSET_DIRECTORY" >&2
  exit 2
fi

release_tag=$1
expected_commit=$2
assets_dir=$3

remote_refs=$(git ls-remote origin "refs/tags/$release_tag" "refs/tags/$release_tag^{}")
remote_commit=$(awk '$2 ~ /\^\{\}$/ { print $1; found = 1 } END { if (!found) exit 1 }' <<<"$remote_refs" || true)
if [[ -z "$remote_commit" ]]; then
  remote_commit=$(awk -v ref="refs/tags/$release_tag" '$2 == ref { print $1; exit }' <<<"$remote_refs")
fi
if [[ "$remote_commit" != "$expected_commit" ]]; then
  echo "Remote tag $release_tag now resolves to ${remote_commit:-<missing>}, expected $expected_commit; refusing to publish." >&2
  exit 1
fi

if [[ ! -f "$assets_dir/SHA256SUMS.txt" ]]; then
  echo "Missing checksum manifest: $assets_dir/SHA256SUMS.txt" >&2
  exit 1
fi
expected_files=()
while IFS= read -r asset; do expected_files+=("$asset"); done < <(find "$assets_dir" -maxdepth 1 -type f -print | LC_ALL=C sort)
if [[ ${#expected_files[@]} -eq 0 ]]; then
  echo "No release package assets found in $assets_dir" >&2
  exit 1
fi

release_info=$(mktemp)
download_dir=$(mktemp -d)
trap 'rm -f "$release_info"; rm -rf "$download_dir"' EXIT

if ! gh release view "$release_tag" --json isDraft,isImmutable,assets >"$release_info" 2>/dev/null; then
  release_args=("$release_tag" "$assets_dir"/* --verify-tag --generate-notes --title "Barelytics $release_tag")
  version_without_build=${release_tag%%+*}
  if [[ "$version_without_build" == *-* ]]; then release_args+=(--prerelease); fi
  gh release create "${release_args[@]}"
  exit 0
fi

is_draft=$(jq -r '.isDraft' "$release_info")
is_immutable=$(jq -r '.isImmutable // false' "$release_info")
existing_names=$(jq -r '.assets[].name' "$release_info" | LC_ALL=C sort)
if [[ -n "$existing_names" ]]; then
  gh release download "$release_tag" --dir "$download_dir"
fi

# A published release may be retried only when every existing asset is an
# unchanged subset of this build. Never clobber assets or remove unknown files.
python3 - "$assets_dir" "$download_dir" "$release_info" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

expected_dir, existing_dir, info_path = map(Path, sys.argv[1:])
info = json.loads(info_path.read_text())
expected = {path.name: path for path in expected_dir.iterdir() if path.is_file()}
existing = {item["name"] for item in info["assets"]}
unknown = sorted(existing - expected.keys())
if unknown:
    raise SystemExit("Existing release has unexpected assets; refusing destructive synchronization: " + ", ".join(unknown))
for name in sorted(existing):
    downloaded = existing_dir / name
    if not downloaded.is_file():
        raise SystemExit(f"Existing release asset {name} could not be downloaded")
    if hashlib.sha256(downloaded.read_bytes()).digest() != hashlib.sha256(expected[name].read_bytes()).digest():
        raise SystemExit(f"Existing release asset {name} differs from this build; refusing to overwrite it")
PY

if [[ "$is_immutable" == "true" ]]; then
  if [[ "$existing_names" == "$(printf '%s\n' "${expected_files[@]##*/}" | LC_ALL=C sort)" ]]; then
    echo "Immutable release $release_tag already has the expected assets; nothing to do."
    exit 0
  fi
  echo "Release $release_tag is immutable and incomplete; refusing to modify it." >&2
  exit 1
fi

expected_names=$(printf '%s\n' "${expected_files[@]##*/}" | LC_ALL=C sort)
if [[ "$is_draft" != "true" ]]; then
  if [[ "$existing_names" == "$expected_names" ]]; then
    echo "Release $release_tag already exists with matching assets; nothing to do."
    exit 0
  fi
  echo "Published release $release_tag is missing assets; refusing to modify a published release." >&2
  exit 1
fi

missing_files=()
while IFS= read -r expected_name; do
  [[ -z "$expected_name" ]] && continue
  if ! jq -e --arg name "$expected_name" '.assets | any(.name == $name)' "$release_info" >/dev/null; then
    missing_files+=("$assets_dir/$expected_name")
  fi
done <<<"$expected_names"
if [[ ${#missing_files[@]} -gt 0 ]]; then
  gh release upload "$release_tag" "${missing_files[@]}"
fi

edit_args=("$release_tag" --draft=false --title "Barelytics $release_tag")
version_without_build=${release_tag%%+*}
if [[ "$version_without_build" == *-* ]]; then
  edit_args+=(--prerelease)
else
  edit_args+=(--prerelease=false)
fi
gh release edit "${edit_args[@]}"
