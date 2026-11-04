<?php
declare(strict_types=1);

namespace Barelytics;

use PDO;
use Throwable;

const REACH_REPORTING_VERSION = 1;
const REACH_BOUNDARY_GRACE_SECONDS = 120;

/** Only the standalone PHP transport is verified; WordPress has no public reach adapter. */
function dailyReachAvailable(): bool
{
    return !defined('ABSPATH');
}

/** Call within the administrator's settings transaction, never from a collector. */
function saveDailyReachSetting(PDO $db, bool $enabled, bool $acknowledged): void
{
    if ($enabled && (!dailyReachAvailable() || schemaVersion($db) !== CURRENT_SCHEMA_VERSION)) throw new \InvalidArgumentException('Daily Reach is unavailable in this adapter.');
    if ($enabled && !$acknowledged) throw new \InvalidArgumentException('Explicit Extended acknowledgement is required for Daily Reach.');
    setSetting($db, 'daily_reach_enabled', $enabled ? '1' : '0');
}

function dailyReachEligible(array $config, string $path, string $userAgent): bool
{
    return !pathIsExcluded($path, $config['path_exclusions']) && !classifyUserAgent($userAgent, $config['bot_patterns'])['automation'];
}

/** Prepare is read-only: authoritative period, no identity and no aggregate write. */
function dailyReachPrepare(PDO $db, string $input, ?int $now = null, ?string $userAgent = null): array
{
    $now ??= time();
    $path = normalizePath($input);
    $config = effectivePrivacyConfig($db);
    $eligible = $path !== null && dailyReachEligible($config, $path, $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return ['enabled' => $config['daily_reach_enabled'], 'eligible' => $eligible,
        'day' => gmdate('Y-m-d', $now), 'period_end' => gmdate('Y-m-d\TH:i:s\Z', (intdiv($now, 86400) + 1) * 86400),
        'reporting_contract_version' => REACH_REPORTING_VERSION, 'path' => $eligible ? $path : null];
}

function dailyReachDayAccepted(string $day, int $now): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) return false;
    if ($day === gmdate('Y-m-d', $now)) return true;
    return $now % 86400 < REACH_BOUNDARY_GRACE_SECONDS && $day === gmdate('Y-m-d', $now - 86400);
}

/** One site-wide received report, not server-side browser deduplication. */
function countDailyReach(PDO $db, string $input, string $day, ?int $now = null, ?string $userAgent = null): int
{
    $now ??= time();
    $path = normalizePath($input);
    if ($path === null) return 400;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $config = effectivePrivacyConfig($db);
        $status = !$config['daily_reach_enabled'] || !dailyReachEligible($config, $path, $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ? 403
            : (dailyReachDayAccepted($day, $now) ? 204 : 400);
        if ($status === 204) executeStatement($db, 'INSERT INTO daily_reach(day,reports) VALUES (:day,1) ON CONFLICT(day) DO UPDATE SET reports=reports+1', [':day' => $day]);
        $db->exec('COMMIT');
        return $status;
    } catch (Throwable $error) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) { }
        throw $error;
    }
}

/** Header checks are defense in depth, never visitor authentication. */
function dailyReachRequestIsSameOrigin(): bool
{
    if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') return false;
    if (!isset($_SERVER['HTTP_ORIGIN'])) return true;
    $origin = parse_url((string) $_SERVER['HTTP_ORIGIN']);
    if (!is_array($origin) || !isset($origin['host'], $origin['scheme']) || $origin['scheme'] !== (isHttpsRequest() ? 'https' : 'http')) return false;
    $authority = strtolower($origin['host']) . (isset($origin['port']) ? ':' . $origin['port'] : '');
    return hash_equals(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), $authority);
}

/** Stateless public collector: no sessions, cookies, logs, receipts or retries. */
function serveDailyReach(string $operation): never
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }
    if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') { http_response_code(415); exit; }
    if (!dailyReachRequestIsSameOrigin()) { http_response_code(403); exit; }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) { http_response_code(413); exit; }
    $object = json_decode($raw);
    if (!$object instanceof \stdClass) { http_response_code(400); exit; }
    $input = get_object_vars($object); $keys = array_keys($input); sort($keys);
    if ($keys !== ($operation === 'prepare' ? ['path'] : ['day', 'path']) || !is_string($input['path']) || normalizePath($input['path']) === null
        || ($operation === 'count' && !is_string($input['day']))) { http_response_code(400); exit; }
    try {
        $db = connectDatabase();
        if ($operation === 'prepare') {
            $result = dailyReachPrepare($db, $input['path']);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            http_response_code(countDailyReach($db, $input['path'], $input['day']));
            runScheduledCleanupIfDue($db);
        }
    } catch (Throwable) { http_response_code(204); }
    exit;
}
