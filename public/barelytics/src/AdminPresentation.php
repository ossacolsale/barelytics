<?php
declare(strict_types=1);
namespace Barelytics;

/** Relative to the PHP entry point, including nested FTP installations. */
function adminAsset(string $asset): string
{
    $path = dirname(__DIR__) . '/' . $asset;
    return $asset . '?v=' . (is_file($path) ? substr((string) hash_file('sha256', $path), 0, 12) : 'missing');
}

function adminPageStart(string $title, string $active = '', bool $navigation = true): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . escape($title) . ' · Barelytics</title><link rel="stylesheet" href="' . escape(adminAsset('admin-ui/admin.css')) . '"></head><body><a class="skip-link" href="#content">Skip to content</a><div class="app-shell">';
    if ($navigation) {
        echo '<aside class="sidebar"><a class="brand-lockup" href="admin.php"><img src="admin-ui/brand-mark.png" width="58" height="40" alt=""><span>Barelytics<small>PRIVATE BY DESIGN</small></span></a><details class="mobile-navigation" open><summary>Navigation</summary><nav aria-label="Administration sections"><p class="nav-label">Analytics</p>';
        foreach (['overview'=>'Overview','pages'=>'Pages','daily'=>'Daily statistics','dimensions'=>'Sources & dimensions','automation'=>'Automation & AI agents','reach'=>'Daily Reach','privacy'=>'Privacy settings','maintenance'=>'Maintenance','audit'=>'Privacy audit','integration'=>'Integration','account'=>'Account','diagnostics'=>'Diagnostics'] as $key => $label) {
            if ($key === 'privacy') echo '<p class="nav-label">Administration</p>';
            $url = match ($key) { 'account', 'diagnostics', 'audit' => $key . '.php', default => 'admin.php#' . $key };
            echo '<a href="' . escape($url) . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . escape($label) . '</a>';
        }
        echo '</nav></details><p class="sidebar-note">Self-hosted analytics.<br>Your data stays with you.</p></aside>';
    }
    echo '<main id="content" class="workspace' . ($navigation ? '' : ' standalone') . '"><header class="page-header"><div><p class="eyebrow">BARELYTICS / ADMINISTRATION</p><h1>' . escape($title) . '</h1></div>' . ($navigation ? '<a href="admin.php" id="return-link">Back to administration →</a>' : '') . '</header>';
    if ($navigation) echo '<script type="module" src="' . escape(adminAsset('admin-ui/app.js')) . '"></script>';
}

function adminPageEnd(): void { echo '</main></div></body></html>'; }
