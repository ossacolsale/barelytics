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
function copyTree(string $source, string $destination): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $target = $destination . '/' . substr($item->getPathname(), strlen($source) + 1);
        if ($item->isDir()) { mkdir($target, 0755, true); }
        else { copy($item->getPathname(), $target); }
    }
}
copyTree($source, $build);
mkdir($build . '/barelytics-core', 0755, true);
copyTree($core, $build . '/barelytics-core');
if (!is_dir(dirname($zipPath))) { mkdir(dirname($zipPath), 0755, true); }
$process = proc_open(['zip', '-q', '-r', $zipPath, 'barelytics'], [STDIN, STDOUT, STDERR], $pipes, $buildRoot);
if (!is_resource($process) || proc_close($process) !== 0) { fwrite(STDERR, "Could not create plugin ZIP (install the zip utility).\n"); exit(1); }
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
