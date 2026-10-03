<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/barelytics/src/Barelytics.php';

use function Barelytics\escape;
use function Barelytics\isBot;
use function Barelytics\normalizePath;
use function Barelytics\passwordMeetsMinimum;
use function Barelytics\referrerHost;
use function Barelytics\requestCountry;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $checks++;
};

$assert(normalizePath('/articles/example') === '/articles/example', 'normal page paths are accepted');
$assert(normalizePath('/articles//example') === '/articles/example', 'repeated slashes are normalized');
$assert(normalizePath('/users/123456/profile') === '/users/:id/profile', 'numeric record identifiers are collapsed');
$assert(normalizePath('/account/person@example.com') === null, 'email-like paths are rejected');
$assert(normalizePath('/account/person%40example.com') === null, 'encoded email-like paths are rejected');
$assert(normalizePath("/bad\xFFpath") === null, 'invalid UTF-8 is rejected');
$assert(normalizePath('/article?email=test@example.com') === null, 'query strings are rejected');
$assert(normalizePath('/article#fragment') === null, 'fragments are rejected');
$assert(normalizePath('https://evil.example/path') === null, 'external URLs are rejected');
$assert(normalizePath('//evil.example/path') === null, 'network-path references are rejected');
$assert(normalizePath('/' . str_repeat('a', 512)) === null, 'oversized paths are rejected');
$assert(normalizePath("/bad\0path") === null, 'control characters are rejected');
$assert(isBot('Mozilla compatible GOOGLEBOT/2.1'), 'crawler matching is case-insensitive');
$assert(isBot('MyTestRobot', ['robot']), 'custom crawler patterns are applied');
$assert(!isBot('Mozilla/5.0 (X11; Linux x86_64)'), 'ordinary browser user agents are accepted');
$assert(referrerHost('https://Example.COM/a?person=123#x') === 'example.com', 'referrer is reduced to hostname');
$assert(referrerHost('javascript:alert(1)') === null, 'non-http referrers are rejected');
$assert(referrerHost('https://user:pass@example.com/path') === null, 'userinfo referrers are rejected');
$assert(referrerHost('https://127.0.0.1/path') === null, 'IP referrer hosts are rejected');
$assert(escape('<script>alert("x")</script>') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', 'HTML output is escaped');
$assert(!passwordMeetsMinimum('short'), 'short passwords are rejected');
$assert(!passwordMeetsMinimum('éééé'), 'password length is measured in characters, not UTF-8 bytes');
$assert(passwordMeetsMinimum('long-password'), '12-character password is accepted');
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
        $db = \Barelytics\openDatabase();
        $assert(\Barelytics\schemaVersion($db) === \Barelytics\CURRENT_SCHEMA_VERSION, 'fresh database receives the current schema migration');
        $assert(\Barelytics\migrateDatabase($db) === \Barelytics\CURRENT_SCHEMA_VERSION, 'schema migration is safe to repeat');
        \Barelytics\recordPageview($db, '/article', 'IT', null);
        \Barelytics\recordPageview($db, "/x'); DROP TABLE pageviews_daily;--", 'XX', null);
        $count = (int) $db->query('SELECT COUNT(*) FROM pageviews_daily')->fetchColumn();
        $assert($count === 2, 'prepared upsert stores hostile strings as data and retains table');
        $views = (int) $db->query("SELECT views FROM pageviews_daily WHERE path = '/article'")->fetchColumn();
        $assert($views === 1, 'aggregate counter increments');
        $assert(!str_contains(file_get_contents($tmp . '/analytics.sqlite') ?: '', '192.0.2.1'), 'database does not contain fixture IP');
        unset($db);
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file);
        @rmdir($tmp);
        putenv('BARELYTICS_DATABASE_PATH');
    }
}

fwrite(STDOUT, "Passed {$checks} Barelytics checks.\n");
