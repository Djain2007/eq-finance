<?php
/**
 * EQ Finance — initial administrator setup.
 * Available ONLY while the admins table is empty; disabled automatically afterwards.
 */
declare(strict_types=1);
$eqfPublicPage = true;
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/auth-layout.php';

use App\Auth\AdminAuth;
use App\Auth\Csrf;
use App\Auth\Session;
use App\Helpers\Url;

$count = AdminAuth::adminCount();
if ($count < 0) {
    http_response_code(503);
    require __DIR__ . '/includes/db-error.php';
    exit;
}
if ($count > 0) {
    http_response_code(403);
    auth_header('Setup disabled', 'An administrator account already exists.');
    echo '<p><a class="btn btn-primary" href="' . e(Url::admin('login.php')) . '">Go to login</a></p>';
    auth_footer();
    exit;
}

$errors = [];
$username = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post_str('username', 50);
    $email = post_str('email', 190);
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $errors = AdminAuth::validateCredentials($username, $email, $password, $confirm);
    if ($errors === []) {
        // Re-check inside a transaction to defeat a race between two setup submissions.
        $pdo = \App\Database\Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT COUNT(*) FROM admins FOR UPDATE');
            if (AdminAuth::adminCount() === 0) {
                AdminAuth::create($username, $email, $password);
                $pdo->commit();
                Session::flash('success', 'Administrator account created. Sign in to continue.');
                Url::redirect(Url::admin('login.php'));
            }
            $pdo->rollBack();
            $errors[] = 'An administrator was created by another request. Please sign in.';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            \App\Helpers\Logger::exception($e, 'setup');
            $errors[] = 'Could not create the account. Check the logs for details.';
        }
    }
}

auth_header('Create administrator', 'This one-time step creates the first admin account and then disables itself.');
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="plain"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" class="form" autocomplete="off">
  <?= Csrf::field() ?>
  <label class="field"><span>Username</span><input type="text" name="username" value="<?= e($username) ?>" required minlength="3" maxlength="50" autofocus></label>
  <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($email) ?>" required maxlength="190"></label>
  <label class="field"><span>Password <small class="muted">(minimum 12 characters)</small></span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
  <label class="field"><span>Confirm password</span><input type="password" name="password_confirm" required minlength="12" autocomplete="new-password"></label>
  <button type="submit" class="btn btn-primary btn-block">Create account</button>
</form>
<?php auth_footer(); ?>
