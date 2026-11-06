<?php
/** EQ Finance admin entry: routes to setup, login or dashboard. */
declare(strict_types=1);
$eqfPublicPage = true;
require __DIR__ . '/includes/init.php';

use App\Auth\AdminAuth;
use App\Helpers\Url;

if (AdminAuth::setupRequired()) {
    Url::redirect(Url::admin('setup.php'));
}
Url::redirect(Url::admin(AdminAuth::check() ? 'dashboard.php' : 'login.php'));
