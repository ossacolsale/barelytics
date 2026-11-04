<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/barelytics/src/Barelytics.php';
if (!extension_loaded('pdo_sqlite') || !function_exists('pcntl_fork')) {
    echo "SQLite process-concurrency checks unavailable (requires PDO SQLite and pcntl).\n";
    exit;
}
$directory = sys_get_temp_dir() . '/barelytics-concurrency-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
putenv('BARELYTICS_DATABASE_PATH=' . $directory . '/analytics.sqlite');
$db = \Barelytics\openDatabase(); \Barelytics\saveDailyReachSetting($db, true, true); \Barelytics\saveDailyPageReachSetting($db, true, true); unset($db);
try {
    $children = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Could not create test worker.');
        if ($pid === 0) {
            try {
                $db = \Barelytics\connectDatabase();
                for ($i = 0; $i < 50; $i++) {
                    \Barelytics\recordPageview($db, '/concurrent', 'IT', 'source.test', ['browser' => 'Other']);
                    if (\Barelytics\countReachV2($db, '/concurrent', gmdate('Y-m-d'), false, true, null, 'Mozilla/5.0') !== 204) throw new RuntimeException('Page reach writer failed.');
                    if (\Barelytics\countDailyReach($db, '/concurrent', gmdate('Y-m-d'), null, 'Mozilla/5.0') !== 204) throw new RuntimeException('Reach writer failed.');
                }
                exit(0);
            } catch (Throwable) { exit(1); }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new RuntimeException('Concurrent aggregate writer failed.');
    }
    $db = \Barelytics\connectDatabase();
    foreach (['pageviews_daily', 'referrers_daily', 'dimensions_daily'] as $table) {
        if ((int) \Barelytics\queryColumn($db, "SELECT SUM(views) FROM $table WHERE path='/concurrent'") !== 200) throw new RuntimeException('Lost concurrent aggregates.');
    }
    if ((int) \Barelytics\queryColumn($db, 'SELECT SUM(reports) FROM daily_reach') !== 200) throw new RuntimeException('Lost concurrent reach increments.');
    if ((int) \Barelytics\queryColumn($db, 'SELECT SUM(reports) FROM daily_page_reach') !== 200) throw new RuntimeException('Lost concurrent page reach increments.');
    $lock = \Barelytics\connectDatabase(); $lock->exec('BEGIN IMMEDIATE');
    $start = microtime(true); $busy = false;
    try { \Barelytics\recordPageview($db, '/busy'); } catch (PDOException) { $busy = true; }
    $lock->exec('ROLLBACK');
    if (!$busy || microtime(true) - $start > 3) throw new RuntimeException('Busy-lock handling is not bounded.');
    $lock->exec('BEGIN IMMEDIATE');
    $reachStart = microtime(true); $reachBusy = false;
    try { \Barelytics\countDailyReach($db, '/busy-reach', gmdate('Y-m-d'), null, 'Mozilla/5.0'); } catch (PDOException) { $reachBusy = true; }
    $lock->exec('ROLLBACK');
    if (!$reachBusy || microtime(true) - $reachStart > 3 || (int) \Barelytics\queryColumn($db, 'SELECT SUM(reports) FROM daily_reach') !== 200) throw new RuntimeException('Busy reach write was not bounded or left a partial increment.');
    $lock->exec('BEGIN IMMEDIATE'); $pageStart=microtime(true); $pageBusy=false;
    try { \Barelytics\countReachV2($db, '/busy-page', gmdate('Y-m-d'), true, true, null, 'Mozilla/5.0'); } catch (PDOException) { $pageBusy=true; }
    $lock->exec('ROLLBACK');
    if (!$pageBusy || microtime(true)-$pageStart>3 || (int)\Barelytics\queryColumn($db,'SELECT SUM(reports) FROM daily_page_reach')!==200 || (int)\Barelytics\queryColumn($db,'SELECT SUM(reports) FROM daily_reach')!==200) throw new RuntimeException('Combined busy write was not bounded or atomic.');
    if (\Barelytics\countDailyReach($db, '/after-lock', gmdate('Y-m-d'), null, 'Mozilla/5.0') !== 204) throw new RuntimeException('Reach did not recover after the busy lock.');
    \Barelytics\recordPageview($db, '/after-lock');
    if ((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE path='/busy'") !== 0) throw new RuntimeException('A failed transaction left a partial view.');
    unset($db, $lock);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $interrupted = \Barelytics\connectDatabase(); $interrupted->exec('BEGIN IMMEDIATE');
        $interrupted->exec("INSERT INTO pageviews_daily VALUES ('2026-11-03','/interrupted','XX',1)");
        exit(0); // Connection closes without COMMIT.
    }
    if ($pid === -1) throw new RuntimeException('Could not create interruption worker.');
    pcntl_waitpid($pid, $status);
    $db = \Barelytics\connectDatabase();
    if ((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM pageviews_daily WHERE path='/interrupted'") !== 0) throw new RuntimeException('Interrupted write was committed.');
    unset($db);
    echo "SQLite concurrent writers, bounded busy locks and interruption checks passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
    putenv('BARELYTICS_DATABASE_PATH');
}
