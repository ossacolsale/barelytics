<?php
declare(strict_types=1);

/** Resolve a path through existing symlinks, including paths not created yet. */
function barelytics_wp_canonical_path(string $path): string
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    if ($path === '') { return DIRECTORY_SEPARATOR; }
    $resolved = realpath($path);
    if ($resolved !== false) { return rtrim($resolved, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR; }
    $parent = dirname($path);
    if ($parent === $path) { return $path; }
    return rtrim(barelytics_wp_canonical_path($parent), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
}

function barelytics_wp_path_is_inside(string $path, string $root): bool
{
    $path = barelytics_wp_canonical_path($path);
    $root = barelytics_wp_canonical_path($root);
    if ($root === DIRECTORY_SEPARATOR) { return str_starts_with($path, DIRECTORY_SEPARATOR); }
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function barelytics_wp_private_directory(string $documentRoot, string $wordpressRoot, ?string $override = null): string
{
    $documentRoot = barelytics_wp_canonical_path($documentRoot);
    $wordpressRoot = barelytics_wp_canonical_path($wordpressRoot);
    if ($override !== null && trim($override) !== '') {
        $candidate = barelytics_wp_canonical_path($override);
    } else {
        $candidate = dirname($documentRoot) . DIRECTORY_SEPARATOR . '.barelytics-data-' . substr(hash('sha256', $wordpressRoot), 0, 12);
    }
    if (barelytics_wp_path_is_inside($candidate, $documentRoot)) {
        throw new RuntimeException('Analytics storage is not private.');
    }
    return $candidate;
}

function barelytics_wp_prepare_directory(string $directory): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Analytics storage is unavailable.');
    }
    if (!@chmod($directory, 0700) || !is_writable($directory)) {
        throw new RuntimeException('Analytics storage is unavailable.');
    }
}

/** Make a consistent SQLite snapshot and retain the original database for recovery. */
function barelytics_wp_migrate_database(string $sourceDirectory, string $targetDirectory): bool
{
    $sourcePath = rtrim($sourceDirectory, DIRECTORY_SEPARATOR) . '/analytics.sqlite';
    $targetPath = rtrim($targetDirectory, DIRECTORY_SEPARATOR) . '/analytics.sqlite';
    if (!is_file($sourcePath)) { return true; }
    if (is_file($targetPath)) { return false; }
    $stagingPath = $targetPath . '.migrating-' . bin2hex(random_bytes(8));
    $source = null;
    $copy = null;
    try {
        $source = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA busy_timeout = 5000');
        $checkpoint = $source->query('PRAGMA wal_checkpoint(FULL)')->fetch(PDO::FETCH_NUM);
        if (!is_array($checkpoint) || (int) ($checkpoint[0] ?? 1) !== 0) { return false; }
        $source = null;
        $source = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA busy_timeout = 5000');
        $quoted = "'" . str_replace("'", "''", $stagingPath) . "'";
        $source->exec('VACUUM main INTO ' . $quoted);
        $copy = new PDO('sqlite:' . $stagingPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { return false; }
        foreach (['pageviews_daily', 'settings'] as $table) {
            $exists = $copy->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name");
            $exists->execute([':name' => $table]);
            if ($exists->fetchColumn() === false) { return false; }
            $sourceCount = (int) $source->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            $copyCount = (int) $copy->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            if ($sourceCount !== $copyCount) { return false; }
        }
        $copy = null;
        $source = null;
        if (!@chmod($stagingPath, 0600) || !@rename($stagingPath, $targetPath)) { return false; }
        return true;
    } catch (Throwable) {
        return false;
    } finally {
        $copy = null;
        $source = null;
        if (is_file($stagingPath)) { @unlink($stagingPath); }
    }
}

function barelytics_wp_protect_legacy_directory(string $directory): bool
{
    $guard = "# Barelytics generated data protection\nRequire all denied\n";
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . '/.htaccess';
    if (is_file($path)) {
        $current = @file_get_contents($path);
        if (!is_string($current)) { return false; }
        if ($current !== $guard && $current !== "Deny from all\n" && $current !== "Order allow,deny\nDeny from all\n") { return false; }
    }
    if (@file_put_contents($path, $guard, LOCK_EX) !== strlen($guard)) { return false; }
    @chmod($path, 0600);
    return true;
}

/** Verify server denial using a short-lived random file and a same-origin request. */
function barelytics_wp_verify_http_denial(string $directory): bool
{
    if (!function_exists('wp_remote_get') || !function_exists('content_url')) { return false; }
    $name = 'barelytics-probe-' . bin2hex(random_bytes(16)) . '.sqlite';
    $secret = bin2hex(random_bytes(32));
    $probePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (@file_put_contents($probePath, $secret, LOCK_EX) !== strlen($secret)) { @unlink($probePath); return false; }
    @chmod($probePath, 0600);
    try {
        $response = wp_remote_get(content_url('barelytics-private/' . rawurlencode($name)), ['timeout' => 5, 'redirection' => 0]);
        if (is_wp_error($response)) { return false; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        return in_array($status, [401, 403, 404], true) && !hash_equals($secret, $body);
    } catch (Throwable) {
        return false;
    } finally {
        @unlink($probePath);
    }
}
