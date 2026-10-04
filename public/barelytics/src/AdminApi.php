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
            if ($resource === 'session') adminApiReply(200, ['authenticated' => true, 'csrf' => $csrf, 'retention_days' => $retention, 'capabilities' => ['logout' => true, 'delete_all' => true, 'account_url' => 'account.php']]);
            if ($resource === 'privacy') adminApiReply(200, $config);
            if ($resource === 'dashboard') {
                $total = (int) queryColumn($db, 'SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $today]);
                $active = (int) queryColumn($db, 'SELECT COUNT(DISTINCT path) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $today]);
                $timeline = queryRows($db, 'SELECT ' . $bucketSql . ' AS period,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY period ORDER BY period', [':from' => $from, ':to' => $today]);
                $top = queryRows($db, 'SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC,path LIMIT 10', [':from' => $from, ':to' => $today]);
                adminApiReply(200, ['total' => $total, 'active_pages' => $active, 'daily_average' => round($total / $period, 1), 'timeline' => $timeline, 'top_pages' => $top, 'period' => $period, 'bucket' => $bucket]);
            }
            if ($resource === 'pages' || $resource === 'daily') {
                $countSql = $resource === 'pages' ? 'SELECT COUNT(*) FROM (SELECT path FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path)' : 'SELECT COUNT(*) FROM (SELECT day,path FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY day,path)';
                $count = (int) queryColumn($db, $countSql, [':from' => $from, ':to' => $today]);
                $rows = $resource === 'pages'
                    ? queryRows($db, 'SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC,path LIMIT :limit OFFSET :offset', [':from' => $from, ':to' => $today, ':limit' => $perPage, ':offset' => $offset])
                    : queryRows($db, 'SELECT day,path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY day,path ORDER BY day DESC,views DESC,path LIMIT :limit OFFSET :offset', [':from' => $from, ':to' => $today, ':limit' => $perPage, ':offset' => $offset]);
                adminApiReply(200, ['rows' => $rows, 'total_rows' => $count, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($count / $perPage), 'period' => $period]);
            }
            if ($resource === 'page') {
                $path = normalizePath((string) ($query['path'] ?? ''));
                if ($path === null) adminApiReply(400, null, 'invalid_path', 'The page path is invalid.');
                $total = (int) queryColumn($db, 'SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE path=:path AND day BETWEEN :from AND :to', [':path' => $path, ':from' => $from, ':to' => $today]);
                $timeline = queryRows($db, 'SELECT ' . $bucketSql . ' AS period,SUM(views) AS views FROM pageviews_daily WHERE path=:path AND day BETWEEN :from AND :to GROUP BY period ORDER BY period', [':path' => $path, ':from' => $from, ':to' => $today]);
                adminApiReply(200, ['path' => $path, 'total' => $total, 'timeline' => $timeline, 'period' => $period, 'bucket' => $bucket]);
            }
            if ($resource === 'dimensions') {
                $dimensions = ['country' => ['country_collection', 'pageviews_daily', 'country'], 'referrer' => ['referrer_collection', 'referrers_daily', 'referrer_host'], 'browser' => ['browser_collection', 'dimensions_daily', 'value'], 'device' => ['device_collection', 'dimensions_daily', 'value'], 'os' => ['os_collection', 'dimensions_daily', 'value']];
                $dimension = (string) ($query['dimension'] ?? 'country');
                if (!isset($dimensions[$dimension])) adminApiReply(400, null, 'invalid_dimension', 'The requested dimension is not supported.');
                [$enabledKey, $table, $column] = $dimensions[$dimension];
                if (empty($config[$enabledKey])) adminApiReply(200, ['dimension' => $dimension, 'label' => $dimension, 'enabled' => false, 'rows' => [], 'total_rows' => 0, 'page' => 1, 'pages' => 1]);
                $where = $table === 'dimensions_daily' ? 'dimension=:dimension AND day BETWEEN :from AND :to' : 'day BETWEEN :from AND :to';
                $params = [':from' => $from, ':to' => $today]; if ($table === 'dimensions_daily') $params[':dimension'] = $dimension;
                $count = (int) queryColumn($db, 'SELECT COUNT(*) FROM (SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $where . ' GROUP BY ' . $column . ')', $params);
                $rows = queryRows($db, 'SELECT ' . $column . ' AS value,SUM(views) AS views FROM ' . $table . ' WHERE ' . $where . ' GROUP BY ' . $column . ' ORDER BY views DESC,value LIMIT :limit OFFSET :offset', $params + [':limit' => $perPage, ':offset' => $offset]);
                adminApiReply(200, ['dimension' => $dimension, 'label' => $dimension, 'enabled' => true, 'rows' => $rows, 'total_rows' => $count, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) ceil($count / $perPage)]);
            }
            if ($resource === 'system') adminApiReply(200, ['application' => 'Barelytics PHP', 'runtime' => PHP_VERSION, 'schema_version' => schemaVersion($db), 'current_schema_version' => CURRENT_SCHEMA_VERSION, 'migration_required' => schemaVersion($db) < CURRENT_SCHEMA_VERSION, 'capabilities' => ['cleanup' => true, 'delete_all' => true, 'logout' => true]]);
            if ($resource === 'audit') {
                $selfTest = privacySelfTest($db);
                adminApiReply(200, ['application' => 'Barelytics PHP', 'schema_version' => schemaVersion($db), 'profile' => $config['profile'], 'fingerprint' => privacyFingerprint($db), 'configuration' => $config, 'checks' => $selfTest['checks'], 'result' => $selfTest['result']]);
            }
            if ($resource === 'integration') {
                $base = requestBasePath();
                adminApiReply(200, ['instructions' => 'Install Barelytics by FTP/SFTP and include the tracker on document pages.', 'javascript_snippet' => '<script defer src="' . $base . 'track.js"></script>']);
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
            $enabled = false; foreach (PRIVACY_DEFAULTS as $key => $_) if (($input[$key] ?? false) === true) $enabled = true;
            if ($enabled && ($input['confirmation'] ?? '') !== 'yes') adminApiReply(400, null, 'confirmation_required', 'Confirm optional dimension collection.');
            $db->beginTransaction();
            try {
                setSetting($db, 'retention_days', $retentionValue);
                foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, ($input[$key] ?? false) === true ? '1' : '0');
                setSetting($db, 'privacy_path_exclusions', implode("\n", $exclusions)); setSetting($db, 'bot_patterns', implode("\n", $bots));
                recordPrivacyConfiguration($db); $db->commit();
            } catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'save_failed', 'Settings could not be saved.'); }
            adminApiReply(200, effectivePrivacyConfig($db));
        }
        if ($resource === 'strict') {
            $db->beginTransaction(); try { foreach (PRIVACY_DEFAULTS as $key => $_) setSetting($db, $key, '0'); recordPrivacyConfiguration($db); $db->commit(); }
            catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'save_failed', 'Strict Mode could not be activated.'); }
            adminApiReply(200, effectivePrivacyConfig($db));
        }
        if ($resource === 'cleanup') { $complete = cleanRetentionBatch($db); adminApiReply(200, ['complete' => $complete]); }
        if ($resource === 'migrate') {
            try { migrateDatabase($db); adminApiReply(200, ['schema_version' => schemaVersion($db), 'migration_required' => false]); }
            catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'migration_failed', 'Database migrations did not complete; existing aggregate data was preserved.'); }
        }
        if ($resource === 'delete-all' && ($input['confirmation'] ?? '') === 'DELETE') { $db->beginTransaction(); try { $db->exec('DELETE FROM pageviews_daily'); $db->exec('DELETE FROM referrers_daily'); if (tableExists($db, 'dimensions_daily')) $db->exec('DELETE FROM dimensions_daily'); $db->commit(); } catch (Throwable) { if ($db->inTransaction()) $db->rollBack(); adminApiReply(500, null, 'delete_failed', 'Aggregate data could not be deleted.'); } adminApiReply(200, ['deleted' => true]); }
        if ($resource === 'logout') { $_SESSION = []; session_destroy(); adminApiReply(200, ['authenticated' => false]); }
        adminApiReply(400, null, 'invalid_action', 'The administration action is not supported.');
    } catch (Throwable) { adminApiReply(500, null, 'internal_error', 'The administration request failed.'); }
}
