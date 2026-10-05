<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { http_response_code(404); exit; }
require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\adminHeaders;
use function Barelytics\connectDatabase;
use function Barelytics\connectReadOnlyDatabase;
use function Barelytics\csrfToken;
use function Barelytics\databasePath;
use function Barelytics\databaseWriteCheck;
use function Barelytics\dataDirectory;
use function Barelytics\diagnosticError;
use function Barelytics\escape;
use function Barelytics\effectivePrivacyConfig;
use function Barelytics\queryColumn;
use function Barelytics\schemaAudit;
use function Barelytics\schemaVersion;
use function Barelytics\setting;
use function Barelytics\sqliteWalCheck;
use function Barelytics\startAdminSession;
use function Barelytics\tableExists;
use function Barelytics\validCsrf;
use const Barelytics\APPLICATION_VERSION;
use const Barelytics\CURRENT_SCHEMA_VERSION;

adminHeaders();
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }
startAdminSession();
if (empty($_SESSION['authenticated'])) { http_response_code(403); exit('Administrator sign-in required.'); }
if ($method === 'POST' && (!validCsrf($_POST['csrf'] ?? null) || ($_POST['action'] ?? '') !== 'database_probe')) {
    http_response_code(403); exit('The form expired or the action was not accepted.');
}

$db = null;
$error = null;
$probe = null;
$wal = null;
try {
    if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('PDO SQLite is unavailable.');
    // GET diagnostics use SQLite read-only URI mode. A deliberate POST opens a writable connection without changing WAL mode.
    $db = $method === 'POST' ? connectDatabase(false) : connectReadOnlyDatabase();
    if (!tableExists($db, 'settings') || setting($db, 'setup_complete', '0') !== '1') {
        http_response_code(403); exit('Completed administrator setup is required.');
    }
} catch (Throwable $failure) {
    http_response_code(503);
    $error = diagnosticError($failure, [databasePath(), dataDirectory()]);
}

if ($db instanceof PDO && $method === 'POST') {
    $probe = databaseWriteCheck($db);
    $wal = sqliteWalCheck($db, databasePath());
}

$checks = [];
if ($db instanceof PDO) {
    $directory = dataDirectory();
    $path = databasePath();
    $docroot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $realDirectory = realpath($directory);
    $insideWebroot = $docroot !== false && $realDirectory !== false && str_starts_with(rtrim(str_replace('\\', '/', $realDirectory), '/') . '/', rtrim(str_replace('\\', '/', $docroot), '/') . '/');
    $apacheDenied = false;
    if ($insideWebroot) {
        $htaccess = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
        $contents = is_file($htaccess) ? (string) @file_get_contents($htaccess) : '';
        $apacheDenied = preg_match('/Require\s+all\s+denied/i', $contents) === 1;
    }
    $location = $insideWebroot ? ($apacheDenied ? 'apache-denied' : 'webroot-unverified') : ((realpath(dirname(__DIR__) . '/var') === $realDirectory || realpath(dirname(__DIR__) . '/data') === $realDirectory) ? 'private' : 'external');
    $schema = schemaVersion($db);
    $sqliteVersion = (string) queryColumn($db, 'SELECT sqlite_version()');
    $schemaCheck = $schema === CURRENT_SCHEMA_VERSION && tableExists($db, 'pageviews_daily') ? schemaAudit($db, 'STRICT', effectivePrivacyConfig($db)) : ['status' => 'FAIL'];
    $checks = [
        ['Application version', setting($db, 'application_version', APPLICATION_VERSION)],
        ['Schema version', $schema . ' / ' . CURRENT_SCHEMA_VERSION],
        ['PHP version', PHP_VERSION],
        ['PDO available', class_exists(PDO::class) ? 'Yes' : 'No'],
        ['PDO SQLite', in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'Available' : 'Unavailable'],
        ['SQLite version', $sqliteVersion],
        ['Data location', $location],
        ['Database file exists', is_file($path) ? 'Yes' : 'No'],
        ['Database file writable', is_file($path) && is_writable($path) ? 'Yes' : 'No'],
        ['Data directory writable', is_dir($directory) && is_writable($directory) ? 'Yes' : 'No'],
        ['Journal mode', (string) queryColumn($db, 'PRAGMA journal_mode')],
        ['WAL sidecar exists', is_file($path . '-wal') ? 'Yes' : 'No'],
        ['WAL sidecar writable', is_file($path . '-wal') && is_writable($path . '-wal') ? 'Yes' : 'No'],
        ['SHM sidecar exists', is_file($path . '-shm') ? 'Yes' : 'No'],
        ['SHM sidecar writable', is_file($path . '-shm') && is_writable($path . '-shm') ? 'Yes' : 'No'],
        ['Aggregate schema', $schemaCheck['status']],
    ];
}
$csrf = csrfToken();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Diagnostics · Barelytics</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="wrap"><header><p class="brand"><img src="admin-ui/brand-mark.png" width="72" height="50" alt="Barelytics"></p><h1>Database diagnostics</h1><p><a href="admin.php">Back to administration</a></p></header>
<?php if ($error !== null): ?><p class="notice error">Diagnostics could not connect: <?= escape($error) ?></p><?php endif; ?>
<?php if ($checks): ?><section class="card"><h2>Read-only diagnostics</h2><p>These checks read the installed configuration and storage status. They do not run migrations or change database settings.</p><table><tbody><?php foreach ($checks as [$label, $value]): ?><tr><th><?= escape($label) ?></th><td><?= escape((string) $value) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section class="card"><h2>Explicit database probe</h2><p>This administrator-triggered test creates a uniquely named temporary table, tests transactional create/read/delete operations, commits, then drops the table. WAL status is checked separately.</p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="database_probe"><button>Run database probe</button></form>
<?php if (is_array($probe)): ?><h3>CRUD result: <?= $probe['ready'] ? 'PASS' : 'FAIL' ?></h3><ul><?php foreach ($probe['steps'] as $step): ?><li><?= escape($step['status']) ?> <?= escape($step['name']) ?><?= isset($step['error']) ? ': ' . escape($step['error']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (is_array($wal)): ?><h3>WAL result: <?= $wal['ready'] ? 'PASS' : 'FAIL' ?></h3><p>Journal mode: <?= escape($wal['mode']) ?>. <?= $wal['mode'] === 'wal' ? 'WAL: ' . ($wal['wal_exists'] ? 'present' : 'missing') . ', ' . ($wal['wal_writable'] ? 'writable' : 'not writable') . '; SHM: ' . ($wal['shm_exists'] ? 'present' : 'missing') . ', ' . ($wal['shm_writable'] ? 'writable' : 'not writable') . '.' : 'WAL is not enabled; this mode is reported without treating it as an error.' ?><?php if ($wal['error']): ?> <?= escape($wal['error']) ?><?php endif; ?></p><?php endif; ?></section><?php endif; ?>
</main></body></html>
