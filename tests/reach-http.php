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
    if (!preg_match('/^Cache-Control: no-store$/mi',implode("\n",$response))) throw new RuntimeException('Missing no-store: '.$endpoint);
    if (!$admin && preg_match('/^Set-Cookie:/mi',implode("\n",$response))) throw new RuntimeException('Reach issued a cookie.');
    return [(int)($status[1]??0),json_decode((string)$body,true),implode("\n",$response)];
};
$check = static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
[$status,$session]=$request('admin.php?api=session',null,'GET',[],true);
$check($status===200 && !empty($session['data']['authenticated']), 'Test administrator is authenticated.');
$csrf=$session['data']['csrf'];
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
[$status,,$headers]=$request('track.php',['path'=>'/reach-pageview']);
$check($status===204 && str_contains($headers,'Barelytics-Reach-Enabled: 1'),'Eligible page-view response advertises reach.');
foreach ([['/private/a','Mozilla/5.0'],['/article','GPTBot/1']] as [$path,$agent]) {
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
$check($status===204 && !str_contains($headers,'Barelytics-Reach-Enabled'),'Strict never advertises reach.');
[$status]=$request('reach-count.php',['path'=>'/article','day'=>$day]);$check($status===403,'Disabled feature cannot count.');
[$status,$audit]=$request('admin.php?api=audit',null,'GET',[],true);
$check($status===200 && $audit['data']['historical_reach'] && $audit['data']['result']==='PASS','Strict audit distinguishes historical reach.');
[$status]=$request('admin.php?api=delete-all',['csrf'=>$csrf,'confirmation'=>'DELETE'],'POST',[],true);
$check($status===200 && (int)\Barelytics\queryColumn($db,'SELECT COUNT(*) FROM daily_reach')===0,'Delete-all removes reach.');
echo "Daily Reach HTTP validation, acknowledgement, attribution, reset, audit and deletion checks passed.\n";
