<?php
/**
 * Admin layout header. Expects: $pageTitle (string), $activeNav (string), $eqfAdmin (array|null).
 */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Auth\Session;
use App\Helpers\Url;
use App\Services\SettingsService;

$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';
$flash = Session::pullFlash();
$apiName = SettingsService::get('api_name');
$maintenance = SettingsService::bool('maintenance_mode');
$apiEnabled = SettingsService::bool('api_enabled');

$nav = [
    'Overview' => [
        ['dashboard', 'Dashboard', 'dashboard.php'],
    ],
    'API management' => [
        ['api-keys', 'API Keys', 'api-keys.php'],
        ['api-usage', 'API Usage', 'api-usage.php'],
        ['logs', 'Request Logs', 'logs.php'],
    ],
    'Configuration' => [
        ['settings', 'API Settings', 'settings.php'],
        ['cache-settings', 'Cache Settings', 'cache-settings.php'],
    ],
    'System' => [
        ['status', 'System Status', 'status.php'],
        ['test', 'API Studio', (Url::rootPath() ?: '') . '/test', true],
        ['docs', 'Documentation', Url::docs(), true],
        ['logout', 'Logout', 'logout.php'],
    ],
];
?><!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> · <?= e($apiName) ?> Admin</title>
<link rel="stylesheet" href="<?= e(Url::asset('css/admin.css')) ?>?v=<?= e(EQF_VERSION) ?>">
<script>
(function(){try{var t=localStorage.getItem('eqf-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
</script>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <a href="<?= e(Url::admin('dashboard.php')) ?>" class="brand-link">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="brand-name"><?= e($apiName) ?></span>
      </a>
      <button class="icon-btn sidebar-close" type="button" data-sidebar-toggle aria-label="Close menu">&times;</button>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $group => $items): ?>
        <div class="nav-group">
          <div class="nav-group-title"><?= e($group) ?></div>
          <?php foreach ($items as $item): ?>
            <?php
              [$id, $label, $href] = $item;
              $external = $item[3] ?? false;
              $url = $external ? $href : Url::admin($href);
            ?>
            <a href="<?= e($url) ?>" class="nav-item<?= $activeNav === $id ? ' active' : '' ?>"<?= $external ? ' target="_blank" rel="noopener"' : '' ?>><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="status-row">
        <span class="dot <?= !$apiEnabled ? 'dot-err' : ($maintenance ? 'dot-warn' : 'dot-ok') ?>"></span>
        <span><?= !$apiEnabled ? 'API disabled' : ($maintenance ? 'Maintenance mode' : 'API operational') ?></span>
      </div>
      <div class="muted small">v<?= e(EQF_VERSION) ?></div>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-toggle></div>

  <main class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" type="button" data-sidebar-toggle aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
      <h1 class="page-title"><?= e($pageTitle) ?></h1>
      <div class="topbar-actions">
        <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle colour theme" title="Toggle theme">
          <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
          <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        </button>
        <?php if ($eqfAdmin): ?>
          <div class="user-chip" title="<?= e($eqfAdmin['email']) ?>"><?= e($eqfAdmin['username']) ?></div>
        <?php endif; ?>
      </div>
    </header>

    <div class="content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['text']) ?></div>
      <?php endif; ?>
