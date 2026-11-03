<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/integrations/wordpress/barelytics';
$core = $root . '/public/barelytics';
$buildRoot = sys_get_temp_dir() . '/barelytics-wordpress-' . bin2hex(random_bytes(8));
$build = $buildRoot . '/barelytics';
$zipPath = $root . '/build/barelytics-wordpress.zip';
if (!function_exists('proc_open')) { fwrite(STDERR, "PHP proc_open is required to call the system zip utility.\n"); exit(1); }
mkdir($build, 0755, true);
function copyPackageFiles(string $source, string $destination, array $files): void
{
    $sourceRoot = realpath($source);
    foreach ($files as $relative) {
        $from = $source . '/' . $relative;
        $target = $destination . '/' . $relative;
        $resolved = realpath($from);
        if ($sourceRoot === false || $resolved === false || !str_starts_with($resolved, $sourceRoot . DIRECTORY_SEPARATOR)
            || !is_file($from) || is_link($from)) throw new RuntimeException('Missing or unsafe package source: ' . $relative);
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
        if (!copy($from, $target)) throw new RuntimeException('Could not copy package source: ' . $relative);
    }
}
copyPackageFiles($source, $build, ['barelytics.php', 'storage.php']);
copy($root . '/integrations/wordpress/README.md', $build . '/README.md');
mkdir($build . '/barelytics-core', 0755, true);
// Explicit source manifest: never traverse operator storage, local tokens or backups.
copyPackageFiles($core, $build . '/barelytics-core', [
    '.htaccess', 'account.php', 'admin.php', 'admin.css', 'audit.php', 'cleanup.php',
    'diagnostics.php', 'install.php', 'token-hash.html', 'token-hash.js', 'track.php', 'track.js',
    'src/.htaccess', 'src/Barelytics.php', 'src/AdminApi.php', 'src/Automation.php', 'data/.htaccess',
    'admin-ui/index.html', 'admin-ui/app.js', 'admin-ui/admin.css', 'admin-ui/config.js',
    'admin-ui/brand-mark.png', 'admin-ui/README.md',
]);
if (!is_dir(dirname($zipPath))) { mkdir(dirname($zipPath), 0755, true); }
// Updating an existing ZIP can retain removed entries, including private files.
$temporaryZip = $buildRoot . '/barelytics-wordpress.zip';
$process = proc_open(['zip', '-q', '-r', $temporaryZip, 'barelytics'], [STDIN, STDOUT, STDERR], $pipes, $buildRoot);
if (!is_resource($process) || proc_close($process) !== 0) { fwrite(STDERR, "Could not create plugin ZIP (install the zip utility).\n"); exit(1); }
if (!rename($temporaryZip, $zipPath)) { fwrite(STDERR, "Could not replace plugin ZIP.\n"); exit(1); }
function removeTree(string $directory): void
{
    foreach (new FilesystemIterator($directory) as $item) {
        if ($item->isDir()) { removeTree($item->getPathname()); }
        else { unlink($item->getPathname()); }
    }
    rmdir($directory);
}
removeTree($buildRoot);
echo $zipPath . PHP_EOL;
