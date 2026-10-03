<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID > 80599) {
    ini_set('display_errors', '0');
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><title>Barelytics PHP requirement</title><h1>Unsupported PHP version</h1><p>Barelytics supports PHP 8.1 through 8.5. Select a supported PHP version in your hosting control panel, then reload this page.</p>';
    exit;
}

require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\adminHeaders;
use function Barelytics\connectDatabase;
use function Barelytics\csrfToken;
use function Barelytics\dataDirectory;
use function Barelytics\escape;
use function Barelytics\isHttpsRequest;
use function Barelytics\migrateDatabase;
use function Barelytics\openDatabase;
use function Barelytics\passwordMeetsMinimum;
use function Barelytics\requestBasePath;
use function Barelytics\resetTokenPath;
use function Barelytics\runSelfTest;
use function Barelytics\setSetting;
use function Barelytics\setupToken;
use function Barelytics\setupTokenPath;
use function Barelytics\verifySetupToken;
use function Barelytics\verifyResetToken;
use function Barelytics\startAdminSession;
use function Barelytics\webrootStatus;
use const Barelytics\CURRENT_SCHEMA_VERSION;
use const Barelytics\SUPPORTED_PHP_MAX;
use const Barelytics\SUPPORTED_PHP_MIN;

adminHeaders();
header('X-Robots-Tag: noindex, nofollow');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }

$error = '';
$message = '';
$dataMode = (string) ($_POST['data_mode'] ?? ($_GET['data_mode'] ?? (getenv('BARELYTICS_DATA_MODE') ?: 'auto')));
if (!in_array($dataMode, ['auto', 'private', 'apache'], true)) $dataMode = 'auto';
putenv('BARELYTICS_DATA_MODE=' . $dataMode);
$dataDir = dataDirectory();
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
$writable = is_dir($dataDir) && is_writable($dataDir);
$protection = $writable ? webrootStatus($dataDir) : ['inside' => false, 'secure' => false, 'mode' => 'not-writable'];
$dbReady = false;
$driverReady = class_exists(PDO::class) && in_array('sqlite', PDO::getAvailableDrivers(), true);
$sqliteVersion = 'unavailable';
$sqliteVersionReady = false;
$walMode = 'unavailable';
$walReady = false;
$db = null;
if ($driverReady && $writable) {
    try {
        $db = connectDatabase();
        $sqliteVersion = (string) $db->query('SELECT sqlite_version()')->fetchColumn();
        $sqliteVersionReady = version_compare($sqliteVersion, '3.24.0', '>=');
        $walMode = strtolower((string) $db->query('PRAGMA journal_mode')->fetchColumn());
        $db->exec('CREATE TABLE IF NOT EXISTS __barelytics_install_check (value TEXT NOT NULL)');
        $db->beginTransaction();
        $db->exec("INSERT INTO __barelytics_install_check (value) VALUES ('write-test')");
        $dbReady = $db->query('SELECT value FROM __barelytics_install_check LIMIT 1')->fetchColumn() === 'write-test';
        $walReady = $walMode !== 'wal' || (
            is_file(databasePath() . '-wal') && is_writable(databasePath() . '-wal')
            && is_file(databasePath() . '-shm') && is_writable(databasePath() . '-shm')
        );
        $db->exec('DELETE FROM __barelytics_install_check');
        $db->commit();
        $db->exec('DROP TABLE __barelytics_install_check');
    } catch (Throwable $ignored) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        $dbReady = false;
        $walReady = false;
    }
}
$sessionReady = function_exists('session_start') && function_exists('session_set_cookie_params');
if ($sessionReady) {
    try { startAdminSession(); $sessionReady = session_status() === PHP_SESSION_ACTIVE; }
    catch (Throwable $ignored) { $sessionReady = false; }
} else {
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) session_write_close();
}
$https = isHttpsRequest();
$token = setupToken();
$setupFilePresent = is_file(setupTokenPath());
$resetFilePresent = is_file(resetTokenPath()) && \Barelytics\readTokenHashFile(resetTokenPath()) !== null;
$permissionMode = $writable ? substr(sprintf('%o', fileperms($dataDir)), -4) : 'unavailable';
$installed = false;
$currentSchema = 0;
if ($db instanceof PDO && \Barelytics\tableExists($db, 'settings')) {
    $installed = \Barelytics\setting($db, 'setup_complete', '0') === '1';
    $currentSchema = \Barelytics\schemaVersion($db);
}
$checks = [
    ['PHP version', PHP_VERSION_ID >= SUPPORTED_PHP_MIN && PHP_VERSION_ID <= SUPPORTED_PHP_MAX, 'Supported range is PHP 8.1 through 8.5. Detected ' . PHP_VERSION . '.'],
    ['PDO', class_exists(PDO::class), 'PDO must be enabled by the hosting provider.'],
    ['PDO SQLite', $driverReady, 'Barelytics requires PDO SQLite. Enable the PHP SQLite/PDO SQLite extension in your hosting control panel or ask your hosting provider to enable it, then reload this page.'],
    ['SQLite version', $sqliteVersionReady, 'SQLite 3.24 or newer is required for prepared aggregate upserts. Detected ' . $sqliteVersion . '.'],
    ['Data directory', $writable, 'The selected data directory must exist and be writable by the PHP process. Use your hosting file manager to adjust ownership or select the Apache-protected fallback.'],
    ['Database write test', $dbReady, 'Barelytics could not create, read, and delete a temporary database value. Check the directory and SQLite permissions.'],
    ['SQLite WAL/SHM', $walReady, $walMode === 'wal' ? ($walReady ? 'WAL mode is enabled and both sidecar files were created and are writable.' : 'WAL mode is enabled, but sidecar files could not be verified. Check database-folder write access.') : 'WAL is unavailable on this filesystem; SQLite is using its fallback journal mode.'],
    ['Admin sessions', $sessionReady, 'PHP sessions must be enabled and writable by the hosting provider.'],
    ['Data protection', $protection['secure'], $protection['mode'] === 'webroot-unverified' ? 'The data directory is under the webroot and this server does not confirm Apache access controls. Choose storage outside the webroot.' : 'Use data storage outside the webroot, or Apache with an effective Require all denied rule. Nginx-only in-webroot storage is refused.'],
];
$canSetup = true;
foreach ($checks as $check) if (!$check[1]) $canSetup = false;
$httpsWarning = !$https;

if ($method === 'POST') {
    if (!$sessionReady || !\Barelytics\validCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403); $error = 'The setup form expired. Reload the page and try again.';
    } elseif ($installed) {
        if (!$canSetup) {
            $error = 'Resolve the environment checks before password recovery.';
        } elseif (!$resetFilePresent || !is_string($_POST['reset_token'] ?? null) || !verifyResetToken((string) $_POST['reset_token'])) {
            usleep(250000);
            $error = 'The installation is locked. A valid FTP-installed reset token is required.';
        } elseif (!is_string($_POST['password'] ?? null) || !passwordMeetsMinimum((string) $_POST['password']) || strlen((string) $_POST['password']) > 1024) {
            $error = 'Choose a password with at least 12 characters and no more than 1024 bytes.';
        } elseif (!is_string($_POST['confirmation'] ?? null) || !hash_equals((string) $_POST['password'], (string) $_POST['confirmation'])) {
            $error = 'The password confirmation does not match.';
        } else {
            try {
                $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
                $db->exec('BEGIN IMMEDIATE');
                if (\Barelytics\setting($db, 'setup_complete', '0') !== '1') throw new RuntimeException('Installation state changed.');
                setSetting($db, 'admin_password_hash', password_hash((string) $_POST['password'], $algorithm));
                $resetPath = resetTokenPath();
                if (!@unlink($resetPath)) throw new RuntimeException('Recovery token could not be removed.');
                $db->exec('COMMIT');
                session_regenerate_id(true);
                $_SESSION = ['authenticated' => true];
                header('Location: admin.php'); exit;
            } catch (Throwable $ignored) {
                if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
                else { try { if ($db instanceof PDO) $db->exec('ROLLBACK'); } catch (Throwable $rollbackError) { } }
                $error = 'Password recovery could not complete. Check the private data-directory permissions and retry.';
            }
        }
    } elseif (!$canSetup) {
        $error = 'Resolve the failed environment checks before completing setup.';
    } elseif ($protection['inside'] && !isset($_POST['webroot_confirmed'])) {
        $error = 'Confirm that the hosting provider enforces the included Apache data-directory denial rule, or select storage outside the webroot.';
    } elseif ($token === null || !is_string($_POST['setup_token'] ?? null) || !verifySetupToken((string) $_POST['setup_token'])) {
        usleep(250000);
        $error = 'The uploaded one-time setup token is missing or incorrect.';
    } elseif (!is_string($_POST['password'] ?? null) || !passwordMeetsMinimum((string) $_POST['password']) || strlen((string) $_POST['password']) > 1024) {
        $error = 'Choose a password with at least 12 characters and no more than 1024 bytes.';
    } elseif (!is_string($_POST['confirmation'] ?? null) || !hash_equals((string) $_POST['password'], (string) $_POST['confirmation'])) {
        $error = 'The password confirmation does not match.';
    } else {
        try {
            $db = openDatabase();
            if (!runSelfTest($db)) throw new RuntimeException('Database self-test failed.');
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $db->exec('BEGIN IMMEDIATE');
            if (\Barelytics\setting($db, 'setup_complete', '0') === '1') throw new RuntimeException('Setup has already completed.');
            setSetting($db, 'admin_password_hash', password_hash((string) $_POST['password'], $algorithm));
            setSetting($db, 'setup_complete', '1');
            setSetting($db, 'application_version', '1.0.0');
            setSetting($db, 'last_migration_at', gmdate('Y-m-d H:i:s'));
            $tokenPath = setupTokenPath();
            if (is_file($tokenPath)) @unlink($tokenPath);
            $db->exec('COMMIT');
            session_regenerate_id(true);
            $_SESSION = ['authenticated' => true];
            header('Location: admin.php'); exit;
        } catch (Throwable $ignored) {
            if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
            else { try { if ($db instanceof PDO) $db->exec('ROLLBACK'); } catch (Throwable $rollbackError) { } }
            $error = 'Barelytics could not complete setup. Check data-directory permissions and try again.';
        }
    }
}
$csrf = $sessionReady ? csrfToken() : '';
$basePath = requestBasePath();
$dataLabel = $dataMode === 'apache' ? 'Apache-protected data/ directory' : ($protection['inside'] ? 'Barelytics data directory' : 'external private data directory');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Barelytics setup</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="wrap"><header><p class="brand">BARELYTICS</p><h1>Browser setup</h1></header>
<?php if ($error !== ''): ?><p class="notice error"><?= escape($error) ?></p><?php endif; ?>
<?php if ($installed): ?><section class="card"><h2>Setup is locked</h2><p>This installation has already been configured. The setup flow cannot create another administrator. Use the admin sign-in page to manage it.</p><p><a href="admin.php">Open analytics administration</a></p><p>Schema version: <?= (int) $currentSchema ?> / <?= CURRENT_SCHEMA_VERSION ?></p></section>
<?php if ($resetFilePresent): ?><section class="card"><h2>FTP-authorized password recovery</h2><p>A recovery token file was installed through FTP. It can change the existing administrator password; it cannot create another account or reset statistics.</p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><label>One-time recovery token<input type="password" name="reset_token" required></label><label>New password<input type="password" name="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="confirmation" minlength="12" maxlength="1024" autocomplete="new-password" required></label><button>Replace administrator password</button></form></section><?php endif; ?>
<?php else: ?>
<section class="card"><h2>Environment checks</h2><table><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach ($checks as [$label, $passed, $detail]): ?><tr><td><?= escape($label) ?></td><td><?= $label === 'Data protection' && $protection['inside'] && $passed ? 'Warning' : ($passed ? 'Pass' : 'Fail') ?></td><td><?= escape($label === 'Data protection' && $protection['inside'] ? 'Apache denial rule is present, but the installer cannot verify host enforcement. Test HTTP access before using this fallback.' : $detail) ?></td></tr><?php endforeach; ?>
<tr><td>Data location</td><td><?= escape($protection['mode']) ?></td><td><?= escape($dataLabel) ?> (folder <code><?= escape(basename($dataDir)) ?>/</code>, mode <?= escape($permissionMode) ?>). <?= $protection['inside'] ? 'Webroot storage is in use; confirm the host honors the Apache denial rule.' : 'Outside the webroot.' ?></td></tr>
<tr><td>Database file</td><td><?= is_file(\Barelytics\databasePath()) ? 'Created' : 'Not created' ?></td><td>SQLite file created by the browser installer.</td></tr>
<tr><td>HTTPS</td><td><?= $https ? 'Pass' : 'Warning' ?></td><td><?= $https ? 'HTTPS is active.' : 'Credentials and admin sessions are not protected in transit. Enable HTTPS before production use.' ?></td></tr>
<tr><td>Setup state</td><td>Fresh install</td><td>A one-time bootstrap token is required; visitors cannot claim the first admin account.</td></tr>
</tbody></table></section>
<?php if (!$canSetup): ?><p class="notice error">Setup is paused until the failed checks are resolved.</p><?php elseif ($token === null): ?>
<section class="card"><h2>Add the one-time setup token</h2><p>Generate a random token with at least 32 characters using a password manager. Use the included offline <a href="token-hash.html" target="_blank" rel="noopener">token file helper</a> to create a SHA-256 hash file without sending the token over the network. Upload the downloaded <code>setup-token.php</code> into this Barelytics package's <code>data/</code> folder using FTP/SFTP. It is a PHP file that returns 404 when opened directly, and the folder also has an Apache denial rule. Alternatively, upload <code>setup.token</code> directly into the private data folder if your FTP account can access it. The hash is invalidated by the permanent setup lock and removed after successful setup when PHP has directory permission.</p><p>The database remains outside the webroot when possible. Nginx-only hosts must use data storage outside the webroot; the setup-token PHP file itself must be handled by PHP, as required for the application.</p><p>Token file detected: <strong><?= $setupFilePresent ? 'yes, but unreadable or invalid' : 'no' ?></strong></p></section>
<?php else: ?>
<section class="card"><h2>Create the administrator account</h2><?php if ($httpsWarning): ?><p class="notice error">Warning: this request is not using HTTPS. Do not submit credentials until HTTPS is enabled.</p><?php endif; ?><?php if ($protection['inside']): ?><p class="notice error">Data is under the webroot. Verify the denial rule in your hosting panel or test the token/database URLs are denied before continuing.</p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="data_mode" value="<?= escape($dataMode) ?>"><?php if ($protection['inside']): ?><label class="check"><input type="checkbox" name="webroot_confirmed" value="yes" required>I verified that this Apache host denies HTTP requests to this data directory.</label><?php endif; ?><label>One-time setup token<input type="password" name="setup_token" autocomplete="off" required></label><label>Password<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="1024" required></label><label>Confirm password<input type="password" name="confirmation" autocomplete="new-password" minlength="12" maxlength="1024" required></label><button>Create administrator password and lock setup</button></form></section>
<?php endif; ?>
<?php if (!$installed && $dataMode !== 'apache'): ?><p class="note">If the private directory is not writable, <a href="?data_mode=apache">try the Apache-denied data/ fallback</a>. It is refused on Nginx-only hosts. <a href="?data_mode=private">Use the package var/ directory</a>.</p><?php elseif (!$installed && $dataMode === 'apache'): ?><p class="note">This fallback is for Apache-compatible hosts only. <a href="?data_mode=auto">Use automatically selected private storage</a>.</p><?php endif; ?>
<?php endif; ?>
<p class="note">Barelytics <?= escape((string) PHP_VERSION) ?> · PHP <?= escape(PHP_VERSION) ?> · UTC · No Composer, npm, shell access, or cron is required.</p>
</main></body></html>
