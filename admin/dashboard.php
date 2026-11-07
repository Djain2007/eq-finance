<?php
/** EQ Finance — admin dashboard. */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Helpers\Url;
use App\Services\CacheService;
use App\Services\StatsService;

$stats = StatsService::dashboard();
$series = StatsService::dailySeries(14);
$top = StatsService::topSymbols(8, 30);
$recent = StatsService::recentActivity(10);
$cache = CacheService::stats();

$seriesMax = max(1, max(array_column($series, 'total')));
$topMax = max(1, (int) ($top[0]['total'] ?? 1));
$successRate = $stats['requests_total'] > 0 ? round($stats['requests_ok'] / $stats['requests_total'] * 100, 1) : null;
$cacheRate = ($stats['cache_hits'] + $stats['cache_misses']) > 0 ? round($stats['cache_hits'] / ($stats['cache_hits'] + $stats['cache_misses']) * 100, 1) : null;

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="stat-grid">
  <a class="stat stat-clickable" href="<?= e(Url::admin('api-keys.php')) ?>" title="Manage API keys">
    <div class="stat-label">Active API keys</div>
    <div class="stat-value"><?= fmt_num($stats['keys_active']) ?> <span class="stat-unit">/ <?= fmt_num($stats['keys_total']) ?> total</span></div>
    <div class="stat-sub"><?= fmt_num($stats['keys_disabled']) ?> disabled · <?= fmt_num($stats['keys_revoked']) ?> revoked<?= $stats['keys_expired'] > 0 ? ' · ' . fmt_num($stats['keys_expired']) . ' expired' : '' ?></div>
  </a>
  <a class="stat stat-clickable" href="<?= e(Url::admin('api-usage.php')) ?>" title="View API usage">
    <div class="stat-label">Requests today</div>
    <div class="stat-value"><?= fmt_num($stats['requests_today']) ?></div>
    <div class="stat-sub"><?= fmt_num($stats['requests_month']) ?> this month · <?= fmt_num($stats['requests_total']) ?> all time</div>
  </a>
  <div class="stat">
    <div class="stat-label">Success rate</div>
    <div class="stat-value"><?= $successRate === null ? '—' : e($successRate) . '%' ?></div>
    <div class="stat-sub"><?= fmt_num($stats['requests_ok']) ?> successful · <?= fmt_num($stats['requests_failed']) ?> failed</div>
  </div>
  <a class="stat stat-clickable" href="<?= e(Url::admin('cache-settings.php')) ?>" title="Inspect cache settings">
    <div class="stat-label">Cache hit rate</div>
    <div class="stat-value"><?= $cacheRate === null ? '—' : e($cacheRate) . '%' ?></div>
    <div class="stat-sub"><?= fmt_num($cache['fresh']) ?> fresh entries · avg <?= $stats['avg_ms'] === null ? '—' : e($stats['avg_ms']) . ' ms' ?></div>
  </a>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Requests — last 14 days</h2><span class="muted small">UTC days</span></div>
    <div class="bars" role="img" aria-label="Daily request volume">
      <?php foreach ($series as $pt): ?>
        <?php $h = (int) round($pt['total'] / $seriesMax * 100); $fh = $pt['total'] > 0 ? (int) round($pt['failed'] / $pt['total'] * 100) : 0; ?>
        <div class="bar-col" title="<?= e($pt['date']) ?>: <?= fmt_num($pt['total']) ?> requests, <?= fmt_num($pt['failed']) ?> failed">
          <div class="bar" style="height:<?= $h ?>%"><div class="bar-fail" style="height:<?= $fh ?>%"></div></div>
          <div class="bar-label"><?= e(date('d', strtotime($pt['date']))) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legend"><span><i class="sw sw-primary"></i>Total</span><span><i class="sw sw-err"></i>Failed</span></div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Most requested symbols</h2><span class="muted small">last 30 days</span></div>
    <?php if (!$top): ?>
      <p class="empty">No stock requests yet.</p>
    <?php else: ?>
      <div class="hbars">
        <?php foreach ($top as $row): ?>
          <div class="hbar-row">
            <div class="hbar-label mono"><?= e($row['symbol']) ?></div>
            <div class="hbar-track"><div class="hbar" style="width:<?= (int) round($row['total'] / $topMax * 100) ?>%"></div></div>
            <div class="hbar-value"><?= fmt_num($row['total']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>Recent API activity</h2><a class="link" href="<?= e(Url::admin('logs.php')) ?>">View all logs</a></div>
  <?php if (!$recent): ?>
    <p class="empty">No requests recorded yet. Create an API key and call <code><?= e(Url::api('stock?symbol=NSE:RELIANCE')) ?></code>.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Time</th><th>Key</th><th>Endpoint</th><th>Symbol</th><th>Status</th><th>Cache</th><th class="num">Duration</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td title="<?= e(fmt_dt($r['created_at'], 'Y-m-d H:i:s')) ?>"><?= e(fmt_ago($r['created_at'])) ?></td>
            <td><?= $r['key_name'] !== null ? e($r['key_name']) . ' <span class="muted mono small">' . e($r['key_prefix']) . '…</span>' : '<span class="muted">—</span>' ?></td>
            <td class="mono"><?= e($r['endpoint']) ?></td>
            <td class="mono"><?= e($r['requested_symbol'] ?? '—') ?></td>
            <td><?= status_badge((int) $r['response_status']) ?><?= $r['error_code'] ? ' <span class="muted small">' . e($r['error_code']) . '</span>' : '' ?></td>
            <td><?= e($r['cache_status']) ?></td>
            <td class="num"><?= fmt_num($r['response_time_ms']) ?> ms</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
