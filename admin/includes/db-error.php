<?php
/** Shown when the database cannot be reached. Reveals nothing sensitive. */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>EQ Finance — Database unavailable</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f6f7f9;color:#1a1d21;margin:0;display:grid;place-items:center;min-height:100vh}.box{background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:32px;max-width:520px}h1{font-size:20px;margin:0 0 12px}code{background:#f0f2f5;padding:2px 6px;border-radius:4px}</style></head>
<body><div class="box"><h1>Database connection failed</h1>
<p>EQ Finance could not connect to MySQL. Check the <code>db</code> section of your configuration file (host, database name, user, password) and make sure the database user has been granted privileges on the database.</p>
<p>The exact error has been written to <code>storage/logs/php-errors.log</code> when that folder is writable.</p></div></body></html>
