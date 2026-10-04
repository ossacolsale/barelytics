<?php
/**
 * Plugin Name: Barelytics
 * Description: Self-hosted, privacy-first aggregate analytics using the Barelytics PHP core.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: MIT
 * Text Domain: barelytics
 */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

define('BARELYTICS_WP_DATA_DIRECTORY', WP_CONTENT_DIR . '/barelytics-private');
if (!is_dir(BARELYTICS_WP_DATA_DIRECTORY)) { wp_mkdir_p(BARELYTICS_WP_DATA_DIRECTORY); }
if (!is_file(BARELYTICS_WP_DATA_DIRECTORY . '/index.php')) { @file_put_contents(BARELYTICS_WP_DATA_DIRECTORY . '/index.php', "<?php http_response_code(404); exit;\n"); }
if (!is_file(BARELYTICS_WP_DATA_DIRECTORY . '/.htaccess')) { @file_put_contents(BARELYTICS_WP_DATA_DIRECTORY . '/.htaccess', "Deny from all\n"); }
putenv('BARELYTICS_DATA_DIRECTORY=' . BARELYTICS_WP_DATA_DIRECTORY);
$_ENV['BARELYTICS_DATA_DIRECTORY'] = BARELYTICS_WP_DATA_DIRECTORY;

$barelyticsCore = __DIR__ . '/barelytics-core/src/Barelytics.php';
if (is_file($barelyticsCore)) { require_once $barelyticsCore; }

register_activation_hook(__FILE__, static function (): void {
    if (!is_dir(BARELYTICS_WP_DATA_DIRECTORY)) { wp_mkdir_p(BARELYTICS_WP_DATA_DIRECTORY); }
    @chmod(BARELYTICS_WP_DATA_DIRECTORY, 0700);
});

add_action('template_redirect', static function (): void {
    if (get_option('barelytics_enabled', '1') !== '1') { return; }
    if (is_admin() || is_feed() || is_robots() || is_trackback() || is_preview() || is_404()) { return; }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !is_singular() && !is_front_page() && !is_home()) { return; }
    if (function_exists('Barelytics\\connectDatabase') && function_exists('Barelytics\\countRequest')) {
        try {
            $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $path = is_string($path) ? \Barelytics\normalizePath($path) : null;
            if ($path !== null) { \Barelytics\countRequest(\Barelytics\connectDatabase(), $path); }
        } catch (Throwable) { /* Analytics must never break a WordPress response. */ }
    }
}, 20);

add_action('admin_menu', static function (): void {
    add_options_page('Barelytics', 'Barelytics', 'manage_options', 'barelytics', 'barelytics_wp_settings_page');
});

add_action('admin_post_barelytics_save', static function (): void {
    if (!current_user_can('manage_options')) { wp_die(esc_html__('You are not allowed to change Barelytics settings.', 'barelytics'), 403); }
    check_admin_referer('barelytics_save');
    $optionalKeys = ['country_collection', 'referrer_collection', 'browser_collection', 'device_collection', 'os_collection'];
    $requestedDimensions = array_filter($optionalKeys, static fn(string $key): bool => isset($_POST[$key]));
    if (isset($_POST['enabled']) && $requestedDimensions !== [] && !isset($_POST['acknowledge_extended'])) {
        wp_safe_redirect(admin_url('options-general.php?page=barelytics&acknowledgement=1'));
        exit;
    }
    update_option('barelytics_enabled', isset($_POST['enabled']) ? '1' : '0', false);
    if (function_exists('Barelytics\\connectDatabase')) {
        try {
            $db = \Barelytics\connectDatabase();
            $enabled = isset($_POST['enabled']);
            $db->beginTransaction();
            foreach ($optionalKeys as $key) { \Barelytics\setSetting($db, $key, $enabled && isset($_POST[$key]) ? '1' : '0'); }
            \Barelytics\recordPrivacyConfiguration($db);
            $db->commit();
        } catch (Throwable) { /* Settings page reports install state; visitor requests remain safe. */ }
    }
    wp_safe_redirect(admin_url('options-general.php?page=barelytics&saved=1'));
    exit;
});

function barelytics_wp_settings_page(): void
{
    if (!current_user_can('manage_options')) { return; }
    $enabled = get_option('barelytics_enabled', '1') === '1';
    $dimensions = [];
    $stats = null;
    $audit = null;
    if (function_exists('Barelytics\\connectDatabase')) {
        try {
            $db = \Barelytics\connectDatabase();
            foreach (['country_collection', 'referrer_collection', 'browser_collection', 'device_collection', 'os_collection'] as $key) { $dimensions[$key] = \Barelytics\setting($db, $key, '0') === '1'; }
            $period = isset($_GET['period']) && in_array((string) $_GET['period'], ['30', '90', '180', '365'], true) ? (int) $_GET['period'] : 30;
            $selectedPath = isset($_GET['path']) ? sanitize_text_field(wp_unslash((string) $_GET['path'])) : '';
            if ($selectedPath !== '' && \Barelytics\normalizePath($selectedPath) !== $selectedPath) { $selectedPath = ''; }
            $to = gmdate('Y-m-d'); $from = gmdate('Y-m-d', time() - (($period - 1) * 86400));
            $stats = [
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'total' => (int) \Barelytics\queryColumn($db, 'SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN :from AND :to', [':from' => $from, ':to' => $to]),
                'pages' => \Barelytics\queryRows($db, 'SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY path ORDER BY views DESC,path LIMIT 100', [':from' => $from, ':to' => $to]),
                'by_day' => \Barelytics\queryRows($db, 'SELECT day,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN :from AND :to GROUP BY day ORDER BY day DESC', [':from' => $from, ':to' => $to]),
                'selected_path' => $selectedPath,
                'page_by_day' => $selectedPath === '' ? [] : \Barelytics\queryRows($db, 'SELECT day,SUM(views) AS views FROM pageviews_daily WHERE path = :path AND day BETWEEN :from AND :to GROUP BY day ORDER BY day DESC', [':path' => $selectedPath, ':from' => $from, ':to' => $to]),
            ];
            $config = \Barelytics\effectivePrivacyConfig($db);
            $audit = ['configuration' => $config, 'fingerprint' => \Barelytics\privacyFingerprint($db), 'schema' => \Barelytics\schemaAudit($db, strtoupper($config['profile']), $config), 'self_test' => \Barelytics\privacySelfTest($db)];
        }
        catch (Throwable) { $dimensions = []; }
    }
    ?>
    <div class="wrap">
      <h1>Barelytics</h1>
      <p><?php echo esc_html__('Strict Mode is enabled by default: Barelytics stores daily page totals and normalized paths, without visitor identifiers or cookies.', 'barelytics'); ?></p>
      <p><?php echo esc_html(sprintf(__('Private SQLite data directory: %s', 'barelytics'), BARELYTICS_WP_DATA_DIRECTORY)); ?></p>
      <?php if (!is_file(__DIR__ . '/barelytics-core/src/Barelytics.php')): ?>
        <div class="notice notice-error"><p><?php echo esc_html__('The PHP core is missing. Reinstall Barelytics using the ZIP created by scripts/build-wordpress-plugin.php.', 'barelytics'); ?></p></div>
      <?php endif; ?>
      <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p><?php echo esc_html__('Settings saved.', 'barelytics'); ?></p></div><?php endif; ?>
      <?php if (isset($_GET['acknowledgement'])): ?><div class="notice notice-error"><p><?php echo esc_html__('Confirm the optional dimension acknowledgement before enabling extended collection.', 'barelytics'); ?></p></div><?php endif; ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="barelytics_save">
        <?php wp_nonce_field('barelytics_save'); ?>
        <p><label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> <?php echo esc_html__('Enable page-view aggregation', 'barelytics'); ?></label></p>
        <fieldset><legend><?php echo esc_html__('Optional dimensions (each opt-in)', 'barelytics'); ?></legend>
          <?php foreach (['country_collection' => 'Country', 'referrer_collection' => 'Referrer hostname', 'browser_collection' => 'Browser', 'device_collection' => 'Device', 'os_collection' => 'Operating system'] as $key => $label): ?>
            <p><label><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($dimensions[$key] ?? false); ?>> <?php echo esc_html($label); ?></label></p>
          <?php endforeach; ?>
          <p><label><input type="checkbox" name="acknowledge_extended" value="1"> <?php echo esc_html__('I acknowledge that selected dimensions add aggregate data beyond Strict Mode.', 'barelytics'); ?></label></p>
        </fieldset>
        <?php submit_button(__('Save settings', 'barelytics')); ?>
      </form>
      <hr><h2><?php echo esc_html__('Statistics', 'barelytics'); ?></h2>
      <?php if ($stats === null): ?><p><?php echo esc_html__('Statistics are not available. Check the PHP SQLite extension and data-directory permissions.', 'barelytics'); ?></p>
      <?php else: ?>
        <form method="get"><input type="hidden" name="page" value="barelytics"><label><?php echo esc_html__('Period', 'barelytics'); ?>
          <select name="period"><?php foreach ([30, 90, 180, 365] as $days): ?><option value="<?php echo esc_attr((string) $days); ?>" <?php selected($stats['period'], $days); ?>><?php echo esc_html(sprintf(__('%d days', 'barelytics'), $days)); ?></option><?php endforeach; ?></select>
        </label><?php submit_button(__('Apply', 'barelytics'), 'secondary', '', false); ?></form>
        <p><strong><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong> <?php echo esc_html(sprintf(__('page views, %s through %s', 'barelytics'), $stats['from'], $stats['to'])); ?></p>
        <h3><?php echo esc_html__('Top pages', 'barelytics'); ?></h3>
        <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Page', 'barelytics'); ?></th><th><?php echo esc_html__('Views', 'barelytics'); ?></th></tr></thead><tbody>
          <?php if ($stats['pages'] === []): ?><tr><td colspan="2"><?php echo esc_html__('No page views in this period yet.', 'barelytics'); ?></td></tr><?php endif; ?>
          <?php foreach ($stats['pages'] as $row): ?><tr><td><a href="<?php echo esc_url(add_query_arg(['page' => 'barelytics', 'period' => $stats['period'], 'path' => (string) $row['path']], admin_url('options-general.php'))); ?>"><code><?php echo esc_html((string) $row['path']); ?></code></a></td><td><?php echo esc_html(number_format_i18n((int) $row['views'])); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <h3><?php echo esc_html__('Daily totals', 'barelytics'); ?></h3>
        <table class="widefat striped"><thead><tr><th><?php echo esc_html__('UTC day', 'barelytics'); ?></th><th><?php echo esc_html__('Views', 'barelytics'); ?></th></tr></thead><tbody>
          <?php if ($stats['by_day'] === []): ?><tr><td colspan="2"><?php echo esc_html__('No page views in this period yet.', 'barelytics'); ?></td></tr><?php endif; ?>
          <?php foreach ($stats['by_day'] as $row): ?><tr><td><?php echo esc_html((string) $row['day']); ?></td><td><?php echo esc_html(number_format_i18n((int) $row['views'])); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($stats['selected_path'] !== ''): ?>
          <h3><?php echo esc_html(sprintf(__('Daily views for %s', 'barelytics'), $stats['selected_path'])); ?></h3>
          <table class="widefat striped"><thead><tr><th><?php echo esc_html__('UTC day', 'barelytics'); ?></th><th><?php echo esc_html__('Views', 'barelytics'); ?></th></tr></thead><tbody>
            <?php foreach ($stats['page_by_day'] as $row): ?><tr><td><?php echo esc_html((string) $row['day']); ?></td><td><?php echo esc_html(number_format_i18n((int) $row['views'])); ?></td></tr><?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      <?php endif; ?>
      <hr><h2><?php echo esc_html__('Privacy audit', 'barelytics'); ?></h2>
      <?php if ($audit === null): ?><p><?php echo esc_html__('Audit details are unavailable until SQLite storage can be opened.', 'barelytics'); ?></p>
      <?php else: ?>
        <p><?php echo esc_html(sprintf(__('Effective profile: %s', 'barelytics'), strtoupper($audit['configuration']['profile']))); ?></p>
        <p><?php echo esc_html(sprintf(__('Configuration fingerprint: %s', 'barelytics'), $audit['fingerprint'])); ?></p>
        <p><?php echo esc_html(sprintf(__('Schema audit: %s · Runtime self-test: %s', 'barelytics'), $audit['schema']['status'], $audit['self_test']['result'])); ?></p>
        <p><?php echo esc_html__('No visitor identifiers, IP addresses, analytics cookies, or third-party analytics requests are used by Strict Mode.', 'barelytics'); ?></p>
      <?php endif; ?>
    </div>
    <?php
}
