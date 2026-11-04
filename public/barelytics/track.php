<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { http_response_code(204); exit; }

require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\normalizePath;
use function Barelytics\connectDatabase;
use function Barelytics\countRequest;
use function Barelytics\runScheduledCleanupIfDue;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$db = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 8192) { http_response_code(413); exit; }
$type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($type !== 'application/json') { http_response_code(415); exit; }
$body = file_get_contents('php://input', false, null, 0, 8193);
if (!is_string($body) || strlen($body) > 8192) { http_response_code(413); exit; }
$payload = \Barelytics\collectorPayload($body);
if ($payload === null) { http_response_code(400); exit; }

try {
    $db = connectDatabase();
    if ($payload['version'] === 2 && !\Barelytics\effectivePrivacyConfig($db)['referrer_collection']) { http_response_code(400); exit; }
    $referrer = $payload['referrer_host'] !== null ? 'https://' . $payload['referrer_host'] : '';
    $counted = countRequest($db, $payload['path'], $referrer, true);
    $reach = $counted ? \Barelytics\dailyReachPrepare($db, $payload['path'], null, null, 2) : null;
    if ($reach !== null && $reach['enabled']) {
        if ($reach['global_enabled']) header('Barelytics-Reach-Enabled: 1');
        if ($reach['page_enabled']) header('Barelytics-Page-Reach-Enabled: 1');
        header('Barelytics-Reach-Path: ' . rawurlencode($payload['path']));
    }
} catch (Throwable $ignored) {
    // Tracking must fail silently to the visitor and never expose internal errors.
}
http_response_code(204);
if ($db instanceof PDO) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    runScheduledCleanupIfDue($db);
}
