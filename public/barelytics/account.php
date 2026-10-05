<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { http_response_code(404); exit; }
require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\adminHeaders;
use function Barelytics\connectDatabase;
use function Barelytics\csrfToken;
use function Barelytics\escape;
use function Barelytics\passwordMeetsMinimum;
use function Barelytics\setting;
use function Barelytics\startAdminSession;
use function Barelytics\tableExists;
use function Barelytics\validCsrf;

adminHeaders();
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }
startAdminSession();
if (empty($_SESSION['authenticated'])) { http_response_code(403); exit('Administrator sign-in required.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('The form expired. Reload and try again.'); }

try {
    $db = connectDatabase();
    if (!tableExists($db, 'settings') || setting($db, 'setup_complete', '0') !== '1' || setting($db, 'admin_password_hash', '') === '') {
        http_response_code(403); exit('Completed administrator setup is required.');
    }
} catch (Throwable) {
    http_response_code(503); exit('Account settings are unavailable.');
}

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = is_string($_POST['current_password'] ?? null) ? (string) $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? (string) $_POST['new_password'] : '';
    $confirmation = is_string($_POST['confirmation'] ?? null) ? (string) $_POST['confirmation'] : '';
    if ($new === '' || strlen($new) > 1024 || !passwordMeetsMinimum($new)) {
        $error = 'Choose a new password with at least 12 characters and no more than 1024 bytes.';
    } elseif (!hash_equals($new, $confirmation)) {
        $error = 'The new password confirmation does not match.';
    } elseif ($current === '' || strlen($current) > 1024) {
        $error = 'The current password is incorrect.';
    } else {
        $transactionOpen = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $transactionOpen = true;
            $hash = setting($db, 'admin_password_hash', '');
            if ($hash === '' || !password_verify($current, $hash)) throw new RuntimeException('Current password is incorrect.');
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $newHash = password_hash($new, $algorithm);
            if (!is_string($newHash) || $newHash === '') throw new RuntimeException('Password hashing failed.');
            $statement = $db->prepare("UPDATE settings SET value = :hash WHERE key = 'admin_password_hash'");
            try {
                $statement->execute([':hash' => $newHash]);
                if ($statement->rowCount() !== 1) throw new RuntimeException('Password setting could not be updated.');
            } finally { $statement->closeCursor(); }
            $db->exec('COMMIT');
            $transactionOpen = false;
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            unset($_SESSION['failed_logins'], $_SESSION['login_failures'], $_SESSION['login_window'], $_SESSION['csrf']);
            $message = 'Administrator password changed.';
        } catch (Throwable $failure) {
            if ($transactionOpen) { try { $db->exec('ROLLBACK'); } catch (Throwable) { } }
            $error = str_contains(strtolower($failure->getMessage()), 'incorrect') ? 'The current password is incorrect.' : 'Password could not be changed. No account settings were altered.';
        }
    }
}
$csrf = csrfToken();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Account · Barelytics</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="wrap"><header><p class="brand"><img src="admin-ui/brand-mark.png" width="72" height="50" alt="Barelytics"></p><h1>Administrator account</h1><p><a href="admin.php">Back to administration</a></p></header>
<?php if ($error !== ''): ?><p class="notice error"><?= escape($error) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice"><?= escape($message) ?></p><?php endif; ?>
<section class="card"><h2>Change password</h2><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><label>Current password<input type="password" name="current_password" autocomplete="current-password" maxlength="1024" required></label><label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="1024" required></label><label>Confirm new password<input type="password" name="confirmation" autocomplete="new-password" minlength="12" maxlength="1024" required></label><button>Change password</button></form></section>
</main></body></html>
