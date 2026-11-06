<?php
/** EQ Finance — landing page: sends visitors to the documentation. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Location: ' . \App\Helpers\Url::docs(), true, 302);
exit;
