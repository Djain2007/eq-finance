<?php
/** EQ Finance — API settings (general, limits, upstream) + admin password change. */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Auth\AdminAuth;
use App\Auth\Csrf;
use App\Auth\Session;
use App\Helpers\Url;
use App\Services\SettingsService;

$errors = [];
$groups = [
    'General' => ['api_name', 'api_description', 'api_version', 'api_enabled', 'maintenance_mode'],
    'Requests & limits' => ['max_batch_size', 'default_requests_per_minute', 'default_requests_per_day', 'default_monthly_limit', 'request_timeout_seconds', 'connect_timeout_seconds'],
    'Upstream identification' => ['user_agent', 'accept_language'],
    'Retention' => ['log_retention_days'],
];
$editable = array_merge(...array_values($groups));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = post_str('form', 20);
    if ($form === 'settings') {
        $input = [];
        foreach ($editable as $key) {
            $type = SettingsService::DEFINITIONS[$key][1];
            $input[$key] = $type === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : post_str($key, 500);
        }
        $errors = SettingsService::update($input);
        if ($errors === []) {
            Session::flash('success', 'Settings saved.');
            Url::redirect(Url::admin('settings.php'));
        }
    } elseif ($form === 'password') {
        $errors = AdminAuth::changePassword(
            (int) $eqfAdmin['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if ($errors === []) {
            Session::flash('success', 'Password changed.');
            Url::redirect(Url::admin('settings.php'));
        }
    }
}

$values = SettingsService::all();
$pageTitle = 'API Settings';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="plain"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form">
  <?= Csrf::field() ?>
  <input type="hidden" name="form" value="settings">
  <?php foreach ($groups as $title => $keys): ?>
    <section class="card">
      <div class="card-head"><h2><?= e($title) ?></h2></div>
      <div class="settings-grid">
        <?php foreach ($keys as $key): ?>
          <?php [$default, $type, $label, $help] = SettingsService::DEFINITIONS[$key]; ?>
          <div class="setting">
            <?php if ($type === 'bool'): ?>
              <label class="switch"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= in_array($values[$key], ['1', 'true', 'on'], true) ? ' checked' : '' ?>><span class="switch-ui"></span><span class="switch-label"><?= e($label) ?></span></label>
            <?php elseif ($type === 'int'): ?>
              <label class="field"><span><?= e($label) ?></span><input type="number" name="<?= e($key) ?>" min="0" max="999999999" value="<?= e($values[$key]) ?>"></label>
            <?php else: ?>
              <label class="field"><span><?= e($label) ?></span><input type="text" name="<?= e($key) ?>" maxlength="500" value="<?= e($values[$key]) ?>"></label>
            <?php endif; ?>
            <p class="help"><?= e($help) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  <div class="form-actions"><button type="submit" class="btn btn-primary">Save settings</button></div>
</form>

<section class="card">
  <div class="card-head"><h2>Administrator password</h2><span class="muted small">Signed in as <?= e($eqfAdmin['username']) ?></span></div>
  <form method="post" class="form form-narrow">
    <?= Csrf::field() ?>
    <input type="hidden" name="form" value="password">
    <label class="field"><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"></label>
    <label class="field"><span>New password <small class="muted">(min. 12 characters)</small></span><input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
    <label class="field"><span>Confirm new password</span><input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>
    <button type="submit" class="btn btn-secondary">Change password</button>
  </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
