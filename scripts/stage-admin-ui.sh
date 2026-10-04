#!/usr/bin/env sh
set -eu
repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ui_source="$repo_root/public/barelytics/admin-ui"
for destination in \
  "$repo_root/packages/node/admin-ui" \
  "$repo_root/packages/python/barelytics/admin-ui" \
  "$repo_root/packages/dotnet/Barelytics/admin-ui" \
  "$repo_root/packages/java/src/main/resources/admin-ui" \
  "$repo_root/packages/ruby/lib/barelytics/admin-ui"
do
  rm -rf -- "$destination"
  mkdir -p -- "$destination"
  cp "$ui_source"/* "$destination"/
done
