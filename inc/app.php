<?php
/**
 * WTG Wartungsplaner – Kern: Einstellungen, Datenbank, Anmeldung, Hilfsfunktionen
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

const ROLLEN = [
    'techniker'   => 'Meine Termine',
    'pflege'      => 'Pflege',
    'organisation'=> 'Organisation',
    'auswertung'  => 'Auswertung',
    'admin'       => 'Verwaltung',
];
const BAUTEIL_TYPEN = ['Akku', 'Batterie', 'Melder', 'Sonstiges'];

function cfg(?string $k = null) {
    static $c = null;
    if ($c === null) {
        $basis = dirname(__DIR__);
        $c = require $basis . '/config.php';
        if (is_file($basis . '/config.lokal.php')) $c = array_replace_recursive($c, require $basis . '/config.lokal.php');
    }
    return $k === null ? $c : ($c[$k] ?? null);
}

/* ====================================================================
   Datenbank
   ==================================================================== */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = cfg('db_dsn');
    if (str_starts_with($dsn, 'sqlite:')) { $d = dirname(substr($dsn, 7)); if (!is_dir($d)) @mkdir($d, 0775, true); }
    $pdo = new PDO($dsn, cfg('db_user') ?: null, cfg('db_pass') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (str_starts_with($dsn, 'sqlite:')) { $pdo->exec('PRAGMA foreign_keys = ON'); $pdo->exec('PRAGMA journal_mode = WAL'); }
    schema($pdo);
    return $pdo;
}
function ist_mysql(): bool { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

function schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $cs = $mysql ? ' DEFAULT CHARSET=utf8mb4' : '';
    $t = [
        "benutzer (id $id, login VARCHAR(100) UNIQUE, name VARCHAR(200), email VARCHAR(200), pw_hash VARCHAR(255),
            rollen VARCHAR(200) NOT NULL DEFAULT '', mitarbeiter_id INTEGER, aktiv INTEGER NOT NULL DEFAULT 1)",
        "mitarbeiter (id $id, name VARCHAR(100) UNIQUE, email VARCHAR(200), aktiv INTEGER NOT NULL DEFAULT 1)",
        "kunden (id $id, name VARCHAR(255), strasse VARCHAR(255), plz VARCHAR(10), ort VARCHAR(255),
            ansprechpartner VARCHAR(255), telefon VARCHAR(100), lat DOUBLE PRECISION, lon DOUBLE PRECISION,
            geo_status VARCHAR(20), geo_hinweis VARCHAR(255))",
        "gruppen (id $id, name VARCHAR(255))",
        "anlagen (id $id, kunde_id INTEGER NOT NULL, art VARCHAR(100), perioden VARCHAR(255),
            zeit_h DOUBLE PRECISION, vor_ort_h DOUBLE PRECISION, zeit_quelle VARCHAR(255),
            gruppe_id INTEGER, stammtechniker_id INTEGER, access_ids VARCHAR(255), aktiv INTEGER NOT NULL DEFAULT 1)",
        "wartungen (id $id, anlage_id INTEGER NOT NULL, jahr INTEGER NOT NULL, periode VARCHAR(50), quartal INTEGER NOT NULL,
            techniker_id INTEGER, status VARCHAR(20) NOT NULL DEFAULT 'offen',
            beginn VARCHAR(19), ende VARCHAR(19), tour_pos INTEGER, fahrt_min INTEGER,
            outlook_id VARCHAR(255), outlook_fehler TEXT,
            ist_h DOUBLE PRECISION, erledigt_am VARCHAR(19), erledigt_von VARCHAR(200))",
        "bauteile (id $id, anlage_id INTEGER NOT NULL, typ VARCHAR(50), bezeichnung VARCHAR(100), anzahl INTEGER,
            eingebaut VARCHAR(10), aktiv INTEGER NOT NULL DEFAULT 1, ersetzt_am VARCHAR(19),
            erstellt_am VARCHAR(19), erstellt_von VARCHAR(200), bemerkung VARCHAR(255))",
        "notizen (id $id, kunde_id INTEGER NOT NULL, anlage_id INTEGER, text TEXT, stand VARCHAR(10),
            erstellt_am VARCHAR(19), erstellt_von VARCHAR(200), bestaetigt_am VARCHAR(19), bestaetigt_von VARCHAR(200),
            aktiv INTEGER NOT NULL DEFAULT 1)",
        "restarbeiten (id $id, anlage_id INTEGER NOT NULL, text TEXT, material VARCHAR(255), dauer_h DOUBLE PRECISION,
            status VARCHAR(20) NOT NULL DEFAULT 'offen', erstellt_am VARCHAR(19), erstellt_von VARCHAR(200),
            erledigt_am VARCHAR(19), erledigt_von VARCHAR(200), wartung_id INTEGER)",
        "meldungen (id $id, kunde_id INTEGER, anlage_id INTEGER, wartung_id INTEGER, text TEXT,
            erstellt_am VARCHAR(19), erstellt_von VARCHAR(200), status VARCHAR(20) NOT NULL DEFAULT 'offen',
            bearbeitet_am VARCHAR(19), bearbeitet_von VARCHAR(200))",
        "abwesenheiten (id $id, mitarbeiter_id INTEGER NOT NULL, von VARCHAR(10), bis VARCHAR(10), art VARCHAR(50))",
        "import_hinweise (id $id, art VARCHAR(50), text TEXT, anlage_id INTEGER, erledigt INTEGER NOT NULL DEFAULT 0)",
        "geo_cache (k VARCHAR(255) PRIMARY KEY, lat DOUBLE PRECISION, lon DOUBLE PRECISION, quelle VARCHAR(50))",
        "fahrt_cache (k VARCHAR(80) PRIMARY KEY, min DOUBLE PRECISION, km DOUBLE PRECISION, quelle VARCHAR(20))",
        "einstellungen (k VARCHAR(50) PRIMARY KEY, v TEXT)",
        // pflegbare Auswahllisten: bauteil_typ, bauteil_bezeichnung, anlagenart
        "listen (id $id, liste VARCHAR(50) NOT NULL, wert VARCHAR(255) NOT NULL, reihenfolge INTEGER NOT NULL DEFAULT 0, aktiv INTEGER NOT NULL DEFAULT 1)",
        // eigene Felder für Kunden oder Anlagen (z. B. Anzahl Fenster, Etagen, VdS-Anlage)
        "felder (id $id, bereich VARCHAR(20) NOT NULL, name VARCHAR(100) NOT NULL, art VARCHAR(20) NOT NULL DEFAULT 'text',
            optionen TEXT, im_termin INTEGER NOT NULL DEFAULT 1, reihenfolge INTEGER NOT NULL DEFAULT 0, aktiv INTEGER NOT NULL DEFAULT 1)",
        "feld_werte (id $id, feld_id INTEGER NOT NULL, objekt_id INTEGER NOT NULL, wert TEXT, stand VARCHAR(10), geaendert_am VARCHAR(19), geaendert_von VARCHAR(200))",
        "outlook_ignoriert (event_id VARCHAR(255) PRIMARY KEY, am VARCHAR(19), von VARCHAR(200))",
        // abgelegte Dateien (PDF-Berichte, Stundennachweise, Fotos) zu Kunde, Anlage oder Wartung/Ticket
        "dokumente (id $id, kunde_id INTEGER NOT NULL, anlage_id INTEGER, wartung_id INTEGER, titel VARCHAR(255), datei VARCHAR(255),
            original VARCHAR(255), mime VARCHAR(100), groesse INTEGER, erstellt_am VARCHAR(19), erstellt_von VARCHAR(200), aktiv INTEGER NOT NULL DEFAULT 1)",
    ];
    foreach ($t as $sql) $db->exec('CREATE TABLE IF NOT EXISTS ' . $sql . $cs);
    // Spalten, die mit späteren Versionen dazugekommen sind (bestehende Datenbanken werden ergänzt, nichts wird entfernt)
    $neu = [
        'wartungen' => ['typ' => "VARCHAR(20) NOT NULL DEFAULT 'wartung'", 'titel' => 'VARCHAR(255)', 'beschreibung' => 'TEXT', 'prio' => 'VARCHAR(20)',
            'faellig_bis' => 'VARCHAR(10)', 'dauer_h' => 'DOUBLE PRECISION', 'quelle' => 'VARCHAR(255)', 'erstellt_am' => 'VARCHAR(19)', 'erstellt_von' => 'VARCHAR(200)', 'bericht' => 'TEXT'],
        'restarbeiten' => ['gemacht' => 'TEXT', 'quelle' => 'VARCHAR(255)', 'bericht_am' => 'VARCHAR(10)'],
    ];
    foreach ($neu as $tab => $spalten) {
        $da = $mysql ? array_column($db->query("SHOW COLUMNS FROM $tab")->fetchAll(PDO::FETCH_ASSOC), 'Field')
                     : array_column($db->query("PRAGMA table_info($tab)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($spalten as $sp => $def) if (!in_array($sp, $da, true)) $db->exec("ALTER TABLE $tab ADD COLUMN $sp $def");
    }
    foreach (['CREATE INDEX IF NOT EXISTS ix_w_jahr ON wartungen (jahr, quartal)', 'CREATE INDEX IF NOT EXISTS ix_w_tech ON wartungen (techniker_id, status)',
              'CREATE INDEX IF NOT EXISTS ix_a_kunde ON anlagen (kunde_id)', 'CREATE INDEX IF NOT EXISTS ix_b_anlage ON bauteile (anlage_id)',
              'CREATE INDEX IF NOT EXISTS ix_n_kunde ON notizen (kunde_id)', 'CREATE INDEX IF NOT EXISTS ix_fw ON feld_werte (feld_id, objekt_id)', 'CREATE INDEX IF NOT EXISTS ix_li ON listen (liste)', 'CREATE INDEX IF NOT EXISTS ix_r_anlage ON restarbeiten (anlage_id)', 'CREATE INDEX IF NOT EXISTS ix_d_kunde ON dokumente (kunde_id)', 'CREATE INDEX IF NOT EXISTS ix_w_beginn ON wartungen (beginn)'] as $ix) {
        try { $db->exec($mysql ? str_replace('IF NOT EXISTS ', '', $ix) : $ix); } catch (Throwable $e) { /* existiert */ }
    }
}

/** Startwerte für die Auswahllisten (nur wenn eine Liste noch leer ist) */
function listen_vorbelegen(): void {
    static $fertig = false; if ($fertig) return; $fertig = true;
    $start = ['bauteil_typ' => ['Akku', 'Batterie', 'Melder', 'Sonstiges']];
    foreach ($start as $liste => $werte) {
        if ((int)wert('SELECT COUNT(*) FROM listen WHERE liste = ?', [$liste])) continue;
        foreach ($werte as $i => $w) einfuegen('listen', ['liste' => $liste, 'wert' => $w, 'reihenfolge' => $i, 'aktiv' => 1]);
    }
    // Bezeichnungen und Anlagenarten aus den vorhandenen Daten übernehmen
    if (!(int)wert("SELECT COUNT(*) FROM listen WHERE liste = 'bauteil_bezeichnung'"))
        foreach (alle("SELECT DISTINCT bezeichnung FROM bauteile WHERE bezeichnung IS NOT NULL AND bezeichnung <> '' ORDER BY bezeichnung") as $i => $r)
            einfuegen('listen', ['liste' => 'bauteil_bezeichnung', 'wert' => $r['bezeichnung'], 'reihenfolge' => $i, 'aktiv' => 1]);
    if (!(int)wert("SELECT COUNT(*) FROM listen WHERE liste = 'akku_typ'") && function_exists('akku_teile')) {
        $typen = ['7,2 Ah' => 1, '12 Ah' => 1, '17 Ah' => 1, '24 Ah' => 1, '38 Ah' => 1];
        foreach (alle("SELECT bezeichnung FROM bauteile WHERE typ = 'Akku' AND aktiv = 1") as $r) foreach (akku_teile($r['bezeichnung'])['teile'] as $t => $_) $typen[$t] = 1;
        uksort($typen, fn($a, $b) => (float)str_replace(',', '.', $a) <=> (float)str_replace(',', '.', $b));
        $i = 0; foreach ($typen as $t => $_) einfuegen('listen', ['liste' => 'akku_typ', 'wert' => $t, 'reihenfolge' => $i++, 'aktiv' => 1]);
    }
    if (!(int)wert("SELECT COUNT(*) FROM listen WHERE liste = 'anlagenart'"))
        foreach (alle("SELECT DISTINCT art FROM anlagen WHERE art IS NOT NULL AND art <> '' ORDER BY art") as $i => $r)
            einfuegen('listen', ['liste' => 'anlagenart', 'wert' => $r['art'], 'reihenfolge' => $i, 'aktiv' => 1]);
}
/** Werte einer Auswahlliste */
function liste(string $name, bool $nurAktive = true): array {
    listen_vorbelegen();
    return array_column(alle('SELECT wert FROM listen WHERE liste = ?' . ($nurAktive ? ' AND aktiv = 1' : '') . ' ORDER BY reihenfolge, wert', [$name]), 'wert');
}
/** eigene Felder eines Bereichs ('kunde' | 'anlage') */
function felder(string $bereich, bool $nurAktive = true): array {
    return alle('SELECT * FROM felder WHERE bereich = ?' . ($nurAktive ? ' AND aktiv = 1' : '') . ' ORDER BY reihenfolge, name', [$bereich]);
}
/** Werte der eigenen Felder für ein Objekt: feld_id => Zeile */
function feld_werte(string $bereich, int $objektId): array {
    $o = [];
    foreach (alle('SELECT w.* FROM feld_werte w JOIN felder f ON f.id = w.feld_id WHERE f.bereich = ? AND w.objekt_id = ?', [$bereich, $objektId]) as $r) $o[(int)$r['feld_id']] = $r;
    return $o;
}
function feld_anzeige(array $f, ?string $w): string {
    if ($w === null || $w === '') return '';
    return match ($f['art']) { 'janein' => $w === '1' ? 'ja' : 'nein', 'datum' => datum($w), default => $w };
}

function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function alle(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function eine(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function wert(string $sql, array $p = []) { $v = q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }
function einfuegen(string $tab, array $d): int {
    q("INSERT INTO $tab (" . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')', array_values($d));
    return (int)db()->lastInsertId();
}
function aendern(string $tab, int $id, array $d): void {
    q("UPDATE $tab SET " . implode(',', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?', [...array_values($d), $id]);
}
function einstellung(string $k, ?string $neu = null): ?string {
    if ($neu !== null) { q('DELETE FROM einstellungen WHERE k = ?', [$k]); q('INSERT INTO einstellungen (k, v) VALUES (?, ?)', [$k, $neu]); return $neu; }
    return wert('SELECT v FROM einstellungen WHERE k = ?', [$k]);
}
function jetzt(): string { return date('Y-m-d H:i:s'); }

/* ====================================================================
   Anmeldung und Bereiche
   ==================================================================== */
function sitzung(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_name('wtgapp');
    session_start();
}
function benutzer(): ?array {
    sitzung();
    static $b = false;
    if ($b === false) $b = !empty($_SESSION['uid']) ? eine('SELECT * FROM benutzer WHERE id = ? AND aktiv = 1', [$_SESSION['uid']]) : null;
    return $b;
}
function rollen(?array $b = null): array { $b ??= benutzer(); return $b ? array_values(array_filter(explode(',', $b['rollen']))) : []; }
function darf(string $rolle): bool { $r = rollen(); return in_array($rolle, $r, true) || in_array('admin', $r, true); }
function nur(string $rolle): array {
    $b = benutzer();
    if (!$b) { header('Location: login.php?weiter=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '')); exit; }
    if (!darf($rolle)) { http_response_code(403); seite_kopf('Kein Zugriff'); echo '<div class="msg err">Dieser Bereich ist für Ihr Konto nicht freigeschaltet. Bitte an den Admin wenden.</div>'; seite_fuss(); exit; }
    return $b;
}
function wer(): string { return benutzer()['name'] ?? 'System'; }

function csrf(): string { sitzung(); return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_feld(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function post(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    sitzung();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Sitzung abgelaufen. Bitte die Seite neu laden.'); }
    return true;
}
function weiter(string $url, ?string $msg = null, string $art = 'ok'): never {
    if ($msg !== null) { sitzung(); $_SESSION['flash'] = [$art, $msg]; }
    header('Location: ' . $url); exit;
}

/* ====================================================================
   Kalender
   ==================================================================== */
function ostersonntag(int $y): DateTimeImmutable {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, intdiv($h + $l - 7 * $m + 114, 31), ($h + $l - 7 * $m + 114) % 31 + 1));
}
/** Feiertage (Niedersachsen) */
function feiertage(int $j): array {
    static $cache = [];
    if (isset($cache[$j])) return $cache[$j];
    $f = [];
    foreach ([[1,1,'Neujahr'],[5,1,'Tag der Arbeit'],[10,3,'Tag der Deutschen Einheit'],[10,31,'Reformationstag'],[12,25,'1. Weihnachtstag'],[12,26,'2. Weihnachtstag']] as [$m, $d, $n]) $f[sprintf('%04d-%02d-%02d', $j, $m, $d)] = $n;
    $o = ostersonntag($j);
    foreach ([-2 => 'Karfreitag', 1 => 'Ostermontag', 39 => 'Christi Himmelfahrt', 50 => 'Pfingstmontag'] as $n => $name) $f[$o->modify("$n days")->format('Y-m-d')] = $name;
    return $cache[$j] = $f;
}
function minuten(string $hhmm): int { [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]); return $h * 60 + $m; }
function hm(int $min): string { return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function quartal_von_bis(int $j, int $q): array {
    $von = new DateTimeImmutable(sprintf('%04d-%02d-01', $j, ($q - 1) * 3 + 1));
    return [$von->format('Y-m-d'), $von->modify('+3 months -1 day')->format('Y-m-d')];
}
/** Arbeitstage: ['datum','von','bis'] (Minuten), ohne Wochenenden/Feiertage, ab $ab */
function arbeitstage(string $von, string $bis, ?string $ab = null): array {
    $out = []; $az = cfg('arbeitszeiten');
    for ($d = new DateTimeImmutable($von); $d->format('Y-m-d') <= $bis; $d = $d->modify('+1 day')) {
        $iso = $d->format('Y-m-d');
        if ($ab !== null && $iso < $ab) continue;
        $z = $az[(int)$d->format('N')] ?? null;
        if (!$z || isset(feiertage((int)$d->format('Y'))[$iso])) continue;
        $out[] = ['datum' => $iso, 'von' => minuten($z[0]), 'bis' => minuten($z[1])];
    }
    return $out;
}
function aktuelles_quartal(): array { return [(int)date('Y'), intdiv((int)date('n') - 1, 3) + 1]; }

/* ====================================================================
   Texte und Formate
   ==================================================================== */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm(?string $s): string {
    $s = mb_strtolower(trim((string)$s));
    $s = str_replace(['ß', 'ä', 'ö', 'ü', 'straße', 'strasse', 'str.'], ['ss', 'ae', 'oe', 'ue', 'str', 'str', 'str'], $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}
function zahl($v, int $nk = 1): string { return number_format((float)$v, $nk, ',', '.'); }
function std($h): string { $h = (float)$h; return ($h == floor($h) ? (string)(int)$h : zahl($h, 2)) . ' Std.'; }
const WT = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const MONATE = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
function datum(?string $iso, bool $wt = false): string {
    if (!$iso) return '–';
    $d = new DateTimeImmutable(substr($iso, 0, 10));
    return ($wt ? WT[(int)$d->format('N')] . ' ' : '') . $d->format('d.m.Y');
}
function monat_jahr(?string $iso): string { return $iso ? (new DateTimeImmutable(substr($iso, 0, 10)))->format('m/Y') : '–'; }
/** Alter in Jahren (Bruch) eines Datums */
function alter_jahre(?string $iso, ?string $bezug = null): ?float {
    if (!$iso) return null;
    $a = new DateTimeImmutable(substr($iso, 0, 10)); $b = new DateTimeImmutable($bezug ?? 'today');
    return ($b->getTimestamp() - $a->getTimestamp()) / (365.25 * 86400);
}
function quartal_name(int $q): string { return "$q. Quartal"; }

/* ====================================================================
   Gemeinsame Abfragen
   ==================================================================== */
function mitarbeiter_liste(bool $nurAktive = true): array {
    return alle('SELECT * FROM mitarbeiter' . ($nurAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY name');
}
function mitarbeiter_namen(): array { $o = []; foreach (mitarbeiter_liste(false) as $m) $o[(int)$m['id']] = $m['name']; return $o; }

/** Vor-Ort-Zeit einer Anlage in Minuten (Lukas' Zeit ist inkl. Hin- und Rückfahrt Firma ↔ Kunde) */
function vor_ort_min(array $anlage, ?float $fahrtFirmaMin): int {
    if ($anlage['vor_ort_h'] !== null && $anlage['vor_ort_h'] !== '') return (int)round((float)$anlage['vor_ort_h'] * 60);
    $gesamt = ($anlage['zeit_h'] !== null && $anlage['zeit_h'] !== '' ? (float)$anlage['zeit_h'] : (float)cfg('standard_zeit_h')) * 60;
    $fahrt = $fahrtFirmaMin ?? 0;
    return (int)max((int)cfg('min_vor_ort_min'), round($gesamt - 2 * $fahrt));
}

/* ====================================================================
   Dokumente (PDF-Berichte, Stundennachweise, Fotos) – liegen in daten/dokumente, nur über dokument.php abrufbar
   ==================================================================== */
const DOK_ENDUNGEN = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
function dok_ordner(): string { return dirname(__DIR__) . '/daten/dokumente'; }
/** Hochgeladene Datei ablegen. Rückgabe: Fehlermeldung oder null */
function dokument_speichern(?array $f, int $kundeId, ?int $anlageId = null, ?int $wartungId = null, string $titel = ''): ?string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return 'Die Datei ist zu groß (höchstens ' . ini_get('upload_max_filesize') . ').';
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'Die Datei konnte nicht hochgeladen werden.';
    $end = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!isset(DOK_ENDUNGEN[$end])) return 'Nur PDF, JPG oder PNG möglich.';
    $kopf = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    $ok = $end === 'pdf' ? str_starts_with($kopf, '%PDF') : ($end === 'png' ? str_starts_with($kopf, "\x89PNG") : str_starts_with($kopf, "\xFF\xD8"));
    if (!$ok) return 'Der Inhalt passt nicht zur Dateiendung .' . $end . '.';
    $ord = dok_ordner() . '/' . $kundeId;
    if (!is_dir($ord) && !@mkdir($ord, 0775, true)) return 'Ablageordner kann nicht angelegt werden.';
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $end;
    if (!move_uploaded_file($f['tmp_name'], "$ord/$name")) return 'Datei konnte nicht gespeichert werden.';
    $orig = mb_substr(basename((string)$f['name']), 0, 200);
    einfuegen('dokumente', ['kunde_id' => $kundeId, 'anlage_id' => $anlageId ?: null, 'wartung_id' => $wartungId ?: null,
        'titel' => trim($titel) !== '' ? trim($titel) : pathinfo($orig, PATHINFO_FILENAME), 'datei' => "$kundeId/$name", 'original' => $orig,
        'mime' => DOK_ENDUNGEN[$end], 'groesse' => (int)$f['size'], 'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'aktiv' => 1]);
    return null;
}
function dateigroesse(int $b): string { return $b >= 1048576 ? zahl($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB'; }
/** Quartal zu einem Datum */
function quartal_von(string $iso): array { return [(int)substr($iso, 0, 4), intdiv((int)substr($iso, 5, 2) - 1, 3) + 1]; }

/** curl mit Zertifikaten: unter Windows ohne eingetragenes curl.cainfo die Windows-Zertifikate verwenden */
function http_neu(string $url) {
    $ch = curl_init($url);
    if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo') && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    return $ch;
}
/** Geheimer Schlüssel (für Kalender-Abos): aus config.php, sonst einmalig zufällig erzeugt und gespeichert */
function geheimnis(): string {
    $k = (string)cfg('geheimer_schluessel');
    if ($k !== '' && !str_starts_with($k, 'BITTE-AENDERN')) return $k;
    return einstellung('geheimer_schluessel') ?? einstellung('geheimer_schluessel', bin2hex(random_bytes(32)));
}
