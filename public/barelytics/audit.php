<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID > 80599) { http_response_code(404); exit; }
require_once __DIR__ . '/src/Barelytics.php';

use function Barelytics\adminHeaders;
use function Barelytics\connectDatabase;
use function Barelytics\csrfToken;
use function Barelytics\effectivePrivacyConfig;
use function Barelytics\escape;
use function Barelytics\privacyFingerprint;
use function Barelytics\privacySelfTest;
use function Barelytics\queryRows;
use function Barelytics\schemaAudit;
use function Barelytics\setting;
use function Barelytics\startAdminSession;
use function Barelytics\tableExists;
use function Barelytics\validCsrf;
use const Barelytics\APPLICATION_VERSION;
use const Barelytics\CURRENT_SCHEMA_VERSION;

adminHeaders();
header('Content-Type: text/html; charset=UTF-8');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
startAdminSession();
if (empty($_SESSION['authenticated'])) { http_response_code(403); exit('Administrator sign-in required.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('The form expired. Reload and try again.'); }

try { $db = connectDatabase(); } catch (Throwable) { http_response_code(503); exit('Privacy audit unavailable.'); }
if (!tableExists($db, 'settings')) { http_response_code(503); exit('Privacy audit unavailable.'); }
$config = effectivePrivacyConfig($db);
$schemaVersion = \Barelytics\schemaVersion($db);
$schema = $schemaVersion === CURRENT_SCHEMA_VERSION ? schemaAudit($db, strtoupper($config['profile']), $config) : ['status' => 'FAIL', 'tables' => [], 'unexpected' => ['Database upgrade required']];
$selfTest = privacySelfTest($db);
$tracker = (string) @file_get_contents(__DIR__ . '/track.js');
$clientStorageFree = $tracker !== '' && !preg_match('/\b(?:localStorage|sessionStorage|indexedDB|document\.cookie)\b/i', $tracker);
$selfTest['checks'][] = ['name' => 'Tracker does not use browser storage', 'status' => $clientStorageFree ? 'PASS' : 'FAIL'];
if (!$clientStorageFree) $selfTest['result'] = 'FAIL';
$hash = privacyFingerprint($db);
$history = [];
if (tableExists($db, 'privacy_configuration_history')) {
    $history = queryRows($db, 'SELECT timestamp, profile, effective_configuration_json, configuration_hash, application_version, schema_version FROM privacy_configuration_history ORDER BY rowid DESC LIMIT 100');
}
$files = [];
foreach (['track.php', 'src/Barelytics.php', 'track.js'] as $relative) {
    $path = __DIR__ . '/' . $relative;
    $files[$relative] = is_file($path) ? hash_file('sha256', $path) : null;
}
$cleanup = setting($db, 'last_cleanup_at', '');
$cleanupTime = $cleanup === '' ? 0 : (strtotime($cleanup . ' UTC') ?: 0);
$externalStatus = $selfTest['checks'][array_search('No third-party analytics or enrichment client in runtime sources', array_column($selfTest['checks'], 'name'), true)]['status'] === 'PASS' ? 'NONE (runtime source scan passed)' : 'UNVERIFIED — inspect runtime sources';
$report = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'application_version' => APPLICATION_VERSION,
    'schema_version' => $schemaVersion,
    'profile' => strtoupper($config['profile']),
    'effective_configuration' => $config,
    'configuration_fingerprint' => $hash,
    'database_schema_audit' => $schema,
    'self_test' => $selfTest,
    'path_privacy' => ['query_strings' => 'removed', 'fragments' => 'not sent; rejected by tracker endpoint', 'identifier_normalization' => 'long numeric, UUID, ULID, hex and opaque path segments replaced with :id; email paths rejected', 'configured_exclusion_pattern_count' => count($config['path_exclusions'])],
    'retention' => ['aggregate_days' => $config['retention_days'], 'cleanup_status' => $cleanupTime > 0 && time() - $cleanupTime < 86400 ? 'PASS — automatic web-triggered cleanup available' : 'WARN — cleanup pending'],
    'external_services' => ['third_party_analytics' => $externalStatus, 'external_geo_ip' => $externalStatus, 'external_tracking' => $externalStatus, 'country_header_source' => $config['trusted_country_header']],
    'application_file_sha256' => $files,
    'configuration_history' => $history,
    'legal_boundary' => 'This report describes the technical configuration and behaviour observed by Barelytics at generation time. It is not a legal certification or legal advice. The site operator remains responsible for applicable legal basis, transparency, retention, third-party processing and server logging.'
];
$report['result'] = $selfTest['result'] === 'FAIL' || $schema['status'] === 'FAIL' ? 'FAIL' : ($selfTest['result'] === 'WARN' || str_starts_with($report['retention']['cleanup_status'], 'WARN') ? 'WARN' : 'PASS');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="barelytics-privacy-audit.json"');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_GET['format'] ?? '') === 'html') {
    header('Content-Disposition: attachment; filename="barelytics-privacy-audit.html"');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Barelytics privacy audit</title><h1>Barelytics privacy audit</h1><p>Generated ' . htmlspecialchars($report['generated_at'], ENT_QUOTES, 'UTF-8') . ' · Result: ' . htmlspecialchars($report['result'], ENT_QUOTES, 'UTF-8') . '</p><p>This report describes technical configuration and observed behavior. It is not a legal certification or legal advice.</p><pre>' . htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></html>';
    exit;
}
$csrf = csrfToken();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Privacy audit · Barelytics</title><link rel="stylesheet" href="admin.css"></head><body><main class="wrap"><header><p class="brand"><img src="admin-ui/brand-mark.png" width="72" height="50" alt="Barelytics"></p><h1>Privacy audit</h1><p><a href="admin.php">Back to administration</a></p></header>
<section class="card"><h2>Technical report</h2><p>This report describes the technical configuration and behaviour observed by Barelytics at generation time. It is not a legal certification and does not constitute legal advice. The site operator remains responsible for applicable legal basis, transparency, retention, third-party processing and server logging.</p><table><tbody><tr><th>Generated (UTC)</th><td><?= escape($report['generated_at']) ?></td></tr><tr><th>Application version</th><td><?= escape(APPLICATION_VERSION) ?></td></tr><tr><th>Schema version</th><td><?= (int) $schemaVersion ?> / <?= CURRENT_SCHEMA_VERSION ?></td></tr><tr><th>Effective profile</th><td><?= escape($report['profile']) ?></td></tr><tr><th>Result</th><td><strong><?= escape($report['result']) ?></strong></td></tr><tr><th>Configuration fingerprint (SHA-256)</th><td><code><?= escape($hash) ?></code></td></tr><tr><th>Path exclusions</th><td><?= count($config['path_exclusions']) ?> configured patterns (pattern values omitted)</td></tr><tr><th>Retention cleanup</th><td><?= escape($report['retention']['cleanup_status']) ?></td></tr></tbody></table></section>
<section class="card"><h2>Effective collection</h2><p>These browser-storage statuses refer to analytics tracking. The authenticated administration interface uses an HttpOnly session cookie; the tracker does not access or set it.</p><table><tbody><?php foreach (['pageviews'=>'Aggregate page views','country_collection'=>'Country','referrer_collection'=>'Referrer hostname','user_agent_collection'=>'User-Agent analytics dimension','browser_collection'=>'Browser category','device_collection'=>'Device category','os_collection'=>'Operating system category','screen_dimensions'=>'Screen dimensions','language_collection'=>'Language','query_string_collection'=>'Query strings','event_collection'=>'Custom events','custom_parameters'=>'Custom parameters','visitor_id'=>'Visitor ID','session_id'=>'Session ID','fingerprinting'=>'Fingerprinting','cookies'=>'Analytics cookies','local_storage'=>'localStorage','session_storage'=>'sessionStorage','indexeddb'=>'IndexedDB','cross_site_tracking'=>'Cross-site recognition','third_party_analytics'=>'Third-party analytics'] as $key => $label): ?><tr><th><?= escape($label) ?></th><td><?= !empty($config[$key]) ? 'ENABLED' : 'DISABLED' ?></td></tr><?php endforeach; ?><tr><th>IP address</th><td>NOT STORED</td></tr><tr><th>Full User-Agent</th><td>NOT STORED (used transiently for bot filtering; coarse categories only if enabled)</td></tr><tr><th>Visitor identifier</th><td>NOT STORED</td></tr><tr><th>Session identifier</th><td>NOT STORED</td></tr><tr><th>Cookies</th><td>NOT STORED for analytics</td></tr><tr><th>Raw event payloads</th><td>NOT STORED</td></tr><tr><th>Pageview aggregate</th><td>STORED</td></tr></tbody></table></section>
<section class="card"><h2>Database schema</h2><p><?= escape($schema['status']) ?></p><?php if ($schema['unexpected']): ?><ul><?php foreach ($schema['unexpected'] as $problem): ?><li><?= escape($problem) ?></li><?php endforeach; ?></ul><?php else: ?><p>Aggregate analytics schema contains no visitor identifiers, IP, User-Agent or request payload columns.</p><?php endif; ?></section>
<section class="card"><h2>Privacy self-test</h2><p>Result: <strong><?= escape($selfTest['result']) ?></strong></p><ul><?php foreach ($selfTest['checks'] as $check): ?><li>[<?= escape($check['status']) ?>] <?= escape($check['name']) ?></li><?php endforeach; ?></ul><form method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><button>Run privacy tests</button></form></section>
<section class="card"><h2>Path, services and release evidence</h2><p>Query strings are removed, fragments are never sent by the browser tracker, and configured exclusions are enforced before collection. Third-party services: <?= escape($report['external_services']['third_party_analytics']) ?>. Country header source: <code><?= escape($config['trusted_country_header']) ?></code>.</p><p>Application file SHA-256:</p><ul><?php foreach ($files as $name => $fileHash): ?><li><code><?= escape($name) ?></code>: <code><?= escape((string) $fileHash) ?></code></li><?php endforeach; ?></ul><p><a href="audit.php?format=html">Export HTML report</a> · <a href="audit.php?format=json">Export JSON report</a></p></section>
<section class="card"><h2>Privacy configuration history</h2><?php if (!$history): ?><p>No configuration history is available.</p><?php else: ?><table><thead><tr><th>Timestamp (UTC)</th><th>Profile</th><th>Effective optional dimensions</th><th>Configuration hash</th><th>Version / schema</th></tr></thead><tbody><?php foreach ($history as $row): $historical = json_decode((string) $row['effective_configuration_json'], true) ?: []; ?><tr><td><?= escape($row['timestamp']) ?></td><td><?= escape($row['profile']) ?></td><td><?php foreach (['country_collection'=>'country','referrer_collection'=>'referrer','browser_collection'=>'browser','device_collection'=>'device','os_collection'=>'OS'] as $key => $label): ?><?= escape($label) ?>=<?= !empty($historical[$key]) ? 'on' : 'off' ?><?= $key === 'os_collection' ? '' : ', ' ?><?php endforeach; ?></td><td><code><?= escape(substr($row['configuration_hash'], 0, 16)) ?>…</code></td><td><?= escape($row['application_version']) ?> / <?= (int) $row['schema_version'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
</main></body></html>
