#!/usr/bin/env bash
set -euo pipefail

report_failure() {
  local status=$?
  printf 'HTTP integration check failed at tests/integration.sh:%s (exit %s)\n' "${BASH_LINENO[0]}" "$status" >&2
  exit "$status"
}
trap report_failure ERR

root="$(cd "$(dirname "$0")/.." && pwd)"
if ! php -r 'exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);'; then
  echo 'HTTP integration checks skipped: PDO SQLite is unavailable.'
  exit 0
fi
tmp="$(mktemp -d)"
if ! port="$(php -r '$s=@stream_socket_server("tcp://127.0.0.1:0", $e, $m); if ($s === false) exit(77); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1); fclose($s);')"; then
  echo 'HTTP integration checks skipped: this environment does not permit localhost sockets.'
  exit 0
fi
db="$tmp/analytics.sqlite"
token="$(php -r 'echo bin2hex(random_bytes(32));')"
password='test-password-long-enough-123'
server_pid=''
cleanup() { if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi; rm -rf "$tmp"; }
trap cleanup EXIT

BARELYTICS_DATABASE_PATH="$db" php -S "127.0.0.1:$port" -t "$root/public" >"$tmp/server.log" 2>&1 &
server_pid=$!
base="http://127.0.0.1:$port"
for _ in $(seq 1 50); do
  if curl -sS -o /dev/null "$base/barelytics/install.php" 2>/dev/null; then break; fi
  sleep .1
done
jar="$tmp/cookies"
digest="$(php -r 'echo "sha256:", hash("sha256", $argv[1]), "\n";' "$token")"
printf '%s' "$digest" > "$tmp/setup.token"
page="$(curl -sS -c "$jar" "$base/barelytics/install.php")"
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$page")"
[[ -n "$csrf" ]]
status="$(curl -sS -o "$tmp/diagnostics-anonymous" -w '%{http_code}' "$base/barelytics/diagnostics.php")"
[[ "$status" == 403 ]]
! grep -q 'Read-only diagnostics' "$tmp/diagnostics-anonymous"

weak="$(curl -sS -b "$jar" -c "$jar" -d "csrf=$csrf&data_mode=auto&setup_token=$token&password=short&confirmation=short" "$base/barelytics/install.php")"
grep -q 'at least 12 characters' <<<"$weak"
mismatch="$(curl -sS -b "$jar" -c "$jar" -d "csrf=$csrf&data_mode=auto&setup_token=$token&password=$password&confirmation=wrong-password" "$base/barelytics/install.php")"
grep -q 'does not match' <<<"$mismatch"
before="$(awk '$6 == "PHPSESSID" {print $7}' "$jar")"
curl -sS -o /dev/null -D "$tmp/setup-headers" -b "$jar" -c "$jar" -d "csrf=$csrf&data_mode=auto&setup_token=$token&password=$password&confirmation=$password" "$base/barelytics/install.php"
test ! -f "$tmp/setup.token"
after="$(awk '$6 == "PHPSESSID" {print $7}' "$jar")"
[[ -n "$after" && "$before" != "$after" ]]

diagnostics="$(curl -sS -b "$jar" "$base/barelytics/diagnostics.php")"
grep -q 'Read-only diagnostics' <<<"$diagnostics"
! grep -q "$tmp" <<<"$diagnostics"
diag_csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$diagnostics")"
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$jar" -d 'action=database_probe' "$base/barelytics/diagnostics.php")" == 403 ]]
probe="$(curl -sS -b "$jar" -c "$jar" -d "csrf=$diag_csrf&action=database_probe" "$base/barelytics/diagnostics.php")"
grep -q 'CRUD result: PASS' <<<"$probe"
grep -q 'PASS CREATE TABLE' <<<"$probe"
grep -q 'PASS DROP TABLE' <<<"$probe"
! grep -q "$tmp" <<<"$probe"

account="$(curl -sS -b "$jar" "$base/barelytics/account.php")"
grep -q 'Change password' <<<"$account"
account_csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$account")"
wrong_password="$(curl -sS -b "$jar" -d "csrf=$account_csrf&current_password=incorrect&new_password=changed-password-long-456&confirmation=changed-password-long-456" "$base/barelytics/account.php")"
grep -q 'current password is incorrect' <<<"$wrong_password"
mismatch_password="$(curl -sS -b "$jar" -d "csrf=$account_csrf&current_password=$password&new_password=changed-password-long-456&confirmation=changed-password-long-457" "$base/barelytics/account.php")"
grep -q 'confirmation does not match' <<<"$mismatch_password"
weak_password="$(curl -sS -b "$jar" -d "csrf=$account_csrf&current_password=$password&new_password=short-pass1&confirmation=short-pass1" "$base/barelytics/account.php")"
grep -q 'at least 12 characters' <<<"$weak_password"
new_password='changed-password-long-456'
curl -sS -b "$jar" -c "$jar" -d "csrf=$account_csrf&current_password=$password&new_password=$new_password&confirmation=$new_password" "$base/barelytics/account.php" > "$tmp/account-changed"
grep -q 'Administrator password changed' "$tmp/account-changed"
php -r '$d=new PDO("sqlite:".$argv[1]); $v=(int)$d->query("SELECT MAX(version) FROM schema_migrations")->fetchColumn(); $setup=$d->query("SELECT value FROM settings WHERE key=\047setup_complete\047")->fetchColumn(); $app=$d->query("SELECT value FROM settings WHERE key=\047application_version\047")->fetchColumn(); $hash=$d->query("SELECT value FROM settings WHERE key=\047admin_password_hash\047")->fetchColumn(); if ($v !== 2 || $setup !== "1" || $app !== "1.1.0" || !password_verify($argv[2], $hash)) exit(1);' "$db" "$new_password"
rm -f "$jar"
page="$(curl -sS -b "$jar" -c "$jar" "$base/barelytics/admin.php")"
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$page")"
old_password="$(curl -sS -b "$jar" -d "csrf=$csrf&login=1&password=$password" "$base/barelytics/admin.php")"
grep -q 'password is incorrect' <<<"$old_password"
curl -sS -o /dev/null -D "$tmp/new-login-headers" -b "$jar" -c "$jar" -d "csrf=$csrf&login=1&password=$new_password" "$base/barelytics/admin.php"
grep -qi '^Location: admin.php' "$tmp/new-login-headers"
password="$new_password"
php -r '$d=new PDO("sqlite:".$argv[1]); $h=$d->query("SELECT value FROM settings WHERE key=\047admin_password_hash\047")->fetchColumn(); if (!is_string($h) || $h === $argv[2] || !password_verify($argv[2], $h)) exit(1);' "$db" "$password"

rm -f "$jar"
page="$(curl -sS -b "$jar" -c "$jar" "$base/barelytics/admin.php")"
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$page")"
[[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$jar" -d 'action=settings' "$base/barelytics/admin.php")" == 403 ]]
wrong="$(curl -sS -b "$jar" -c "$jar" -d "csrf=$csrf&login=1&password=wrong" "$base/barelytics/admin.php")"
grep -q 'password is incorrect' <<<"$wrong"
before="$(awk '$6 == "PHPSESSID" {print $7}' "$jar")"
curl -sS -o /dev/null -D "$tmp/login-headers" -b "$jar" -c "$jar" -d "csrf=$csrf&login=1&password=$password" "$base/barelytics/admin.php"
after="$(awk '$6 == "PHPSESSID" {print $7}' "$jar")"
[[ -n "$after" && "$before" != "$after" ]]

status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"path":"https://evil.example/"}' "$base/barelytics/track.php")"
[[ "$status" == 400 ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{' "$base/barelytics/track.php")"
[[ "$status" == 400 ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"path":"/article?email=private@example.test"}' "$base/barelytics/track.php")"
[[ "$status" == 400 ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' -A 'Googlebot' -H 'Content-Type: application/json' --data '{"path":"/article"}' "$base/barelytics/track.php")"
[[ "$status" == 204 ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"path":"/article"}' "$base/barelytics/track.php")"
[[ "$status" == 204 ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' "$base/barelytics/track.php")"
[[ "$status" == 405 ]]
payload="$(printf '{\"path\":\"/%2100s\"}' '')"
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data "$payload" "$base/barelytics/track.php")"
[[ "$status" == 413 ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data "{\"path\":\"/x'); DROP TABLE pageviews_daily;--\"}" "$base/barelytics/track.php")"
[[ "$status" == 204 ]]
dashboard="$(curl -sS -b "$jar" "$base/barelytics/admin.php")"
grep -q '&#039;)' <<<"$dashboard"
php -r '$d=new PDO("sqlite:".$argv[1]); $n=(int)$d->query("SELECT views FROM pageviews_daily WHERE path=\047/article\047")->fetchColumn(); if ($n !== 1) exit(1);' "$db"
php -r '$d=new PDO("sqlite:".$argv[1]); $n=(int)$d->query("SELECT COUNT(*) FROM pageviews_daily")->fetchColumn(); if ($n !== 2) exit(1);' "$db"

php -r '$d=new PDO("sqlite:".$argv[1]); $d->exec("DROP TABLE schema_migrations");' "$db"
upgrade="$(curl -sS -b "$jar" "$base/barelytics/admin.php")"
grep -q 'Database upgrade required' <<<"$upgrade"
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$upgrade")"
upgrade="$(curl -sS -b "$jar" -c "$jar" -d "csrf=$csrf&action=migrate" "$base/barelytics/admin.php")"
grep -q 'migrations completed' <<<"$upgrade"
expected_schema_version="$(php -r 'require $argv[1]; echo \Barelytics\CURRENT_SCHEMA_VERSION;' "$root/public/barelytics/src/Barelytics.php")"
php -r '$d=new PDO("sqlite:".$argv[1]); $n=(int)$d->query("SELECT COUNT(*) FROM pageviews_daily")->fetchColumn(); $v=(int)$d->query("SELECT MAX(version) FROM schema_migrations")->fetchColumn(); if ($n !== 2 || $v !== (int)$argv[2]) exit(1);' "$db" "$expected_schema_version"

php -r '$d=new PDO("sqlite:".$argv[1]); $q=$d->prepare("INSERT INTO pageviews_daily(day,path,country,views) VALUES(\0472000-01-01\047,\047/old\047,\047XX\047,1)"); $q->execute(); $d->exec("UPDATE settings SET value=\0472000-01-01 00:00:00\047 WHERE key=\047last_cleanup_at\047");' "$db"
curl -sS -o /dev/null -b "$jar" "$base/barelytics/admin.php"
php -r '$d=new PDO("sqlite:".$argv[1]); if ((int)$d->query("SELECT COUNT(*) FROM pageviews_daily WHERE path=\047/old\047")->fetchColumn() !== 0) exit(1);' "$db"

locked="$(curl -sS -b "$jar" "$base/barelytics/install.php?reset=1")"
grep -q 'Setup is locked' <<<"$locked"
! grep -q 'Create the administrator account' <<<"$locked"
! grep -q 'Database write test' <<<"$locked"
! grep -q 'Environment checks' <<<"$locked"
recovery_token="$(php -r 'echo bin2hex(random_bytes(32));')"
recovery_digest="$(php -r 'echo "sha256:", hash("sha256", $argv[1]), "\n";' "$recovery_token")"
printf '%s' "$recovery_digest" > "$tmp/reset.token"
recovery="$(curl -sS -b "$jar" -c "$jar" "$base/barelytics/install.php")"
grep -q 'FTP-authorized password recovery' <<<"$recovery"
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' <<<"$recovery")"
before="$(awk '$6 == "PHPSESSID" {print $7}' "$jar")"
recovery_password='replacement-admin-password-987'
curl -sS -o /dev/null -b "$jar" -c "$jar" -d "csrf=$csrf&reset_token=$recovery_token&password=$recovery_password&confirmation=$recovery_password" "$base/barelytics/install.php"
test ! -f "$tmp/reset.token"
after="$(awk '$6 == "PHPSESSID" {print $7}' "$jar")"
[[ -n "$after" && "$before" != "$after" ]]
php -r '$d=new PDO("sqlite:".$argv[1]); $h=$d->query("SELECT value FROM settings WHERE key=\047admin_password_hash\047")->fetchColumn(); $n=(int)$d->query("SELECT COUNT(*) FROM pageviews_daily")->fetchColumn(); if (!password_verify($argv[2], $h) || $n !== 2) exit(1);' "$db" "$recovery_password"

kill "$server_pid"; wait "$server_pid" 2>/dev/null || true; server_pid=''
bad_port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0", $e, $m); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1); fclose($s);')"
mkdir "$tmp/not-a-database"
BARELYTICS_DATABASE_PATH="$tmp/not-a-database" php -S "127.0.0.1:$bad_port" -t "$root/public" >"$tmp/failure-server.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 50); do
  if curl -sS -o /dev/null "http://127.0.0.1:$bad_port/barelytics/admin.php" 2>/dev/null; then break; fi
  sleep .1
done
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"path":"/degraded"}' "http://127.0.0.1:$bad_port/barelytics/track.php")"
[[ "$status" == 204 ]]

echo 'HTTP, admin authentication, CSRF, aggregation, and retention integration checks passed.'
