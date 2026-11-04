<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/barelytics/src/Barelytics.php';
require_once dirname(__DIR__) . '/integrations/wordpress/barelytics/storage.php';

use function Barelytics\escape;
use function Barelytics\isBot;
use function Barelytics\normalizePath;
use function Barelytics\passwordMeetsMinimum;
use function Barelytics\referrerHost;
use function Barelytics\requestCountry;
use function Barelytics\databasePath;
use function Barelytics\databaseWriteCheck;
use function Barelytics\queryColumn;
use function Barelytics\sqliteWalCheck;
use function Barelytics\runtimePrivacyChecks;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $checks++;
};

$assert(\Barelytics\SUPPORTED_PHP_MIN === 80200, 'supported PHP baseline excludes the end-of-life 8.1 branch');
$assert(\Barelytics\SUPPORTED_PHP_MAX === 80599, 'supported PHP ceiling matches the CI matrix');
$wpRoot = sys_get_temp_dir() . '/barelytics-wp-path-' . bin2hex(random_bytes(5));
mkdir($wpRoot . '/public/wp', 0700, true);
mkdir($wpRoot . '/private', 0700, true);
$wpDocroot = $wpRoot . '/public';
$wpInstall = $wpDocroot . '/wp';
$wpDefaultData = barelytics_wp_private_directory($wpDocroot, $wpInstall);
$assert(!barelytics_wp_path_is_inside($wpDefaultData, $wpDocroot), 'default WordPress storage is outside the document root');
$wpCustomData = barelytics_wp_private_directory($wpDocroot, $wpInstall, $wpRoot . '/private/custom');
$assert($wpCustomData === $wpRoot . '/private/custom', 'custom out-of-root WordPress storage override is accepted');
$unsafeOverrideRejected = false;
try { barelytics_wp_private_directory($wpDocroot, $wpInstall, $wpDocroot . '/wp/wp-content/private'); }
catch (RuntimeException) { $unsafeOverrideRejected = true; }
$assert($unsafeOverrideRejected, 'custom WordPress storage inside the document root is rejected');
$assert(barelytics_wp_path_is_inside($wpDocroot . '/wp-content', $wpDocroot), 'WordPress content under the document root is recognized');
$symlinkPath = $wpRoot . '/public/private-link';
if (@symlink($wpRoot . '/private', $symlinkPath)) {
    $assert(!barelytics_wp_path_is_inside($symlinkPath, $wpDocroot), 'symlinked out-of-root paths resolve outside the document root');
    $insideLink = $wpRoot . '/outside-link';
    @symlink($wpDocroot . '/wp', $insideLink);
    $assert(barelytics_wp_path_is_inside($insideLink, $wpDocroot), 'symlinks into the document root are recognized');
    $unsafeSymlinkRejected = false;
    try { barelytics_wp_private_directory($wpDocroot, $wpInstall, $insideLink); }
    catch (RuntimeException) { $unsafeSymlinkRejected = true; }
    $assert($unsafeSymlinkRejected, 'custom symlinked storage inside the document root is rejected');
}
$htaccessBlock = $wpRoot . '/blocked/.htaccess';
mkdir(dirname($htaccessBlock), 0700, true);
mkdir($htaccessBlock, 0700);
$assert(!barelytics_wp_protect_legacy_directory(dirname($htaccessBlock)), 'failed deny-rule creation is reported');
$generatedRules = $wpRoot . '/legacy-generated';
mkdir($generatedRules, 0700);
file_put_contents($generatedRules . '/.htaccess', "Deny from all\n");
$assert(barelytics_wp_protect_legacy_directory($generatedRules)
    && file_get_contents($generatedRules . '/.htaccess') === "# Barelytics generated data protection\nRequire all denied\n", 'recognized Apache legacy rule is upgraded to Apache 2.4 syntax');
$adminRules = $wpRoot . '/legacy-admin';
mkdir($adminRules, 0700);
file_put_contents($adminRules . '/.htaccess', "# Administrator rules\nRequire ip 192.0.2.4\n");
$assert(!barelytics_wp_protect_legacy_directory($adminRules)
    && file_get_contents($adminRules . '/.htaccess') === "# Administrator rules\nRequire ip 192.0.2.4\n", 'administrator-maintained Apache rules are preserved');
$projectRoot = dirname(__DIR__);
$installerSource = (string) file_get_contents($projectRoot . '/public/barelytics/install.php');
$assert((bool) preg_match('/if \(PHP_VERSION_ID < (\d+) \|\| PHP_VERSION_ID > (\d+)\)/', $installerSource, $runtimeGuard)
    && (int) $runtimeGuard[1] === \Barelytics\SUPPORTED_PHP_MIN
    && (int) $runtimeGuard[2] === \Barelytics\SUPPORTED_PHP_MAX, 'installer runtime gate matches the supported PHP range');
$assert(str_contains($installerSource, 'Barelytics supports PHP 8.2 through 8.5')
    && str_contains($installerSource, 'Supported range is PHP 8.2 through 8.5'), 'installer messages match the supported PHP range');
$ciWorkflow = (string) file_get_contents($projectRoot . '/.github/workflows/ci.yml');
$releaseWorkflow = (string) file_get_contents($projectRoot . '/.github/workflows/release.yml');
$phpAction = 'shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962';
$rubyAction = 'ruby/setup-ruby@de492cf36cbab201753d720ce4f4e336cd688c94';
$assert(substr_count($ciWorkflow . $releaseWorkflow, $phpAction) === 4, 'all PHP setup action references are pinned to the reviewed upstream commit');
$assert(substr_count($ciWorkflow . $releaseWorkflow, $rubyAction) === 2, 'all Ruby setup action references are pinned to the reviewed upstream commit');
$assert(str_contains((string) file_get_contents($projectRoot . '/.github/dependabot.yml'), 'package-ecosystem: github-actions')
    && str_contains((string) file_get_contents($projectRoot . '/.github/dependabot.yml'), 'interval: weekly'), 'Dependabot proposes weekly GitHub Actions updates');
$phpMatrixVersions = static function (string $workflow): array {
    if (!preg_match('/^\s+php: \[([^]]+)\]$/m', $workflow, $match)) return [];
    preg_match_all("/'(8\.\d+)'/", $match[1], $versions);
    return array_map(static function (string $version): int {
        [$major, $minor] = array_map('intval', explode('.', $version));
        return ($major * 10000) + ($minor * 100) + 99;
    }, $versions[1]);
};
$expectedPhpMatrix = [80299, 80399, 80499, 80599];
$assert($phpMatrixVersions($ciWorkflow) === $expectedPhpMatrix, 'CI PHP matrix matches the supported PHP range');
$assert($phpMatrixVersions($releaseWorkflow) === $expectedPhpMatrix, 'release PHP matrix matches the supported PHP range');
$runtimeDocs = [
    'README.md' => '/PHP \| 8\.2–8\.5/',
    'INSTALL.md' => '/PHP 8\.2–8\.5/',
    'CONTRIBUTING.md' => '/PHP 8\.2–8\.5/',
    'docs/installation.md' => '/PHP 8\.2 through 8\.5/',
    'docs/deployment.md' => '/PHP range is 8\.2–8\.5/',
    'docs/shared-hosting.md' => '/PHP 8\.2–8\.5/',
    'docs/facts.md' => '/PHP 8\.2–8\.5/',
];
foreach ($runtimeDocs as $path => $pattern) {
    $document = (string) file_get_contents($projectRoot . '/' . $path);
    $assert(preg_match($pattern, $document) === 1, "{$path} documents the supported PHP range");
}
$lifecycleDocs = (string) file_get_contents($projectRoot . '/docs/installation.md');
$assert(str_contains($lifecycleDocs, 'security-only support through 2026-12-31') && str_contains($lifecycleDocs, 'from 2027-01-01 the planned minimum is PHP 8.3'), 'PHP 8.2 cutoff and planned PHP 8.3 baseline are documented');
$phpSecurityDocs = [
    'SECURITY.md',
    'INSTALL.md',
    'docs/deployment.md',
    'docs/installation.md',
    'docs/security.md',
    'docs/shared-hosting.md',
];
foreach ($phpSecurityDocs as $path) {
    $document = (string) file_get_contents($projectRoot . '/' . $path);
    $assert(str_contains($document, 'CVE-2026-109618') && str_contains($document, 'CVE-2026-109619'), "{$path} names both 2026 PHP runtime advisories");
    foreach (['8.2.35', '8.3.36', '8.4.27', '8.5.12'] as $fixedVersion) {
        $assert(str_contains($document, $fixedVersion), "{$path} documents fixed PHP build {$fixedVersion}");
    }
    $assert(str_contains($document, '2026-10-21') && stripos($document, 'backport') !== false, "{$path} documents advisory date and vendor backport guidance");
}
$securityPolicy = (string) file_get_contents($projectRoot . '/SECURITY.md');
$assert(str_contains($securityPolicy, 'not evidence of a vulnerability in Barelytics application code')
    && str_contains($securityPolicy, 'only complete mitigation'), 'PHP advisories are distinguished from Barelytics code and updates are identified as the complete mitigation');
$assert(normalizePath('/articles/example') === '/articles/example', 'normal page paths are accepted');
$assert(normalizePath('/articles//example') === '/articles/example', 'repeated slashes are normalized');
$assert(normalizePath('/users/123456/profile') === '/users/:id/profile', 'numeric record identifiers are collapsed');
$assert(normalizePath('/profile/550e8400-e29b-41d4-a716-446655440000') === '/profile/:id', 'UUID-like path identifiers are collapsed');
$assert(normalizePath('/orders/01ARZ3NDEKTSV4RRFFQ69G5FAV') === '/orders/:id', 'ULID-like path identifiers are collapsed');
$assert(normalizePath('/tokens/0123456789abcdef0123456789abcdef') === '/tokens/:id', 'long hexadecimal path identifiers are collapsed');
$assert(normalizePath('/account/person@example.com') === null, 'email-like paths are rejected');
$assert(normalizePath('/account/person%40example.com') === null, 'encoded email-like paths are rejected');
$assert(normalizePath("/bad\xFFpath") === null, 'invalid UTF-8 is rejected');
$assert(normalizePath('/article?email=test@example.com') === null, 'query strings are rejected');
$assert(normalizePath('/article#fragment') === null, 'fragments are rejected');
$assert(normalizePath('https://evil.example/path') === null, 'external URLs are rejected');
$assert(normalizePath('//evil.example/path') === null, 'network-path references are rejected');
$assert(normalizePath('/' . str_repeat('a', 512)) === null, 'oversized paths are rejected');
$assert(normalizePath("/bad\0path") === null, 'control characters are rejected');
foreach (json_decode(file_get_contents(__DIR__ . '/../spec/test-vectors/path-normalization.json'), true) as $vector) {
    $input = is_array($vector['input']) ? $vector['input']['prefix'] . str_repeat($vector['input']['repeat'], $vector['input']['count']) : $vector['input'];
    $assert(normalizePath($input) === $vector['expected'], 'shared path normalization vector');
    if (isset($vector['excluded'])) $assert(\Barelytics\pathIsExcluded($input, \Barelytics\DEFAULT_PATH_EXCLUSIONS) === $vector['excluded'], 'shared path exclusion vector');
}
$assert(isBot('Mozilla compatible GOOGLEBOT/2.1'), 'crawler matching is case-insensitive');
$assert(isBot('MyTestRobot', ['robot']), 'custom crawler patterns are applied');
$assert(!isBot('Mozilla/5.0 (X11; Linux x86_64)'), 'ordinary browser user agents are accepted');
$assert(referrerHost('https://Example.COM/a?person=123#x') === 'example.com', 'referrer is reduced to hostname');
$assert(referrerHost('javascript:alert(1)') === null, 'non-http referrers are rejected');
$assert(referrerHost('https://user:pass@example.com/path') === null, 'userinfo referrers are rejected');
$assert(referrerHost('https://127.0.0.1/path') === null, 'IP referrer hosts are rejected');
$assert(referrerHost('https://0x7f000001/path') === null, 'numeric alternative IP referrers are rejected');
$assert(escape('<script>alert("x")</script>') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', 'HTML output is escaped');
$assert(!passwordMeetsMinimum('short'), 'short passwords are rejected');
$assert(!passwordMeetsMinimum('éééé'), 'password length is measured in characters, not UTF-8 bytes');
$assert(passwordMeetsMinimum('long-password'), '12-character password is accepted');
$installerSource = (string) file_get_contents(dirname(__DIR__) . '/public/barelytics/install.php');
$assert((bool) preg_match('/^use function Barelytics\\\\databasePath;\s*$/m', $installerSource), 'installer imports the namespaced databasePath function');
$assert(function_exists('Barelytics\\databasePath'), 'namespaced databasePath helper is available');
$runtimeChecks = runtimePrivacyChecks();
$assert(in_array('Tracker source contains no analytics cookie, localStorage or sessionStorage access', array_column($runtimeChecks, 'name'), true) && count(array_filter($runtimeChecks, static fn($check) => $check['status'] !== 'PASS')) === 0, 'runtime privacy source scan passes without matching its own URL detector');
$tokenFixture = bin2hex(random_bytes(32));
$tokenPath = sys_get_temp_dir() . '/barelytics-token-' . bin2hex(random_bytes(6));
file_put_contents($tokenPath, 'sha256:' . hash('sha256', $tokenFixture));
putenv('BARELYTICS_SETUP_TOKEN_FILE=' . $tokenPath);
$assert(\Barelytics\verifySetupToken($tokenFixture), 'setup token matches its stored one-way hash');
$assert(!\Barelytics\verifySetupToken('incorrect-token'), 'setup token hash rejects incorrect values');
unlink($tokenPath);
$ftpTokenPath = sys_get_temp_dir() . '/barelytics-ftp-token-' . bin2hex(random_bytes(6));
file_put_contents($ftpTokenPath, '<?php http_response_code(404); exit; ?>' . "\nsha256:" . hash('sha256', $tokenFixture) . "\n");
putenv('BARELYTICS_SETUP_TOKEN_FILE=' . $ftpTokenPath);
$assert(\Barelytics\verifySetupToken($tokenFixture), 'FTP-uploadable PHP token file is parsed without execution');
unlink($ftpTokenPath);
putenv('BARELYTICS_SETUP_TOKEN_FILE');
putenv('BARELYTICS_SETUP_TOKEN_FILE');
$dataPath = sys_get_temp_dir() . '/barelytics-data-' . bin2hex(random_bytes(6));
mkdir($dataPath, 0700);
$resetFixture = bin2hex(random_bytes(32));
file_put_contents($dataPath . '/reset.token', 'sha256:' . hash('sha256', $resetFixture));
putenv('BARELYTICS_DATA_DIRECTORY=' . $dataPath);
$assert(\Barelytics\verifyResetToken($resetFixture), 'FTP-installed recovery token matches its hash');
$assert(!\Barelytics\verifyResetToken('incorrect-token'), 'invalid recovery token is rejected');
unlink($dataPath . '/reset.token');
rmdir($dataPath);
putenv('BARELYTICS_DATA_DIRECTORY');
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'it';
$assert(requestCountry() === 'IT', 'trusted country header is stored as uppercase country code');
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'ITA';
$assert(requestCountry() === 'XX', 'invalid country codes fail closed');
unset($_SERVER['HTTP_CF_IPCOUNTRY']);
$_SERVER['SCRIPT_NAME'] = '/tools/barelytics/admin.php';
$assert(\Barelytics\requestBasePath() === '/tools/barelytics/', 'subdirectory base path is derived from the current endpoint');
unset($_SERVER['SCRIPT_NAME']);
session_save_path(sys_get_temp_dir());
session_id(bin2hex(random_bytes(16)));
session_start();
$csrf = \Barelytics\csrfToken();
$assert(\Barelytics\validCsrf($csrf), 'session-bound CSRF token verifies');
$assert(!\Barelytics\validCsrf('predictable'), 'invalid CSRF token is rejected');
$_SESSION = [];
session_destroy();

$sqliteAvailable = in_array('sqlite', PDO::getAvailableDrivers(), true);
if (!$sqliteAvailable) {
    fwrite(STDOUT, "SQLite integration checks skipped: PDO SQLite is unavailable.\n");
} else {
    $tmp = sys_get_temp_dir() . '/barelytics-test-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);
    putenv('BARELYTICS_DATABASE_PATH=' . $tmp . '/analytics.sqlite');
    try {
        $wpMigrationSource = $tmp . '/legacy';
        $wpMigrationTarget = $tmp . '/migrated';
        mkdir($wpMigrationSource, 0700);
        mkdir($wpMigrationTarget, 0700);
        file_put_contents($wpMigrationTarget . '/analytics.sqlite.migrating-interrupted', 'partial snapshot');
        $migrationDb = new PDO('sqlite:' . $wpMigrationSource . '/analytics.sqlite');
        $migrationDb->exec('PRAGMA journal_mode = WAL');
        $migrationDb->exec('CREATE TABLE pageviews_daily(day TEXT, path TEXT, views INTEGER)');
        $migrationDb->exec('CREATE TABLE settings(key TEXT PRIMARY KEY, value TEXT)');
        $migrationDb->exec("INSERT INTO pageviews_daily VALUES ('2026-11-02','/migration',7)");
        $migrationDb->exec("INSERT INTO settings VALUES ('setup_complete','1')");
        $assert(barelytics_wp_migrate_database($wpMigrationSource, $wpMigrationTarget), 'WordPress SQLite migration snapshots WAL data and validates the copy');
        $migrationDb = null;
        $migratedDb = new PDO('sqlite:' . $wpMigrationTarget . '/analytics.sqlite');
        $assert((int) $migratedDb->query('SELECT SUM(views) FROM pageviews_daily')->fetchColumn() === 7
            && $migratedDb->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'migrated WordPress database preserves rows and passes integrity check');
        $assert(is_file($wpMigrationSource . '/analytics.sqlite'), 'successful migration keeps the original database recoverable');
        $assert(is_file($wpMigrationTarget . '/analytics.sqlite.migrating-interrupted'), 'an interrupted migration artifact does not replace or delete the source database');
        $migratedDb = null;
        $badMigrationSource = $tmp . '/legacy-invalid';
        $badMigrationTarget = $tmp . '/migrated-invalid';
        mkdir($badMigrationSource, 0700);
        mkdir($badMigrationTarget, 0700);
        file_put_contents($badMigrationSource . '/analytics.sqlite', 'not a database');
        $assert(!barelytics_wp_migrate_database($badMigrationSource, $badMigrationTarget)
            && is_file($badMigrationSource . '/analytics.sqlite')
            && !is_file($badMigrationTarget . '/analytics.sqlite'), 'failed WordPress migration leaves source intact and target uninitialized');
        $db = \Barelytics\openDatabase();
        $assert(\Barelytics\runSelfTest($db), 'installer self-test releases all PDO statements and commits its probe');
        $crudProbe = databaseWriteCheck($db);
        $assert($crudProbe['ready'], 'installer CRUD probe creates, reads, deletes, commits, and drops its database test table');
        $assert(array_column($crudProbe['steps'], 'name') === ['CREATE TABLE', 'BEGIN', 'INSERT', 'SELECT', 'DELETE', 'COMMIT', 'DROP TABLE'], 'database probe reports each operation separately');
        $walProbe = sqliteWalCheck($db, databasePath());
        $assert($walProbe['ready'], 'installer WAL check passes when WAL sidecars are available or SQLite uses its fallback mode');
        if ($walProbe['mode'] === 'wal') $assert(is_file(databasePath() . '-wal') && is_file(databasePath() . '-shm'), 'WAL probe verifies both SQLite sidecar files');
        $failedWalProbe = sqliteWalCheck($db, $tmp . '/missing/analytics.sqlite');
        $assert(!$failedWalProbe['ready'] && $crudProbe['ready'], 'a WAL diagnostic failure does not change the independent successful CRUD result');
        $assert(\Barelytics\schemaVersion($db) === \Barelytics\CURRENT_SCHEMA_VERSION, 'fresh database receives the current schema migration');
        $assert(\Barelytics\migrateDatabase($db) === \Barelytics\CURRENT_SCHEMA_VERSION, 'schema migration is safe to repeat');
        \Barelytics\setSetting($db, 'setup_complete', '1');
        \Barelytics\setSetting($db, 'admin_password_hash', password_hash('existing-admin-password', PASSWORD_DEFAULT));
        $assert(\Barelytics\tableExists($db, 'settings') && \Barelytics\setting($db, 'setup_complete', '0') === '1' && \Barelytics\schemaVersion($db) === \Barelytics\CURRENT_SCHEMA_VERSION, 'current-schema database helpers close cursors before follow-up operations');
        $existingHash = \Barelytics\setting($db, 'admin_password_hash', '');
        $existingViews = (int) \Barelytics\queryColumn($db, 'SELECT COALESCE(SUM(views), 0) FROM pageviews_daily');
        $existingProbe = databaseWriteCheck($db);
        $assert($existingProbe['ready'] && \Barelytics\schemaVersion($db) === \Barelytics\CURRENT_SCHEMA_VERSION, 'existing current-schema database passes CRUD probe without migration');
        $assert(\Barelytics\setting($db, 'setup_complete', '0') === '1' && \Barelytics\setting($db, 'admin_password_hash', '') === $existingHash && (int) \Barelytics\queryColumn($db, 'SELECT COALESCE(SUM(views), 0) FROM pageviews_daily') === $existingViews, 'diagnostic probe preserves setup, admin hash, and analytics aggregates');
        $readOnlyDb = \Barelytics\connectReadOnlyDatabase();
        $readOnlyWriteBlocked = false;
        try { $readOnlyDb->exec('CREATE TABLE read_only_guard (value TEXT)'); } catch (PDOException) { $readOnlyWriteBlocked = true; }
        $assert($readOnlyWriteBlocked, 'read-only diagnostics connection cannot modify the database');
        unset($readOnlyDb);
        $privacy = \Barelytics\effectivePrivacyConfig($db);
        $assert($privacy['profile'] === 'strict' && !$privacy['country_collection'] && !$privacy['referrer_collection'] && !$privacy['browser_collection'] && !$privacy['device_collection'] && !$privacy['os_collection'], 'fresh installs use Strict Mode with every optional dimension disabled');
        $assert(\Barelytics\privacySelfTest($db)['result'] === 'PASS', 'fresh strict profile passes the runtime privacy self-test');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0 Windows';
        $_SERVER['HTTP_REFERER'] = 'https://private.example/search?email=secret@example.com';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'IT';
        $assert(\Barelytics\countRequest($db, '/about'), 'strict request is counted');
        $strict = $db->query("SELECT country, views FROM pageviews_daily WHERE path = '/about'")->fetch();
        $assert($strict['country'] === 'XX' && (int) $strict['views'] === 1, 'strict request stores only the aggregate path and default country placeholder');
        $assert((int) $db->query('SELECT COUNT(*) FROM referrers_daily')->fetchColumn() === 0 && (int) $db->query('SELECT COUNT(*) FROM dimensions_daily')->fetchColumn() === 0, 'disabled dimensions are not persisted');
        $assert(!\Barelytics\countRequest($db, '/account/42'), 'default private path exclusion prevents an analytics write');
        $payload = ['path' => '/about', 'country' => 'IT', 'visitor_id' => 'abc', 'email' => 'person@example.com'];
        $assert(\Barelytics\collectorPayload(json_encode($payload)) === null, 'collector rejects additional client properties before collection');
        foreach (['country_collection','referrer_collection','browser_collection','device_collection','os_collection'] as $key) \Barelytics\setSetting($db, $key, '1');
        \Barelytics\recordPrivacyConfiguration($db);
        $assert(\Barelytics\effectivePrivacyConfig($db)['profile'] === 'extended', 'enabling optional dimensions computes the Extended profile');
        $assert(\Barelytics\countRequest($db, '/extended'), 'extended request is counted');
        $assert((int) $db->query("SELECT COUNT(*) FROM referrers_daily WHERE path = '/extended'")->fetchColumn() === 1, 'enabled referrer collection stores only an aggregate hostname');
        $assert((int) $db->query("SELECT COUNT(DISTINCT dimension) FROM dimensions_daily WHERE path = '/extended'")->fetchColumn() === 3, 'enabled browser, device and OS collection stores coarse aggregate categories');
        $historyCount = (int) $db->query('SELECT COUNT(*) FROM privacy_configuration_history')->fetchColumn();
        $assert($historyCount >= 2, 'material privacy configuration changes are recorded in history');
        $unknown = \Barelytics\schemaAudit($db, 'EXTENDED', \Barelytics\effectivePrivacyConfig($db));
        $assert($unknown['status'] === 'PASS', 'aggregate-only extended schema passes audit');
        $assert(\Barelytics\pathIsExcluded('/admin/users', ['/admin/*']), 'wildcard path exclusion matches nested routes');
        $rowsBeforePreparedUpserts = (int) $db->query('SELECT COUNT(*) FROM pageviews_daily')->fetchColumn();
        $hostilePath = "/x'); DROP TABLE pageviews_daily;--";
        \Barelytics\recordPageview($db, '/article', 'IT', null);
        \Barelytics\recordPageview($db, $hostilePath, 'XX', null);
        $count = (int) $db->query('SELECT COUNT(*) FROM pageviews_daily')->fetchColumn();
        $hostileRow = $db->prepare('SELECT COUNT(*) FROM pageviews_daily WHERE path = :path');
        $hostileRow->execute([':path' => $hostilePath]);
        $assert($count === $rowsBeforePreparedUpserts + 2 && (int) $hostileRow->fetchColumn() === 1, 'prepared upsert stores hostile strings as data and retains table');
        $hostileRow->closeCursor();
        $views = (int) $db->query("SELECT views FROM pageviews_daily WHERE path = '/article'")->fetchColumn();
        $assert($views === 1, 'aggregate counter increments');
        $assert(!str_contains(file_get_contents($tmp . '/analytics.sqlite') ?: '', '192.0.2.1'), 'database does not contain fixture IP');
        $assert(!\Barelytics\countRequest($db, '/admin') && !\Barelytics\countRequest($db, '/%61dmin/report'), 'private base and encoded paths are excluded');
        \Barelytics\recordPageview($db, '/canonical-day', 'IT', 'example.test', ['browser' => 'Other'], strtotime('2026-01-01 23:59:59 UTC'));
        foreach (['pageviews_daily', 'referrers_daily', 'dimensions_daily'] as $table) {
            $assert(\Barelytics\queryColumn($db, "SELECT day FROM $table WHERE path='/canonical-day'") === '2026-01-01', 'one event uses one UTC day in ' . $table);
        }
        $db->exec("CREATE TRIGGER fail_dimension BEFORE INSERT ON dimensions_daily BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        try { \Barelytics\recordPageview($db, '/interrupted', 'XX', null, ['browser' => 'Other']); } catch (PDOException) { }
        $assert((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE path='/interrupted'") === 0, 'failed dimension writes roll back the page view');
        $db->exec('DROP TRIGGER fail_dimension');
        foreach (\Barelytics\PRIVACY_DEFAULTS as $key => $_) \Barelytics\setSetting($db, $key, '0');
        $audit = \Barelytics\schemaAudit($db, 'STRICT', \Barelytics\effectivePrivacyConfig($db));
        $assert($audit['status'] === 'PASS' && in_array('country', $audit['historical_dimensions'], true), 'retained Extended aggregates do not indicate active Strict collection');
        $db->beginTransaction();
        for ($i = 0; $i < 1001; $i++) \Barelytics\executeStatement($db, "INSERT INTO pageviews_daily VALUES ('2000-01-01', :path, 'XX', 1)", [':path' => '/expired-' . $i]);
        $db->commit();
        \Barelytics\setSetting($db, 'last_cleanup_at', '');
        \Barelytics\runScheduledCleanupIfDue($db);
        $assert((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE day='2000-01-01'") === 1, 'automatic retention work is bounded');
        \Barelytics\runScheduledCleanupIfDue($db);
        $assert((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE day='2000-01-01'") === 0, 'automatic retention resumes pending batches without waiting a day');
        foreach (json_decode(file_get_contents(__DIR__ . '/../spec/test-vectors/automation.json'), true) as $vector) {
            $classification = \Barelytics\classifyUserAgent($vector['user_agent'], $vector['additional_patterns'] ?? []);
            foreach (['category', 'family', 'identity', 'automation'] as $field) $assert($classification[$field] === $vector[$field], 'shared automation vector: ' . $field);
            $assert($classification['verified'] === false, 'declared automation is never marked verified');
        }
        $_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1';
        $assert(!\Barelytics\countRequest($db, '/automation'), 'automation does not update ordinary views');
        $assert(!\Barelytics\countRequest($db, '/private/bot'), 'private routes exclude automation');
        $assert((int) \Barelytics\queryColumn($db, "SELECT requests FROM automation_daily WHERE path='/automation'") === 1, 'Strict stores separate finite automation aggregates');
        $assert((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE path='/automation'") === 0, 'bot requests never enter ordinary page-view totals');
        $db->exec("INSERT INTO automation_daily VALUES ('2000-01-01','/expired','ai','openai',2)");
        \Barelytics\cleanRetentionBatch($db);
        $assert((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM automation_daily WHERE day='2000-01-01'") === 0, 'automation participates in retention');
        $legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec(file_get_contents(__DIR__ . '/../spec/test-vectors/legacy-v2.sql'));
        $assert(\Barelytics\migrateDatabase($legacy) === \Barelytics\CURRENT_SCHEMA_VERSION && \Barelytics\migrateDatabase($legacy) === \Barelytics\CURRENT_SCHEMA_VERSION, 'schema-2 migration is additive and repeatable');
        $assert((int) \Barelytics\queryColumn($legacy, "SELECT views FROM pageviews_daily WHERE path='/legacy'") === 7 && \Barelytics\setting($legacy, 'admin_password_hash', '') === 'fixture-unchanged-credential' && \Barelytics\effectivePrivacyConfig($legacy)['retention_days'] === 30, 'migration preserves legacy totals, credentials and settings');
        $legacy->exec('CREATE TABLE request_log(user_agent TEXT)');
        $assert(\Barelytics\schemaAudit($legacy, 'STRICT', \Barelytics\effectivePrivacyConfig($legacy))['status'] === 'FAIL', 'unrecognized raw-data tables fail audit');
        $legacy->exec('DROP TABLE request_log');
        $legacy->exec('ALTER TABLE settings ADD COLUMN ip_address TEXT');
        $assert(\Barelytics\schemaAudit($legacy, 'STRICT', \Barelytics\effectivePrivacyConfig($legacy))['status'] === 'FAIL', 'identity columns in approved metadata tables fail audit');
        $legacy->exec("INSERT INTO schema_migrations VALUES (99,'fixture')");
        $futureRejected = false;
        try { \Barelytics\migrateDatabase($legacy); } catch (RuntimeException) { $futureRejected = true; }
        $assert($futureRejected && \Barelytics\schemaVersion($legacy) === 99, 'future schemas are not downgraded or rewritten');
        unset($legacy);
        unset($db);
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file);
        @rmdir($tmp);
        putenv('BARELYTICS_DATABASE_PATH');
    }
}

fwrite(STDOUT, "Passed {$checks} Barelytics checks.\n");
