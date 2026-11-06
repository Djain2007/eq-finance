<?php
/** EQ Finance — system status: environment checks and a live upstream probe. */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Auth\Csrf;
use App\Config\Config;
use App\Database\Database;
use App\Helpers\Url;
use App\Services\GoogleFinanceScraper;
use App\Services\SettingsService;
use App\Services\SymbolValidator;

$probe = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('form', 10) === 'probe') {
    $v = SymbolValidator::validate(post_str('symbol', 48) ?: 'NASDAQ:AAPL');
    if (!$v['ok']) {
        $probe = ['ok' => false, 'code' => $v['code'], 'message' => $v['message']];
    } else {
        $t = microtime(true);
        $probe = (new GoogleFinanceScraper())->fetchQuote($v['exchange'], $v['symbol']);
        $probe['ms'] = (int) round((microtime(true) - $t) * 1000);
        $probe['symbol'] = $v['canonical'];
    }
}

$checks = [];
$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
foreach (['curl' => 'cURL', 'dom' => 'DOM', 'pdo_mysql' => 'PDO MySQL', 'mbstring' => 'Multibyte string', 'json' => 'JSON', 'openssl' => 'OpenSSL'] as $ext => $label) {
    $checks[] = [$label . ' extension', extension_loaded($ext) ? 'loaded' : 'missing', extension_loaded($ext)];
}
$dbOk = Database::ping();
$dbVersion = '—';
if ($dbOk) {
    try { $dbVersion = (string) Database::connection()->query('SELECT VERSION()')->fetchColumn(); } catch (\Throwable $e) {}
}
$checks[] = ['Database connection', $dbOk ? 'connected (' . $dbVersion . ')' : 'failed', $dbOk];
$logsWritable = is_dir(EQF_STORAGE . '/logs') && is_writable(EQF_STORAGE . '/logs');
$checks[] = ['Log directory writable', $logsWritable ? 'yes' : 'no (logging falls back to PHP error log)', $logsWritable];
$checks[] = ['HTTPS', Url::isHttps() ? 'yes' : 'no', Url::isHttps() || !Config::get('app.force_https')];
$checks[] = ['Configuration outside document root', str_starts_with((string) realpath(dirname(EQF_ROOT) . '/eq-finance-config.php'), dirname(EQF_ROOT)) && is_file(dirname(EQF_ROOT) . '/eq-finance-config.php') ? 'yes' : 'no (protected by .htaccess instead)', true];
$checks[] = ['Debug mode', Config::get('app.debug') ? 'ON — disable in production' : 'off', !Config::get('app.debug')];
$maxExec = (int) ini_get('max_execution_time');
$checks[] = ['PHP max_execution_time', $maxExec === 0 ? 'unlimited' : $maxExec . 's', $maxExec === 0 || $maxExec >= 20];
$checks[] = ['allow_url_fopen (not required)', ini_get('allow_url_fopen') ? 'on' : 'off', true];

$pageTitle = 'System Status';
$activeNav = 'status';
require __DIR__ . '/includes/header.php';
?>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Environment</h2></div>
    <div class="table-wrap"><table class="table">
      <tbody>
      <?php foreach ($checks as [$label, $value, $ok]): ?>
        <tr><td><?= e($label) ?></td><td><?= e($value) ?></td><td class="num"><span class="dot <?= $ok ? 'dot-ok' : 'dot-warn' ?>"></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Service</h2></div>
    <div class="table-wrap"><table class="table"><tbody>
      <tr><td>API</td><td><?= SettingsService::bool('api_enabled') ? 'enabled' : 'disabled' ?></td></tr>
      <tr><td>Maintenance mode</td><td><?= SettingsService::bool('maintenance_mode') ? 'on' : 'off' ?></td></tr>
      <tr><td>Version</td><td><?= e(SettingsService::get('api_version')) ?> (app <?= e(EQF_VERSION) ?>)</td></tr>
      <tr><td>Base URL</td><td class="mono small"><?= e(Url::api()) ?></td></tr>
      <tr><td>Status endpoint</td><td><a class="link" href="<?= e(Url::api('status')) ?>" target="_blank" rel="noopener">/api/v1/status</a></td></tr>
      <tr><td>Supported exchanges</td><td><?= count(SymbolValidator::allowedExchanges()) ?> (configured in config file)</td></tr>
      <tr><td>Storage path</td><td class="mono small"><?= e(basename(dirname(EQF_STORAGE)) . '/' . basename(EQF_STORAGE)) ?></td></tr>
    </tbody></table></div>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>Upstream probe</h2><span class="muted small">Performs one live request to the data source (bypasses the cache; does not count against any key).</span></div>
  <form method="post" class="form inline-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="form" value="probe">
    <input type="text" name="symbol" value="<?= e($probe['symbol'] ?? 'NASDAQ:AAPL') ?>" class="mono" maxlength="48">
    <button type="submit" class="btn btn-primary">Run probe</button>
  </form>
  <?php if ($probe !== null): ?>
    <?php if ($probe['ok']): ?>
      <div class="alert alert-success">Extraction succeeded in <?= (int) $probe['ms'] ?> ms — <?= e($probe['data']['name'] ?? $probe['symbol']) ?>: <?= e($probe['data']['price']) ?> <?= e($probe['data']['currency'] ?? '') ?></div>
      <details><summary class="link">Show extracted fields</summary>
        <pre class="code-block"><?= e(json_encode($probe['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
      </details>
    <?php else: ?>
      <div class="alert alert-danger"><strong><?= e($probe['code']) ?></strong> — <?= e($probe['message']) ?><?= isset($probe['http_status']) ? ' (HTTP ' . (int) $probe['http_status'] . ')' : '' ?><?= isset($probe['ms']) ? ' · ' . (int) $probe['ms'] . ' ms' : '' ?></div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
