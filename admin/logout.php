<?php
/** EQ Finance — logout. POST (CSRF-protected) is preferred; GET shows a confirmation. */
declare(strict_types=1);
$eqfPublicPage = true;
require __DIR__ . '/includes/init.php';

use App\Auth\AdminAuth;
use App\Auth\Csrf;
use App\Auth\Session;
use App\Helpers\Url;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminAuth::logout();
    session_start();
    Session::flash('success', 'You have been signed out.');
    Url::redirect(Url::admin('login.php'));
}

if (!AdminAuth::check()) {
    Url::redirect(Url::admin('login.php'));
}

require __DIR__ . '/includes/auth-layout.php';
auth_header('Sign out', 'End your administrator session?');
?>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <button type="submit" class="btn btn-primary btn-block">Sign out</button>
  <a class="btn btn-ghost btn-block" href="<?= e(Url::admin('dashboard.php')) ?>">Cancel</a>
</form>
<?php auth_footer(); ?>
