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
const CURRENT_SCHEMA_VERSION = 2;
const APPLICATION_VERSION = '1.1.0';
const PRIVACY_DEFAULTS = [
    'country_collection' => false,
    'referrer_collection' => false,
    'browser_collection' => false,
    'device_collection' => false,
    'os_collection' => false,
];
const DEFAULT_PATH_EXCLUSIONS = ['/admin/*', '/admin.php', '/account/*', '/checkout/*', '/customer/*', '/patient/*', '/profile/*', '/private/*'];
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
    $identifierPattern = '~(?<=/)(?:[0-9]{6,}|[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16,}|[A-Za-z0-9_-]{32,})(?=/|$)~i';
    if ($decodedPath !== $path && preg_match($identifierPattern, $decodedPath)) return null;
    $path = preg_replace($identifierPattern, ':id', $path) ?? $path;
    if (preg_match('~(?<=/)[^/]*@[^/]*(?=/|$)~', $path)) return null;
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

/** Read one value and release the SQLite cursor before returning. */
function queryColumn(PDO $db, string $sql, array $params = []): mixed
{
    $statement = $db->prepare($sql);
    try {
        $statement->execute($params);
        return $statement->fetchColumn();
    } finally {
        $statement->closeCursor();
    }
}

/** Read all rows and release the SQLite cursor before returning. */
function queryRows(PDO $db, string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): array
{
    $statement = $db->prepare($sql);
    try {
        $statement->execute($params);
        return $statement->fetchAll($fetchMode);
    } finally {
        $statement->closeCursor();
    }
}

/** Execute a statement, capture its affected row count, and release its cursor. */
function executeStatement(PDO $db, string $sql, array $params = []): int
{
    $statement = $db->prepare($sql);
    try {
        $statement->execute($params);
        return $statement->rowCount();
    } finally {
        $statement->closeCursor();
    }
}

function passwordMeetsMinimum(string $password): bool
{
    if (!preg_match('//u', $password)) return false;
    $characters = preg_match_all('/./us', $password);
    return $characters !== false && $characters >= 12;
}

function setting(PDO $db, string $key, string $default): string
{
    $value = queryColumn($db, 'SELECT value FROM settings WHERE key = :key', [':key' => $key]);
    return $value === false ? $default : (string) $value;
}

function setSetting(PDO $db, string $key, string $value): void
{
    executeStatement($db, 'INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [':key' => $key, ':value' => $value]);
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

function connectDatabase(bool $configureForWrites = true): PDO
{
    $path = databasePath();
    $parent = dirname($path);
    if ($configureForWrites && !is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new \RuntimeException('Analytics storage is unavailable.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if (!$configureForWrites) return $db;
    $db->exec('PRAGMA busy_timeout = 1000');
    $journalMode = null;
    try {
        $journalMode = $db->query('PRAGMA journal_mode = WAL');
        $journalMode->fetchColumn();
        $journalMode->closeCursor();
        unset($journalMode);
    } catch (Throwable) {
        if ($journalMode instanceof \PDOStatement) { try { $journalMode->closeCursor(); } catch (Throwable) { } }
        unset($journalMode);
        /* WAL is optional on constrained hosts. */
    }
    @chmod($path, 0600);
    return $db;
}

/** Open an existing database using SQLite's read-only URI mode. */
function connectReadOnlyDatabase(): PDO
{
    $path = databasePath();
    if (!is_file($path)) throw new \RuntimeException('Analytics database is unavailable.');
    $uriPath = str_replace('%2F', '/', rawurlencode($path));
    return new PDO('sqlite:file:' . $uriPath . '?mode=ro', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Run a real file-backed SQLite write/read/delete/commit/drop probe. */
function databaseWriteCheck(PDO $db): array
{
    $table = '__barelytics_probe_' . bin2hex(random_bytes(8));
    $insert = $select = $delete = null;
    $steps = [];
    $stage = 'CREATE TABLE';
    try {
        $db->exec('CREATE TABLE ' . $table . ' (value TEXT NOT NULL)');
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        $stage = 'BEGIN';
        $db->beginTransaction();
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        $value = 'write-test-' . bin2hex(random_bytes(8));
        $stage = 'INSERT';
        $insert = $db->prepare('INSERT INTO ' . $table . ' (value) VALUES (:value)');
        $insert->execute([':value' => $value]);
        $insert->closeCursor();
        unset($insert);
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        $stage = 'SELECT';
        $select = $db->prepare('SELECT value FROM ' . $table . ' WHERE value = :value LIMIT 1');
        $select->execute([':value' => $value]);
        $ready = $select->fetchColumn() === $value;
        $select->closeCursor();
        unset($select);
        if (!$ready) throw new \RuntimeException('SQLite did not return the written probe value.');
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        $stage = 'DELETE';
        $delete = $db->prepare('DELETE FROM ' . $table . ' WHERE value = :value');
        $delete->execute([':value' => $value]);
        $deleted = $delete->rowCount() === 1;
        $delete->closeCursor();
        unset($delete);
        if (!$deleted) throw new \RuntimeException('SQLite did not delete the probe value.');
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        $stage = 'COMMIT';
        $db->commit();
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        $stage = 'DROP TABLE';
        $db->exec('DROP TABLE ' . $table);
        $steps[] = ['name' => $stage, 'status' => 'PASS'];
        return ['ready' => true, 'error' => null, 'steps' => $steps];
    } catch (Throwable $error) {
        foreach ([$insert, $select, $delete] as $statement) {
            if ($statement instanceof \PDOStatement) { try { $statement->closeCursor(); } catch (Throwable) { } }
        }
        unset($statement, $insert, $select, $delete);
        if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable) { } }
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $table);
            $steps[] = ['name' => 'CLEANUP', 'status' => 'PASS'];
        } catch (Throwable) {
            $steps[] = ['name' => 'CLEANUP', 'status' => 'FAIL'];
        }
        $message = diagnosticError($error, [databasePath(), dataDirectory()]);
        $steps[] = ['name' => $stage, 'status' => 'FAIL', 'error' => $message];
        return ['ready' => false, 'error' => $message, 'steps' => $steps];
    }
}

/** Check WAL sidecars independently so a WAL diagnostic cannot alter the CRUD result. */
function sqliteWalCheck(PDO $db, string $path): array
{
    try {
        $mode = strtolower((string) queryColumn($db, 'PRAGMA journal_mode'));
        if ($mode !== 'wal') return ['ready' => true, 'mode' => $mode, 'error' => null, 'wal_exists' => null, 'wal_writable' => null, 'shm_exists' => null, 'shm_writable' => null];
        $wal = $path . '-wal';
        $shm = $path . '-shm';
        $walExists = is_file($wal);
        $walWritable = $walExists && is_writable($wal);
        $shmExists = is_file($shm);
        $shmWritable = $shmExists && is_writable($shm);
        $ready = $walExists && $walWritable && $shmExists && $shmWritable;
        return ['ready' => $ready, 'mode' => $mode, 'error' => $ready ? null : 'WAL is active, but its sidecar files are missing or not writable.', 'wal_exists' => $walExists, 'wal_writable' => $walWritable, 'shm_exists' => $shmExists, 'shm_writable' => $shmWritable];
    } catch (Throwable $error) {
        return ['ready' => false, 'mode' => 'unavailable', 'error' => diagnosticError($error, [$path, dirname($path)]), 'wal_exists' => null, 'wal_writable' => null, 'shm_exists' => null, 'shm_writable' => null];
    }
}

/** Return a short diagnostic without stack traces or known private storage paths. */
function diagnosticError(Throwable $error, array $privatePaths = []): string
{
    $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($error->getMessage())) ?? 'The check failed.';
    foreach ($privatePaths as $path) {
        if (is_string($path) && $path !== '') $message = str_replace($path, '[private path]', $message);
    }
    $message = trim(preg_replace('/\s+/', ' ', $message) ?? 'The check failed.');
    return $message === '' ? 'The check failed.' : substr($message, 0, 180);
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
            executeStatement($db, 'INSERT INTO settings (key, value) VALUES (:key, :value)', [':key' => $probeKey, ':value' => 'ok']);
            $valid = queryColumn($db, 'SELECT value FROM settings WHERE key = :key', [':key' => $probeKey]) === 'ok';
            executeStatement($db, 'DELETE FROM settings WHERE key = :key', [':key' => $probeKey]);
            if (!$valid || !tableExists($db, 'pageviews_daily') || !tableExists($db, 'referrers_daily') || !tableExists($db, 'settings')) {
                throw new \RuntimeException('Migration verification failed.');
            }
            setSetting($db, 'application_version', '1.0.0');
            setSetting($db, 'last_migration_at', gmdate('Y-m-d H:i:s'));
        },
        2 => static function (PDO $db): void {
            $db->exec('CREATE TABLE IF NOT EXISTS dimensions_daily (day TEXT NOT NULL, path TEXT NOT NULL, dimension TEXT NOT NULL, value TEXT NOT NULL, views INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, path, dimension, value))');
            $db->exec('CREATE TABLE IF NOT EXISTS privacy_configuration_history (timestamp TEXT NOT NULL, profile TEXT NOT NULL, effective_configuration_json TEXT NOT NULL, configuration_hash TEXT NOT NULL, application_version TEXT NOT NULL, schema_version INTEGER NOT NULL)');
            // Upgrades must never turn on optional collection by inheriting legacy code defaults.
            foreach (PRIVACY_DEFAULTS as $key => $default) {
                if (setting($db, $key, '') === '') setSetting($db, $key, '0');
            }
            if (setting($db, 'privacy_path_exclusions', '') === '') setSetting($db, 'privacy_path_exclusions', implode("\n", DEFAULT_PATH_EXCLUSIONS));
            setSetting($db, 'application_version', APPLICATION_VERSION);
            setSetting($db, 'last_migration_at', gmdate('Y-m-d H:i:s'));
        },
    ];
    $applied = (int) queryColumn($db, 'SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
    foreach ($migrations as $version => $migration) {
        if ($version <= $applied) continue;
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ((int) queryColumn($db, 'SELECT COALESCE(MAX(version), 0) FROM schema_migrations') < $version) {
                $migration($db);
                executeStatement($db, 'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)', [':version' => $version, ':applied_at' => gmdate('Y-m-d H:i:s')]);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            else { try { $db->exec('ROLLBACK'); } catch (Throwable) { } }
            throw $error;
        }
        $applied = $version;
    }
    if (tableExists($db, 'privacy_configuration_history')) recordPrivacyConfiguration($db);
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
    return queryColumn($db, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name", [':name' => $table]) !== false;
}

function schemaVersion(PDO $db): int
{
    if (!tableExists($db, 'schema_migrations')) return 0;
    return (int) queryColumn($db, 'SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
}

/** Canonical effective configuration shared by tracking, administration and audit. Invalid values fail closed. */
function effectivePrivacyConfig(PDO $db): array
{
    $config = ['pageviews' => true, 'retention_days' => 180];
    foreach (PRIVACY_DEFAULTS as $key => $default) {
        try { $value = setting($db, $key, '0'); }
        catch (Throwable) { $value = '0'; }
        $config[$key] = $value === '1';
    }
    try {
        $retention = setting($db, 'retention_days', '180');
        $config['retention_days'] = in_array($retention, ['30', '90', '180', '365'], true) ? (int) $retention : 180;
        $raw = setting($db, 'privacy_path_exclusions', implode("\n", DEFAULT_PATH_EXCLUSIONS));
        $config['path_exclusions'] = array_values(array_unique(array_filter(array_map('trim', explode("\n", $raw)), static fn($v) => $v !== '' && strlen($v) <= 200)));
        $botText = setting($db, 'bot_patterns', '');
        $config['bot_patterns'] = array_values(array_unique(array_filter(array_map('trim', explode("\n", $botText)), static fn($v) => $v !== '' && strlen($v) <= 200)));
    } catch (Throwable) {
        $config['path_exclusions'] = DEFAULT_PATH_EXCLUSIONS;
        $config['bot_patterns'] = [];
    }
    sort($config['path_exclusions'], SORT_STRING);
    sort($config['bot_patterns'], SORT_STRING);
    $config['trusted_country_header'] = getenv('BARELYTICS_COUNTRY_HEADER') ?: 'HTTP_CF_IPCOUNTRY';
    $config['profile'] = count(array_filter(array_intersect_key($config, PRIVACY_DEFAULTS))) === 0 ? 'strict' : 'extended';
    // Identifiers, storage, generic events, query strings and third-party services are unsupported.
    $config += ['visitor_id' => false, 'session_id' => false, 'fingerprinting' => false, 'cookies' => false,
        'local_storage' => false, 'session_storage' => false, 'indexeddb' => false, 'cross_site_tracking' => false,
        'event_collection' => false, 'query_string_collection' => false, 'third_party_analytics' => false,
        'user_agent_collection' => false, 'screen_dimensions' => false, 'language_collection' => false, 'custom_parameters' => false];
    return $config;
}

function privacyFingerprint(PDO $db): string
{
    $config = effectivePrivacyConfig($db);
    ksort($config);
    return hash('sha256', json_encode(['application_version' => APPLICATION_VERSION, 'schema_version' => schemaVersion($db), 'configuration' => $config], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function recordPrivacyConfiguration(PDO $db): void
{
    if (!tableExists($db, 'privacy_configuration_history')) return;
    $config = effectivePrivacyConfig($db);
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $hash = privacyFingerprint($db);
    if (queryColumn($db, 'SELECT configuration_hash FROM privacy_configuration_history ORDER BY rowid DESC LIMIT 1') === $hash) return;
    executeStatement($db, 'INSERT INTO privacy_configuration_history (timestamp, profile, effective_configuration_json, configuration_hash, application_version, schema_version) VALUES (:timestamp, :profile, :json, :hash, :version, :schema)', [
        ':timestamp' => gmdate('Y-m-d\TH:i:s\Z'), ':profile' => strtoupper($config['profile']), ':json' => $json,
        ':hash' => $hash, ':version' => APPLICATION_VERSION, ':schema' => schemaVersion($db)]);
}

function pathIsExcluded(string $path, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        $quoted = preg_quote($pattern, '~');
        $regex = '~^' . str_replace('\\*', '.*', $quoted) . '$~';
        if (preg_match($regex, $path)) return true;
    }
    return false;
}

function schemaAudit(PDO $db, string $profile, ?array $config = null): array
{
    $config ??= [];
    $allowed = ['day', 'path', 'views', 'country'];
    $tables = ['pageviews_daily', 'referrers_daily', 'dimensions_daily'];
    $unexpected = [];
    $tableColumns = [];
    foreach ($tables as $table) {
        if (!tableExists($db, $table)) {
            $required = $table === 'pageviews_daily'
                || ($table === 'referrers_daily' && !empty($config['referrer_collection']))
                || ($table === 'dimensions_daily' && (!empty($config['browser_collection']) || !empty($config['device_collection']) || !empty($config['os_collection'])));
            if ($required) $unexpected[] = 'missing table ' . $table;
            continue;
        }
        $columns = queryRows($db, 'PRAGMA table_info(' . $table . ')');
        $tableColumns[$table] = array_map(static fn($column) => strtolower((string) $column['name']), $columns);
        $required = $table === 'dimensions_daily' ? ['day','path','dimension','value','views']
            : ($table === 'referrers_daily' ? ['day','path','referrer_host','views'] : ['day','path','country','views']);
        foreach ($required as $column) if (!in_array($column, $tableColumns[$table], true)) $unexpected[] = 'missing column ' . $table . '.' . $column;
        foreach ($columns as $column) {
            $name = strtolower((string) $column['name']);
            $valid = $table === 'dimensions_daily' ? in_array($name, ['day','path','dimension','value','views'], true)
                : ($table === 'referrers_daily' ? in_array($name, ['day','path','referrer_host','views'], true) : in_array($name, $allowed, true));
            if (!$valid) $unexpected[] = $table . '.' . $name;
        }
    }
    $allTables = queryRows($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'", [], PDO::FETCH_COLUMN);
    foreach ($allTables as $table) {
        if (in_array($table, ['pageviews_daily','referrers_daily','dimensions_daily','privacy_configuration_history','settings','schema_migrations','maintenance_state','schema_version'], true)) continue;
        $unexpected[] = 'unrecognized table ' . (string) $table;
        $quotedTable = '"' . str_replace('"', '""', (string) $table) . '"';
        $columns = queryRows($db, 'PRAGMA table_info(' . $quotedTable . ')');
        foreach ($columns as $column) {
            $name = strtolower((string) $column['name']);
            if (preg_match('/(^|_)(ip|ip_address|visitor_id|user_id|session_id|cookie_id|fingerprint|user_agent|screen_width|screen_height)(_|$)/', $name)) $unexpected[] = $table . '.' . $name;
        }
    }
    if (isset($tableColumns['pageviews_daily']) && in_array('country', $tableColumns['pageviews_daily'], true) && empty($config['country_collection']) && (int) queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE country <> 'XX'") > 0) $unexpected[] = 'country aggregate data exists while country collection is disabled';
    if (tableExists($db, 'referrers_daily') && empty($config['referrer_collection']) && (int) queryColumn($db, 'SELECT COUNT(*) FROM referrers_daily') > 0) $unexpected[] = 'referrer aggregate data exists while referrer collection is disabled';
    if (isset($tableColumns['dimensions_daily']) && in_array('dimension', $tableColumns['dimensions_daily'], true)) {
        foreach (['browser_collection' => 'browser', 'device_collection' => 'device', 'os_collection' => 'os'] as $flag => $dimension) {
            if (empty($config[$flag]) && (int) queryColumn($db, 'SELECT COUNT(*) FROM dimensions_daily WHERE dimension = :dimension', [':dimension' => $dimension]) > 0) $unexpected[] = $dimension . ' aggregate data exists while collection is disabled';
        }
        if ((int) queryColumn($db, "SELECT COUNT(*) FROM dimensions_daily WHERE dimension NOT IN ('browser','device','os')") > 0) $unexpected[] = 'unsupported dimension data exists';
    }
    if (tableExists($db, 'privacy_configuration_history')) {
        $columns = queryRows($db, 'PRAGMA table_info(privacy_configuration_history)');
        foreach ($columns as $column) if (!in_array(strtolower((string) $column['name']), ['timestamp','profile','effective_configuration_json','configuration_hash','application_version','schema_version'], true)) $unexpected[] = 'privacy_configuration_history.' . $column['name'];
    } else $unexpected[] = 'missing table privacy_configuration_history';
    return ['status' => $unexpected ? 'FAIL' : 'PASS', 'tables' => $tables, 'unexpected' => $unexpected];
}

function runtimePrivacyChecks(): array
{
    $runtimeFiles = [dirname(__DIR__) . '/track.js', dirname(__DIR__) . '/track.php', __FILE__];
    $runtimeCode = array_map(static fn($file) => is_file($file) ? (string) file_get_contents($file) : '', $runtimeFiles);
    $runtimeAvailable = !in_array('', $runtimeCode, true);
    $remotePattern = '~https?' . ':' . '/' . '/~i';
    $remoteEndpoint = false;
    $remoteClient = false;
    foreach ($runtimeCode as $source) {
        if (preg_match($remotePattern, $source)) $remoteEndpoint = true;
        if (preg_match('/\b(?:curl_init|fsockopen|pfsockopen)\s*\(/i', $source)) $remoteClient = true;
    }
    $localTracker = $runtimeAvailable && str_contains($runtimeCode[0], "new URL('track.php', current.src)")
        && str_contains($runtimeCode[0], 'navigator.sendBeacon') && str_contains($runtimeCode[0], 'fetch(endpoint');
    return [
        ['name' => 'Same-origin tracker endpoint', 'status' => $localTracker && !$remoteEndpoint ? 'PASS' : 'FAIL'],
        ['name' => 'No third-party analytics or enrichment client in runtime sources', 'status' => $runtimeAvailable && !$remoteEndpoint && !$remoteClient ? 'PASS' : 'FAIL'],
    ];
}

function privacySelfTest(PDO $db): array
{
    $config = effectivePrivacyConfig($db);
    $schema = schemaAudit($db, strtoupper($config['profile']), $config);
    $checks = [];
    $checks[] = ['name' => 'Effective privacy configuration loaded', 'status' => 'PASS'];
    foreach (['country_collection','referrer_collection','browser_collection','device_collection','os_collection'] as $key) {
        $checks[] = ['name' => str_replace('_collection', '', $key) . ' collection ' . ($config[$key] ? 'enabled by administrator' : 'disabled'), 'status' => 'PASS'];
        try { $raw = setting($db, $key, '0'); $valid = in_array($raw, ['0','1'], true); }
        catch (Throwable) { $valid = false; }
        if (!$valid) $checks[] = ['name' => $key . ' configuration invalid; effective collection fails closed', 'status' => 'WARN'];
    }
    foreach (['cookies','local_storage','session_storage','indexeddb','visitor_id','session_id','fingerprinting','cross_site_tracking','event_collection','query_string_collection','third_party_analytics','user_agent_collection','screen_dimensions','language_collection','custom_parameters'] as $key) {
        $checks[] = ['name' => $key . ' disabled', 'status' => $config[$key] ? 'FAIL' : 'PASS'];
    }
    try { $rawRetention = setting($db, 'retention_days', '180'); $validRetention = in_array($rawRetention, ['30','90','180','365'], true); }
    catch (Throwable) { $validRetention = false; }
    if (!$validRetention) $checks[] = ['name' => 'Invalid retention configuration; safe 180-day default is effective', 'status' => 'WARN'];
    try { $rawBots = setting($db, 'bot_patterns', ''); $validBots = strlen($rawBots) <= 2000 && count(array_filter(explode("\n", $rawBots), static fn($line) => strlen(trim($line)) > 200)) === 0; }
    catch (Throwable) { $validBots = false; }
    if (!$validBots) $checks[] = ['name' => 'Invalid bot filter configuration; oversized patterns are ignored', 'status' => 'WARN'];
    $checks[] = ['name' => 'Aggregate schema audit', 'status' => $schema['status']];
    $checks[] = ['name' => 'IP address not in Barelytics schema', 'status' => $schema['status']];
    $checks[] = ['name' => 'Retention period configured', 'status' => in_array($config['retention_days'], [30,90,180,365], true) ? 'PASS' : 'FAIL'];
    $checks = array_merge($checks, runtimePrivacyChecks());
    return ['checks' => $checks, 'result' => in_array('FAIL', array_column($checks, 'status'), true) ? 'FAIL' : (in_array('WARN', array_column($checks, 'status'), true) ? 'WARN' : 'PASS'), 'schema' => $schema];
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
    $insert = $select = $delete = null;
    try {
        $db->beginTransaction();
        $insert = $db->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)');
        $insert->execute([':key' => $key, ':value' => 'ok']);
        $insert->closeCursor();
        unset($insert);
        $select = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $select->execute([':key' => $key]);
        $ok = $select->fetchColumn() === 'ok';
        $select->closeCursor();
        unset($select);
        $delete = $db->prepare('DELETE FROM settings WHERE key = :key');
        $delete->execute([':key' => $key]);
        $delete->closeCursor();
        unset($delete);
        $db->commit();
        return $ok;
    } catch (Throwable) {
        foreach ([$insert, $select, $delete] as $statement) {
            if ($statement instanceof \PDOStatement) { try { $statement->closeCursor(); } catch (Throwable) { } }
        }
        unset($statement, $insert, $select, $delete);
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

/** A bounded 1,000-row retention batch, safe to resume on a later request. */
function cleanRetentionBatch(PDO $db, ?int $retention = null, int $limit = 1000): bool
{
    $retention ??= effectivePrivacyConfig($db)['retention_days'];
    $retention = max(30, min(365, $retention));
    $limit = max(1, min(1000, $limit));
    $cutoff = gmdate('Y-m-d', time() - $retention * 86400);
    $db->beginTransaction();
    try {
        $pageCount = executeStatement($db, 'DELETE FROM pageviews_daily WHERE rowid IN (SELECT rowid FROM pageviews_daily WHERE day < :cutoff ORDER BY day LIMIT :limit)', [':cutoff' => $cutoff, ':limit' => $limit]);
        $referrerCount = executeStatement($db, 'DELETE FROM referrers_daily WHERE rowid IN (SELECT rowid FROM referrers_daily WHERE day < :cutoff ORDER BY day LIMIT :limit)', [':cutoff' => $cutoff, ':limit' => $limit]);
        $dimensionCount = 0;
        if (tableExists($db, 'dimensions_daily')) {
            $dimensionCount = executeStatement($db, 'DELETE FROM dimensions_daily WHERE rowid IN (SELECT rowid FROM dimensions_daily WHERE day < :cutoff ORDER BY day LIMIT :limit)', [':cutoff' => $cutoff, ':limit' => $limit]);
        }
        $complete = $pageCount < $limit && $referrerCount < $limit && $dimensionCount < $limit;
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

function recordPageview(PDO $db, string $path, string $country = 'XX', ?string $host = null, array $dimensions = []): void
{
    $db->beginTransaction();
    try {
        executeStatement($db, 'INSERT INTO pageviews_daily (day, path, country, views) VALUES (:day, :path, :country, 1) ON CONFLICT(day, path, country) DO UPDATE SET views = views + 1', [':day' => gmdate('Y-m-d'), ':path' => $path, ':country' => $country]);
        if ($host !== null) {
            executeStatement($db, 'INSERT INTO referrers_daily (day, path, referrer_host, views) VALUES (:day, :path, :host, 1) ON CONFLICT(day, path, referrer_host) DO UPDATE SET views = views + 1', [':day' => gmdate('Y-m-d'), ':path' => $path, ':host' => $host]);
        }
        foreach ($dimensions as $dimension => $value) {
            if (in_array($dimension, ['browser', 'device', 'os'], true) && is_string($value) && $value !== '') {
                executeStatement($db, 'INSERT INTO dimensions_daily (day, path, dimension, value, views) VALUES (:day, :path, :dimension, :value, 1) ON CONFLICT(day, path, dimension, value) DO UPDATE SET views = views + 1', [':day' => gmdate('Y-m-d'), ':path' => $path, ':dimension' => $dimension, ':value' => $value]);
            }
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
    $config = effectivePrivacyConfig($db);
    if (pathIsExcluded($path, $config['path_exclusions'])) return false;
    $patterns = array_merge(DEFAULT_BOT_PATTERNS, $config['bot_patterns']);
    if (isBot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $patterns)) return false;
    $country = $config['country_collection'] ? requestCountry() : 'XX';
    $host = $config['referrer_collection'] ? referrerHost((string) ($_SERVER['HTTP_REFERER'] ?? '')) : null;
    $dimensions = [];
    if ($config['browser_collection'] || $config['device_collection'] || $config['os_collection']) {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($config['browser_collection']) $dimensions['browser'] = str_contains($ua, 'Firefox/') ? 'Firefox' : ((str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/')) ? 'Edge' : ((str_contains($ua, 'Chrome/')) ? 'Chrome' : ((str_contains($ua, 'Safari/')) ? 'Safari' : 'Other')));
        if ($config['os_collection']) $dimensions['os'] = stripos($ua, 'Windows') !== false ? 'Windows' : (stripos($ua, 'Android') !== false ? 'Android' : (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false ? 'iOS' : (stripos($ua, 'Mac OS') !== false ? 'macOS' : (stripos($ua, 'Linux') !== false ? 'Linux' : 'Other'))));
        if ($config['device_collection']) $dimensions['device'] = stripos($ua, 'Tablet') !== false || stripos($ua, 'iPad') !== false ? 'tablet' : (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false ? 'mobile' : 'desktop');
    }
    recordPageview($db, $path, $country, $host, $dimensions);
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
