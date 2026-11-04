<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/barelytics/src/Barelytics.php';
use function Barelytics\{migrateDatabase,effectivePrivacyConfig,saveDailyReachSetting,dailyReachPrepare,countDailyReach,queryColumn,recordPrivacyConfiguration,privacySelfTest,cleanRetentionBatch};
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
migrateDatabase($db);
$config = effectivePrivacyConfig($db);
$check(!$config['daily_reach_enabled'] && !$config['indexeddb'] && $config['profile'] === 'strict', 'Fresh install is Strict with reach off.');
$check(privacySelfTest($db)['result'] === 'PASS', 'Strict audit passes.');
try { saveDailyReachSetting($db, true, false); throw new RuntimeException('Missing acknowledgement accepted.'); } catch (InvalidArgumentException) { }
saveDailyReachSetting($db, true, true); recordPrivacyConfiguration($db);
$check(effectivePrivacyConfig($db)['profile'] === 'extended' && privacySelfTest($db)['result'] === 'PASS', 'Reach alone is valid Extended measurement.');
$before = (int) queryColumn($db, 'SELECT COUNT(*) FROM daily_reach');
$now = strtotime('2026-11-03 23:59:59 UTC');
$prepared = dailyReachPrepare($db, '/article/123456', $now, 'Mozilla/5.0');
$check($prepared === ['enabled'=>true,'eligible'=>true,'day'=>'2026-11-03','period_end'=>'2026-11-04T00:00:00Z','reporting_contract_version'=>1,'path'=>'/article/:id'], 'Prepare has authoritative UTC day and safe path.');
$check((int) queryColumn($db, 'SELECT COUNT(*) FROM daily_reach') === $before, 'Prepare never writes reach.');
foreach ([['2026-11-03', '2026-11-03 23:59:59',204],['2026-11-03','2026-11-04 00:00:00',204],['2026-11-03','2026-11-04 00:01:59',204],['2026-11-03','2026-11-04 00:02:00',400],['2026-11-04','2026-11-03 23:59:59',400],['2026-11-02','2026-11-04 00:00:00',400],['2026-02-31','2026-11-04 00:00:00',400]] as [$day,$time,$status]) {
    $check(countDailyReach($db, '/article', $day, strtotime($time.' UTC'), 'Mozilla/5.0') === $status, 'Boundary/stale period: '.$time);
}
$check((int) queryColumn($db, "SELECT reports FROM daily_reach WHERE day='2026-11-03'") === 3 && (int) queryColumn($db, 'SELECT COUNT(*) FROM daily_reach') === 1, 'Grace reports retain their explicitly supplied day.');
foreach (['/private/a','/%61dmin/report'] as $path) {
    $check(!dailyReachPrepare($db, $path, $now, 'Mozilla/5.0')['eligible'] && countDailyReach($db,$path,'2026-11-03',$now,'Mozilla/5.0')===403, 'Excluded routes do not count.');
}
$check(!dailyReachPrepare($db,'/article',$now,'GPTBot/1')['eligible'] && countDailyReach($db,'/article','2026-11-03',$now,'GPTBot/1')===403, 'Automation is ineligible.');
$db->exec("CREATE TRIGGER fail_reach BEFORE UPDATE ON daily_reach BEGIN SELECT RAISE(ABORT, 'fixture'); END");
try { countDailyReach($db,'/article','2026-11-03',$now,'Mozilla/5.0'); throw new RuntimeException('Failed write accepted.'); } catch (PDOException) { }
$db->exec('DROP TRIGGER fail_reach');
\Barelytics\recordPageview($db, '/independent-view');
$check((int)queryColumn($db, 'SELECT SUM(views) FROM pageviews_daily')===1, 'Ordinary page views remain operational after a failed reach write.');
$check((int) queryColumn($db, "SELECT reports FROM daily_reach WHERE day='2026-11-03'") === 3, 'Failed reach update rolls back.');
putenv('BARELYTICS_DAILY_REACH_ALLOWED=0');
$check(!dailyReachPrepare($db,'/article',$now,'Mozilla/5.0')['enabled'] && countDailyReach($db,'/article','2026-11-03',$now,'Mozilla/5.0')===403, 'Operator kill switch rejects collection.');
putenv('BARELYTICS_DAILY_REACH_ALLOWED');
saveDailyReachSetting($db,false,true); recordPrivacyConfiguration($db);
$audit=privacySelfTest($db);
$check($audit['result']==='PASS' && $audit['schema']['historical_reach'] && effectivePrivacyConfig($db)['profile']==='strict', 'Disabled reach history does not fail Strict audit.');
$db->exec("INSERT INTO daily_reach VALUES ('2000-01-01',5)"); cleanRetentionBatch($db);
$check((int) queryColumn($db,"SELECT COUNT(*) FROM daily_reach WHERE day='2000-01-01'")===0, 'Retention includes reach.');
$check(migrateDatabase($db)===\Barelytics\CURRENT_SCHEMA_VERSION && (int)queryColumn($db,'SELECT COUNT(*) FROM schema_migrations')===\Barelytics\CURRENT_SCHEMA_VERSION, 'Migration is idempotent.');
$check(str_contains((string)queryColumn($db,'SELECT effective_configuration_json FROM privacy_configuration_history WHERE profile=\'EXTENDED\' LIMIT 1'),'"daily_reach_enabled":true'), 'History records enablement.');
echo "Daily Reach settings, prepare, boundaries, policy, failure, migration, audit and retention checks passed.\n";
