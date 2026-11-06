<?php
/** EQ Finance — admin login. */
declare(strict_types=1);
$eqfPublicPage = true;
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/auth-layout.php';

use App\Auth\AdminAuth;
use App\Auth\Csrf;
use App\Auth\LoginRateLimiter;
use App\Auth\Session;
use App\Helpers\Url;

if (AdminAuth::setupRequired()) {
    Url::redirect(Url::admin('setup.php'));
}
if (AdminAuth::check()) {
    Url::redirect(Url::admin('dashboard.php'));
}

$error = null;
$username = '';
$locked = LoginRateLimiter::lockedFor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post_str('username', 190);
    $password = (string) ($_POST['password'] ?? '');
    if ($locked > 0) {
        $error = 'Too many failed attempts. Try again in ' . (int) ceil($locked / 60) . ' minute(s).';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $admin = AdminAuth::attempt($username, $password);
        if ($admin === null) {
            LoginRateLimiter::recordFailure();
            usleep(random_int(150000, 400000)); // slow down guessing
            $locked = LoginRateLimiter::lockedFor();
            $error = $locked > 0
                ? 'Too many failed attempts. Try again in ' . (int) ceil($locked / 60) . ' minute(s).'
                : 'Invalid username or password.';
        } else {
            LoginRateLimiter::clear();
            AdminAuth::login($admin);
            Url::redirect(Url::admin('dashboard.php'));
        }
    }
}

$flash = Session::pullFlash();
auth_header('Sign in', 'Administrator access');
?>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <label class="field"><span>Username or email</span><input type="text" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username"></label>
  <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
  <button type="submit" class="btn btn-primary btn-block"<?= $locked > 0 ? ' disabled' : '' ?>>Sign in</button>
</form>
<p class="auth-foot"><a href="<?= e(Url::docs()) ?>">API documentation</a></p>
<?php auth_footer(); ?>
