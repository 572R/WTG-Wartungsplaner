<?php
/** Lädt alle Bausteine. Jede Seite beginnt mit: require __DIR__ . '/inc/start.php'; */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit; }
require __DIR__ . '/app.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/geo.php';
require __DIR__ . '/microsoft.php';
require __DIR__ . '/plan.php';
