<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID > 80599) { header('Location: install.php'); exit; }

require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\adminHeaders;
use function Barelytics\cleanRetentionBatch;
use function Barelytics\csrfToken;
use function Barelytics\connectDatabase;
use function Barelytics\escape;
use function Barelytics\runScheduledCleanupIfDue;
use function Barelytics\requestBasePath;
use function Barelytics\schemaVersion;
use function Barelytics\setSetting;
use function Barelytics\effectivePrivacyConfig;
use function Barelytics\recordPrivacyConfiguration;
use const Barelytics\PRIVACY_DEFAULTS;
use function Barelytics\setting;
use function Barelytics\startAdminSession;
use function Barelytics\tableExists;
use function Barelytics\validCsrf;
use function Barelytics\webrootStatus;
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
                header('Location: admin.php'); exit;
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
            $optionalEnabled = false;
            foreach (PRIVACY_DEFAULTS as $key => $_) if (isset($_POST[$key])) $optionalEnabled = true;
            if (!in_array($retention, ['30', '90', '180', '365'], true)) $error = 'Select a supported retention period.';
            elseif (!is_string($botText) || strlen($botText) > 2000 || count(array_filter(explode("\n", $botText), static fn($line) => strlen(trim($line)) > 200)) > 0) $error = 'The custom bot pattern list is too long or contains a pattern over 200 characters.';
            elseif (!is_string($exclusions) || strlen($exclusions) > 4000 || count(array_filter(explode("\n", $exclusions), static fn($line) => trim($line) !== '' && (strlen(trim($line)) > 200 || !str_starts_with(trim($line), '/') || str_contains($line, '?') || str_contains($line, '#')))) > 0) $error = 'Use path patterns beginning with /, one per line, without query strings.';
            elseif ($optionalEnabled && ($_POST['confirm_extended'] ?? '') !== 'yes') $error = 'Confirm that you understand the selected dimensions change the data processed.';
            else {
                $db->beginTransaction();
                try {
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
                foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, '0');
                recordPrivacyConfiguration($db);
                $db->commit();
                $message = 'Strict Mode is active. Optional dimensions are disabled; historical aggregates were retained.';
            } catch (Throwable) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Strict Mode could not be activated. The previous configuration remains active.';
            }
        } elseif ($action === 'delete_all' && ($_POST['confirm_delete'] ?? '') === 'yes') {
            $db->exec('DELETE FROM pageviews_daily'); $db->exec('DELETE FROM referrers_daily'); if (tableExists($db, 'dimensions_daily')) $db->exec('DELETE FROM dimensions_daily'); $message = 'All aggregate statistics were deleted.';
        } elseif ($action === 'cleanup') {
            $complete = cleanRetentionBatch($db);
            $message = $complete ? 'Expired statistics were removed.' : 'A bounded cleanup batch ran. Further expired records will be removed on a later request.';
        } else $error = 'That action was not accepted.';
    }
    $csrf = csrfToken();
}

$authenticated = !empty($_SESSION['authenticated']) && $setupDone;
if ($authenticated && $currentSchema >= CURRENT_SCHEMA_VERSION) runScheduledCleanupIfDue($db);
$periods = ['7' => '7 days', '30' => '30 days', '90' => '90 days', '180' => '180 days', '365' => '365 days'];
$period = isset($_GET['period']) && isset($periods[(string) $_GET['period']]) ? (int) $_GET['period'] : 30;
$retention = effectivePrivacyConfig($db)['retention_days'];
$period = min($period, $retention);
$to = gmdate('Y-m-d'); $from = gmdate('Y-m-d', time() - max(0, $period - 1) * 86400);
$basePath = requestBasePath();
$jsSnippet = '<script defer src="' . $basePath . 'track.js"></script>';
$phpSnippet = "require_once rtrim(\$_SERVER['DOCUMENT_ROOT'], '/') . '" . $basePath . "src/Barelytics.php';\n\\Barelytics\\track();";
$total = 0; $daily = $pages = $countries = $referrers = $dimensionStats = [];
if ($authenticated && $currentSchema >= CURRENT_SCHEMA_VERSION) {
    $stmt = $db->prepare('SELECT COALESCE(SUM(views), 0) FROM pageviews_daily WHERE day BETWEEN :from AND :to'); $stmt->execute([':from' => $from, ':to' => $to]); $total = (int) $stmt->fetchColumn();
    $stmt = $db->prepare('SELECT day, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY day ORDER BY day'); $stmt->execute([':from' => $from, ':to' => $to]); $daily = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT path, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC LIMIT 50'); $stmt->execute([':from' => $from, ':to' => $to]); $pages = $stmt->fetchAll();
    $effectivePrivacy = effectivePrivacyConfig($db);
    if ($effectivePrivacy['country_collection']) { $stmt = $db->prepare('SELECT country, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY country ORDER BY views DESC'); $stmt->execute([':from' => $from, ':to' => $to]); $countries = $stmt->fetchAll(); }
    if ($effectivePrivacy['referrer_collection']) {
        $stmt = $db->prepare('SELECT referrer_host, SUM(views) AS views FROM referrers_daily WHERE day BETWEEN :from AND :to GROUP BY referrer_host ORDER BY views DESC LIMIT 50'); $stmt->execute([':from' => $from, ':to' => $to]); $referrers = $stmt->fetchAll();
    }
    foreach (['browser_collection' => 'browser', 'device_collection' => 'device', 'os_collection' => 'os'] as $flag => $dimension) {
        if (!$effectivePrivacy[$flag]) continue;
        $stmt = $db->prepare('SELECT value, SUM(views) AS views FROM dimensions_daily WHERE dimension = :dimension AND day BETWEEN :from AND :to GROUP BY value ORDER BY views DESC');
        $stmt->execute([':dimension' => $dimension, ':from' => $from, ':to' => $to]);
        $dimensionStats[$dimension] = $stmt->fetchAll();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Barelytics administration</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="wrap"><header><p class="brand">BARELYTICS</p><h1>Analytics administration</h1></header>
<?php if ($error !== ''): ?><p class="notice error"><?= escape($error) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="notice"><?= escape($message) ?></p><?php endif; ?>
<?php if (!$authenticated): ?>
<section class="card"><h2>Sign in</h2><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="login" value="1"><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in</button></form></section>
<?php elseif ($currentSchema < CURRENT_SCHEMA_VERSION): ?>
<section class="card"><h2>Database upgrade required</h2><p>Your aggregate data is intact. Barelytics will apply versioned, retry-safe database migrations, then run a read/write check.</p><p>Back up the private data directory before upgrading.</p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="migrate"><button>Apply database upgrade</button></form></section>
<?php else: ?>
<div class="toolbar"><p>Page views in the selected period: <strong><?= number_format($total) ?></strong></p><form method="get"><label>Period<select name="period"><?php foreach ($periods as $days => $label): if ((int) $days <= $retention): ?><option value="<?= escape((string) $days) ?>" <?= $period === (int) $days ? 'selected' : '' ?>><?= escape($label) ?></option><?php endif; endforeach; ?></select></label><button>Apply</button></form><p><a href="audit.php">Privacy audit</a></p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form></div>
<section class="card"><h2>Connect your site</h2><p>Choose one integration method per page to prevent double counting. The JavaScript snippet works for static HTML pages and adjusts to this Barelytics subdirectory.</p><label>JavaScript snippet<textarea rows="2" readonly><?= escape($jsSnippet) ?></textarea></label><p>For a server-rendered PHP page, add this call once per page response:</p><pre><code><?= escape($phpSnippet) ?></code></pre><p>The site’s Content Security Policy may need its own path under <code>script-src</code> and <code>connect-src</code>. The script makes a same-origin non-blocking POST and fails silently.</p></section>
<section class="grid"><div class="card"><h2>Daily page views</h2><table><thead><tr><th>Day (UTC)</th><th>Views</th></tr></thead><tbody><?php foreach ($daily as $row): ?><tr><td><?= escape((string) $row['day']) ?></td><td><?= number_format((int) $row['views']) ?></td></tr><?php endforeach; if (!$daily): ?><tr><td colspan="2">No data for this period.</td></tr><?php endif; ?></tbody></table></div>
<div class="card"><h2>Top pages</h2><table><thead><tr><th>Path</th><th>Views</th></tr></thead><tbody><?php foreach ($pages as $row): ?><tr><td><?= escape((string) $row['path']) ?></td><td><?= number_format((int) $row['views']) ?></td></tr><?php endforeach; if (!$pages): ?><tr><td colspan="2">No data for this period.</td></tr><?php endif; ?></tbody></table></div>
<?php if (effectivePrivacyConfig($db)['country_collection']): ?>
<div class="card"><h2>Page views by country</h2><table><thead><tr><th>Country</th><th>Views</th></tr></thead><tbody><?php foreach ($countries as $row): ?><tr><td><?= escape((string) $row['country']) ?></td><td><?= number_format((int) $row['views']) ?></td></tr><?php endforeach; if (!$countries): ?><tr><td colspan="2">No data for this period.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php $dashboardPrivacy = effectivePrivacyConfig($db); if ($dashboardPrivacy['referrer_collection']): ?><div class="card"><h2>Top referrer hosts</h2><table><thead><tr><th>Hostname</th><th>Views</th></tr></thead><tbody><?php foreach ($referrers as $row): ?><tr><td><?= escape((string) $row['referrer_host']) ?></td><td><?= number_format((int) $row['views']) ?></td></tr><?php endforeach; if (!$referrers): ?><tr><td colspan="2">No data for this period.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?><?php foreach (['browser'=>'Browser category','device'=>'Device category','os'=>'Operating system category'] as $dimension => $label): if (isset($dimensionStats[$dimension])): ?><div class="card"><h2><?= escape($label) ?></h2><table><thead><tr><th>Category</th><th>Views</th></tr></thead><tbody><?php foreach ($dimensionStats[$dimension] as $row): ?><tr><td><?= escape((string) $row['value']) ?></td><td><?= number_format((int) $row['views']) ?></td></tr><?php endforeach; if (!$dimensionStats[$dimension]): ?><tr><td colspan="2">No data for this period.</td></tr><?php endif; ?></tbody></table></div><?php endif; endforeach; ?></section>
<p class="note">Bot/crawler filtering is heuristic and may not identify every automated request.</p>
<section class="card"><h2>Analytics privacy</h2><?php $privacy = effectivePrivacyConfig($db); ?><p>Effective profile: <strong><?= strtoupper(escape($privacy['profile'])) ?></strong>. Strict Mode records aggregate page views only. Optional dimensions use coarse aggregate categories and can be disabled at any time.</p><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="settings"><label>Aggregate retention<select name="retention_days"><?php foreach ([30, 90, 180, 365] as $value): ?><option value="<?= $value ?>" <?= $retention === $value ? 'selected' : '' ?>><?= $value ?> days</option><?php endforeach; ?></select></label><h3>Optional dimensions</h3><label class="check"><input type="checkbox" name="country_collection" <?= $privacy['country_collection'] ? 'checked' : '' ?>> Country from trusted server header (only two-letter country code; no external lookup)</label><label class="check"><input type="checkbox" name="referrer_collection" <?= $privacy['referrer_collection'] ? 'checked' : '' ?>> Referrer hostname (drops paths and query strings)</label><label class="check"><input type="checkbox" name="browser_collection" <?= $privacy['browser_collection'] ? 'checked' : '' ?>> Coarse browser category</label><label class="check"><input type="checkbox" name="device_collection" <?= $privacy['device_collection'] ? 'checked' : '' ?>> Coarse device category</label><label class="check"><input type="checkbox" name="os_collection" <?= $privacy['os_collection'] ? 'checked' : '' ?>> Coarse operating-system category</label><p>Enabling any option changes the effective profile to Extended. Barelytics does not support visitor IDs, fingerprinting, query-string analytics, generic events, browser storage, or third-party analytics.</p><label class="check"><input type="checkbox" name="confirm_extended" value="yes"> I understand optional dimensions change the information processed by Barelytics.</label><label>Private path exclusions, one pattern per line<textarea name="path_exclusions" rows="5" maxlength="4000"><?= escape(implode("\n", $privacy['path_exclusions'])) ?></textarea></label><p>Excluded paths are not recorded. Query strings and fragments are always omitted.</p><label>Additional bot patterns, one per line<textarea name="bot_patterns" rows="4" maxlength="2000"><?= escape(setting($db, 'bot_patterns', '')) ?></textarea></label><button>Save privacy settings</button></form><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="strict"><button class="secondary">Return to Strict Mode</button></form></section>
<section class="card"><h2>Maintenance</h2><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="cleanup"><button class="secondary">Delete statistics older than retention</button></form><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="delete_all"><label class="check"><input type="checkbox" name="confirm_delete" value="yes" required> I understand this permanently deletes all statistics</label><button class="danger">Delete all statistics</button></form></section>
<?php $storageStatus = webrootStatus(dataDirectory()); ?>
<section class="card"><h2>Diagnostics</h2><table><tbody><tr><th>Barelytics</th><td><?= escape(setting($db, 'application_version', 'development')) ?></td></tr><tr><th>PHP</th><td><?= escape(PHP_VERSION) ?></td></tr><tr><th>SQLite</th><td><?= escape((string) $db->query('SELECT sqlite_version()')->fetchColumn()) ?></td></tr><tr><th>PDO SQLite</th><td>Available</td></tr><tr><th>HTTPS</th><td><?= \Barelytics\isHttpsRequest() ? 'Active' : 'Warning: this request is not HTTPS' ?></td></tr><tr><th>Data storage</th><td><?= $storageStatus['inside'] ? 'Inside document root; Apache denial rule configured. Verify host enforcement.' : 'Outside document root or server path is private' ?></td></tr><tr><th>Schema</th><td><?= (int) $currentSchema ?> / <?= CURRENT_SCHEMA_VERSION ?></td></tr><tr><th>Last migration (UTC)</th><td><?= escape(setting($db, 'last_migration_at', 'Not recorded')) ?></td></tr><tr><th>Last retention cleanup (UTC)</th><td><?= escape(setting($db, 'last_cleanup_at', 'Not run yet')) ?></td></tr><tr><th>Tracker endpoint</th><td>Same-origin POST endpoint available</td></tr></tbody></table></section>
<?php endif; ?></main></body></html>
