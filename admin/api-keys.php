<?php
/** EQ Finance — API key management. */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';

use App\Auth\Csrf;
use App\Auth\Session;
use App\Helpers\Url;
use App\Services\ApiKeyService;
use App\Services\RateLimitService;
use App\Services\SettingsService;

$errors = [];
$newKey = null; // plaintext shown exactly once

/** Parse the limit/expiry fields shared by create and edit forms. */
function key_form_values(array &$errors): array
{
    $v = [
        'name'                => post_str('name', 100),
        'requests_per_minute' => post_str('requests_per_minute', 12),
        'requests_per_day'    => post_str('requests_per_day', 12),
        'monthly_limit'       => post_str('monthly_limit', 12),
        'expires_at'          => null,
    ];
    if ($v['name'] === '') {
        $errors[] = 'Give the key a name.';
    }
    foreach (['requests_per_minute', 'requests_per_day', 'monthly_limit'] as $f) {
        if ($v[$f] !== '' && !preg_match('/^\d{1,9}$/', $v[$f])) {
            $errors[] = 'Limits must be whole numbers (0 or blank = unlimited).';
            break;
        }
    }
    $exp = post_str('expires_at', 25);
    if ($exp !== '') {
        $ts = \DateTime::createFromFormat('Y-m-d\TH:i', $exp, new \DateTimeZone('UTC'))
            ?: \DateTime::createFromFormat('Y-m-d', $exp, new \DateTimeZone('UTC'));
        if (!$ts) {
            $errors[] = 'Expiry date is not valid.';
        } elseif ($ts->getTimestamp() <= time()) {
            $errors[] = 'Expiry date must be in the future.';
        } else {
            $v['expires_at'] = $ts->format('Y-m-d H:i:s');
        }
    }
    return $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 20);
    $id = (int) post_str('id', 12);
    try {
        switch ($action) {
            case 'create':
                $v = key_form_values($errors);
                if ($errors === []) {
                    $newKey = ApiKeyService::create($v);
                    Session::flash('success', 'API key "' . $v['name'] . '" created. Copy it now — it will not be shown again.');
                }
                break;
            case 'update':
                $v = key_form_values($errors);
                if ($errors === [] && $id > 0) {
                    ApiKeyService::update($id, $v);
                    Session::flash('success', 'API key updated.');
                    Url::redirect(Url::admin('api-keys.php'));
                }
                break;
            case 'disable':
            case 'enable':
                if ($id > 0 && ApiKeyService::setStatus($id, $action === 'enable' ? 'active' : 'disabled')) {
                    Session::flash('success', 'API key ' . ($action === 'enable' ? 'enabled' : 'disabled') . '.');
                }
                Url::redirect(Url::admin('api-keys.php'));
            case 'revoke':
                if ($id > 0) {
                    ApiKeyService::revoke($id);
                    Session::flash('success', 'API key revoked. It can no longer be used.');
                }
                Url::redirect(Url::admin('api-keys.php'));
            case 'rotate':
                if ($id > 0) {
                    $newKey = ApiKeyService::rotate($id);
                    if ($newKey) {
                        Session::flash('success', 'Key rotated. The old key is revoked; copy the replacement now — it will not be shown again.');
                    } else {
                        $errors[] = 'That key cannot be rotated.';
                    }
                }
                break;
            default:
                $errors[] = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        \App\Helpers\Logger::exception($e, 'api-keys');
        $errors[] = 'The operation failed. Check storage/logs/app.log for details.';
    }
}

$editId = (int) get_str('edit', 12);
$editKey = $editId > 0 ? ApiKeyService::find($editId) : null;
if ($editKey && $editKey['status'] === 'revoked') {
    $editKey = null;
}

$counts = ApiKeyService::counts();
$statusFilter = get_str('status', 10);
if ($statusFilter === '' && get_str('show', 10) === 'all') {
    $statusFilter = 'all';
}
if (!in_array($statusFilter, ['all', 'active', 'disabled', 'revoked', 'expired'], true)) {
    $statusFilter = 'all';
}

$keys = ApiKeyService::all($statusFilter === 'all' ? null : $statusFilter);
$defaults = [
    'requests_per_minute' => SettingsService::get('default_requests_per_minute'),
    'requests_per_day'    => SettingsService::get('default_requests_per_day'),
    'monthly_limit'       => SettingsService::get('default_monthly_limit'),
];

$pageTitle = 'API Keys';
$activeNav = 'api-keys';
require __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="plain"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($newKey): ?>
  <section class="card card-accent">
    <div class="card-head"><h2>Your new API key</h2></div>
    <p>This is the only time the full key will be displayed. Store it somewhere safe.</p>
    <div class="key-reveal">
      <code id="new-key" class="mono"><?= e($newKey['key']) ?></code>
      <button type="button" class="btn btn-secondary" data-copy="#new-key">Copy</button>
    </div>
    <p class="muted small">Use it as <code>Authorization: Bearer <?= e($newKey['key']) ?></code></p>
  </section>
<?php endif; ?>

<div class="grid-side">
  <section class="card">
    <div class="card-head"><h2><?= $editKey ? 'Edit key' : 'Create API key' ?></h2>
      <?php if ($editKey): ?><a class="link" href="<?= e(Url::admin('api-keys.php')) ?>">Cancel</a><?php endif; ?>
    </div>
    <form method="post" class="form">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="<?= $editKey ? 'update' : 'create' ?>">
      <?php if ($editKey): ?><input type="hidden" name="id" value="<?= (int) $editKey['id'] ?>"><?php endif; ?>
      <label class="field"><span>Name</span><input type="text" name="name" required maxlength="100" placeholder="e.g. Mobile app (production)" value="<?= e($editKey['name'] ?? post_str('name')) ?>"></label>
      <div class="field-row">
        <label class="field"><span>Per minute</span><input type="number" name="requests_per_minute" min="0" max="999999999" placeholder="unlimited" value="<?= e($editKey ? (string) ($editKey['requests_per_minute'] ?? '') : $defaults['requests_per_minute']) ?>"></label>
        <label class="field"><span>Per day</span><input type="number" name="requests_per_day" min="0" max="999999999" placeholder="unlimited" value="<?= e($editKey ? (string) ($editKey['requests_per_day'] ?? '') : $defaults['requests_per_day']) ?>"></label>
        <label class="field"><span>Per month</span><input type="number" name="monthly_limit" min="0" max="999999999" placeholder="unlimited" value="<?= e($editKey ? (string) ($editKey['monthly_limit'] ?? '') : $defaults['monthly_limit']) ?>"></label>
      </div>
      <p class="muted small">Leave blank or 0 for unlimited. Each symbol in a batch call counts as one request.</p>
      <label class="field"><span>Expires (UTC, optional)</span><input type="datetime-local" name="expires_at" value="<?= e($editKey && $editKey['expires_at'] ? date('Y-m-d\TH:i', strtotime($editKey['expires_at'] . ' UTC')) : '') ?>"></label>
      <button type="submit" class="btn btn-primary"><?= $editKey ? 'Save changes' : 'Create key' ?></button>
    </form>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Keys <span class="badge badge-muted"><?= count($keys) ?></span></h2>
      </div>
      <div class="filter-pills" role="tablist">
        <a href="<?= e(Url::admin('api-keys.php?status=all')) ?>" class="filter-pill<?= $statusFilter === 'all' ? ' active' : '' ?>">All <span class="pill-count"><?= $counts['total'] ?></span></a>
        <a href="<?= e(Url::admin('api-keys.php?status=active')) ?>" class="filter-pill<?= $statusFilter === 'active' ? ' active' : '' ?>">Active <span class="pill-count"><?= $counts['active'] ?></span></a>
        <a href="<?= e(Url::admin('api-keys.php?status=disabled')) ?>" class="filter-pill<?= $statusFilter === 'disabled' ? ' active' : '' ?>">Disabled <span class="pill-count"><?= $counts['disabled'] ?></span></a>
        <a href="<?= e(Url::admin('api-keys.php?status=revoked')) ?>" class="filter-pill<?= $statusFilter === 'revoked' ? ' active' : '' ?>">Revoked <span class="pill-count"><?= $counts['revoked'] ?></span></a>
        <?php if ($counts['expired'] > 0): ?>
          <a href="<?= e(Url::admin('api-keys.php?status=expired')) ?>" class="filter-pill<?= $statusFilter === 'expired' ? ' active' : '' ?>">Expired <span class="pill-count"><?= $counts['expired'] ?></span></a>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$keys): ?>
      <p class="empty"><?= $statusFilter === 'all' ? 'No API keys yet. Create one with the form.' : 'No ' . e($statusFilter) . ' API keys found.' ?></p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Name</th><th>Status</th><th>Limits (min / day / month)</th><th>Used now</th><th>Created</th><th>Expires</th><th>Last used</th><th class="num">Requests</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
          <?php $usage = $k['status'] === 'revoked' ? null : RateLimitService::usage((int) $k['id']); ?>
          <tr class="<?= $k['status'] === 'revoked' ? 'row-muted' : '' ?>">
            <td><strong><?= e($k['name']) ?></strong><div class="mono small muted"><?= e($k['key_prefix']) ?>…</div></td>
            <td><?= key_badge($k['status']) ?><?php if ($k['status'] === 'active' && $k['expires_at'] && $k['expires_at'] <= gmdate('Y-m-d H:i:s')): ?> <span class="badge badge-warn">Expired</span><?php endif; ?></td>
            <td class="small"><?= e(fmt_limit($k['requests_per_minute'])) ?> / <?= e(fmt_limit($k['requests_per_day'])) ?> / <?= e(fmt_limit($k['monthly_limit'])) ?></td>
            <td class="small"><?= $usage ? e($usage['minute']) . ' / ' . e($usage['day']) . ' / ' . e($usage['month']) : '—' ?></td>
            <td class="small" title="<?= e(fmt_dt($k['created_at'], 'Y-m-d H:i:s')) ?>"><?= e(fmt_dt($k['created_at'], 'M j, Y')) ?></td>
            <td class="small"><?= $k['expires_at'] ? e(fmt_dt($k['expires_at'])) : '<span class="muted">Never</span>' ?></td>
            <td class="small"><?= e(fmt_ago($k['last_used_at'])) ?></td>
            <td class="num"><a class="link" href="<?= e(Url::admin('logs.php?key_id=' . (int) $k['id'])) ?>"><?= fmt_num($k['total_requests']) ?></a></td>
            <td class="actions">
              <?php if ($k['status'] !== 'revoked'): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e(Url::admin('api-keys.php?edit=' . (int) $k['id'])) ?>">Edit</a>
                <form method="post" class="inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <?php if ($k['status'] === 'active'): ?>
                    <button class="btn btn-sm btn-ghost" name="action" value="disable">Disable</button>
                  <?php else: ?>
                    <button class="btn btn-sm btn-ghost" name="action" value="enable">Enable</button>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-ghost" name="action" value="rotate" data-confirm="Rotate this key? The current key stops working immediately and a replacement is generated.">Rotate</button>
                  <button class="btn btn-sm btn-danger-ghost" name="action" value="revoke" data-confirm="Revoke this key permanently? This cannot be undone.">Revoke</button>
                </form>
              <?php else: ?>
                <span class="muted small">Revoked <?= e(fmt_dt($k['revoked_at'], 'M j, Y')) ?><?= $k['replaced_by_id'] ? ' · rotated' : '' ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
