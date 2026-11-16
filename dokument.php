<?php
/** Abgelegte Datei anzeigen oder herunterladen (nur angemeldet) */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
$b = benutzer();
if (!$b) { header('Location: login.php'); exit; }
if (!darf('pflege') && !darf('organisation') && !darf('techniker') && !darf('auswertung')) { http_response_code(403); exit('Kein Zugriff.'); }
$d = eine('SELECT * FROM dokumente WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
$pfad = $d ? realpath(dok_ordner() . '/' . $d['datei']) : false;
if (!$d || !$pfad || !str_starts_with($pfad, realpath(dok_ordner()) . DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Datei nicht gefunden.'); }
header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . filesize($pfad));
header('X-Content-Type-Options: nosniff');
$name = $d['original'] ?: basename($d['datei']);
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
readfile($pfad);
