<?php
/** EQ Finance — cache settings and maintenance. */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Auth\Csrf;
use App\Auth\Session;
use App\Helpers\Url;
use App\Services\CacheService;
use App\Services\RateLimitService;
use App\Services\SettingsService;
use App\Services\SymbolValidator;
use App\Services\UsageLogger;

$errors = [];
$keys = ['cache_ttl_seconds', 'cache_serve_stale', 'cache_max_stale_seconds', 'cache_retention_seconds'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = post_str('form', 20);
    if ($form === 'settings') {
        $input = [];
        foreach ($keys as $key) {
            $type = SettingsService::DEFINITIONS[$key][1];
            $input[$key] = $type === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : post_str($key, 20);
        }
        $errors = SettingsService::update($input);
        if ($errors === []) {
            Session::flash('success', 'Cache settings saved.');
            Url::redirect(Url::admin('cache-settings.php'));
        }
    } elseif ($form === 'purge') {
        $symbol = post_str('symbol', 48);
        if ($symbol === '') {
            $n = CacheService::purge();
            Session::flash('success', 'Cache cleared (' . $n . ' entries removed).');
        } else {
            $v = SymbolValidator::validate($symbol);
            if ($v['ok']) {
                $n = CacheService::purge($v['canonical']);
                Session::flash($n ? 'success' : 'warning', $n ? 'Cache entry for ' . $v['canonical'] . ' removed.' : 'No cache entry found for ' . $v['canonical'] . '.');
            } else {
                Session::flash('danger', $v['message']);
            }
        }
        Url::redirect(Url::admin('cache-settings.php'));
    } elseif ($form === 'cleanup') {
        $removed = CacheService::cleanup(SettingsService::int('cache_retention_seconds'));
        $rl = RateLimitService::cleanup();
        $logs = UsageLogger::cleanup(SettingsService::int('log_retention_days'));
        Session::flash('success', "Cleanup finished: $removed cache rows, $rl rate-limit counters, $logs log rows removed.");
        Url::redirect(Url::admin('cache-settings.php'));
    }
}

$values = SettingsService::all();
$stats = CacheService::stats();
$pageTitle = 'Cache Settings';
$activeNav = 'cache-settings';
require __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="plain"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><div class="stat-label">Cached symbols</div><div class="stat-value"><?= fmt_num($stats['total']) ?></div><div class="stat-sub"><?= fmt_num($stats['fresh']) ?> fresh · <?= fmt_num($stats['expired']) ?> expired</div></div>
  <div class="stat"><div class="stat-label">Cache hits served</div><div class="stat-value"><?= fmt_num($stats['hits']) ?></div><div class="stat-sub">since entries were last refreshed</div></div>
  <div class="stat"><div class="stat-label">Cache duration</div><div class="stat-value"><?= e($values['cache_ttl_seconds']) ?>s</div><div class="stat-sub"><?= $values['cache_serve_stale'] === '1' ? 'stale fallback on' : 'stale fallback off' ?></div></div>
</div>

<div class="grid-side">
  <form method="post" class="form">
    <?= Csrf::field() ?>
    <input type="hidden" name="form" value="settings">
    <section class="card">
      <div class="card-head"><h2>Caching</h2></div>
      <div class="settings-grid">
        <?php foreach ($keys as $key): ?>
          <?php [$default, $type, $label, $help] = SettingsService::DEFINITIONS[$key]; ?>
          <div class="setting">
            <?php if ($type === 'bool'): ?>
              <label class="switch"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= $values[$key] === '1' ? ' checked' : '' ?>><span class="switch-ui"></span><span class="switch-label"><?= e($label) ?></span></label>
            <?php else: ?>
              <label class="field"><span><?= e($label) ?></span><input type="number" name="<?= e($key) ?>" min="0" max="999999999" value="<?= e($values[$key]) ?>"></label>
            <?php endif; ?>
            <p class="help"><?= e($help) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="muted small">Set the cache duration to 0 to disable caching (every request hits the data source — not recommended).</p>
      <button type="submit" class="btn btn-primary">Save cache settings</button>
    </section>
  </form>

  <div>
    <section class="card">
      <div class="card-head"><h2>Purge cache</h2></div>
      <form method="post" class="form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form" value="purge">
        <label class="field"><span>Symbol <small class="muted">(leave blank to clear everything)</small></span><input type="text" name="symbol" placeholder="NSE:RELIANCE"></label>
        <button type="submit" class="btn btn-secondary" data-confirm="Purge cached quotes?">Purge</button>
      </form>
    </section>
    <section class="card">
      <div class="card-head"><h2>Maintenance</h2></div>
      <p class="small">Removes expired cache rows, stale rate-limit counters and old usage logs according to the retention settings. The same job runs from <code>cron/cleanup.php</code>.</p>
      <form method="post" class="form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form" value="cleanup">
        <button type="submit" class="btn btn-secondary">Run cleanup now</button>
      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
