<?php
declare(strict_types=1);

namespace Barelytics;

ini_set('display_errors', '0');

use PDO;
use Throwable;

const DEFAULT_BOT_PATTERNS = [
    'bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'headless', 'lighthouse',
    'pagespeed', 'semrush', 'ahrefsbot', 'mj12bot', 'dotbot', 'facebookexternalhit',
    'twitterbot', 'linkedinbot', 'discordbot', 'telegrambot', 'whatsapp', 'petalbot',
    'yandex', 'baiduspider', 'bytespider', 'duckduckbot', 'applebot', 'googlebot', 'bingbot',
];
const CURRENT_SCHEMA_VERSION = 1;
const SUPPORTED_PHP_MIN = 80100;
const SUPPORTED_PHP_MAX = 80599;

function normalizePath(string $path, int $maxBytes = 512): ?string
{
    if ($path === '' || strlen($path) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        return null;
    }
    // Accept only origin-form paths. Never accept URLs, query strings, or fragments.
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '?') || str_contains($path, '#')) {
        return null;
    }
    $path = preg_replace('~/+~', '/', $path) ?? '';
    if ($path === '' || strlen($path) > $maxBytes || preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) {
        return null;
    }
    $decodedPath = rawurldecode($path);
    if (preg_match('//u', $decodedPath) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $decodedPath)
        || str_contains($decodedPath, '@') || str_contains($decodedPath, '?') || str_contains($decodedPath, '#')) return null;
    // Collapse common numeric and UUID-style record identifiers before aggregation.
    $identifierPattern = '~(?<=/)(?:[0-9]{6,}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})(?=/|$)~i';
    if ($decodedPath !== $path && preg_match($identifierPattern, $decodedPath)) return null;
    $path = preg_replace($identifierPattern, ':id', $path) ?? $path;
    return $path;
}

function isBot(string $userAgent, array $patterns = DEFAULT_BOT_PATTERNS): bool
{
    foreach ($patterns as $pattern) {
        if (is_string($pattern) && $pattern !== '' && stripos($userAgent, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function referrerHost(string $referer): ?string
{
    if ($referer === '' || strlen($referer) > 2048) {
        return null;
    }
    $parts = parse_url($referer);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    $host = strtolower(rtrim($parts['host'], '.'));
    if ($host === '' || isset($parts['user']) || isset($parts['pass']) || filter_var($host, FILTER_VALIDATE_IP) !== false
        || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
        return null;
    }
    return $host;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function passwordMeetsMinimum(string $password): bool
{
    if (!preg_match('//u', $password)) return false;
    $characters = preg_match_all('/./us', $password);
    return $characters !== false && $characters >= 12;
}

function setting(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setSetting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function dataDirectory(): string
{
    $legacyPath = getenv('BARELYTICS_DATABASE_PATH');
    if ($legacyPath) return dirname($legacyPath);
    $configured = getenv('BARELYTICS_DATA_DIRECTORY');
    if ($configured) return $configured;
    $mode = getenv('BARELYTICS_DATA_MODE') ?: '';
    $root = dirname(__DIR__);
    $private = $root . '/var';
    $fallback = $root . '/data';
    $docroot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $instance = basename($root) . '-' . substr(hash('sha256', $root), 0, 8);
    $external = $docroot !== false ? dirname($docroot) . '/.barelytics-data-' . $instance : '';
    foreach ([$external, $private, $fallback] as $candidate) {
        if ($candidate !== '' && is_file($candidate . '/analytics.sqlite')) return $candidate;
    }
    if ($mode === 'apache') return $fallback;
    if ($mode === 'private') return $private;
    if ($external !== '' && (is_dir($external) ? is_writable($external) : is_writable(dirname($external)))) return $external;
    return $private;
}

function databasePath(): string
{
    return getenv('BARELYTICS_DATABASE_PATH') ?: rtrim(dataDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'analytics.sqlite';
}

function setupTokenPath(): string
{
    $configured = getenv('BARELYTICS_SETUP_TOKEN_FILE');
    if (is_string($configured) && $configured !== '') return $configured;
    $privatePath = rtrim(dataDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'setup.token';
    if (is_file($privatePath)) return $privatePath;
    $ftpInboxPath = dirname(__DIR__) . '/data/setup-token.php';
    return is_file($ftpInboxPath) ? $ftpInboxPath : $privatePath;
}

function resetTokenPath(): string
{
    return rtrim(dataDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'reset.token';
}

function readTokenHashFile(string $path): ?string
{
    if (!is_file($path) || !is_readable($path)) return null;
    $token = file_get_contents($path);
    if (!is_string($token)) return null;
    $token = trim($token);
    $token = preg_replace('/^<\?php http_response_code\(404\); exit; \?>\R/', '', $token) ?? $token;
    return preg_match('/^sha256:[a-f0-9]{64}$/', $token) ? $token : null;
}

function setupToken(): ?string
{
    $envToken = getenv('BARELYTICS_SETUP_TOKEN');
    if (is_string($envToken) && $envToken !== '') return $envToken;
    return readTokenHashFile(setupTokenPath());
}

function verifySetupToken(string $submitted): bool
{
    if (strlen($submitted) < 32 || strlen($submitted) > 4096) return false;
    $envToken = getenv('BARELYTICS_SETUP_TOKEN');
    if (is_string($envToken) && $envToken !== '') return hash_equals($envToken, $submitted);
    $storedHash = setupToken();
    if (!is_string($storedHash) || !preg_match('/^sha256:([a-f0-9]{64})$/', $storedHash, $matches)) return false;
    return hash_equals($matches[1], hash('sha256', $submitted));
}

function verifyResetToken(string $submitted): bool
{
    if (strlen($submitted) < 32 || strlen($submitted) > 4096) return false;
    $storedHash = readTokenHashFile(resetTokenPath());
    if (!is_string($storedHash) || !preg_match('/^sha256:([a-f0-9]{64})$/', $storedHash, $matches)) return false;
    return hash_equals($matches[1], hash('sha256', $submitted));
}

function connectDatabase(): PDO
{
    $path = databasePath();
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new \RuntimeException('Analytics storage is unavailable.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA busy_timeout = 1000');
    try { $db->query('PRAGMA journal_mode = WAL')->fetchColumn(); } catch (Throwable) { /* WAL is optional on constrained hosts. */ }
    @chmod($path, 0600);
    return $db;
}

/** Versioned, repeatable migrations. Each migration and version marker commit atomically. */
function migrateDatabase(PDO $db): int
{
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
    $migrations = [
        1 => static function (PDO $db): void {
            $db->exec('CREATE TABLE IF NOT EXISTS pageviews_daily (day TEXT NOT NULL, path TEXT NOT NULL, country TEXT NOT NULL DEFAULT \'XX\', views INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, path, country))');
            $db->exec('CREATE TABLE IF NOT EXISTS referrers_daily (day TEXT NOT NULL, path TEXT NOT NULL, referrer_host TEXT NOT NULL, views INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, path, referrer_host))');
            $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $probeKey = '__migration_probe_' . bin2hex(random_bytes(8));
            $insert = $db->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)');
            $insert->execute([':key' => $probeKey, ':value' => 'ok']);
            $select = $db->prepare('SELECT value FROM settings WHERE key = :key');
            $select->execute([':key' => $probeKey]);
            $valid = $select->fetchColumn() === 'ok';
            $delete = $db->prepare('DELETE FROM settings WHERE key = :key');
            $delete->execute([':key' => $probeKey]);
            if (!$valid || !tableExists($db, 'pageviews_daily') || !tableExists($db, 'referrers_daily') || !tableExists($db, 'settings')) {
                throw new \RuntimeException('Migration verification failed.');
            }
            setSetting($db, 'application_version', '1.0.0');
            setSetting($db, 'last_migration_at', gmdate('Y-m-d H:i:s'));
        },
    ];
    $stmt = $db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
    $applied = (int) $stmt->fetchColumn();
    foreach ($migrations as $version => $migration) {
        if ($version <= $applied) continue;
        $db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
            if ((int) $stmt->fetchColumn() < $version) {
                $migration($db);
                $stmt = $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
                $stmt->execute([':version' => $version, ':applied_at' => gmdate('Y-m-d H:i:s')]);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            else { try { $db->exec('ROLLBACK'); } catch (Throwable) { } }
            throw $error;
        }
        $applied = $version;
    }
    return $applied;
}

function openDatabase(): PDO
{
    $db = connectDatabase();
    migrateDatabase($db);
    return $db;
}

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute([':name' => $table]);
    return $stmt->fetchColumn() !== false;
}

function schemaVersion(PDO $db): int
{
    if (!tableExists($db, 'schema_migrations')) return 0;
    return (int) $db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
}

function isHttpsRequest(): bool
{
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
}

function requestBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/barelytics/admin.php'));
    $directory = rtrim(dirname($script), '/.');
    return ($directory === '' ? '' : $directory) . '/';
}

function webrootStatus(string $directory): array
{
    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $target = realpath($directory);
    if ($target === false) return ['inside' => false, 'secure' => false, 'mode' => 'unknown'];
    if ($root === false) return ['inside' => false, 'secure' => false, 'mode' => 'unknown'];
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $target = rtrim(str_replace('\\', '/', $target), '/') . '/';
    $inside = str_starts_with($target, $root);
    if (!$inside) return ['inside' => false, 'secure' => true, 'mode' => 'external'];
    $server = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    $apache = function_exists('apache_get_version') || str_contains($server, 'apache');
    if (!$apache) return ['inside' => true, 'secure' => false, 'mode' => 'webroot-unverified'];
    $rules = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
    $contents = is_file($rules) ? (string) file_get_contents($rules) : '';
    if (!preg_match('/Require\s+all\s+denied/i', $contents)) {
        $block = "\n# Barelytics private data protection\nOptions -Indexes\nRequire all denied\n";
        if (@file_put_contents($rules, $contents . $block, LOCK_EX) === false) {
            return ['inside' => true, 'secure' => false, 'mode' => 'webroot-unprotected'];
        }
    }
    return ['inside' => true, 'secure' => true, 'mode' => 'apache-denied'];
}

function runSelfTest(PDO $db): bool
{
    $key = '__install_probe_' . bin2hex(random_bytes(8));
    try {
        $db->beginTransaction();
        $insert = $db->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)');
        $insert->execute([':key' => $key, ':value' => 'ok']);
        $select = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $select->execute([':key' => $key]);
        $ok = $select->fetchColumn() === 'ok';
        $delete = $db->prepare('DELETE FROM settings WHERE key = :key');
        $delete->execute([':key' => $key]);
        $db->commit();
        return $ok;
    } catch (Throwable) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

/** A bounded 1,000-row retention batch, safe to resume on a later request. */
function cleanRetentionBatch(PDO $db, ?int $retention = null, int $limit = 1000): bool
{
    $retention ??= (int) setting($db, 'retention_days', '180');
    $retention = max(30, min(365, $retention));
    $limit = max(1, min(1000, $limit));
    $cutoff = gmdate('Y-m-d', time() - $retention * 86400);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('DELETE FROM pageviews_daily WHERE rowid IN (SELECT rowid FROM pageviews_daily WHERE day < :cutoff ORDER BY day LIMIT :limit)');
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $pageCount = $stmt->rowCount();
        $stmt = $db->prepare('DELETE FROM referrers_daily WHERE rowid IN (SELECT rowid FROM referrers_daily WHERE day < :cutoff ORDER BY day LIMIT :limit)');
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $referrerCount = $stmt->rowCount();
        $complete = $pageCount < $limit && $referrerCount < $limit;
        setSetting($db, 'last_cleanup_at', gmdate('Y-m-d H:i:s'));
        $db->commit();
        return $complete;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function runScheduledCleanupIfDue(PDO $db): void
{
    if (schemaVersion($db) !== CURRENT_SCHEMA_VERSION || !tableExists($db, 'settings')) return;
    try { $last = setting($db, 'last_cleanup_at', ''); }
    catch (Throwable) { return; }
    $lastTime = $last === '' ? 0 : (strtotime($last . ' UTC') ?: 0);
    if (time() - $lastTime < 86400) return;
    try { cleanRetentionBatch($db); } catch (Throwable) { /* Analytics maintenance must not affect a page. */ }
}

function requestCountry(): string
{
    // Optional, trusted edge header only. The raw address is never read or persisted.
    $header = getenv('BARELYTICS_COUNTRY_HEADER') ?: 'HTTP_CF_IPCOUNTRY';
    $value = $_SERVER[$header] ?? '';
    return is_string($value) && preg_match('/^[A-Za-z]{2}$/', $value) ? strtoupper($value) : 'XX';
}

function recordPageview(PDO $db, string $path, string $country, ?string $host): void
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO pageviews_daily (day, path, country, views) VALUES (:day, :path, :country, 1) ON CONFLICT(day, path, country) DO UPDATE SET views = views + 1');
        $stmt->execute([':day' => gmdate('Y-m-d'), ':path' => $path, ':country' => $country]);
        if ($host !== null) {
            $stmt = $db->prepare('INSERT INTO referrers_daily (day, path, referrer_host, views) VALUES (:day, :path, :host, 1) ON CONFLICT(day, path, referrer_host) DO UPDATE SET views = views + 1');
            $stmt->execute([':day' => gmdate('Y-m-d'), ':path' => $path, ':host' => $host]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function countRequest(PDO $db, string $path): bool
{
    if (schemaVersion($db) !== CURRENT_SCHEMA_VERSION) return false;
    $patterns = DEFAULT_BOT_PATTERNS;
    $custom = setting($db, 'bot_patterns', '');
    if ($custom !== '') $patterns = array_merge($patterns, array_filter(array_map('trim', explode("\n", $custom))));
    if (isBot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $patterns)) return false;
    $country = setting($db, 'country_collection', '1') === '1' ? requestCountry() : 'XX';
    $host = setting($db, 'referrer_collection', '0') === '1' ? referrerHost((string) ($_SERVER['HTTP_REFERER'] ?? '')) : null;
    recordPageview($db, $path, $country, $host);
    return true;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf'];
}

function validCsrf(mixed $value): bool
{
    return is_string($value) && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $value);
}

function startAdminSession(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => requestBasePath(),
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Generic server-rendered PHP integration. Call once per document response. */
function track(): void
{
    try {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parts = parse_url($requestUri);
        $path = is_array($parts) && isset($parts['path']) ? normalizePath((string) $parts['path']) : null;
        if ($path === null) return;
        $db = connectDatabase();
        countRequest($db, $path);
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(static function () use ($db): void {
                if (!function_exists('fastcgi_finish_request')) return;
                fastcgi_finish_request();
                runScheduledCleanupIfDue($db);
            });
        }
    } catch (Throwable) {
        // Analytics failures must not break the host application's response.
    }
}

function adminHeaders(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}
