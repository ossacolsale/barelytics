<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/barelytics/src/Barelytics.php';
$db = new PDO('sqlite:' . $argv[2]);
$cookie = '';
foreach (file($argv[3], FILE_IGNORE_NEW_LINES) as $line) {
    $columns = explode("\t", $line);
    if (count($columns) === 7 && $columns[5] === 'PHPSESSID') $cookie = 'PHPSESSID=' . $columns[6];
}
$request = static function (string $endpoint, mixed $payload = null, string $method = 'POST', array $headers = [], bool $admin = false) use ($argv, $cookie): array {
    $headers += ['Content-Type'=>'application/json','User-Agent'=>'Mozilla/5.0'];
    if ($admin) $headers['Cookie'] = $cookie;
    $lines = []; foreach ($headers as $key=>$value) $lines[] = $key . ': ' . $value;
    $raw = is_string($payload) ? $payload : json_encode($payload ?? new stdClass());
    $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$lines),'content'=>$method==='POST'?$raw:'','ignore_errors'=>true]]);
    $body = file_get_contents($argv[1] . '/barelytics/' . $endpoint, false, $context);
    $response = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
    preg_match('~HTTP/\S+ (\d+)~', $response[0] ?? '', $status);
    if (!preg_match('/^Cache-Control: [^\r\n]*\bno-store\b/mi',implode("\n",$response))) throw new RuntimeException('Missing no-store: '.$endpoint);
    if (!$admin && preg_match('/^Set-Cookie:/mi',implode("\n",$response))) throw new RuntimeException('Reach issued a cookie.');
    return [(int)($status[1]??0),json_decode((string)$body,true),implode("\n",$response)];
};
$check = static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
[$status,$session]=$request('admin.php?api=session',null,'GET',[],true);
$check($status===200 && !empty($session['data']['authenticated']), 'Test administrator is authenticated.');
$csrf=$session['data']['csrf'];
[$status,,$headers]=$request('track.php',['path'=>'/central-strict']);
$check($status===204 && !str_contains($headers,'Barelytics-Reach-'), 'Normal Strict collector omits both reach headers.');
[$status,$integration]=$request('admin.php?api=integration',null,'GET',[],true);
$check($status===200 && $integration['data']['javascript_snippet']==='<script defer src="/barelytics/track.js"></script>'
    && str_contains($integration['data']['instructions'],'Native PHP track() calls alone cannot measure browser reach'), 'Integration documents the ordinary script and PHP-only limitation.');
$config=\Barelytics\effectivePrivacyConfig($db);
$settings=['csrf'=>$csrf,'retention_days'=>180,'path_exclusions'=>implode("\n",$config['path_exclusions']),'bot_patterns'=>'','daily_reach_enabled'=>true];
[$status]=$request('admin.php?api=privacy',$settings,'POST',[],true);
$check($status===400, 'Enabling reach requires explicit acknowledgement.');
[$status,$data]=$request('admin.php?api=privacy',$settings+['confirmation'=>'yes'],'POST',[],true);
$check($status===200 && $data['data']['profile']==='extended' && $data['data']['indexeddb']===true, 'Reach alone activates Extended.');
$prepared=$request('reach-prepare.php',['path'=>'/article/123456']);
$day=$prepared[1]['day'];
$check($prepared[0]===200 && $prepared[1]['enabled'] && $prepared[1]['eligible'] && $prepared[1]['path']==='/article/:id', 'Prepare validates and normalizes.');
$check((int)\Barelytics\queryColumn($db,'SELECT COUNT(*) FROM daily_reach')===0,'Prepare does not increment.');
[$status,,$headers]=$request('track.php',['path'=>'/reach-pageview/123456']);
$check($status===204 && str_contains($headers,'Barelytics-Reach-Enabled: 1')
    && str_contains($headers,'Barelytics-Reach-Path: %2Freach-pageview%2F%3Aid'),'Ordinary page-view response authorizes reach with a normalized encoded path.');
$before=(int)\Barelytics\queryColumn($db,'SELECT SUM(views) FROM pageviews_daily');
[$status,,$headers]=$request('track.php',['version'=>2,'path'=>'/central-v2','referrer_host'=>'example.test']);
$check($status===400 && !str_contains($headers,'Barelytics-Reach-') && (int)\Barelytics\queryColumn($db,'SELECT SUM(views) FROM pageviews_daily')===$before, 'Rejected v2 does not count or authorize reach.');
[$status,,$headers]=$request('track.php',['path'=>'/central-v2']);
$check($status===204 && str_contains($headers,'Barelytics-Reach-Enabled: 1') && (int)\Barelytics\queryColumn($db,'SELECT SUM(views) FROM pageviews_daily')===$before+1, 'Path-only fallback counts once and uses central reach policy.');
foreach ([['/private/a','Mozilla/5.0'],['/article','GPTBot/1'],['/article','Meta-WebIndexer/1.1'],['/article','Meta-ExternalAgent/1.1'],['/article','Meta-ExternalFetcher/1.1']] as [$path,$agent]) {
    [$status,,$headers]=$request('track.php',['path'=>$path],'POST',['User-Agent'=>$agent]);
    $check($status===204 && !str_contains($headers,'Barelytics-Reach-'), 'Ineligible pageview never authorizes browser reach.');
    [$status,$data]=$request('reach-prepare.php',['path'=>$path],'POST',['User-Agent'=>$agent]);
    $check($status===200 && !$data['eligible'],'Prepare excludes private/automated requests.');
    [$status]=$request('reach-count.php',['path'=>$path,'day'=>$day],'POST',['User-Agent'=>$agent]);
    $check($status===403,'Count repeats eligibility checks.');
}
foreach (['reach-prepare.php','reach-count.php'] as $endpoint) {
    $valid=$endpoint==='reach-prepare.php'?['path'=>'/article']:['day'=>$day,'path'=>'/article'];
    foreach ([[$valid,'GET',[],405],[$valid,'POST',['Content-Type'=>'text/plain'],415],['x'.str_repeat(' ',8192),'POST',[],413],['{','POST',[],400],[['path'=>'/article','visitor_id'=>'reject'],'POST',[],400],[$valid,'POST',['Origin'=>'https://other.test'],403],[$valid,'POST',['Origin'=>str_replace('http://','https://',$argv[1])],403],[$valid,'POST',['Sec-Fetch-Site'=>'cross-site'],403],["{\"path\":\"/\xff\"}",'POST',[],400]] as [$body,$method,$headers,$expected]) {
        [$actual]=$request($endpoint,$body,$method,$headers);$check($actual===$expected,$endpoint.' validation expected '.$expected.', got '.$actual);
    }
}
foreach (['invalid',gmdate('Y-m-d',time()-172800),gmdate('Y-m-d',time()+86400)] as $invalid) {
    [$status]=$request('reach-count.php',['path'=>'/article','day'=>$invalid]);$check($status===400,'Reject stale/future/invalid period.');
}
for($i=0;$i<2;$i++) {[$status]=$request('reach-count.php',['path'=>'/article','day'=>$day]);$check($status===204,'Valid reach report accepted.');}
[$status,$report]=$request('admin.php?api=reach',null,'GET',[],true);
$check($status===200 && $report['data']['total']===2,'Site-wide reports count receipts, not server deduplication.');
[$status,$strict]=$request('admin.php?api=strict',['csrf'=>$csrf],'POST',[],true);
$check($status===200 && !$strict['data']['daily_reach_enabled'] && !$strict['data']['indexeddb'],'Strict reset disables reach and browser storage.');
[$status,,$headers]=$request('track.php',['path'=>'/after-reach-reset']);
$check($status===204 && !str_contains($headers,'Barelytics-Reach-')
    && (int)\Barelytics\queryColumn($db,"SELECT SUM(views) FROM pageviews_daily WHERE path='/after-reach-reset'")===1,'Same ordinary collector continues pageviews after Strict reset without reach authorization.');
[$status]=$request('reach-count.php',['path'=>'/article','day'=>$day]);$check($status===403,'Disabled feature cannot count.');
[$status,$audit]=$request('admin.php?api=audit',null,'GET',[],true);
$check($status===200 && $audit['data']['historical_reach'] && $audit['data']['result']==='PASS','Strict audit distinguishes historical reach.');
// Independent centrally controlled Page Reach; old v1 semantics remain site-wide only.
$pageSettings=array_replace($settings,['daily_reach_enabled'=>false,'daily_page_reach_enabled'=>true]);
[$status]=$request('admin.php?api=privacy',$pageSettings,'POST',[],true);
$check($status===400,'Page Reach requires Extended acknowledgement.');
[$status]=$request('admin.php?api=privacy',array_replace($pageSettings,['daily_page_reach_enabled'=>'true','confirmation'=>'yes']),'POST',[],true);
$check($status===400,'Page Reach setting is strictly boolean.');
[$status,$data]=$request('admin.php?api=privacy',$pageSettings+['confirmation'=>'yes'],'POST',[],true);
$check($status===200 && $data['data']['daily_page_reach_available'] && $data['data']['daily_page_reach_enabled'] && !$data['data']['daily_reach_enabled'] && $data['data']['profile']==='extended','Page-only setting works centrally and independently.');
[$status,,$headers]=$request('track.php',['path'=>'/page-only']);
$check($status===204 && str_contains($headers,'Barelytics-Page-Reach-Enabled: 1') && !str_contains($headers,'Barelytics-Reach-Enabled:'),'Page-only authorization cannot activate a legacy global client.');
[$status,$legacy]=$request('reach-prepare.php',['path'=>'/page-only']);
$check($status===200 && !$legacy['enabled'] && $legacy['reporting_contract_version']===1,'Legacy prepare stays v1.');
[$status,$prepared]=$request('reach-prepare.php',['version'=>2,'path'=>'/page-only']);
$check($status===200 && $prepared['enabled'] && $prepared['page_enabled'] && !$prepared['global_enabled'] && $prepared['reporting_contract_version']===2 && is_int($prepared['server_time']),'v2 prepare supplies separate flags and authoritative time.');
$pagePayload=['version'=>2,'day'=>$prepared['day'],'path'=>'/page-only','global'=>false,'page'=>true];
foreach ([array_replace($pagePayload,['global'=>'false']),array_replace($pagePayload,['version'=>1]),array_replace($pagePayload,['version'=>3]),array_replace($pagePayload,['page'=>false]),$pagePayload+['fingerprint'=>str_repeat('a',64)],$pagePayload+['visitor_id'=>'forbidden']] as $invalid) {
    [$status]=$request('reach-count.php',$invalid); $check($status===400,'Versioned count rejects extra identity, hash and malformed flags.');
}
[$status]=$request('reach-count.php',$pagePayload);$check($status===204,'Page-only report accepted.');
[$status]=$request('track.php',['path'=>'/page-only']);$check($status===204,'Pageviews remain independent.');
foreach (['pages','daily','page&path=%2Fpage-only'] as $api) {
    [$status,$report]=$request('admin.php?api='.$api,null,'GET',[],true);
    $check($status===200 && $report['data']['page_reach']['available'] && $report['data']['page_reach']['enabled'] && $report['data']['page_reach']['has_data'],'Page reports expose current collection and observed history.');
    if (str_starts_with($api,'page&')) $check($report['data']['total']===2 && $report['data']['page_reach_reports']===1 && $report['data']['timeline'][0]['page_reach_reports']===1,'Page detail compares independent totals.');
    else {
        $found=array_values(array_filter($report['data']['rows'],static fn($row)=>$row['path']==='/page-only'));
        $check(count($found)===1 && $found[0]['views']===2 && $found[0]['page_reach_reports']===1,'Page/day tables include observed reports without multiplication.');
        $missing=array_values(array_filter($report['data']['rows'],static fn($row)=>$row['path']==='/central-strict'));
        $check(count($missing)===1 && $missing[0]['page_reach_reports']===null,'Historical pageviews do not fabricate zero/backfilled reach.');
    }
}
\Barelytics\setSetting($db,'daily_page_reach_limited_days',json_encode([$prepared['day']]));
[$status,$report]=$request('admin.php?api=pages',null,'GET',[],true);
$check($report['data']['page_reach']['partial'] && $report['data']['page_reach']['limited_days']===[$prepared['day']],'API reports cap-related partial data explicitly.');
[$status,$strict]=$request('admin.php?api=strict',['csrf'=>$csrf],'POST',[],true);
$check($status===200 && !$strict['data']['daily_page_reach_enabled'] && !$strict['data']['indexeddb'],'Strict resets both independent settings.');
[$status]=$request('reach-count.php',$pagePayload);$check($status===403,'Page count rechecks current server disablement.');
[$status,$audit]=$request('audit.php?format=json',null,'GET',[],true);
$check($status===200 && $audit['historical_page_reach'] && $audit['effective_configuration']['daily_page_reach_enabled']===false,'Privacy audit separates retained page history and current collection.');
[$status]=$request('admin.php?api=delete-all',['csrf'=>$csrf,'confirmation'=>'DELETE'],'POST',[],true);
$check($status===200 && (int)\Barelytics\queryColumn($db,'SELECT COUNT(*) FROM daily_reach')===0,'Delete-all removes reach.');
$check((int)\Barelytics\queryColumn($db,'SELECT COUNT(*) FROM daily_page_reach')===0 && \Barelytics\pageReachLimitedDays($db)===[],'Delete-all removes page aggregates and partial-day metadata.');
echo "Daily Reach HTTP validation, acknowledgement, attribution, reset, audit and deletion checks passed.\n";
