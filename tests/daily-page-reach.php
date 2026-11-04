<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/barelytics/src/Barelytics.php';
use function Barelytics\{migrateDatabase,effectivePrivacyConfig,saveDailyReachSetting,saveDailyPageReachSetting,dailyReachPrepare,countReachV2,countDailyReach,queryColumn,queryRows,setting,setSetting,cleanRetentionBatch,privacySelfTest,dailyPageReachReportState};
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
migrateDatabase($db);
$check(!effectivePrivacyConfig($db)['daily_page_reach_enabled'], 'Page Reach defaults off.');
saveDailyReachSetting($db, true, true);
$db->exec("INSERT INTO daily_reach VALUES ('2026-11-03',7)");
$db->exec('DROP TABLE daily_page_reach'); $db->exec('DELETE FROM schema_migrations WHERE version=5');
setSetting($db, 'daily_page_reach_enabled', '1');
$check(migrateDatabase($db) === 5 && migrateDatabase($db) === 5, 'Schema-4 upgrade is additive and repeatable.');
$check(!effectivePrivacyConfig($db)['daily_page_reach_enabled'] && effectivePrivacyConfig($db)['daily_reach_enabled'] && (int)queryColumn($db,'SELECT reports FROM daily_reach') === 7, 'Upgrade defaults new metric off and preserves old setting/history.');
try { saveDailyPageReachSetting($db, true, false); throw new RuntimeException('Unacknowledged Page Reach accepted.'); } catch (InvalidArgumentException) { }
saveDailyPageReachSetting($db, true, true);
$now = strtotime('2026-11-03T12:00:00Z'); $day = '2026-11-03';
$prepare = dailyReachPrepare($db, '/article/123456', $now, 'Mozilla/5.0', 2);
$check($prepare['enabled'] && $prepare['global_enabled'] && $prepare['page_enabled'] && $prepare['reporting_contract_version'] === 2 && $prepare['server_time'] === $now && $prepare['path'] === '/article/:id', 'v2 has authoritative time, normalized path and separate flags.');
$legacy = dailyReachPrepare($db, '/article', $now, 'Mozilla/5.0');
$check($legacy['reporting_contract_version'] === 1 && !array_key_exists('page_enabled', $legacy), 'v1 is unchanged.');
$check(countReachV2($db, '/article', $day, true, true, $now, 'Mozilla/5.0') === 204, 'Combined report accepted.');
$check((int)queryColumn($db,'SELECT reports FROM daily_reach') === 8 && (int)queryColumn($db,'SELECT reports FROM daily_page_reach') === 1, 'Both aggregate increments commit.');
countReachV2($db, '/other', $day, false, true, $now, 'Mozilla/5.0');
$check((int)queryColumn($db,'SELECT reports FROM daily_reach') === 8, 'Page-only report does not change global reach.');
countDailyReach($db, '/legacy', $day, $now, 'Mozilla/5.0');
$check((int)queryColumn($db,'SELECT COUNT(*) FROM daily_page_reach') === 2, 'Legacy report does not change page reach.');
$db->exec("CREATE TRIGGER fail_page_reach BEFORE UPDATE ON daily_page_reach BEGIN SELECT RAISE(ABORT,'synthetic'); END");
try { countReachV2($db, '/article', $day, true, true, $now, 'Mozilla/5.0'); throw new RuntimeException('Failure accepted.'); } catch (PDOException) { }
$db->exec('DROP TRIGGER fail_page_reach');
$check((int)queryColumn($db,'SELECT reports FROM daily_reach') === 9 && (int)queryColumn($db,"SELECT reports FROM daily_page_reach WHERE path='/article'") === 1, 'Failed combined report rolls back both counters.');
foreach (['/private/a','/%61dmin/a','/user@example.test','/a/%2fsecret','/article?secret'] as $path) $check(countReachV2($db,$path,$day,true,true,$now,'Mozilla/5.0') !== 204, 'Private/ambiguous/invalid paths fail closed.');
$check(countReachV2($db,'/article',$day,true,true,$now,'GPTBot/1') === 403, 'Automation excluded.');
foreach ([119=>204,120=>400] as $seconds=>$status) $check(countReachV2($db,'/boundary',$day,false,true,strtotime('2026-11-04T00:00:00Z')+$seconds,'Mozilla/5.0') === $status, 'v2 retains UTC grace attribution.');
saveDailyReachSetting($db, false, true);
$check(effectivePrivacyConfig($db)['profile'] === 'extended' && effectivePrivacyConfig($db)['indexeddb'], 'Page-only configuration is independently Extended.');
$check(!dailyReachPrepare($db,'/article',$now,'Mozilla/5.0')['enabled'], 'Legacy client cannot interpret page-only enablement as global.');
$before = (int)queryColumn($db,'SELECT reports FROM daily_reach');
$check(countReachV2($db,'/article',$day,true,true,$now,'Mozilla/5.0') === 204 && (int)queryColumn($db,'SELECT reports FROM daily_reach') === $before, 'An in-flight request cannot write a disabled component.');
putenv('BARELYTICS_DAILY_REACH_ALLOWED=0');
$check(!dailyReachPrepare($db,'/article',$now,'Mozilla/5.0',2)['enabled'] && countReachV2($db,'/article',$day,false,true,$now,'Mozilla/5.0') === 403, 'Kill switch covers both metrics.'); putenv('BARELYTICS_DAILY_REACH_ALLOWED');
// Fill a single day without exercising collector or local deduplication.
$db->exec('DELETE FROM daily_page_reach');
$db->beginTransaction(); $insert=$db->prepare('INSERT INTO daily_page_reach VALUES (?,?,1)');
for ($i=0;$i<\Barelytics\PAGE_REACH_DAILY_PATH_LIMIT;$i++) $insert->execute([$day,'/page-'.$i]); $db->commit();
$check(countReachV2($db,'/over-cap',$day,false,true,$now,'Mozilla/5.0') === 429, 'New paths fail closed at finite server cap.');
$check(countReachV2($db,'/page-0',$day,false,true,$now,'Mozilla/5.0') === 204, 'Existing aggregate keys continue accepting reports.');
$status=dailyPageReachReportState($db,$day,$day);
$check($status['partial'] && $status['limited_days'] === [$day] && (int)queryColumn($db,'SELECT COUNT(*) FROM daily_page_reach') === \Barelytics\PAGE_REACH_DAILY_PATH_LIMIT, 'Partial days are explicit and cardinality is bounded.');
$check(dailyPageReachReportState($db,'2000-01-01','2000-01-02')['has_data'] === false, 'Absent observations remain distinct from zero.');
saveDailyPageReachSetting($db, false, true);
$audit=privacySelfTest($db);
$check($audit['result'] === 'PASS' && $audit['schema']['historical_page_reach'] && effectivePrivacyConfig($db)['profile'] === 'strict', 'Strict audit retains independent page history.');
$db->exec("CREATE TABLE visitor_page_fingerprints(visitor_id TEXT, fingerprint TEXT)");
$check(privacySelfTest($db)['result'] === 'FAIL', 'Audit rejects server-side fingerprints or visitor tables.'); $db->exec('DROP TABLE visitor_page_fingerprints');
$columns=array_column(queryRows($db,'PRAGMA table_info(daily_page_reach)'), 'name');
$check($columns === ['day','path','reports'], 'New table has only aggregate columns.');
$db->exec("INSERT INTO daily_page_reach VALUES ('2000-01-01','/old-a',1),('2000-01-01','/old-b',2)");
setSetting($db,'daily_page_reach_limited_days','["2000-01-01"]');
cleanRetentionBatch($db,30,1);
$check((int)queryColumn($db,"SELECT COUNT(*) FROM daily_page_reach WHERE day='2000-01-01'") === 1, 'Cleanup is bounded.');
cleanRetentionBatch($db,30,1);
$check((int)queryColumn($db,"SELECT COUNT(*) FROM daily_page_reach WHERE day='2000-01-01'") === 0 && setting($db,'daily_page_reach_limited_days','[]') === '[]', 'Cleanup removes expired rows and operational day flags.');
// Realistic synthetic growth experiment, never a production database.
$file = tempnam(sys_get_temp_dir(), 'barelytics-page-size-');
try {
    $sized = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); migrateDatabase($sized);
    $sized->beginTransaction(); $insert=$sized->prepare('INSERT INTO daily_page_reach VALUES (?,?,120)');
    for ($d=31;$d<=60;$d++) for ($p=0;$p<500;$p++) $insert->execute([gmdate('Y-m-d',time()-$d*86400), '/articles/synthetic-page-'.$p]);
    $sized->commit(); $rows=(int)queryColumn($sized,'SELECT COUNT(*) FROM daily_page_reach');
    $bytes=(int)queryColumn($sized,'PRAGMA page_count')*(int)queryColumn($sized,'PRAGMA page_size');
    $sized->exec('UPDATE daily_page_reach SET reports=reports+1000');
    $check((int)queryColumn($sized,'SELECT COUNT(*) FROM daily_page_reach') === $rows, 'Growth follows distinct day/path keys, not report volume.');
    $batches=0; do { $done=cleanRetentionBatch($sized,30); $batches++; } while (!$done && $batches<20);
    $check($done && (int)queryColumn($sized,'SELECT COUNT(*) FROM daily_page_reach') === 0, 'All synthetic old aggregates are cleaned in bounded batches.');
    echo 'Synthetic growth: '.$rows.' rows (500 paths x 30 days), '.$bytes.' SQLite bytes; '.$batches.' cleanup batches; '.queryColumn($sized,'PRAGMA freelist_count')." reusable pages after deletion.\n";
    unset($insert,$sized);
} finally { unlink($file); }
echo "Daily Page Reach migration, v1/v2, flags, privacy, cap, rollback, retention and growth checks passed.\n";
