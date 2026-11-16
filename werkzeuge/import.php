<?php
/**
 * Einmaliger Import: Access-Datenbank + Excel-Liste von Lukas -> WTG Wartungsplaner
 *
 *   php werkzeuge/import.php --access=WTG_Plan.accdb [--excel=Zeitaufwand.xlsx] [--excel-stand=2026-11-11] [--neu] [--testzugaenge]
 *
 *   --neu           vorhandene Stammdaten löschen und neu importieren (Benutzer bleiben)
 *   --testzugaenge  Test-Benutzer mit Passwort "test" anlegen
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/inc/app.php';
require dirname(__DIR__) . '/inc/dateien.php';
require dirname(__DIR__) . '/inc/geo.php';
require dirname(__DIR__) . '/inc/microsoft.php';
require dirname(__DIR__) . '/inc/plan.php';

$o = getopt('', ['access:', 'excel::', 'excel-stand::', 'neu', 'testzugaenge']);
$aus = fn(string $s) => print($s . PHP_EOL);
if (empty($o['access']) || !is_file($o['access'])) { $aus('Bitte --access=Pfad/zur/WTG_Plan.accdb angeben.'); exit(1); }
$excel = $o['excel'] ?? null; if ($excel !== null && !is_file($excel)) { $aus("Excel-Datei nicht gefunden: $excel"); exit(1); }
$excelStand = $o['excel-stand'] ?? date('Y-m-d');

if ((int)wert('SELECT COUNT(*) FROM anlagen') > 0) {
    if (!isset($o['neu'])) { $aus('Die Datenbank enthält schon Daten. Zum Neu-Import --neu angeben (Benutzer bleiben erhalten).'); exit(1); }
    foreach (['wartungen', 'bauteile', 'notizen', 'restarbeiten', 'meldungen', 'anlagen', 'gruppen', 'kunden', 'import_hinweise'] as $t) db()->exec("DELETE FROM $t");
}
$hinweis = fn(string $art, string $text, ?int $aid = null) => einfuegen('import_hinweise', ['art' => $art, 'text' => $text, 'anlage_id' => $aid, 'erledigt' => 0]);
// Ortskürzel aus Lukas' Liste ausschreiben, damit "Stadt BS Kita" und "Stadt Braunschweig Kita" gleich werden
$kuerzel = ['bs' => 'braunschweig', 'gö' => 'göttingen', 'goe' => 'göttingen', 'wob' => 'wolfsburg', 'he' => 'helmstedt', 'sz' => 'salzgitter',
    'wf' => 'wolfenbüttel', 'h' => 'hannover', 'pe' => 'peine', 'gf' => 'gifhorn', 'hi' => 'hildesheim', 'gs' => 'goslar', 'lk' => 'landkreis'];
$lang = function (string $s) use ($kuerzel): string {
    return preg_replace_callback('/(?<![\\p{L}])(\\p{L}{1,3})(?![\\p{L}])/u', fn($m) => $kuerzel[mb_strtolower($m[1])] ?? $m[1], $s);
};
$aehnlich = function (string $a, string $b): float {
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;
    if (strlen($a) >= 4 && strlen($b) >= 4 && (str_contains($a, $b) || str_contains($b, $a))) return 90;
    similar_text($a, $b, $p); return $p;
};

/* ---------- 1. Wartungen aus Access -> Kunden, Anlagen, Mitarbeiter ---------- */
$w = access_tabelle($o['access'], 'Wartungen');
$spalten = array_keys($w[0]);
[$sv, $benutzt] = spalten_zuordnen($spalten, [
    'id' => ['id'], 'betreff' => ['betreff', 'kunde', 'kunden'], 'anlage' => ['anlage'],
    'strasse' => ['strasse', 'strae', 'stre', 'str', 'adresse'], 'plz' => ['postleitzahl', 'plz'], 'ort' => ['ort', 'stadt'],
    'intervall' => ['wartungsintervall', 'intervall'], 'ansprechpartner' => ['ansprechpartner', 'ap', 'kontakt'], 'telefon' => ['telefon', 'tel'],
    'hinweis' => ['anlagenhinweis', 'hinweis', 'hinweise', 'bemerkung', 'notiz'],
]);
$techs = [];
foreach ($spalten as $s) {
    if (isset($benutzt[$s])) continue;
    $werte = []; $n = 0;
    foreach ($w as $r) { $v = trim((string)($r[$s] ?? '')); if ($v === '') continue; $n++; $werte[$v] = 1; if (count($werte) > 3) continue 2; }
    if ($n) $techs[] = $s;
}
$mid = [];
foreach ($techs as $t) $mid[$t] = (int)(wert('SELECT id FROM mitarbeiter WHERE name = ?', [$t]) ?? einfuegen('mitarbeiter', ['name' => $t, 'email' => null, 'aktiv' => 1]));
$f = fn($r, $k) => isset($sv[$k]) ? ($r[$sv[$k]] ?? null) : null;

db()->beginTransaction();
$kunden = []; $anlagen = [];
foreach ($w as $r) {
    $name = trim((string)$f($r, 'betreff')); if ($name === '') continue;
    $plz = $f($r, 'plz'); $plz = $plz !== null && $plz !== '' ? str_pad((string)(int)$plz, 5, '0', STR_PAD_LEFT) : '';
    $kk = norm($name) . '|' . norm($f($r, 'strasse'));
    if (!isset($kunden[$kk])) $kunden[$kk] = einfuegen('kunden', ['name' => $name, 'strasse' => trim((string)$f($r, 'strasse')), 'plz' => $plz, 'ort' => trim((string)$f($r, 'ort')),
        'ansprechpartner' => $f($r, 'ansprechpartner'), 'telefon' => $f($r, 'telefon')]);
    $art = trim((string)$f($r, 'anlage')) ?: 'Anlage';
    $ak = $kk . '|' . norm($art);
    $tech = null; foreach ($techs as $t) if (trim((string)($r[$t] ?? '')) !== '') { $tech = $mid[$t]; break; }
    $anlagen[$ak] ??= ['kunde_id' => $kunden[$kk], 'art' => $art, 'perioden' => [], 'tech' => [], 'ids' => [], 'name' => $name, 'strasse' => (string)$f($r, 'strasse'), 'hinweis' => $f($r, 'hinweis')];
    $anlagen[$ak]['perioden'][] = trim((string)$f($r, 'intervall'));
    if ($tech) $anlagen[$ak]['tech'][$tech] = ($anlagen[$ak]['tech'][$tech] ?? 0) + 1;
    $anlagen[$ak]['ids'][] = (int)$f($r, 'id');
}
$reihen = ['1. Quartal' => 1, '2. Quartal' => 2, '3. Quartal' => 3, '4. Quartal' => 4, '1. Halbjahr' => 5, '2. Halbjahr' => 6, 'Jahreswartung' => 7];
foreach ($anlagen as $ak => $a) {
    $p = array_values(array_unique(array_filter($a['perioden'])));
    usort($p, fn($x, $y) => ($reihen[$x] ?? 9) <=> ($reihen[$y] ?? 9));
    arsort($a['tech']);
    $anlagen[$ak]['id'] = einfuegen('anlagen', ['kunde_id' => $a['kunde_id'], 'art' => $a['art'], 'perioden' => implode('|', $p),
        'stammtechniker_id' => $a['tech'] ? array_key_first($a['tech']) : null, 'access_ids' => implode(',', $a['ids']), 'aktiv' => 1]);
    if (!$a['tech']) $hinweis('ohne_techniker', "{$a['name']} ({$a['art']}): kein Mitarbeiter in Access eingetragen", $anlagen[$ak]['id']);
    if (trim((string)$a['hinweis']) !== '') einfuegen('notizen', ['kunde_id' => $a['kunde_id'], 'anlage_id' => $anlagen[$ak]['id'], 'text' => trim($a['hinweis']), 'stand' => date('Y-m-d'), 'erstellt_am' => jetzt(), 'erstellt_von' => 'Import Access', 'aktiv' => 1]);
}
db()->commit();
$aus(count($kunden) . ' Kunden, ' . count($anlagen) . ' Anlagen, Mitarbeiter: ' . implode(', ', $techs));

/* ---------- 2. Zeiten, Gruppen, Notizen aus Excel (oder Access-Tabelle "Zeitaufwand") ---------- */
$zeilen = []; // [nr => ['kunde','art','pro_jahr','zeit','notizen'=>[], 'gruppe'=>?]]
$verbunden = [];
if ($excel) {
    $x = xlsx_lesen($excel);
    foreach ($x['zeilen'] as $nr => $z) {
        if ($nr === 1 || empty($z[1])) continue;
        $zeilen[$nr] = ['kunde' => (string)$z[1], 'art' => trim((string)($z[2] ?? '')), 'pro_jahr' => $z[3] ?? null, 'zeit' => $z[4] ?? null,
            'notizen' => array_values(array_filter([$z[5] ?? null, $z[6] ?? null, $z[7] ?? null], fn($v) => $v !== null && $v !== ''))];
    }
    $verbunden = $x['verbunden'];
    $quelle = 'Excel ' . basename($excel);
} else {
    $za = access_tabelle($o['access'], 'Zeitaufwand');
    [$zv] = spalten_zuordnen($za ? array_keys($za[0]) : [], ['kunde' => ['kunde', 'kunden'], 'art' => ['anlage'], 'pro_jahr' => ['intervall'], 'zeit' => ['stunde', 'stunden', 'dauer'], 'gruppe' => ['gruppe']]);
    foreach ($za as $i => $r) $zeilen[$i + 2] = ['kunde' => (string)($r[$zv['kunde']] ?? ''), 'art' => trim((string)($r[$zv['art'] ?? ''] ?? '')), 'pro_jahr' => $r[$zv['pro_jahr'] ?? ''] ?? null,
        'zeit' => isset($zv['zeit']) && $r[$zv['zeit']] !== null ? (float)str_replace(',', '.', $r[$zv['zeit']]) : null, 'notizen' => [], 'gruppe' => isset($zv['gruppe']) ? $r[$zv['gruppe']] : null];
    $quelle = 'Access Zeitaufwand';
}
// Zuordnung Zeile -> Anlage
$kand = [];
foreach ($anlagen as $a) $kand[] = ['id' => $a['id'], 'kunde_id' => $a['kunde_id'], 'art' => norm($a['art']), 'n1' => norm($lang($a['name'])), 'n2' => norm($lang($a['name']) . $a['strasse'])];
$zuord = []; $exakt = 0; $unscharf = 0; $ohne = 0;
foreach ($zeilen as $nr => $z) {
    $n = norm($lang($z['kunde'])); $art = norm($z['art']);
    $best = null; $bestS = 0;
    foreach ($kand as $k) {
        $s = max($aehnlich($n, $k['n1']), $aehnlich($n, $k['n2']));
        if ($art !== '' && $k['art'] !== $art) $s -= (str_contains($k['art'], $art) || str_contains($art, $k['art'])) ? 8 : 25;
        if ($s > $bestS) { $bestS = $s; $best = $k; }
    }
    if ($best && $bestS >= 99) { $zuord[$nr] = $best; $exakt++; }
    elseif ($best && $bestS >= 83) {
        $zuord[$nr] = $best; $unscharf++;
        $kn = wert('SELECT name FROM kunden WHERE id = ?', [$best['kunde_id']]);
        $hinweis('unscharf', "Liste: \"{$z['kunde']}\" ({$z['art']}) → zugeordnet zu \"$kn\" (" . round($bestS) . " % ähnlich) – bitte prüfen", $best['id']);
    } else { $ohne++; $hinweis('ohne_zuordnung', "Liste: \"{$z['kunde']}\" ({$z['art']}) – keine passende Anlage gefunden" . (is_numeric($z['zeit']) ? ', Zeit ' . zahl($z['zeit'], 2) . ' Std.' : '')); }
}
db()->beginTransaction();
foreach ($zeilen as $nr => $z) {
    if (!isset($zuord[$nr])) continue;
    $aid = $zuord[$nr]['id'];
    if (is_numeric($z['zeit'])) aendern('anlagen', $aid, ['zeit_h' => (float)$z['zeit'], 'zeit_quelle' => $quelle]);
    else $hinweis('zeit_fehlt', "{$z['kunde']} ({$z['art']}): keine Zeit in der Liste" . ($z['zeit'] ? " (\"{$z['zeit']}\")" : ''), $aid);
    $pj = is_numeric($z['pro_jahr']) ? (float)$z['pro_jahr'] : null;
    $per = array_filter(explode('|', (string)wert('SELECT perioden FROM anlagen WHERE id = ?', [$aid])));
    if ($pj == 0.5) aendern('anlagen', $aid, ['perioden' => '2-jährlich']);
    elseif ($pj !== null && (int)$pj !== count($per)) $hinweis('intervall', "{$z['kunde']} ({$z['art']}): Liste sagt {$z['pro_jahr']}× pro Jahr, Access hat " . count($per) . ' Termine', $aid);
}
db()->commit();
$aus("Zeiten aus $quelle: $exakt eindeutig, $unscharf ähnlich (bitte prüfen), $ohne ohne Zuordnung");

// Gruppen: verbundene Zellen mit "zusammen", Hinweise "zusammen mit X", Access-Spalte "Gruppe"
$eltern = [];
$finde = function ($a) use (&$eltern, &$finde) { while (isset($eltern[$a]) && $eltern[$a] !== $a) $a = $eltern[$a] = $eltern[$eltern[$a]] ?? $eltern[$a]; return $a; };
$vereine = function ($a, $b) use (&$eltern, $finde) { $eltern[$a] ??= $a; $eltern[$b] ??= $b; $ra = $finde($a); $rb = $finde($b); if ($ra !== $rb) $eltern[$rb] = $ra; };
foreach ($verbunden as [$z1, $s1, $z2, $s2]) {
    $text = mb_strtolower((string)($zeilen[$z1]['notizen'][0] ?? ''));
    if ($s1 !== 5 || !str_contains($text, 'zusammen')) continue;
    $ids = []; for ($r = $z1; $r <= $z2; $r++) if (isset($zuord[$r])) $ids[] = $zuord[$r]['id'];
    for ($i = 1; $i < count($ids); $i++) $vereine($ids[0], $ids[$i]);
}
foreach ($zeilen as $nr => $z) {
    foreach ($z['notizen'] as $t) {
        if (!preg_match('/zusammen mit\s+(.+?)\s*(terminieren|einplanen)?\s*$/iu', $t, $m) && !preg_match('/^nur mit\s+(.+?)\s*(zusammen)?\s*(terminieren|einplanen)\s*$/iu', $t, $m)) continue;
        if (!isset($zuord[$nr])) continue;
        $ziel = norm($m[1]); $best = null; $bestS = 0;
        foreach ($zeilen as $nr2 => $z2) {
            if ($nr2 === $nr || !isset($zuord[$nr2])) continue;
            $s = $aehnlich($ziel, norm($z2['kunde'])); $s2 = $aehnlich($ziel, norm($z2['kunde'] . $z2['art']));
            // "zusammen mit EMA": andere Anlage desselben Kunden
            if ($zuord[$nr2]['kunde_id'] === $zuord[$nr]['kunde_id'] && $ziel === norm($z2['art'])) $s = 100;
            if (max($s, $s2) > $bestS) { $bestS = max($s, $s2); $best = $nr2; }
        }
        if ($best !== null && $bestS >= 60) $vereine($zuord[$nr]['id'], $zuord[$best]['id']);
        else $hinweis('gruppe', "{$z['kunde']}: Hinweis \"$t\" – Partner nicht gefunden", $zuord[$nr]['id']);
    }
    if (!empty($z['gruppe']) && isset($zuord[$nr])) { $eltern['G' . $z['gruppe']] ??= 'G' . $z['gruppe']; $vereine('G' . $z['gruppe'], $zuord[$nr]['id']); }
}
$gruppen = [];
foreach (array_keys($eltern) as $a) if (is_int($a)) $gruppen[$finde($a)][] = $a;
db()->beginTransaction();
$gNr = 0;
foreach ($gruppen as $mitglieder) {
    $mitglieder = array_values(array_unique($mitglieder));
    if (count($mitglieder) < 2) continue;
    $namen = alle('SELECT DISTINCT k.name FROM anlagen a JOIN kunden k ON k.id = a.kunde_id WHERE a.id IN (' . implode(',', $mitglieder) . ')');
    $gid = einfuegen('gruppen', ['name' => implode(' + ', array_slice(array_column($namen, 'name'), 0, 3)) . (count($namen) > 3 ? ' …' : '')]);
    q('UPDATE anlagen SET gruppe_id = ? WHERE id IN (' . implode(',', $mitglieder) . ')', [$gid]); $gNr++;
}
// Notizen aus der Liste (ohne Gruppen-Hinweise)
$nNr = 0;
foreach ($zeilen as $nr => $z) foreach ($z['notizen'] as $t) {
    if (preg_match('/zusammen/i', $t) || !isset($zuord[$nr])) continue;
    einfuegen('notizen', ['kunde_id' => $zuord[$nr]['kunde_id'], 'anlage_id' => $zuord[$nr]['id'], 'text' => $t, 'stand' => $excelStand,
        'erstellt_am' => jetzt(), 'erstellt_von' => 'Import ' . ($excel ? 'Excel-Liste' : 'Access'), 'aktiv' => 1]); $nNr++;
}
db()->commit();
$aus("$gNr Gruppen, $nNr Notizen aus der Liste");

/* ---------- 3. Akkus -> Bauteile (Zuordnung über die Straße) ---------- */
$akN = 0; $akOhne = 0;
try {
    $ak = access_tabelle($o['access'], 'Akku');
    [$av] = spalten_zuordnen($ak ? array_keys($ak[0]) : [], ['kunde' => ['kunde', 'kunden'], 'strasse' => ['strasse', 'strae', 'stre', 'str', 'adresse'], 'anlage' => ['anlage'], 'akku' => ['akku', 'akkus'], 'alter' => ['alter', 'datum', 'eingebaut', 'einbaudatum']]);
    $proStrasse = [];
    foreach (alle('SELECT a.id, a.art, k.strasse FROM anlagen a JOIN kunden k ON k.id = a.kunde_id') as $a) $proStrasse[norm($a['strasse'])][] = $a;
    db()->beginTransaction();
    foreach ($ak as $r) {
        $text = trim((string)($r[$av['akku'] ?? ''] ?? '')); $ein = datum_iso($r[$av['alter'] ?? ''] ?? null);
        if ($text === '' && !$ein) continue;
        $liste = $proStrasse[norm($r[$av['strasse'] ?? ''] ?? '')] ?? [];
        if (!$liste) { $akOhne++; $hinweis('akku', 'Akku "' . ($r[$av['kunde'] ?? ''] ?? '') . '", ' . ($r[$av['strasse'] ?? ''] ?? '') . ": $text – keine Anlage an dieser Adresse"); continue; }
        $typ = preg_replace('/[^a-z]/', '', mb_strtolower((string)($r[$av['anlage'] ?? ''] ?? '')));
        $passend = array_values(array_filter($liste, fn($a) => $typ !== '' && str_contains($typ, preg_replace('/[^a-z]/', '', mb_strtolower(explode('+', $a['art'])[0])))));
        $a = ($passend ?: $liste)[0];
        $anz = 1; $bez = $text;
        if (preg_match('/^(\d+)\s*[x×*]\s*(\d+(?:[,.]\d+)?)\s*(?:v?ah)?$/iu', $text, $m)) { $anz = (int)$m[1]; $bez = str_replace('.', ',', $m[2]) . ' Ah'; }
        einfuegen('bauteile', ['anlage_id' => $a['id'], 'typ' => 'Akku', 'bezeichnung' => $bez, 'anzahl' => $anz, 'eingebaut' => $ein, 'aktiv' => 1,
            'erstellt_am' => jetzt(), 'erstellt_von' => 'Import Access', 'bemerkung' => trim((string)($r[$av['anlage'] ?? ''] ?? '')) ?: null]);
        $akN++;
    }
    db()->commit();
} catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $aus('Hinweis: Akku-Tabelle nicht gelesen (' . $e->getMessage() . ')'); }
$aus("$akN Akkus übernommen, $akOhne ohne passende Adresse");

/* ---------- 4. Wartungen für dieses und nächstes Jahr ---------- */
foreach ([(int)date('Y'), (int)date('Y') + 1] as $j) $aus("Wartungen $j: " . wartungen_erzeugen($j) . ' angelegt');
// Vergangene Quartale des laufenden Jahres nicht als "offen" führen
[$jA, $qA] = aktuelles_quartal();
$weg = q("DELETE FROM wartungen WHERE jahr = ? AND quartal < ? AND status = 'offen' AND COALESCE(typ, 'wartung') = 'wartung'", [$jA, $qA])->rowCount();
if ($weg) $aus("($weg Wartungen aus bereits vergangenen Quartalen $jA nicht übernommen)");

/* ---------- 5. Benutzer ---------- */
if ((int)wert('SELECT COUNT(*) FROM benutzer') === 0) {
    $test = isset($o['testzugaenge']);
    $pw = fn() => $test ? 'test' : substr(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), 0, 10);
    $neu = [];
    foreach (mitarbeiter_liste() as $m) { $p = $pw(); einfuegen('benutzer', ['login' => mb_strtolower($m['name']), 'name' => $m['name'], 'pw_hash' => password_hash($p, PASSWORD_DEFAULT), 'rollen' => 'techniker', 'mitarbeiter_id' => $m['id'], 'aktiv' => 1]); $neu[] = mb_strtolower($m['name']) . " / $p"; }
    foreach ([['buero', 'Büro', 'pflege,organisation'], ['chef', 'Leitung', 'auswertung,organisation'], ['admin', 'Admin', 'admin']] as [$l, $n, $r]) {
        $p = $pw(); einfuegen('benutzer', ['login' => $l, 'name' => $n, 'pw_hash' => password_hash($p, PASSWORD_DEFAULT), 'rollen' => $r, 'aktiv' => 1]); $neu[] = "$l / $p";
    }
    $aus('Zugänge angelegt (Anmeldename / Passwort):'); foreach ($neu as $z) $aus('   ' . $z);
}
$aus('Fertig. Als Nächstes die Adressen geokodieren: php werkzeuge/geokodieren.php');
