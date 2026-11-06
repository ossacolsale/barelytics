<?php
// Local synthetic test server only. Never ship or mount this router in production.
declare(strict_types=1);
$control = json_decode((string) file_get_contents((string) getenv('REACH_TEST_CONTROL')), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/favicon.ico') { http_response_code(204); return; }
// Synthetic cache/deployment fixture; Apache policy is tested independently.
if ($path === '/barelytics/track.js') {
    $file = !empty($control['tracker_legacy']) ? __DIR__ . '/fixtures/legacy-track.js' : __DIR__ . '/../../public/barelytics/track.js';
    $etag = '"' . hash_file('sha256', $file) . '"';
    header('Content-Type: application/javascript');
    header('Cache-Control: ' . (!empty($control['tracker_legacy']) ? 'public, max-age=3600' : 'no-cache, must-revalidate'));
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
    readfile($file); return;
}
if (in_array($path, ['/barelytics/reach-prepare.php','/barelytics/reach-count.php'], true) && isset($control['clock'])) {
    require_once __DIR__ . '/../../public/barelytics/src/Barelytics.php';
    $db = \Barelytics\connectDatabase();
    $input = json_decode((string) file_get_contents('php://input'), true);
    $now = strtotime($control['clock']);
    header('Cache-Control: no-store');
    if (str_contains($path, 'prepare')) {
        header('Content-Type: application/json');
        echo json_encode(\Barelytics\dailyReachPrepare($db, $input['path'], $now, null, $input['version'] ?? 1));
    } else http_response_code(($input['version'] ?? 1) === 2 ? \Barelytics\countReachV2($db, $input['path'], $input['day'], $input['global'], $input['page'], $now) : \Barelytics\countDailyReach($db, $input['path'], $input['day'], $now));
    return;
}
if ($path === '/barelytics/track.php' && isset($control['collector_delay_ms'])) usleep($control['collector_delay_ms'] * 1000);
if (str_starts_with($path, '/barelytics/')) return false;
if (!empty($control['block_connections'])) header("Content-Security-Policy: default-src 'self'; connect-src 'none'");
header('Content-Type: text/html; charset=UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Daily Reach test</title></head><body><h1>Daily Reach test</h1>';
echo '<script defer src="/barelytics/track.js' . (!empty($control['tracker_version']) ? '?v=1.2.1' : '') . '"' . (isset($control['reach_attribute']) ? ' data-daily-reach="' . htmlspecialchars($control['reach_attribute'], ENT_QUOTES) . '"' : '') . '></script></body></html>';
