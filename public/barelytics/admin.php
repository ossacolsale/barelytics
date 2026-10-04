<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { header('Location: install.php'); exit; }

require_once __DIR__ . '/src/Barelytics.php';
require_once __DIR__ . '/src/AdminApi.php';

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
use function Barelytics\queryColumn;
use function Barelytics\queryRows;
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
    \Barelytics\dispatchAdminApi($db, $resource, $_GET, $input, $authenticated, $csrf);
}
if (isset($_GET['config'])) {
    if (!$authenticated) { http_response_code(403); exit; }
    header('Content-Type: text/javascript; charset=UTF-8'); header('Cache-Control: no-store');
    echo 'window.BARELYTICS_ADMIN_CONFIG = { apiBase: "admin.php", apiQuery: true, loginUrl: "admin.php", accountUrl: "account.php" };'; exit;
}
if (($_GET['ui'] ?? '') === '1') {
    if (!$authenticated) { header('Location: admin.php'); exit; }
    header('Content-Type: text/html; charset=UTF-8');
    $html = file_get_contents(__DIR__ . '/admin-ui/index.html');
    if (!is_string($html)) { http_response_code(503); exit('Administration UI unavailable.'); }
    $html = str_replace('admin-ui/config.js', 'admin.php?config=1', $html);
    echo $html; exit;
}
if ($authenticated && $currentSchema >= CURRENT_SCHEMA_VERSION) runScheduledCleanupIfDue($db);
$periods = ['7' => '7 days', '30' => '30 days', '90' => '90 days', '180' => '180 days', '365' => '365 days'];
$requestedPeriod = is_string($_GET['period'] ?? null) ? $_GET['period'] : '30';
$period = isset($periods[$requestedPeriod]) ? (int) $requestedPeriod : 30;
$retention = effectivePrivacyConfig($db)['retention_days'];
$period = min($period, $retention);
$to = gmdate('Y-m-d'); $from = gmdate('Y-m-d', time() - max(0, $period - 1) * 86400);
$views = ['overview', 'pages', 'daily', 'page', 'dimensions'];
$view = is_string($_GET['view'] ?? null) && in_array($_GET['view'], $views, true) ? $_GET['view'] : 'overview';
$requestedBucket = is_string($_GET['bucket'] ?? null) ? $_GET['bucket'] : 'day';
$bucket = in_array($requestedBucket, ['day', 'week', 'month'], true) ? $requestedBucket : 'day';
$bucketExpression = match ($bucket) {
    'week' => "strftime('%Y-W%W', day)",
    'month' => "substr(day, 1, 7)",
    default => 'day',
};
$requestedPath = is_string($_GET['path'] ?? null) ? $_GET['path'] : '';
$selectedPath = strlen($requestedPath) <= 512 && !preg_match('/[\x00-\x1F\x7F]/', $requestedPath) ? $requestedPath : '';
$pageNumber = is_string($_GET['page_num'] ?? null) && preg_match('/^[0-9]+$/D', $_GET['page_num']) === 1 ? max(1, min(10000, (int) $_GET['page_num'])) : 1;
$rowsPerPage = 50;
$rowOffset = ($pageNumber - 1) * $rowsPerPage;
$requestedDimension = is_string($_GET['dimension'] ?? null) ? $_GET['dimension'] : 'country';
$dimensions = [
    'country' => ['label' => 'Country', 'table' => 'pageviews_daily', 'column' => 'country', 'enabled' => 'country_collection'],
    'referrer' => ['label' => 'Referrer host', 'table' => 'referrers_daily', 'column' => 'referrer_host', 'enabled' => 'referrer_collection'],
    'browser' => ['label' => 'Browser', 'table' => 'dimensions_daily', 'column' => 'value', 'enabled' => 'browser_collection'],
    'device' => ['label' => 'Device', 'table' => 'dimensions_daily', 'column' => 'value', 'enabled' => 'device_collection'],
    'os' => ['label' => 'Operating system', 'table' => 'dimensions_daily', 'column' => 'value', 'enabled' => 'os_collection'],
];
$dimension = isset($dimensions[$requestedDimension]) ? $requestedDimension : 'country';
$dimensionInfo = $dimensions[$dimension];
$privacyConfig = effectivePrivacyConfig($db);
$basePath = requestBasePath();
$jsSnippet = '<script defer src="' . $basePath . 'track.js"></script>';
$phpSnippet = "require_once rtrim(\$_SERVER['DOCUMENT_ROOT'], '/') . '" . $basePath . "src/Barelytics.php';\n\\Barelytics\\track();";
$total = 0; $activePages = 0; $dailyAverage = 0; $timeline = $topPages = $resultRows = [];
$resultCount = 0; $pageTotal = 0; $available = false;
if ($authenticated && $currentSchema >= CURRENT_SCHEMA_VERSION) {
    $total = (int) queryColumn($db, 'SELECT COALESCE(SUM(views), 0) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $to]);
    $activePages = (int) queryColumn($db, 'SELECT COUNT(DISTINCT path) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $to]);
    $dailyAverage = $period > 0 ? round($total / $period, 1) : 0;
    $topPages = queryRows($db, 'SELECT path, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC, path LIMIT 10', [':from' => $from, ':to' => $to]);

    if (in_array($view, ['overview', 'page'], true)) {
        $sql = 'SELECT ' . $bucketExpression . ' AS bucket, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to';
        $params = [':from' => $from, ':to' => $to];
        if ($view === 'page') { $sql .= ' AND path = :path'; $params[':path'] = $selectedPath; }
        $sql .= ' GROUP BY bucket ORDER BY bucket';
        $timeline = queryRows($db, $sql, $params);
        if ($view === 'page' && $selectedPath !== '') $pageTotal = (int) queryColumn($db, 'SELECT COALESCE(SUM(views), 0) FROM pageviews_daily WHERE path = :path AND day BETWEEN :from AND :to', [':path' => $selectedPath, ':from' => $from, ':to' => $to]);
    } elseif ($view === 'pages') {
        $resultCount = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT path FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path)', [':from' => $from, ':to' => $to]);
        $resultRows = queryRows($db, 'SELECT path, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC, path LIMIT :limit OFFSET :offset', [':from' => $from, ':to' => $to, ':limit' => $rowsPerPage, ':offset' => $rowOffset]);
    } elseif ($view === 'daily') {
        $resultCount = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT day, path FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY day, path)', [':from' => $from, ':to' => $to]);
        $resultRows = queryRows($db, 'SELECT day, path, SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY day, path ORDER BY day DESC, views DESC, path LIMIT :limit OFFSET :offset', [':from' => $from, ':to' => $to, ':limit' => $rowsPerPage, ':offset' => $rowOffset]);
    } elseif ($view === 'dimensions') {
        $available = !empty($privacyConfig[$dimensionInfo['enabled']]);
        $isDimensionTable = $dimensionInfo['table'] === 'dimensions_daily';
        if ($available) {
            $where = $isDimensionTable ? 'dimension = :dimension AND day BETWEEN :from AND :to' : 'day BETWEEN :from AND :to';
            $params = [':from' => $from, ':to' => $to];
            if ($isDimensionTable) $params[':dimension'] = $dimension;
            $table = $dimensionInfo['table'];
            $column = $dimensionInfo['column'];
            $resultCount = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $where . ' GROUP BY ' . $column . ')', $params);
            $resultRows = queryRows($db, 'SELECT ' . $column . ' AS label, SUM(views) AS views FROM ' . $table . ' WHERE ' . $where . ' GROUP BY ' . $column . ' ORDER BY views DESC, label LIMIT :limit OFFSET :offset', $params + [':limit' => $rowsPerPage, ':offset' => $rowOffset]);
        }
    }
}
$makeUrl = static function (array $overrides = []) use ($view, $period, $bucket, $selectedPath, $dimension): string {
    return '?' . http_build_query(array_merge(['view' => $view, 'period' => $period, 'bucket' => $bucket, 'path' => $selectedPath, 'dimension' => $dimension], $overrides));
};
$timelineValues = array_map(static fn($row) => (int) $row['views'], $timeline);
$timelineMax = $timelineValues ? max(1, ...$timelineValues) : 1;
$resultPages = max(1, (int) ceil($resultCount / $rowsPerPage));
$metricPageCount = $view === 'page' ? count($timeline) : $activePages;
$metricAverage = $view === 'page' ? ($period > 0 ? round($pageTotal / $period, 1) : 0) : $dailyAverage;
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
<nav class="section-nav" aria-label="Administration sections">
<a href="admin.php?ui=1">Shared administration UI</a><a href="#statistics">Statistics</a><a href="#explore">Explore data</a><a href="#integration">Site integration</a><a href="#privacy">Privacy</a><a href="#maintenance">Maintenance</a><a href="#system">System</a><a href="audit.php">Privacy audit</a><a href="account.php">Account</a>
<form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form>
</nav>

<section class="stats-panel" id="statistics">
<div class="section-heading"><div><p class="eyebrow">YOUR TRAFFIC</p><h2>Statistics</h2><p class="note">Aggregated page views · UTC dates · retention <?= (int) $retention ?> days</p></div>
<form method="get" class="filter-form">
<label>View<select name="view"><option value="overview" <?= $view === 'overview' ? 'selected' : '' ?>>Overview</option><option value="pages" <?= $view === 'pages' ? 'selected' : '' ?>>All pages</option><option value="daily" <?= $view === 'daily' ? 'selected' : '' ?>>Day × page</option><option value="dimensions" <?= $view === 'dimensions' ? 'selected' : '' ?>>Dimensions</option><?php if ($view === 'page'): ?><option value="page" selected>Page detail</option><?php endif; ?></select></label>
<label>Period<select name="period"><?php foreach ($periods as $days => $label): if ((int) $days <= $retention): ?><option value="<?= escape((string) $days) ?>" <?= $period === (int) $days ? 'selected' : '' ?>><?= escape($label) ?></option><?php endif; endforeach; ?></select></label>
<?php if (in_array($view, ['overview', 'page'], true)): ?><label>Group by<select name="bucket"><option value="day" <?= $bucket === 'day' ? 'selected' : '' ?>>Day</option><option value="week" <?= $bucket === 'week' ? 'selected' : '' ?>>Week</option><option value="month" <?= $bucket === 'month' ? 'selected' : '' ?>>Month</option></select></label><?php else: ?><input type="hidden" name="bucket" value="<?= escape($bucket) ?>"><?php endif; ?>
<?php if ($view === 'dimensions'): ?><label>Dimension<select name="dimension"><?php foreach ($dimensions as $key => $info): ?><option value="<?= escape($key) ?>" <?= $dimension === $key ? 'selected' : '' ?>><?= escape($info['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if ($view === 'page' && $selectedPath !== ''): ?><input type="hidden" name="path" value="<?= escape($selectedPath) ?>"><?php endif; ?>
<button>Apply</button></form></div>

<div class="metric-grid"><article class="metric metric-primary"><span><?= $view === 'page' ? 'Views for this page' : 'Page views' ?></span><strong><?= number_format($view === 'page' ? $pageTotal : $total) ?></strong><small><?= escape($periods[(string) $period]) ?></small></article><article class="metric"><span><?= $view === 'page' ? 'Periods with views' : 'Pages viewed' ?></span><strong><?= number_format($metricPageCount) ?></strong><small><?= $view === 'page' ? escape($bucket === 'day' ? 'days' : ($bucket === 'week' ? 'weeks' : 'months')) : 'distinct paths' ?></small></article><article class="metric"><span>Daily average</span><strong><?= number_format($metricAverage, 1) ?></strong><small><?= $view === 'page' ? 'views for this page' : 'views across all pages' ?> per day</small></article></div>

<?php if ($view === 'overview'): ?>
<div class="stats-layout"><article class="card chart-card"><div class="card-heading"><div><h3>Views over time</h3><p class="note">Grouped by <?= $bucket === 'day' ? 'day' : ($bucket === 'week' ? 'week' : 'month') ?></p></div></div>
<?php if ($timeline): ?><div class="bar-chart"><?php foreach ($timeline as $row): ?><div class="bar-row"><span class="bar-label"><?= escape((string) $row['bucket']) ?></span><progress max="<?= $timelineMax ?>" value="<?= (int) $row['views'] ?>" aria-label="<?= escape((string) $row['bucket']) ?>: <?= number_format((int) $row['views']) ?> views"></progress><strong><?= number_format((int) $row['views']) ?></strong></div><?php endforeach; ?></div><?php else: ?><p class="empty-state">No page views in this period yet.</p><?php endif; ?></article>
<article class="card"><div class="card-heading"><div><h3>Top pages</h3><p class="note">Most viewed paths for this period</p></div><a class="text-link" href="<?= escape($makeUrl(['view' => 'pages', 'page_num' => 1])) ?>">See all</a></div>
<?php if ($topPages): ?><ol class="ranked-list"><?php foreach ($topPages as $row): ?><li><a href="<?= escape($makeUrl(['view' => 'page', 'path' => (string) $row['path'], 'bucket' => 'day'])) ?>"><span><?= escape((string) $row['path']) ?></span><strong><?= number_format((int) $row['views']) ?></strong></a></li><?php endforeach; ?></ol><?php else: ?><p class="empty-state">No pages to show yet.</p><?php endif; ?></article></div>
<div class="cta-grid"><a class="cta-card" href="<?= escape($makeUrl(['view' => 'daily', 'page_num' => 1])) ?>"><strong>Every day × every page</strong><span>Inspect individual page counts for each day.</span><b>Open daily detail →</b></a><a class="cta-card" href="<?= escape($makeUrl(['view' => 'pages', 'page_num' => 1])) ?>"><strong>Compare all pages</strong><span>Sort paths by total views in the selected period.</span><b>Browse pages →</b></a><a class="cta-card" href="<?= escape($makeUrl(['view' => 'dimensions', 'dimension' => 'country'])) ?>"><strong>Break down dimensions</strong><span>Explore country, referrer and enabled categories.</span><b>Explore dimensions →</b></a></div>

<?php elseif ($view === 'page'): ?>
<article class="card chart-card"><div class="card-heading"><div><p class="eyebrow">PAGE DETAIL</p><h3 class="path-title"><?= $selectedPath !== '' ? escape($selectedPath) : 'Choose a page from the overview' ?></h3><p class="note">This path’s views over the selected period.</p></div><a class="text-link" href="<?= escape($makeUrl(['view' => 'pages', 'page_num' => 1])) ?>">Choose another page</a></div>
<?php if ($selectedPath !== '' && $timeline): ?><div class="bar-chart"><?php foreach ($timeline as $row): ?><div class="bar-row"><span class="bar-label"><?= escape((string) $row['bucket']) ?></span><progress max="<?= $timelineMax ?>" value="<?= (int) $row['views'] ?>" aria-label="<?= escape((string) $row['bucket']) ?>: <?= number_format((int) $row['views']) ?> views"></progress><strong><?= number_format((int) $row['views']) ?></strong></div><?php endforeach; ?></div><?php elseif ($selectedPath !== ''): ?><p class="empty-state">No views for this page in the selected period.</p><?php endif; ?></article>

<?php elseif ($view === 'pages'): ?>
<article class="card"><div class="card-heading"><div><p class="eyebrow">PAGE COMPARISON</p><h3>All pages</h3><p class="note"><?= number_format($resultCount) ?> paths · sorted by views, highest first</p></div><a class="text-link" href="<?= escape($makeUrl(['view' => 'daily', 'page_num' => 1])) ?>">View day × page</a></div>
<div class="table-scroll"><table><thead><tr><th>Page path</th><th>Views</th><th>Explore</th></tr></thead><tbody><?php foreach ($resultRows as $row): ?><tr><td class="path-cell"><?= escape((string) $row['path']) ?></td><td><?= number_format((int) $row['views']) ?></td><td><a href="<?= escape($makeUrl(['view' => 'page', 'path' => (string) $row['path'], 'bucket' => 'day'])) ?>">Daily trend →</a></td></tr><?php endforeach; if (!$resultRows): ?><tr><td colspan="3" class="empty-state">No pages in this period.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($resultPages > 1): ?><div class="pagination"><span>Page <?= $pageNumber ?> of <?= $resultPages ?></span><div><?php if ($pageNumber > 1): ?><a href="<?= escape($makeUrl(['page_num' => $pageNumber - 1])) ?>">← Previous</a><?php endif; ?><?php if ($pageNumber < $resultPages): ?><a href="<?= escape($makeUrl(['page_num' => $pageNumber + 1])) ?>">Next →</a><?php endif; ?></div></div><?php endif; ?></article>

<?php elseif ($view === 'daily'): ?>
<article class="card"><div class="card-heading"><div><p class="eyebrow">FULL DAILY BREAKDOWN</p><h3>Day × page</h3><p class="note"><?= number_format($resultCount) ?> day/page combinations · newest days first</p></div><a class="text-link" href="<?= escape($makeUrl(['view' => 'pages', 'page_num' => 1])) ?>">Compare page totals</a></div>
<div class="table-scroll"><table><thead><tr><th>Day (UTC)</th><th>Page path</th><th>Views</th><th>Explore</th></tr></thead><tbody><?php foreach ($resultRows as $row): ?><tr><td><?= escape((string) $row['day']) ?></td><td class="path-cell"><?= escape((string) $row['path']) ?></td><td><?= number_format((int) $row['views']) ?></td><td><a href="<?= escape($makeUrl(['view' => 'page', 'path' => (string) $row['path'], 'bucket' => 'day'])) ?>">Page trend →</a></td></tr><?php endforeach; if (!$resultRows): ?><tr><td colspan="4" class="empty-state">No page views in this period.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($resultPages > 1): ?><div class="pagination"><span>Page <?= $pageNumber ?> of <?= $resultPages ?></span><div><?php if ($pageNumber > 1): ?><a href="<?= escape($makeUrl(['page_num' => $pageNumber - 1])) ?>">← Previous</a><?php endif; ?><?php if ($pageNumber < $resultPages): ?><a href="<?= escape($makeUrl(['page_num' => $pageNumber + 1])) ?>">Next →</a><?php endif; ?></div></div><?php endif; ?></article>

<?php elseif ($view === 'dimensions'): ?>
<article class="card"><div class="card-heading"><div><p class="eyebrow">AGGREGATED BREAKDOWN</p><h3><?= escape($dimensionInfo['label']) ?></h3></div><a class="text-link" href="#privacy">Configure dimensions →</a></div>
<?php if (!$available): ?><p class="empty-state">This dimension is off. Enable it in Privacy settings to collect it going forward.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th><?= escape($dimensionInfo['label']) ?></th><th>Views</th></tr></thead><tbody><?php foreach ($resultRows as $row): ?><tr><td><?= escape((string) $row['label']) ?></td><td><?= number_format((int) $row['views']) ?></td></tr><?php endforeach; if (!$resultRows): ?><tr><td colspan="2" class="empty-state">No values in this period.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?></article>
<?php endif; ?>
</section>

<section class="admin-section" id="explore"><div class="section-heading"><div><p class="eyebrow">ANALYSIS</p><h2>Explore the data</h2><p class="note">Change the period above, then choose the level of detail you need.</p></div></div><div class="cta-grid"><a class="cta-card" href="<?= escape($makeUrl(['view' => 'overview', 'bucket' => 'day'])) ?>"><strong>Daily trend</strong><span>See total views for each day, week or month.</span><b>Open overview →</b></a><a class="cta-card" href="<?= escape($makeUrl(['view' => 'daily', 'page_num' => 1])) ?>"><strong>All pages by day</strong><span>One row for every day and page combination.</span><b>Open day × page →</b></a><a class="cta-card" href="<?= escape($makeUrl(['view' => 'pages', 'page_num' => 1])) ?>"><strong>Totals by page</strong><span>Rank every page and open its own trend.</span><b>Open page totals →</b></a><a class="cta-card" href="<?= escape($makeUrl(['view' => 'dimensions', 'dimension' => 'country'])) ?>"><strong>Optional dimensions</strong><span>Country, referrer host, browser, device and OS.</span><b>Open breakdowns →</b></a></div></section>

<section class="admin-section" id="integration"><div class="section-heading"><div><p class="eyebrow">GET STARTED</p><h2>Site integration</h2></div></div><article class="card"><p>Use one integration method per page to prevent double counting. The JavaScript snippet works for static HTML pages and adjusts to this Barelytics subdirectory.</p><label>JavaScript snippet<textarea rows="2" readonly><?= escape($jsSnippet) ?></textarea></label><p>For a server-rendered PHP page, add this call once per page response:</p><pre><code><?= escape($phpSnippet) ?></code></pre><p class="note">Your site’s Content Security Policy may need this path under <code>script-src</code> and <code>connect-src</code>. The tracker sends a same-origin non-blocking request.</p></article></section>

<section class="admin-section" id="privacy"><div class="section-heading"><div><p class="eyebrow">DATA COLLECTION</p><h2>Privacy settings</h2></div><span class="status-pill"><?= strtoupper(escape($privacyConfig['profile'])) ?> mode</span></div><article class="card"><p>Strict Mode records aggregate page views only. Optional dimensions use coarse categories. Bot filtering is heuristic.</p><form method="post" class="settings-form"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="settings"><label>Aggregate retention<select name="retention_days"><?php foreach ([30, 90, 180, 365] as $value): ?><option value="<?= $value ?>" <?= $retention === $value ? 'selected' : '' ?>><?= $value ?> days</option><?php endforeach; ?></select></label><h3>Optional dimensions</h3><label class="check"><input type="checkbox" name="country_collection" <?= $privacyConfig['country_collection'] ? 'checked' : '' ?>> Country from a trusted server header</label><label class="check"><input type="checkbox" name="referrer_collection" <?= $privacyConfig['referrer_collection'] ? 'checked' : '' ?>> Referrer hostname only</label><label class="check"><input type="checkbox" name="browser_collection" <?= $privacyConfig['browser_collection'] ? 'checked' : '' ?>> Coarse browser category</label><label class="check"><input type="checkbox" name="device_collection" <?= $privacyConfig['device_collection'] ? 'checked' : '' ?>> Coarse device category</label><label class="check"><input type="checkbox" name="os_collection" <?= $privacyConfig['os_collection'] ? 'checked' : '' ?>> Coarse operating-system category</label><p class="note">Enabling a dimension changes the profile to Extended. It starts collecting from that point; historical values are not reconstructed.</p><label class="check"><input type="checkbox" name="confirm_extended" value="yes"> I understand optional dimensions change the information processed.</label><label>Private path exclusions, one pattern per line<textarea name="path_exclusions" rows="5" maxlength="4000"><?= escape(implode("\n", $privacyConfig['path_exclusions'])) ?></textarea></label><label>Additional bot patterns, one per line<textarea name="bot_patterns" rows="4" maxlength="2000"><?= escape(setting($db, 'bot_patterns', '')) ?></textarea></label><button>Save privacy settings</button></form><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="strict"><button class="secondary">Return to Strict Mode</button></form><p><a href="audit.php">Open privacy audit →</a></p></article></section>

<section class="admin-section" id="maintenance"><div class="section-heading"><div><p class="eyebrow">DATABASE TASKS</p><h2>Maintenance</h2></div></div><article class="card maintenance-actions"><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="cleanup"><p>Remove expired aggregates in a bounded batch.</p><button class="secondary">Clean up expired data</button></form><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="delete_all"><label class="check"><input type="checkbox" name="confirm_delete" value="yes" required> Delete all aggregate statistics permanently</label><button class="danger">Delete all statistics</button></form></article></section>

<section class="admin-section" id="system"><div class="section-heading"><div><p class="eyebrow">APPLICATION</p><h2>System and account</h2></div></div><article class="card system-links"><p>Barelytics <?= escape(setting($db, 'application_version', 'development')) ?> · Schema <?= (int) $currentSchema ?> / <?= CURRENT_SCHEMA_VERSION ?> · PHP <?= escape(PHP_VERSION) ?>.</p><a href="diagnostics.php">Open database diagnostics →</a><a href="account.php">Change administrator password →</a><a href="audit.php">Download or inspect privacy audit →</a></article></section>
<?php endif; ?></main></body></html>
