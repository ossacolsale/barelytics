<?php
declare(strict_types=1);

// Exercise real plugin callbacks with a minimal WordPress host, without cron/FastCGI.
$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/barelytics-wp-hooks-' . bin2hex(random_bytes(6));
mkdir($fixture . '/site/wp-content', 0700, true);
mkdir($fixture . '/plugin', 0700);
copy($root . '/integrations/wordpress/barelytics/barelytics.php', $fixture . '/plugin/barelytics.php');
copy($root . '/integrations/wordpress/barelytics/storage.php', $fixture . '/plugin/storage.php');
symlink($root . '/public/barelytics', $fixture . '/plugin/barelytics-core');
define('ABSPATH', $fixture . '/site/');
define('WP_CONTENT_DIR', $fixture . '/site/wp-content');
define('BARELYTICS_WP_DATA_DIRECTORY', $fixture . '/private');
$_SERVER['DOCUMENT_ROOT'] = $fixture . '/site';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/article';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$actions = []; $activation = null;
function add_action(string $name, callable $callback, int $priority = 10): void { global $actions; $actions[$name][] = $callback; }
function register_activation_hook(string $file, callable $callback): void { global $activation; $activation = $callback; }
function get_option(string $key, mixed $default = false): mixed { return $default; }
function current_user_can(string $capability): bool { return true; }
function wp_create_nonce(string $action): string { return 'synthetic-admin-nonce'; }
function admin_url(string $path): string { return '/wp-admin/' . $path; }
function plugins_url(string $path, string $file): string { return '/wp-content/plugins/barelytics/' . $path; }
function add_query_arg(array $query, string $url): string { return $url . '?' . http_build_query($query); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_admin(): bool { return false; }
function is_feed(): bool { return false; }
function is_robots(): bool { return false; }
function is_trackback(): bool { return false; }
function is_preview(): bool { return false; }
function is_404(): bool { return false; }
function is_singular(): bool { return true; }

function removeFixture(string $path): void
{
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) removeFixture($entry->getPathname());
    rmdir($path);
}

try {
    require $fixture . '/plugin/barelytics.php';
    if (!barelytics_wp_storage_ready() || !is_callable($activation)) throw new RuntimeException('Plugin initialization failed.');
    $activation();
    $db = \Barelytics\connectDatabase();
    if (\Barelytics\schemaVersion($db) !== \Barelytics\CURRENT_SCHEMA_VERSION) throw new RuntimeException('Activation did not migrate a fresh store.');
    $db->exec("INSERT INTO pageviews_daily VALUES ('2000-01-01','/expired','XX',1)");
    $db->exec("INSERT INTO automation_daily VALUES ('2000-01-01','/expired','ai','openai',1)");
    foreach ($actions['template_redirect'] as $callback) $callback();
    if ((int) \Barelytics\queryColumn($db, "SELECT views FROM pageviews_daily WHERE path='/article'") !== 1) throw new RuntimeException('WordPress did not count its document request.');
    foreach (['pageviews_daily', 'automation_daily'] as $table) {
        if ((int) \Barelytics\queryColumn($db, "SELECT COUNT(*) FROM $table WHERE day='2000-01-01'") !== 0) throw new RuntimeException('WordPress retention requires an unavailable scheduler.');
    }
    $_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1';
    foreach ($actions['template_redirect'] as $callback) $callback();
    if ((int) \Barelytics\queryColumn($db, "SELECT requests FROM automation_daily WHERE path='/article'") !== 1) throw new RuntimeException('WordPress automation was not separated.');
    $_SERVER['REQUEST_URI'] = '/%61dmin/private';
    foreach ($actions['template_redirect'] as $callback) $callback();
    if ((int) \Barelytics\queryColumn($db, "SELECT SUM(requests) FROM automation_daily") !== 1) throw new RuntimeException('WordPress exclusion bypass.');
    if (\Barelytics\effectivePrivacyConfig($db)['daily_page_reach_available'] || \Barelytics\effectivePrivacyConfig($db)['daily_page_reach_enabled'] || \Barelytics\effectivePrivacyConfig($db)['daily_reach_available'] || \Barelytics\effectivePrivacyConfig($db)['daily_reach_enabled']) throw new RuntimeException('WordPress must not advertise unsupported reach.');
    ob_start();
    barelytics_wp_shared_admin_page();
    $administration = (string) ob_get_clean();
    foreach (['src="/wp-content/plugins/barelytics/barelytics-core/admin-ui/brand-mark.png"', '/admin-ui/admin.css?ver=', '/admin-ui/app.js?ver=', 'id="page-title"'] as $expected) {
        if (!str_contains($administration, $expected)) throw new RuntimeException('WordPress shared UI lost a local asset or canonical shell.');
    }
    if (str_contains($administration, 'src="admin-ui/')) throw new RuntimeException('WordPress admin assets resolve relative to the wrong document.');
    unset($db);
    echo "WordPress activation, collection, retention and shared administration asset checks passed.\n";
} finally {
    removeFixture($fixture);
}
