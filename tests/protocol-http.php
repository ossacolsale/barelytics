<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/barelytics/src/Barelytics.php';
$db = new PDO('sqlite:' . $argv[2]);
$vectors = json_decode((string) file_get_contents(__DIR__ . '/../spec/test-vectors/protocol.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($vectors as $index => $v) {
    \Barelytics\setSetting($db, 'referrer_collection', !empty($v['referrer_collection']) ? '1' : '0');
    $raw = isset($v['raw_hex']) ? hex2bin($v['raw_hex']) : (isset($v['body_bytes']) ? str_repeat('x', $v['body_bytes']) : (is_string($v['body'] ?? null) ? $v['body'] : json_encode($v['body'] ?? new stdClass(), JSON_THROW_ON_ERROR)));
    if (isset($v['body_size'])) $raw = str_pad($raw, $v['body_size']);
    $type = $v['content_type'] ?? 'application/json';
    $headers = "User-Agent: Mozilla/5.0\r\nReferer: https://beacon.test/current?private\r\nConnection: close\r\n";
    // An explicit empty header suppresses PHP's default form media type.
    $headers .= 'Content-Type: ' . $type . "\r\n";
    $context = stream_context_create(['http' => ['method' => $v['method'], 'header' => $headers, 'content' => $raw, 'ignore_errors' => true]]);
    file_get_contents($argv[1] . '/barelytics/track.php', false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
    preg_match('~HTTP/\S+ (\d+)~', $responseHeaders[0] ?? '', $matches);
    if ((int) ($matches[1] ?? 0) !== $v['status']) throw new RuntimeException('Protocol vector ' . $index . ' failed: ' . ($matches[1] ?? 'no status'));
    $joined = implode("\n", $responseHeaders);
    if (!preg_match('/^Cache-Control: no-store$/mi', $joined) || preg_match('/^Set-Cookie:/mi', $joined)) throw new RuntimeException('Collector cache/cookie contract failed.');
}
if ((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM referrers_daily WHERE referrer_host='beacon.test'") !== 0) throw new RuntimeException('Beacon Referer was attributed as a navigation source.');
if ((int) \Barelytics\queryColumn($db, "SELECT views FROM referrers_daily WHERE path='/v2' AND referrer_host='example.test'") !== 1) throw new RuntimeException('Minimized hostname was not stored.');
\Barelytics\setSetting($db, 'referrer_collection', '0');
echo "Shared collector HTTP vectors passed.\n";
