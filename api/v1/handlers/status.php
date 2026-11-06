<?php
/** GET /api/v1/status — public health check (no API key required). */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');

use App\Database\Database;
use App\Helpers\Response;
use App\Services\SettingsService;

$dbOk = Database::ping();
$apiEnabled = $dbOk && SettingsService::bool('api_enabled');
$maintenance = $dbOk && SettingsService::bool('maintenance_mode');

$status = !$dbOk ? 'degraded' : (!$apiEnabled ? 'disabled' : ($maintenance ? 'maintenance' : 'ok'));

Response::success([
    'status'      => $status,
    'name'        => $dbOk ? SettingsService::get('api_name') : EQF_NAME,
    'api_version' => $dbOk ? SettingsService::get('api_version') : EQF_VERSION,
    'service_available' => $apiEnabled && !$maintenance,
    'database'    => $dbOk ? 'connected' : 'unavailable',
    'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
], ['source' => 'EQ Finance'], $dbOk ? 200 : 503);
