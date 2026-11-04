<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { header('Location: install.php'); exit; }

require_once __DIR__ . '/src/Barelytics.php';
require_once __DIR__ . '/src/AdminApi.php';
require_once __DIR__ . '/src/AdminPresentation.php';

use function Barelytics\adminHeaders;
use function Barelytics\cleanRetentionBatch;
use function Barelytics\csrfToken;
use function Barelytics\connectDatabase;
use function Barelytics\escape;
use function Barelytics\runScheduledCleanupIfDue;
use function Barelytics\schemaVersion;
use function Barelytics\setSetting;
use function Barelytics\effectivePrivacyConfig;
use function Barelytics\recordPrivacyConfiguration;
use const Barelytics\PRIVACY_DEFAULTS;
use function Barelytics\setting;
use function Barelytics\queryColumn;
use function Barelytics\startAdminSession;
use function Barelytics\tableExists;
use function Barelytics\validCsrf;
use const Barelytics\CURRENT_SCHEMA_VERSION;

adminHeaders();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405); header('Allow: GET, POST'); exit;
}
if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) { header('Location: install.php'); exit; }
startAdminSession();
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }
$db = null;
$error = '';
try { $db = connectDatabase(); } catch (Throwable $ignored) { header('Location: install.php'); exit; }
if (!tableExists($db, 'settings')) { header('Location: install.php'); exit; }
$passwordHash = setting($db, 'admin_password_hash', '');
$setupDone = setting($db, 'setup_complete', '0') === '1';
if (!$setupDone || $passwordHash === '') { header('Location: install.php'); exit; }
$currentSchema = schemaVersion($db);
if ($currentSchema > CURRENT_SCHEMA_VERSION) { http_response_code(503); exit('This database was created by a newer Barelytics version. Restore matching application files or contact the site administrator.'); }
$csrf = csrfToken();
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_GET['api'])) {
    if (!validCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403); $error = 'Your form expired. Reload the page and try again.';
    } elseif (isset($_POST['login'])) {
        $now = time();
        if (($now - (int) ($_SESSION['login_window'] ?? $now)) > 900) {
            $_SESSION['login_window'] = $now; $_SESSION['login_failures'] = 0;
        }
        if ((int) ($_SESSION['login_failures'] ?? 0) >= 5) {
            $error = 'Too many failed attempts. Wait 15 minutes and try again.';
        } else {
            $password = $_POST['password'] ?? '';
            $valid = is_string($password) && strlen($password) <= 1024 && password_verify($password, $passwordHash);
            if (!$valid) {
                $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
                usleep(250000);
                $error = 'The password is incorrect.';
            } else {
                if (password_needs_rehash($passwordHash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
                    setSetting($db, 'admin_password_hash', password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT));
                }
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                unset($_SESSION['login_failures'], $_SESSION['login_window'], $_SESSION['csrf']);
                $returnQuery = array_filter($_GET, static fn($value, $key) => in_array($key, ['view','period','bucket','path','dimension','page_num','ui','fallback'], true) && is_string($value), ARRAY_FILTER_USE_BOTH);
                header('Location: admin.php' . ($returnQuery ? '?' . http_build_query($returnQuery) : '')); exit;
            }
        }
    } elseif (!empty($_SESSION['authenticated'])) {
        $action = $_POST['action'] ?? '';
        if ($action === 'migrate' && $currentSchema < CURRENT_SCHEMA_VERSION) {
            try {
                \Barelytics\migrateDatabase($db);
                $currentSchema = schemaVersion($db);
                $message = 'Database migrations completed and verified.';
            } catch (Throwable $ignored) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'The migration did not complete. Your data was preserved; retry from this page or restore your backup.';
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            }
            session_destroy(); header('Location: admin.php'); exit;
        } elseif ($action === 'settings') {
            $retention = $_POST['retention_days'] ?? '';
            $botText = $_POST['bot_patterns'] ?? '';
            $exclusions = $_POST['path_exclusions'] ?? '';
            $optionalEnabled = isset($_POST['daily_reach_enabled']) || isset($_POST['daily_page_reach_enabled']);
            foreach (PRIVACY_DEFAULTS as $key => $_) if (isset($_POST[$key])) $optionalEnabled = true;
            if (!in_array($retention, ['30', '90', '180', '365'], true)) $error = 'Select a supported retention period.';
            elseif (!is_string($botText) || strlen($botText) > 2000 || count(array_filter(explode("\n", $botText), static fn($line) => strlen(trim($line)) > 200)) > 0) $error = 'The custom bot pattern list is too long or contains a pattern over 200 characters.';
            elseif (!is_string($exclusions) || strlen($exclusions) > 4000 || count(array_filter(explode("\n", $exclusions), static fn($line) => trim($line) !== '' && (strlen(trim($line)) > 200 || !str_starts_with(trim($line), '/') || str_contains($line, '?') || str_contains($line, '#')))) > 0) $error = 'Use path patterns beginning with /, one per line, without query strings.';
            elseif ($optionalEnabled && ($_POST['confirm_extended'] ?? '') !== 'yes') $error = 'Confirm that you understand the selected Extended measurement changes the data processed.';
            else {
                $db->beginTransaction();
                try {
                    \Barelytics\saveDailyReachSetting($db, isset($_POST['daily_reach_enabled']), ($_POST['confirm_extended'] ?? '') === 'yes');
                    \Barelytics\saveDailyPageReachSetting($db, isset($_POST['daily_page_reach_enabled']), ($_POST['confirm_extended'] ?? '') === 'yes');
                    setSetting($db, 'retention_days', $retention);
                    foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, isset($_POST[$key]) ? '1' : '0');
                    setSetting($db, 'privacy_path_exclusions', trim($exclusions));
                    setSetting($db, 'bot_patterns', $botText);
                    recordPrivacyConfiguration($db);
                    $db->commit();
                    $message = 'Settings saved.';
                } catch (Throwable) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Settings could not be saved. The previous configuration remains active.';
                }
            }
        } elseif ($action === 'strict') {
            $db->beginTransaction();
            try {
                \Barelytics\saveDailyReachSetting($db, false, true);
                \Barelytics\saveDailyPageReachSetting($db, false, true);
                foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, '0');
                recordPrivacyConfiguration($db);
                $db->commit();
                $message = 'Strict Mode is active. Optional measurement is disabled; historical aggregates were retained.';
            } catch (Throwable) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Strict Mode could not be activated. The previous configuration remains active.';
            }
        } elseif ($action === 'delete_all' && ($_POST['confirm_delete'] ?? '') === 'yes') {
            $db->exec('DELETE FROM pageviews_daily'); $db->exec('DELETE FROM referrers_daily'); if (tableExists($db, 'dimensions_daily')) $db->exec('DELETE FROM dimensions_daily'); if (tableExists($db, 'automation_daily')) $db->exec('DELETE FROM automation_daily');
            if (tableExists($db, 'daily_reach')) $db->exec('DELETE FROM daily_reach');
            if (tableExists($db, 'daily_page_reach')) $db->exec('DELETE FROM daily_page_reach');
            setSetting($db, 'daily_page_reach_limited_days', '[]'); $message = 'All aggregate statistics were deleted.';
        } elseif ($action === 'cleanup') {
            $complete = cleanRetentionBatch($db);
            $message = $complete ? 'Expired statistics were removed.' : 'A bounded cleanup batch ran. Further expired records will be removed on a later request.';
} else $error = 'That action was not accepted.';
    }
    $csrf = csrfToken();
}

$authenticated = !empty($_SESSION['authenticated']) && $setupDone;
if (isset($_GET['api'])) {
    $resource = is_string($_GET['api']) ? $_GET['api'] : '';
    $input = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 8192) \Barelytics\adminApiReply(413, null, 'too_large', 'Request is too large.');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) \Barelytics\adminApiReply(400, null, 'invalid_json', 'The request body is invalid.');
        $input = $decoded;
    }
    if ($authenticated && $currentSchema < CURRENT_SCHEMA_VERSION && !in_array($resource, ['session', 'system', 'migrate', 'logout'], true)) \Barelytics\adminApiReply(409, null, 'migration_required', 'Apply the database upgrade before opening reports.');
    \Barelytics\dispatchAdminApi($db, $resource, $_GET, $input, $authenticated, $csrf);
}
if (isset($_GET['config'])) {
    if (!$authenticated) { http_response_code(403); exit; }
    header('Content-Type: text/javascript; charset=UTF-8'); header('Cache-Control: no-store');
    echo 'window.BARELYTICS_ADMIN_CONFIG = { apiBase: "admin.php", apiQuery: true, loginUrl: "admin.php", accountUrl: "account.php" };'; exit;
}
if ($authenticated && $currentSchema >= CURRENT_SCHEMA_VERSION) {
    runScheduledCleanupIfDue($db);
    if (($_GET['fallback'] ?? '') !== '1') {
        header('Content-Type: text/html; charset=UTF-8');
        $html = file_get_contents(__DIR__ . '/admin-ui/index.html');
        if (!is_string($html)) { http_response_code(503); exit('Administration UI unavailable.'); }
        $html = str_replace('admin-ui/config.js', 'admin.php?config=1', $html);
        foreach (['admin-ui/admin.css', 'admin-ui/app.js'] as $asset) {
            $html = str_replace($asset . '"', \Barelytics\escape(\Barelytics\adminAsset($asset)) . '"', $html);
        }
        if ($message !== '' || $error !== '') $html = str_replace('<noscript>', '<p class="notice">' . escape($error ?: $message) . '</p><noscript>', $html);
        echo $html; exit;
    }
}
\Barelytics\adminPageStart('Administration', '', false);
?>
<?php if ($error !== ''): ?><p class="notice error" role="alert"><?= escape($error) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice" role="status"><?= escape($message) ?></p><?php endif; ?>
<?php if (!$authenticated): ?>
<section class="card auth-card"><p class="eyebrow">PRIVATE ANALYTICS</p><h2>Welcome back</h2><p>Sign in to your Barelytics administration.</p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="login" value="1"><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in</button></form></section>
<?php elseif ($currentSchema < CURRENT_SCHEMA_VERSION): ?>
<section class="card"><h2>Database upgrade required</h2><p>Your aggregate data is intact. Barelytics will apply versioned, retry-safe database migrations, then run a read/write check.</p><p>Back up the private data directory before upgrading.</p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="migrate"><button>Apply database upgrade</button></form></section>
<?php else: ?>
<section class="card"><h2>Administration without JavaScript</h2><p>The interactive reports require JavaScript. This summary and the account, audit and diagnostics pages remain available without it.</p><p>Page views, last 30 UTC days: <strong><?= number_format((int) queryColumn($db, 'SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day >= :day', [':day' => gmdate('Y-m-d', time() - 29 * 86400)])) ?></strong></p><p><a href="admin.php">Open interactive administration</a> · <a href="account.php">Account</a> · <a href="audit.php">Privacy audit</a> · <a href="diagnostics.php">Diagnostics</a></p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form></section>
<?php endif; ?>
<?php \Barelytics\adminPageEnd(); ?>
