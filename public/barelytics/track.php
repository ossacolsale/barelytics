<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID > 80599) { http_response_code(204); exit; }

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
if ($length > 2048) { http_response_code(413); exit; }
$type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($type, ['application/json', 'text/plain'], true)) { http_response_code(415); exit; }
$body = file_get_contents('php://input', false, null, 0, 2049);
if (!is_string($body) || strlen($body) > 2048) { http_response_code(413); exit; }
$payload = $type === 'application/json' ? json_decode($body, true) : ['path' => $body];
if (!is_array($payload) || !isset($payload['path']) || !is_string($payload['path'])) { http_response_code(400); exit; }
$path = normalizePath($payload['path']);
if ($path === null) { http_response_code(400); exit; }
try {
    $db = connectDatabase();
    countRequest($db, $path);
} catch (Throwable $ignored) {
    // Tracking must fail silently to the visitor and never expose internal errors.
}
http_response_code(204);
if ($db instanceof PDO && function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    runScheduledCleanupIfDue($db);
}
