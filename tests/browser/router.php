<?php
// Local synthetic test server only. Never ship or mount this router in production.
declare(strict_types=1);
$control = json_decode((string) file_get_contents((string) getenv('REACH_TEST_CONTROL')), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/favicon.ico') { http_response_code(204); return; }
if (in_array($path, ['/barelytics/reach-prepare.php','/barelytics/reach-count.php'], true) && isset($control['clock'])) {
    require_once __DIR__ . '/../../public/barelytics/src/Barelytics.php';
    $db = \Barelytics\connectDatabase();
    $input = json_decode((string) file_get_contents('php://input'), true);
    $now = strtotime($control['clock']);
    header('Cache-Control: no-store');
    if (str_contains($path, 'prepare')) {
        header('Content-Type: application/json');
        echo json_encode(\Barelytics\dailyReachPrepare($db, $input['path'], $now));
    } else http_response_code(\Barelytics\countDailyReach($db, $input['path'], $input['day'], $now));
    return;
}
if (str_starts_with($path, '/barelytics/')) return false;
header('Content-Type: text/html; charset=UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Daily Reach test</title></head><body><h1>Daily Reach test</h1>';
echo '<script defer src="/barelytics/track.js"' . (!empty($control['optin']) ? ' data-daily-reach="true"' : '') . '></script></body></html>';
