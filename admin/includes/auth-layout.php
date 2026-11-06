<?php
/**
 * Minimal centred layout for login / setup pages.
 * Usage: $authTitle, then include this file with $authBody (callable) or output between header/footer helpers.
 */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Helpers\Url;

function auth_header(string $title, string $subtitle = ''): void
{
    $apiName = \App\Services\SettingsService::get('api_name');
    ?><!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($apiName) ?></title>
<link rel="stylesheet" href="<?= e(Url::asset('css/admin.css')) ?>?v=<?= e(EQF_VERSION) ?>">
<script>(function(){try{var t=localStorage.getItem('eqf-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="brand-mark" aria-hidden="true"></span><span><?= e($apiName) ?></span></div>
  <h1 class="auth-title"><?= e($title) ?></h1>
  <?php if ($subtitle !== ''): ?><p class="auth-subtitle"><?= e($subtitle) ?></p><?php endif; ?>
<?php
}

function auth_footer(): void
{
    ?>
</div>
<script src="<?= e(Url::asset('js/admin.js')) ?>?v=<?= e(EQF_VERSION) ?>"></script>
</body>
</html>
<?php
}
