<?php
declare(strict_types=1);

namespace Barelytics;

use PDO;
use Throwable;

function adminApiReply(int $status, mixed $data = null, ?string $code = null, ?string $message = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $ok = $status >= 200 && $status < 300;
    echo json_encode(['ok' => $ok, 'data' => $ok ? $data : null, 'error' => $ok ? null : ['code' => $code ?? 'request_failed', 'message' => $message ?? 'The administration request failed.']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Bounded aggregate-only automation report for an authenticated caller. */
function automationReport(PDO $db, string $from, string $to, string $bucketSql = 'day', int $page = 1, int $perPage = 50): array
{
    if (!in_array($bucketSql, ['day', "strftime('%Y-W%W', day)", 'substr(day, 1, 7)'], true)) $bucketSql = 'day';
    $page = max(1, min(10000, $page)); $perPage = max(1, min(50, $perPage));
    $params = [':from' => $from, ':to' => $to];
    $total = (int) queryColumn($db, 'SELECT COALESCE(SUM(requests),0) FROM automation_daily WHERE day BETWEEN :from AND :to', $params);
    $count = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT path FROM automation_daily WHERE day BETWEEN :from AND :to GROUP BY path)', $params);
    $page = min($page, max(1, (int) ceil($count / $perPage)));
    return ['total' => $total, 'verified' => false, 'page' => $page, 'per_page' => $perPage, 'total_rows' => $count, 'pages' => (int) ceil($count / $perPage),
        'timeline' => queryRows($db, 'SELECT ' . $bucketSql . ' AS period,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN :from AND :to GROUP BY period ORDER BY period', $params),
        'categories' => queryRows($db, 'SELECT category,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN :from AND :to GROUP BY category ORDER BY requests DESC,category', $params),
        'families' => queryRows($db, 'SELECT category,family,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN :from AND :to GROUP BY category,family ORDER BY requests DESC,category,family', $params),
        'rows' => queryRows($db, 'SELECT path,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY requests DESC,path LIMIT :limit OFFSET :offset', $params + [':limit' => $perPage, ':offset' => ($page - 1) * $perPage])];
}

/** Serve the shared admin API. Caller has already initialized the standalone PHP session. */
function dispatchAdminApi(PDO $db, string $resource, array $query, array $input, bool $authenticated, string $csrf, bool $csrfVerifiedByHost = false): never
{
    if (!$authenticated) adminApiReply(403, null, 'forbidden', 'Administrator sign-in required.');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $config = effectivePrivacyConfig($db);
    $retention = (int) $config['retention_days'];
    $periods = [7, 30, 90, 180, 365];
    $requestedPeriod = filter_var($query['period'] ?? 30, FILTER_VALIDATE_INT);
    $period = min(in_array($requestedPeriod, $periods, true) ? $requestedPeriod : 30, $retention);
    $today = gmdate('Y-m-d');
    $from = gmdate('Y-m-d', time() - ($period - 1) * 86400);
    $bucket = in_array($query['bucket'] ?? 'day', ['day', 'week', 'month'], true) ? (string) ($query['bucket'] ?? 'day') : 'day';
    $bucketSql = match ($bucket) { 'week' => "strftime('%Y-W%W', day)", 'month' => 'substr(day, 1, 7)', default => 'day' };
    $page = max(1, min(10000, filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
    $perPage = max(1, min(50, filter_var($query['per_page'] ?? 50, FILTER_VALIDATE_INT) ?: 50));
    $offset = ($page - 1) * $perPage;

    try {
        if ($method === 'GET') {
            if ($resource === 'session') adminApiReply(200, ['authenticated' => true, 'csrf' => $csrf, 'retention_days' => $retention, 'capabilities' => ['logout' => true, 'delete_all' => true, 'account_url' => 'account.php', 'diagnostics_url' => 'diagnostics.php', 'audit_url' => 'audit.php']]);
            if ($resource === 'privacy') adminApiReply(200, $config);
            if ($resource === 'reach') adminApiReply(200, ['available' => dailyReachAvailable(), 'enabled' => $config['daily_reach_enabled'], 'reporting_contract_version' => REACH_REPORTING_VERSION, 'period' => $period,
                'total' => (int) queryColumn($db, 'SELECT COALESCE(SUM(reports),0) FROM daily_reach WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $today]),
                'timeline' => queryRows($db, 'SELECT day,reports FROM daily_reach WHERE day BETWEEN :from AND :to ORDER BY day', [':from' => $from, ':to' => $today])]);
            if ($resource === 'automation') adminApiReply(200, automationReport($db, $from, $today, $bucketSql, $page, $perPage));
            if ($resource === 'dashboard') {
                $total = (int) queryColumn($db, 'SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $today]);
                $active = (int) queryColumn($db, 'SELECT COUNT(DISTINCT path) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $today]);
                $timeline = queryRows($db, 'SELECT ' . $bucketSql . ' AS period,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY period ORDER BY period', [':from' => $from, ':to' => $today]);
                $top = queryRows($db, 'SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC,path LIMIT 10', [':from' => $from, ':to' => $today]);
                adminApiReply(200, ['total' => $total, 'active_pages' => $active, 'daily_average' => round($total / $period, 1), 'timeline' => $timeline, 'top_pages' => $top, 'period' => $period, 'bucket' => $bucket]);
            }
            if ($resource === 'pages' || $resource === 'daily') {
                // Combine aggregate populations without multiplying reports by country rows.
                $source = '(SELECT day,path,views,NULL AS reports FROM pageviews_daily UNION ALL SELECT day,path,0 AS views,reports FROM daily_page_reach)';
                $group = $resource === 'pages' ? 'path' : 'day,path';
                $params = [':from' => $from, ':to' => $today];
                $count = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT ' . $group . ' FROM ' . $source . ' WHERE day BETWEEN :from AND :to GROUP BY ' . $group . ')', $params);
                $page = min($page, max(1, (int) ceil($count / $perPage))); $offset = ($page - 1) * $perPage;
                $rows = queryRows($db, 'SELECT ' . $group . ',SUM(views) AS views,SUM(reports) AS page_reach_reports FROM ' . $source . ' WHERE day BETWEEN :from AND :to GROUP BY ' . $group . ' ORDER BY ' . ($resource === 'daily' ? 'day DESC,' : '') . 'views DESC,path LIMIT :limit OFFSET :offset', $params + [':limit' => $perPage, ':offset' => $offset]);
                adminApiReply(200, ['rows' => $rows, 'total_rows' => $count, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($count / $perPage), 'period' => $period, 'page_reach' => dailyPageReachReportState($db, $from, $today)]);
            }
            if ($resource === 'page') {
                $path = normalizePath((string) ($query['path'] ?? ''));
                if ($path === null) adminApiReply(400, null, 'invalid_path', 'The page path is invalid.');
                $source = '(SELECT day,path,views,NULL AS reports FROM pageviews_daily UNION ALL SELECT day,path,0 AS views,reports FROM daily_page_reach)';
                $params = [':path' => $path, ':from' => $from, ':to' => $today];
                $totals = queryRows($db, 'SELECT COALESCE(SUM(views),0) AS views,SUM(reports) AS reports FROM ' . $source . ' WHERE path=:path AND day BETWEEN :from AND :to', $params)[0];
                $timeline = queryRows($db, 'SELECT ' . $bucketSql . ' AS period,SUM(views) AS views,SUM(reports) AS page_reach_reports FROM ' . $source . ' WHERE path=:path AND day BETWEEN :from AND :to GROUP BY period ORDER BY period', $params);
                adminApiReply(200, ['path' => $path, 'total' => (int) $totals['views'], 'page_reach_reports' => $totals['reports'], 'timeline' => $timeline, 'period' => $period, 'bucket' => $bucket, 'page_reach' => dailyPageReachReportState($db, $from, $today)]);
            }
            if ($resource === 'dimensions') {
                $pagePath = array_key_exists('path', $query) && is_string($query['path']) ? normalizePath($query['path']) : null;
                if (array_key_exists('path', $query) && $pagePath === null) adminApiReply(400, null, 'invalid_path', 'The page path is invalid.');
                $dimensions = ['country' => ['country_collection', 'pageviews_daily', 'country'], 'referrer' => ['referrer_collection', 'referrers_daily', 'referrer_host'], 'browser' => ['browser_collection', 'dimensions_daily', 'value'], 'device' => ['device_collection', 'dimensions_daily', 'value'], 'os' => ['os_collection', 'dimensions_daily', 'value']];
                $dimension = (string) ($query['dimension'] ?? 'country');
                if (!isset($dimensions[$dimension])) adminApiReply(400, null, 'invalid_dimension', 'The requested dimension is not supported.');
                [$enabledKey, $table, $column] = $dimensions[$dimension];
                if (empty($config[$enabledKey])) adminApiReply(200, ['dimension' => $dimension, 'label' => $dimension, 'enabled' => false, 'rows' => [], 'total_rows' => 0, 'page' => 1, 'pages' => 1]);
                $where = $table === 'dimensions_daily' ? 'dimension=:dimension AND day BETWEEN :from AND :to' : 'day BETWEEN :from AND :to';
                $params = [':from' => $from, ':to' => $today]; if ($table === 'dimensions_daily') $params[':dimension'] = $dimension;
                if (array_key_exists('path', $query)) { $where .= ' AND path=:path'; $params[':path'] = $pagePath; }
                $count = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $where . ' GROUP BY ' . $column . ')', $params);
                $page = min($page, max(1, (int) ceil($count / $perPage))); $offset = ($page - 1) * $perPage;
                $rows = queryRows($db, 'SELECT ' . $column . ' AS value,SUM(views) AS views FROM ' . $table . ' WHERE ' . $where . ' GROUP BY ' . $column . ' ORDER BY views DESC,value LIMIT :limit OFFSET :offset', $params + [':limit' => $perPage, ':offset' => $offset]);
                adminApiReply(200, ['dimension' => $dimension, 'label' => $dimension, 'enabled' => true, 'rows' => $rows, 'total_rows' => $count, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($count / $perPage)]);
            }
            if ($resource === 'system') adminApiReply(200, ['application' => 'Barelytics PHP', 'runtime' => PHP_VERSION, 'schema_version' => schemaVersion($db), 'current_schema_version' => CURRENT_SCHEMA_VERSION, 'migration_required' => schemaVersion($db) < CURRENT_SCHEMA_VERSION, 'capabilities' => ['cleanup' => true, 'delete_all' => true, 'logout' => true]]);
            if ($resource === 'audit') {
                $selfTest = privacySelfTest($db);
                adminApiReply(200, ['application' => 'Barelytics PHP', 'contract_version' => CONTRACT_VERSION, 'schema_version' => schemaVersion($db), 'profile' => $config['profile'], 'fingerprint' => privacyFingerprint($db), 'configuration' => $config, 'historical_dimensions' => $selfTest['schema']['historical_dimensions'], 'historical_reach' => $selfTest['schema']['historical_reach'], 'historical_page_reach' => $selfTest['schema']['historical_page_reach'], 'configuration_history' => queryRows($db, 'SELECT timestamp,profile,effective_configuration_json,configuration_hash,application_version,schema_version FROM privacy_configuration_history ORDER BY timestamp DESC,rowid DESC LIMIT 100'), 'checks' => $selfTest['checks'], 'result' => $selfTest['result']]);
            }
            if ($resource === 'integration') {
                $base = requestBasePath();
                adminApiReply(200, ['instructions' => 'Install Barelytics by FTP/SFTP and choose one page-view integration per page. The normal browser tracker automatically participates in site-wide Daily Reach and separate Daily Page Reach when enabled in Privacy settings and authorized by the host/CMP permission callback, when required. No Daily Reach script attribute is required. Strict prevents analytics browser-storage access. Assess consent or exemption requirements before enabling Reach; storage restrictions and network failures can miss reports. Native PHP track() calls alone cannot measure browser reach; use the browser tracker instead to include it, without counting page views twice.', 'javascript_snippet' => '<script defer src="' . $base . 'track.js"></script>', 'php_snippet' => "require_once rtrim(\$_SERVER['DOCUMENT_ROOT'], '/') . '" . $base . "src/Barelytics.php';\n\\Barelytics\\track();"]);
            }
            adminApiReply(404, null, 'not_found', 'Administration resource not found.');
        }
        if ($method !== 'POST') adminApiReply(405, null, 'method_not_allowed', 'Method not allowed.');
        if (!$csrfVerifiedByHost && !validCsrf($input['csrf'] ?? null)) adminApiReply(403, null, 'csrf', 'Request verification failed.');
        if ($resource === 'privacy') {
            $retentionValue = (string) ($input['retention_days'] ?? '');
            $exclusionText = $input['path_exclusions'] ?? null; $botText = $input['bot_patterns'] ?? null;
            if (!in_array($retentionValue, ['30', '90', '180', '365'], true) || !is_string($exclusionText) || strlen($exclusionText) > 4000 || !is_string($botText) || strlen($botText) > 2000) adminApiReply(400, null, 'invalid_settings', 'One or more settings are invalid.');
            $exclusions = array_values(array_unique(array_filter(array_map('trim', explode("\n", $exclusionText)), static fn($line) => $line !== '')));
            $bots = array_values(array_unique(array_filter(array_map('trim', explode("\n", $botText)), static fn($line) => $line !== '')));
            foreach ($exclusions as $line) if (strlen($line) > 200 || !str_starts_with($line, '/') || str_contains($line, '?') || str_contains($line, '#')) adminApiReply(400, null, 'invalid_settings', 'One or more settings are invalid.');
            foreach ($bots as $line) if (strlen($line) > 200) adminApiReply(400, null, 'invalid_settings', 'One or more settings are invalid.');
            if (array_key_exists('daily_reach_enabled', $input) && !is_bool($input['daily_reach_enabled'])) adminApiReply(400, null, 'invalid_settings', 'Daily Reach must be a boolean.');
            if (array_key_exists('daily_page_reach_enabled', $input) && !is_bool($input['daily_page_reach_enabled'])) adminApiReply(400, null, 'invalid_settings', 'Daily Page Reach must be a boolean.');
            $pageReachEnabled = ($input['daily_page_reach_enabled'] ?? false) === true;
            $reachEnabled = ($input['daily_reach_enabled'] ?? false) === true;
            if (($reachEnabled || $pageReachEnabled) && !dailyReachAvailable()) adminApiReply(400, null, 'unavailable', 'Daily Reach is unavailable in this adapter.');
            $enabled = $reachEnabled || $pageReachEnabled; foreach (PRIVACY_DEFAULTS as $key => $_) if (($input[$key] ?? false) === true) $enabled = true;
            if ($enabled && ($input['confirmation'] ?? '') !== 'yes') adminApiReply(400, null, 'confirmation_required', 'Confirm optional Extended measurement.');
            $db->beginTransaction();
            try {
                saveDailyReachSetting($db, $reachEnabled, ($input['confirmation'] ?? '') === 'yes');
                saveDailyPageReachSetting($db, $pageReachEnabled, ($input['confirmation'] ?? '') === 'yes');
                setSetting($db, 'retention_days', $retentionValue);
                foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, ($input[$key] ?? false) === true ? '1' : '0');
                setSetting($db, 'privacy_path_exclusions', implode("\n", $exclusions)); setSetting($db, 'bot_patterns', implode("\n", $bots));
                recordPrivacyConfiguration($db); $db->commit();
            } catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'save_failed', 'Settings could not be saved.'); }
            adminApiReply(200, effectivePrivacyConfig($db));
        }
        if ($resource === 'strict') {
            $db->beginTransaction(); try { saveDailyReachSetting($db, false, true); saveDailyPageReachSetting($db, false, true); foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, '0'); recordPrivacyConfiguration($db); $db->commit(); }
            catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'save_failed', 'Strict Mode could not be activated.'); }
            adminApiReply(200, effectivePrivacyConfig($db));
        }
        if ($resource === 'cleanup') { $complete = cleanRetentionBatch($db); adminApiReply(200, ['complete' => $complete]); }
        if ($resource === 'migrate') {
            try { migrateDatabase($db); adminApiReply(200, ['schema_version' => schemaVersion($db), 'migration_required' => false]); }
            catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'migration_failed', 'Database migrations did not complete; existing aggregate data was preserved.'); }
        }
        if ($resource === 'delete-all' && ($input['confirmation'] ?? '') === 'DELETE') { $db->beginTransaction(); try { $db->exec('DELETE FROM pageviews_daily'); $db->exec('DELETE FROM referrers_daily'); if (tableExists($db, 'dimensions_daily')) $db->exec('DELETE FROM dimensions_daily'); if (tableExists($db, 'automation_daily')) $db->exec('DELETE FROM automation_daily'); if (tableExists($db, 'daily_reach')) $db->exec('DELETE FROM daily_reach'); if (tableExists($db, 'daily_page_reach')) $db->exec('DELETE FROM daily_page_reach'); setSetting($db, 'daily_page_reach_limited_days', '[]'); $db->commit(); } catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'delete_failed', 'Aggregate data could not be deleted.'); } adminApiReply(200, ['deleted' => true]); }
        if ($resource === 'logout') { $_SESSION = []; session_destroy(); adminApiReply(200, ['authenticated' => false]); }
        adminApiReply(400, null, 'invalid_action', 'The administration action is not supported.');
    } catch (Throwable) { adminApiReply(500, null, 'internal_error', 'The administration request failed.'); }
}
