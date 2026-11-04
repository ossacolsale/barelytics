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
    if (\Barelytics\effectivePrivacyConfig($db)['daily_reach_available'] || \Barelytics\effectivePrivacyConfig($db)['daily_reach_enabled']) throw new RuntimeException('WordPress must not advertise unsupported reach.');
    unset($db);
    echo "WordPress activation, collection and retention callbacks passed.\n";
} finally {
    removeFixture($fixture);
}
