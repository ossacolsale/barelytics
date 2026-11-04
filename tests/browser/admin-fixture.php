<?php
// Synthetic aggregates only; used by the local browser harness.
declare(strict_types=1);
require __DIR__ . '/../../public/barelytics/src/Barelytics.php';
$db = \Barelytics\openDatabase();
\Barelytics\setSetting($db, 'setup_complete', '1');
\Barelytics\setSetting($db, 'admin_password_hash', password_hash('synthetic-browser-admin-password', PASSWORD_DEFAULT));
\Barelytics\setSetting($db, 'retention_days', '365');
foreach (\Barelytics\PRIVACY_DEFAULTS as $key => $_) \Barelytics\setSetting($db, $key, '1');
\Barelytics\saveDailyReachSetting($db, true, true);
\Barelytics\saveDailyPageReachSetting($db, true, true);
\Barelytics\setSetting($db, 'daily_page_reach_limited_days', json_encode([gmdate('Y-m-d')]));
$paths = ['/','/journal/designing-with-less','/' . str_repeat('long.segment', 42) . 'endingx', '/caffè/日本語/%E2%9C%93', '/' . str_repeat('nested/', 65) . 'ending-a', '/' . str_repeat('nested/', 65) . 'ending-b'];
for ($n = 0; $n < 60; $n++) $paths[] = '/articles/story-' . chr(65 + intdiv($n, 26)) . chr(65 + $n % 26);
foreach ($paths as $path) if (\Barelytics\normalizePath($path) !== $path) throw new RuntimeException('Fixture paths must already be normalized.');
$db->beginTransaction();
for ($i = 0; $i < 365; $i++) {
    $day = gmdate('Y-m-d', time() - $i * 86400);
    foreach ($paths as $j => $path) {
        $views = $j === 2 ? 12345678 + ($i % 17) * 654321 : 100 + ($i * 13 + $j * 17) % 800;
        \Barelytics\executeStatement($db, 'INSERT INTO pageviews_daily VALUES (?,?,?,?)', [$day, $path, 'IT', $views]);
        \Barelytics\executeStatement($db, 'INSERT INTO daily_page_reach VALUES (?,?,?)', [$day, $path, $j === 0 ? 0 : 20 + $j]);
        \Barelytics\executeStatement($db, 'INSERT INTO referrers_daily VALUES (?,?,?,?)', [$day, $path, 'example.test', $views]);
        foreach (['browser' => 'Firefox', 'device' => 'Desktop', 'os' => 'Linux'] as $dimension => $value) \Barelytics\executeStatement($db, 'INSERT INTO dimensions_daily VALUES (?,?,?,?,?)', [$day, $path, $dimension, $value, $views]);
        \Barelytics\executeStatement($db, 'INSERT INTO automation_daily VALUES (?,?,?,?,?)', [$day, $path, $j % 2 ? 'ai_crawler' : 'uncertain_automation', $j % 2 ? 'GPTBot' : 'Unknown', 5 + $j]);
    }
    \Barelytics\executeStatement($db, 'INSERT INTO daily_reach VALUES (?,?)', [$day, 500 + $i]);
}
\Barelytics\recordPrivacyConfiguration($db);
$db->commit();
