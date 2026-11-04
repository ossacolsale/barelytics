<?php
declare(strict_types=1);

header('Cache-Control: no-store');
if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { http_response_code(204); exit; }
require_once __DIR__ . '/src/Barelytics.php';
\Barelytics\serveDailyReach('count');
