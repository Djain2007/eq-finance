<?php
/** Shown when no configuration file was found. Reveals nothing sensitive. */
declare(strict_types=1);
defined('EQF_ROOT') or exit('Direct access denied.');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>EQ Finance — Not configured</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f6f7f9;color:#1a1d21;margin:0;display:grid;place-items:center;min-height:100vh}.box{background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:32px;max-width:520px}h1{font-size:20px;margin:0 0 12px}code{background:#f0f2f5;padding:2px 6px;border-radius:4px}ol{padding-left:20px}li{margin:6px 0}</style></head>
<body><div class="box"><h1>EQ Finance is not configured yet</h1>
<p>No configuration file was found. To finish installation:</p>
<ol>
<li>Copy <code>config/config.example.php</code> to <code>config/config.php</code> (or to <code>eq-finance-config.php</code> one level above the project folder).</li>
<li>Enter your MySQL host, database name, user and password.</li>
<li>Import <code>database/install.sql</code> into that database (phpMyAdmin → Import).</li>
<li>Reload this page to create the first administrator account.</li>
</ol>
<p>See <code>README.md</code> for the full cPanel installation guide.</p></div></body></html>
