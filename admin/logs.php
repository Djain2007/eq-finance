<?php
/** EQ Finance — request logs with filters and pagination. */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Helpers\Url;
use App\Services\ApiKeyService;
use App\Services\StatsService;

$filters = [
    'key_id'   => (int) get_str('key_id', 12),
    'endpoint' => in_array(get_str('endpoint', 10), ['stock', 'stocks', 'search', 'status'], true) ? get_str('endpoint', 10) : '',
    'status'   => get_str('status', 8),
    'from'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', get_str('from', 10)) ? get_str('from', 10) : '',
    'to'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', get_str('to', 10)) ? get_str('to', 10) : '',
    'symbol'   => strtoupper(preg_replace('/[^A-Za-z0-9:.\-_&,]/', '', get_str('symbol', 64)) ?? ''),
];
$page = max(1, (int) get_str('page', 6));
$result = StatsService::logs($filters, $page, 50);
$keys = ApiKeyService::all();

$pageTitle = 'Request Logs';
$activeNav = 'logs';
require __DIR__ . '/includes/header.php';
?>

<section class="card">
  <form method="get" class="filters">
    <label class="field"><span>API key</span>
      <select name="key_id">
        <option value="">All keys</option>
        <?php foreach ($keys as $k): ?>
          <option value="<?= (int) $k['id'] ?>"<?= $filters['key_id'] === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['name']) ?> (<?= e($k['key_prefix']) ?>…)</option>
        <?php endforeach; ?>
      </select></label>
    <label class="field"><span>Endpoint</span>
      <select name="endpoint">
        <option value="">All</option>
        <?php foreach (['stock', 'stocks', 'search', 'status'] as $ep): ?>
          <option value="<?= $ep ?>"<?= $filters['endpoint'] === $ep ? ' selected' : '' ?>>/<?= $ep ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="field"><span>Status</span>
      <select name="status">
        <option value="">All</option>
        <option value="success"<?= $filters['status'] === 'success' ? ' selected' : '' ?>>Success (2xx)</option>
        <option value="client"<?= $filters['status'] === 'client' ? ' selected' : '' ?>>Client error (4xx)</option>
        <option value="server"<?= $filters['status'] === 'server' ? ' selected' : '' ?>>Server/upstream (5xx)</option>
        <?php foreach ([401, 403, 429, 502] as $code): ?>
          <option value="<?= $code ?>"<?= $filters['status'] === (string) $code ? ' selected' : '' ?>><?= $code ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="field"><span>From</span><input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
    <label class="field"><span>To</span><input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    <label class="field"><span>Symbol</span><input type="text" name="symbol" value="<?= e($filters['symbol']) ?>" placeholder="NSE:RELIANCE"></label>
    <div class="filter-actions">
      <button class="btn btn-primary" type="submit">Filter</button>
      <a class="btn btn-ghost" href="<?= e(Url::admin('logs.php')) ?>">Reset</a>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head"><h2><?= fmt_num($result['total']) ?> request<?= $result['total'] === 1 ? '' : 's' ?></h2><span class="muted small">Page <?= $result['page'] ?> of <?= $result['pages'] ?></span></div>
  <?php if (!$result['rows']): ?>
    <p class="empty">No requests match these filters.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Time (<?= e(\App\Config\Config::get('app.display_timezone', 'UTC')) ?>)</th><th>Key</th><th>Endpoint</th><th>Symbol</th><th>Status</th><th>Error</th><th>Cache</th><th class="num">Duration</th></tr></thead>
      <tbody>
      <?php foreach ($result['rows'] as $r): ?>
        <tr>
          <td class="small nowrap"><?= e(fmt_dt($r['created_at'], 'Y-m-d H:i:s')) ?></td>
          <td class="small"><?= $r['key_name'] !== null ? e($r['key_name']) . ' <span class="mono muted">' . e($r['key_prefix']) . '…</span>' : '<span class="muted">unauthenticated</span>' ?></td>
          <td class="mono">/<?= e($r['endpoint']) ?></td>
          <td class="mono small"><?= e($r['requested_symbol'] ?? '—') ?></td>
          <td><?= status_badge((int) $r['response_status']) ?></td>
          <td class="small mono"><?= e($r['error_code'] ?? '') ?></td>
          <td class="small"><?= e($r['cache_status']) ?></td>
          <td class="num small"><?= fmt_num($r['response_time_ms']) ?> ms</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($result['pages'] > 1): ?>
    <div class="pagination">
      <a class="btn btn-sm btn-ghost<?= $result['page'] <= 1 ? ' disabled' : '' ?>" href="<?= e(Url::admin('logs.php') . qs(['page' => max(1, $result['page'] - 1)])) ?>">Previous</a>
      <span class="muted small">Page <?= $result['page'] ?> / <?= $result['pages'] ?></span>
      <a class="btn btn-sm btn-ghost<?= $result['page'] >= $result['pages'] ? ' disabled' : '' ?>" href="<?= e(Url::admin('logs.php') . qs(['page' => min($result['pages'], $result['page'] + 1)])) ?>">Next</a>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
