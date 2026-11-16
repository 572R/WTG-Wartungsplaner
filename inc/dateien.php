<?php
/** Lesen von Access-Datenbanken (Microsoft-Treiber oder mdbtools) und Excel-Dateien (.xlsx) */
declare(strict_types=1);

function utf8($v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}
function spalten_schluessel(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/[^a-z0-9]/', '', str_replace(['ß', 'ẞ', 'ä', 'ö', 'ü'], ['ss', 'ss', 'ae', 'oe', 'ue'], $s));
}
function spalten_zuordnen(array $spalten, array $felder): array {
    $von = []; $benutzt = [];
    foreach ($felder as $f => $namen) foreach ($spalten as $s) {
        if (!isset($benutzt[$s]) && in_array(spalten_schluessel($s), $namen, true)) { $von[$f] = $s; $benutzt[$s] = true; break; }
    }
    return [$von, $benutzt];
}
function datum_iso(?string $v): ?string {
    if ($v === null || trim($v) === '') return null;
    $v = trim($v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})/', $v, $m)) { $y = (int)$m[3]; if ($y < 100) $y += 2000; return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]); }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $v, $m)) { $y = (int)$m[3]; if ($y < 100) $y += 2000; return sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]); }
    return null;
}

function access_pdo(string $datei): ?PDO {
    static $pdo = [];
    if (array_key_exists($datei, $pdo)) return $pdo[$datei];
    $pdo[$datei] = null;
    if (!extension_loaded('pdo_odbc')) return null;
    try {
        $pdo[$datei] = new PDO('odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $datei . ';', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) {
        if (!preg_match('/can.t open lib|data source name not found|IM002|01000/i', $e->getMessage())) throw $e;
    }
    return $pdo[$datei];
}

function access_tabelle(string $datei, string $tabelle): array {
    if ($pdo = access_pdo($datei)) {
        $rows = $pdo->query("SELECT * FROM [$tabelle]")->fetchAll();
    } else {
        $mdb = trim((string)shell_exec('command -v mdb-export 2>/dev/null'));
        foreach (['/opt/homebrew/bin/mdb-export', '/usr/local/bin/mdb-export', '/usr/bin/mdb-export'] as $p) if ($mdb === '' && is_executable($p)) $mdb = $p;
        if ($mdb === '') throw new RuntimeException(PHP_OS_FAMILY === 'Windows' ? 'Access-Treiber fehlt: "Microsoft Access Database Engine 2016 Redistributable" (64-Bit) installieren.' : 'mdbtools fehlt: im Terminal "brew install mdbtools" ausführen.');
        $csv = shell_exec(escapeshellarg($mdb) . " -d '\t' -D '%Y-%m-%d' " . escapeshellarg($datei) . ' ' . escapeshellarg($tabelle) . ' 2>/dev/null');
        if (!$csv) throw new RuntimeException("Tabelle \"$tabelle\" konnte nicht gelesen werden.");
        $f = fopen('php://memory', 'r+'); fwrite($f, $csv); rewind($f);
        $kopf = fgetcsv($f, null, "\t", '"', ''); $rows = [];
        while (($z = fgetcsv($f, null, "\t", '"', '')) !== false) {
            if ($z === [null] || count($z) !== count($kopf)) continue;
            $rows[] = array_combine($kopf, array_map(fn($v) => $v === '' ? null : $v, $z));
        }
    }
    return array_map(fn($r) => array_combine(array_map('utf8', array_keys($r)), array_map('utf8', array_values($r))), $rows);
}

/**
 * Erstes Tabellenblatt einer .xlsx-Datei.
 * Rückgabe: ['zeilen' => [zeilennr => [spaltenindex => wert]], 'verbunden' => [[z1,s1,z2,s2], …]] (1-basiert)
 */
function xlsx_lesen(string $datei): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP-Erweiterung "zip" fehlt (php.ini: extension=zip).');
    $z = new ZipArchive();
    if ($z->open($datei) !== true) throw new RuntimeException("Excel-Datei nicht lesbar: $datei");
    $strings = [];
    if (($s = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = simplexml_load_string($s);
        foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $strings[] = $t; }
    }
    // erstes Blatt über workbook.xml + Beziehungen finden
    $blatt = 'xl/worksheets/sheet1.xml';
    $wb = simplexml_load_string((string)$z->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string((string)$z->getFromName('xl/_rels/workbook.xml.rels'));
    if ($wb && $rels) {
        $rid = (string)$wb->sheets->sheet[0]->attributes('r', true)->id;
        foreach ($rels->Relationship as $r) if ((string)$r['Id'] === $rid) $blatt = 'xl/' . ltrim(str_replace('../', '', (string)$r['Target']), '/');
        $blatt = str_replace('xl/xl/', 'xl/', $blatt);
    }
    $x = simplexml_load_string((string)$z->getFromName($blatt));
    $z->close();
    $spalte = function (string $ref): int { preg_match('/^([A-Z]+)/', $ref, $m); $n = 0; foreach (str_split($m[1]) as $c) $n = $n * 26 + ord($c) - 64; return $n; };
    $zeilen = [];
    foreach ($x->sheetData->row as $row) {
        $nr = (int)$row['r'];
        foreach ($row->c as $c) {
            $t = (string)$c['t']; $v = isset($c->v) ? (string)$c->v : (isset($c->is->t) ? (string)$c->is->t : null);
            if ($v === null) continue;
            if ($t === 's') $v = $strings[(int)$v] ?? '';
            elseif ($t === '' || $t === 'n') $v = is_numeric($v) ? $v + 0 : $v;
            $zeilen[$nr][$spalte((string)$c['r'])] = is_string($v) ? trim($v) : $v;
        }
    }
    $verbunden = [];
    if (isset($x->mergeCells)) foreach ($x->mergeCells->mergeCell as $m) {
        [$a, $b] = explode(':', (string)$m['ref']);
        $verbunden[] = [(int)preg_replace('/\D/', '', $a), $spalte($a), (int)preg_replace('/\D/', '', $b), $spalte($b)];
    }
    return ['zeilen' => $zeilen, 'verbunden' => $verbunden];
}
