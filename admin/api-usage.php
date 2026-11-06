<?php
/** EQ Finance — API usage overview (per key, per endpoint, errors). */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Helpers\Url;
use App\Services\StatsService;

$days = (int) get_str('days', 3);
$days = in_array($days, [7, 30, 90], true) ? $days : 30;

$perKey = StatsService::perKeyUsage($days);
$endpoints = StatsService::endpointBreakdown($days);
$errors = StatsService::errorBreakdown($days);
$series = StatsService::dailySeries(min($days, 30));
$seriesMax = max(1, max(array_column($series, 'total')));
$endpointMax = max(1, (int) ($endpoints[0]['total'] ?? 1));
$errorMax = max(1, (int) ($errors[0]['total'] ?? 1));

$pageTitle = 'API Usage';
$activeNav = 'api-usage';
require __DIR__ . '/includes/header.php';
?>

<div class="toolbar">
  <div class="segmented">
    <?php foreach ([7, 30, 90] as $d): ?>
      <a href="<?= e(Url::admin('api-usage.php?days=' . $d)) ?>" class="<?= $days === $d ? 'active' : '' ?>">Last <?= $d ?> days</a>
    <?php endforeach; ?>
  </div>
</div>

<section class="card">
  <div class="card-head"><h2>Daily volume</h2><span class="muted small">last <?= count($series) ?> days (UTC)</span></div>
  <div class="bars bars-tall" role="img" aria-label="Daily request volume">
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
  <div class="card-head"><h2>Usage by API key</h2></div>
  <?php if (!$perKey): ?><p class="empty">No API keys yet.</p><?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Key</th><th>Status</th><th class="num">Requests</th><th class="num">Failed</th><th class="num">Today</th><th>Last used</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($perKey as $k): ?>
        <tr>
          <td><strong><?= e($k['name']) ?></strong> <span class="mono small muted"><?= e($k['key_prefix']) ?>…</span></td>
          <td><?= key_badge($k['status']) ?></td>
          <td class="num"><?= fmt_num($k['total']) ?></td>
          <td class="num"><?= fmt_num($k['failed']) ?></td>
          <td class="num"><?= fmt_num($k['today']) ?></td>
          <td class="small"><?= e(fmt_ago($k['last_used_at'])) ?></td>
          <td class="actions"><a class="btn btn-sm btn-ghost" href="<?= e(Url::admin('logs.php?key_id=' . (int) $k['id'])) ?>">Logs</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>By endpoint</h2></div>
    <?php if (!$endpoints): ?><p class="empty">No data for this period.</p><?php else: ?>
    <div class="hbars">
      <?php foreach ($endpoints as $r): ?>
        <div class="hbar-row">
          <div class="hbar-label mono">/<?= e($r['endpoint']) ?></div>
          <div class="hbar-track"><div class="hbar" style="width:<?= (int) round($r['total'] / $endpointMax * 100) ?>%"></div></div>
          <div class="hbar-value"><?= fmt_num($r['total']) ?> <span class="muted small">· <?= fmt_num($r['failed']) ?> failed · <?= (int) round((float) $r['avg_ms']) ?> ms</span></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <section class="card">
    <div class="card-head"><h2>Error codes</h2></div>
    <?php if (!$errors): ?><p class="empty">No errors in this period.</p><?php else: ?>
    <div class="hbars">
      <?php foreach ($errors as $r): ?>
        <div class="hbar-row">
          <div class="hbar-label mono small"><?= e($r['error_code']) ?></div>
          <div class="hbar-track"><div class="hbar hbar-err" style="width:<?= (int) round($r['total'] / $errorMax * 100) ?>%"></div></div>
          <div class="hbar-value"><?= fmt_num($r['total']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
