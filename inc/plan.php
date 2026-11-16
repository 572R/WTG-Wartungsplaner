<?php
/**
 * Planung: Wartungen je Jahr erzeugen, Auslastung, Zuweisungsvorschlag, Touren nach Nähe, Termintext
 */
declare(strict_types=1);

/* ====================================================================
   Wartungen eines Jahres aus den Anlagen erzeugen
   ==================================================================== */
/** Zielquartal je Periode. Halbjahr/Jahr werden je Stammtechniker nach Region verteilt. */
function wartungen_erzeugen(int $jahr): int {
    $anlagen = alle('SELECT a.*, k.plz, k.ort, k.name AS kunde FROM anlagen a JOIN kunden k ON k.id = a.kunde_id WHERE a.aktiv = 1');
    $vorhanden = [];
    foreach (alle('SELECT anlage_id, periode FROM wartungen WHERE jahr = ?', [$jahr]) as $r) $vorhanden[$r['anlage_id'] . '|' . $r['periode']] = true;
    $ord = fn($a, $b) => (((int)$a['plz'] ?: 99999) <=> ((int)$b['plz'] ?: 99999)) ?: strcmp(norm($a['ort']), norm($b['ort'])) ?: strcmp($a['kunde'], $b['kunde']) ?: ($a['id'] <=> $b['id']);
    $neu = []; $gruppen = [];
    foreach ($anlagen as $a) {
        foreach (array_filter(explode('|', (string)$a['perioden'])) as $p) {
            if (isset($vorhanden[$a['id'] . '|' . $p])) continue;
            if (preg_match('/^([1-4])\.\s*Quartal/u', $p, $m)) { $neu[] = [$a['id'], $p, (int)$m[1]]; continue; }
            $art = preg_match('/^([12])\.\s*Halbjahr/u', $p, $m) ? 'H' . $m[1] : (preg_match('/^Jahr|^Jahres/u', $p) ? 'J' : (preg_match('/^2-jährlich/u', $p) ? 'Z' : null));
            if ($art === 'Z') { // alle 2 Jahre: nur in geraden bzw. ungeraden Jahren, je nach Anlage
                if (($a['id'] + $jahr) % 2) continue; $art = 'J';
            }
            if ($art) $gruppen[($a['stammtechniker_id'] ?? 0) . '|' . $art][] = $a + ['_p' => $p];
        }
    }
    foreach ($gruppen as $k => $liste) {
        usort($liste, $ord); $n = count($liste); $art = substr($k, strrpos($k, '|') + 1);
        foreach ($liste as $i => $a) {
            $qu = $art === 'J' ? intdiv($i * 4, $n) + 1 : ($art === 'H1' ? 1 : 3) + ($i < intdiv($n + 1, 2) ? 0 : 1);
            $neu[] = [$a['id'], $a['_p'], $qu];
        }
    }
    db()->beginTransaction();
    foreach ($neu as [$aid, $p, $qu]) einfuegen('wartungen', ['anlage_id' => $aid, 'jahr' => $jahr, 'periode' => $p, 'quartal' => $qu, 'status' => 'offen']);
    db()->commit();
    return count($neu);
}

/* ====================================================================
   Abfragen
   ==================================================================== */
const W_SELECT = 'SELECT w.*, a.art, a.zeit_h, COALESCE(w.dauer_h, a.vor_ort_h) AS vor_ort_h,
        CASE WHEN w.typ = \'ticket\' THEN NULL ELSE a.gruppe_id END AS gruppe_id, a.stammtechniker_id, a.kunde_id,
        k.name AS kunde, k.strasse, k.plz, k.ort, k.lat, k.lon, g.name AS gruppe
    FROM wartungen w JOIN anlagen a ON a.id = w.anlage_id JOIN kunden k ON k.id = a.kunde_id LEFT JOIN gruppen g ON g.id = a.gruppe_id AND w.typ <> \'ticket\'';

/** Wartungen eines Quartals (+ noch offene aus früheren Quartalen desselben Jahres) */
function wartungen_quartal(int $jahr, int $q, bool $mitFrueheren = true): array {
    return alle(W_SELECT . ' WHERE w.jahr = ? AND (w.quartal = ?' . ($mitFrueheren ? " OR (w.quartal < ? AND w.status IN ('offen','zugewiesen'))" : '') . ')
        ORDER BY k.plz, k.ort, k.name, a.art', $mitFrueheren ? [$jahr, $q, $q] : [$jahr, $q]);
}

/** Zeit inkl. Fahrt (Planungsgröße für die Auslastung) in Minuten */
function zeit_gesamt_min(array $w): int {
    // Ticket mit geschätzter Dauer: Dauer vor Ort + Hin- und Rückfahrt ab Firma
    if (($w['typ'] ?? 'wartung') === 'ticket' && $w['dauer_h'] !== null && $w['dauer_h'] !== '')
        return (int)round((float)$w['dauer_h'] * 60 + 2 * (fahrt_firma_min($w) ?? 0));
    return (int)round(((float)($w['zeit_h'] ?? 0) ?: (float)cfg('standard_zeit_h')) * 60);
}
function ist_ticket(array $w): bool { return ($w['typ'] ?? 'wartung') === 'ticket'; }
/** Kurzname für Listen: „Ticket: Melder defekt" bzw. Anlagenart */
function auftrag_name(array $w): string { return ist_ticket($w) ? 'Ticket: ' . ($w['titel'] ?: 'ohne Titel') : 'Wartung ' . $w['art']; }

/* ====================================================================
   Akku-Typen: Bezeichnungen wie „2x12AH 1x7Ah" in einzelne Typen zerlegen
   ==================================================================== */
function akku_typ_name(string $kap): string { $k = (float)str_replace(',', '.', $kap); return str_replace('.', ',', (string)$k) . ' Ah'; }
/**
 * Zerlegt eine Akku-Bezeichnung. Ergebnis: ['teile' => [typ => Stück], 'klar' => bool]
 * „7,2 Ah" (Anzahl 3) → [7,2 Ah => 3]; „2x12AH 1x7Ah" → [12 Ah => 2, 7 Ah => 1]; Unlesbares → klar = false
 */
function akku_teile(?string $bez, int $anzahl = 1): array {
    $s = mb_strtolower(trim((string)$bez));
    $anzahl = max(1, $anzahl);
    if ($s === '') return ['teile' => [], 'klar' => false];
    $s = preg_replace('/\b\d+\s*v\b(?!\s*a)/u', ' ', $s);                       // Spannung „12V" entfernen
    $s = preg_replace('/\b(rwa|nt|netzteile?|bma|ema|akkus?|batterien?|stk\.?|stück|je|und)\b/u', ' ', $s);
    $s = str_replace('×', 'x', $s);
    preg_match_all('/(?:(\d+)\s*x\s*)?(\d{1,3}(?:[.,]\d{1,2})?)\s*(?:v?\s*a\s*h\w*)?/u', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $teile = []; $rest = $s; $ohneX = 0; $n = 0;
    foreach ($m as $t) {
        if (trim($t[0][0]) === '') continue;
        $n++; $mal = $t[1][0] !== '' ? (int)$t[1][0] : null; if ($mal === null) $ohneX++;
        $typ = akku_typ_name($t[2][0]);
        $teile[$typ] = ($teile[$typ] ?? 0) + ($mal ?? 1) * $anzahl;
        $rest = substr_replace($rest, str_repeat(' ', strlen($t[0][0])), $t[0][1], strlen($t[0][0]));
    }
    $klar = $n > 0 && trim(preg_replace('/[\s,;+\/&]+/u', '', $rest)) === '' && !($n > 1 && $ohneX > 0)
        && !array_filter(array_keys($teile), fn($k) => (float)str_replace(',', '.', $k) <= 0 || (float)str_replace(',', '.', $k) > 300);
    return ['teile' => $klar ? $teile : [], 'klar' => $klar];
}
/** Akku fällig? (Einbau bekannt und älter als die Tauschfrist zum Stichtag) */
function akku_faellig(array $b, ?string $bezug = null): bool {
    return $b['typ'] === 'Akku' && $b['eingebaut'] && alter_jahre($b['eingebaut'], $bezug) >= (float)cfg('akku_tausch_jahre');
}
/**
 * Akku-Bedarf für eine Menge Anlagen: welche Akkus zum Stichtag getauscht werden müssen
 * @return array ['typen' => [typ => Stück], 'unklar' => [Bezeichnungen], 'unbekannt' => Anzahl ohne Einbaudatum]
 */
function akku_bedarf(array $anlageIds, ?string $bezug = null): array {
    $o = ['typen' => [], 'unklar' => [], 'unbekannt' => 0];
    $anlageIds = array_values(array_unique(array_map('intval', $anlageIds)));
    if (!$anlageIds) return $o;
    foreach (array_chunk($anlageIds, 500) as $teil)
        foreach (alle("SELECT * FROM bauteile WHERE aktiv = 1 AND typ = 'Akku' AND anlage_id IN (" . implode(',', $teil) . ')') as $b) {
            if (!$b['eingebaut']) { $o['unbekannt']++; continue; }
            if (!akku_faellig($b, $bezug)) continue;
            $z = akku_teile($b['bezeichnung'], (int)$b['anzahl']);
            if (!$z['klar']) { $o['unklar'][] = trim(($b['anzahl'] > 1 ? $b['anzahl'] . '× ' : '') . $b['bezeichnung']); continue; }
            foreach ($z['teile'] as $t => $n) $o['typen'][$t] = ($o['typen'][$t] ?? 0) + $n;
        }
    uksort($o['typen'], fn($a, $b) => (float)str_replace(',', '.', $a) <=> (float)str_replace(',', '.', $b));
    return $o;
}
function bedarf_text(array $bed): string {
    $t = implode(', ', array_map(fn($k, $v) => "{$v}× {$k}", array_keys($bed['typen']), $bed['typen']));
    if ($bed['unklar']) $t .= ($t ? ', ' : '') . 'außerdem prüfen: ' . implode('; ', $bed['unklar']);
    return $t;
}

/* ====================================================================
   Belegung und Auslastung
   ==================================================================== */
/** Belegte Zeiten eines Mitarbeiters: [datum => [[von,bis,art], …]] aus Abwesenheiten, Outlook, terminierten Wartungen */
function belegung(int $mid, string $von, string $bis, ?array &$fehler = null): array {
    $b = [];
    foreach (alle('SELECT * FROM abwesenheiten WHERE mitarbeiter_id = ? AND bis >= ? AND von <= ?', [$mid, $von, $bis]) as $a) {
        for ($d = new DateTimeImmutable(max($a['von'], $von)); $d->format('Y-m-d') <= min($a['bis'], $bis); $d = $d->modify('+1 day')) $b[$d->format('Y-m-d')][] = [0, 1440, 'abwesend'];
    }
    foreach (alle("SELECT beginn, ende FROM wartungen WHERE techniker_id = ? AND status IN ('terminiert','erledigt') AND beginn >= ? AND beginn <= ?", [$mid, "$von 00:00:00", "$bis 23:59:59"]) as $w) {
        $b[substr($w['beginn'], 0, 10)][] = [minuten(substr($w['beginn'], 11, 5)), minuten(substr($w['ende'], 11, 5)), 'wartung'];
    }
    if (outlook_aktiv()) {
        sitzung(); $ck = "outlook|$mid|$von|$bis";
        $c = $_SESSION['outlook_cache'][$ck] ?? null;
        if ($c && $c[0] > time() - 600) $ol = $c[1];
        else {
            try { $ol = outlook_belegt($mid, $von, $bis); $_SESSION['outlook_cache'][$ck] = [time(), $ol]; }
            catch (Throwable $e) { $ol = []; $fehler[] = $e->getMessage(); }
        }
        foreach ($ol as [$d, $v, $e, $art]) $b[$d][] = [$v, $e, $art === 'oof' ? 'abwesend' : 'termin'];
    }
    return $b;
}

/** Freie Blöcke eines Tages im Arbeitsfenster */
function freie_bloecke(int $von, int $bis, array $belegt): array {
    usort($belegt, fn($a, $b) => $a[0] <=> $b[0]);
    $frei = []; $t = $von;
    foreach ($belegt as [$s, $e]) {
        if ($e <= $t) continue;
        if ($s > $t) $frei[] = [$t, min($s, $bis)];
        $t = max($t, $e);
        if ($t >= $bis) break;
    }
    if ($t < $bis) $frei[] = [$t, $bis];
    return array_values(array_filter($frei, fn($f) => $f[1] - $f[0] >= 30));
}

/**
 * Auslastung je Mitarbeiter in einem Quartal (Minuten):
 *  arbeit = Arbeitszeit laut Plan, abwesend, termine (Outlook), verfuegbar = (arbeit − abwesend − termine) × Anteil,
 *  zugewiesen / terminiert = Zeit inkl. Fahrt aus Lukas' Liste, frei = verfuegbar − zugewiesen − terminiert
 */
function auslastung(int $jahr, int $q, ?array &$fehler = null, bool $abHeute = true): array {
    [$von, $bis] = quartal_von_bis($jahr, $q);
    $heute = date('Y-m-d');
    $tage = arbeitstage($von, $bis, !$abHeute || max($von, $heute) > $bis ? null : max($von, $heute));
    $anteil = (float)cfg('max_anteil_wartung'); $out = [];
    $proTech = [];
    foreach (alle(W_SELECT . " WHERE w.jahr = ? AND w.techniker_id IS NOT NULL AND (w.quartal = ? OR (w.beginn >= ? AND w.beginn <= ?))", [$jahr, $q, "$von 00:00:00", "$bis 23:59:59"]) as $w) {
        $proTech[(int)$w['techniker_id']][$w['status']] = ($proTech[(int)$w['techniker_id']][$w['status']] ?? 0) + zeit_gesamt_min($w);
    }
    foreach (mitarbeiter_liste() as $m) {
        $bel = belegung((int)$m['id'], $tage ? $tage[0]['datum'] : $von, $bis, $fehler);
        $arbeit = 0; $abw = 0; $term = 0;
        foreach ($tage as $t) {
            $len = $t['bis'] - $t['von']; $arbeit += $len;
            foreach ($bel[$t['datum']] ?? [] as [$s, $e, $art]) {
                if ($art === 'wartung') continue;
                $ueber = max(0, min($e, $t['bis']) - max($s, $t['von']));
                if ($art === 'abwesend') $abw += $ueber; else $term += $ueber;
            }
        }
        $abw = min($abw, $arbeit);
        $verf = (int)round(max(0, $arbeit - $abw - $term) * $anteil);
        $p = $proTech[(int)$m['id']] ?? [];
        $zug = $p['zugewiesen'] ?? 0; $ter = $p['terminiert'] ?? 0; $erl = $p['erledigt'] ?? 0;
        $out[(int)$m['id']] = ['name' => $m['name'], 'arbeit' => $arbeit, 'abwesend' => $abw, 'termine' => $term, 'verfuegbar' => $verf,
            'zugewiesen' => $zug, 'terminiert' => $ter, 'erledigt' => $erl, 'frei' => $verf - $zug - $ter];
    }
    return $out;
}

/* ====================================================================
   Zuweisungsvorschlag: passende offene Wartungen für einen Mitarbeiter
   ==================================================================== */
function zuweisungs_vorschlag(int $mid, int $jahr, int $q, float $stunden): array {
    $alle = wartungen_quartal($jahr, $q);
    $offen = array_values(array_filter($alle, fn($w) => $w['status'] === 'offen' && $w['lat'] !== null));
    if (!$offen) return [];
    // Mittelpunkt: bereits zugewiesene des Mitarbeiters, sonst seine Stammkunden, sonst Firma
    $basis = array_filter($alle, fn($w) => (int)$w['techniker_id'] === $mid && $w['lat'] !== null);
    if (!$basis) $basis = array_filter($offen, fn($w) => (int)$w['stammtechniker_id'] === $mid);
    $f = firma_koord();
    $mitte = $basis ? ['lat' => array_sum(array_column($basis, 'lat')) / count($basis), 'lon' => array_sum(array_column($basis, 'lon')) / count($basis)] : $f;
    // Einheiten: Gruppen geschlossen
    $einheiten = [];
    foreach ($offen as $w) { $k = $w['gruppe_id'] ? 'g' . $w['gruppe_id'] : 'w' . $w['id']; $einheiten[$k][] = $w; }
    $liste = [];
    foreach ($einheiten as $k => $ws) {
        $p = ['lat' => (float)$ws[0]['lat'], 'lon' => (float)$ws[0]['lon']];
        $stamm = count(array_filter($ws, fn($w) => (int)$w['stammtechniker_id'] === $mid)) > 0;
        $liste[] = ['ids' => array_column($ws, 'id'), 'min' => array_sum(array_map('zeit_gesamt_min', $ws)),
            'rang' => ($stamm ? 0 : 1000) + ($mitte ? luftlinie_km($mitte, $p) : 0)];
    }
    usort($liste, fn($a, $b) => $a['rang'] <=> $b['rang']);
    $ziel = $stunden * 60; $summe = 0; $ids = [];
    foreach ($liste as $e) { if ($summe >= $ziel) break; if ($summe + $e['min'] > $ziel * 1.1 && $summe > 0) continue; $ids = array_merge($ids, $e['ids']); $summe += $e['min']; }
    return $ids;
}

/* ====================================================================
   Touren: Tage nach Nähe füllen (Lukas: Termin 1 setzen, dann jeweils den nächstgelegenen)
   ==================================================================== */
/**
 * @param array $opt  'von','bis' (Y-m-d, Standard: Quartal), 'wochentage' (1–7, Standard: alle Arbeitstage),
 *                    'ids' (nur diese Wartungen; Standard: alle zugewiesenen)
 */
function touren_planen(int $mid, int $jahr, int $q, array $opt = []): array {
    [$von, $bis] = quartal_von_bis($jahr, $q);
    if (!empty($opt['von'])) $von = $opt['von'];
    if (!empty($opt['bis'])) $bis = $opt['bis'];
    $morgen = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    $tage = arbeitstage($von, $bis, max($von, $morgen));
    if (!empty($opt['wochentage'])) $tage = array_values(array_filter($tage, fn($t) => in_array((int)(new DateTimeImmutable($t['datum']))->format('N'), $opt['wochentage'], true)));
    $ids = isset($opt['ids']) ? array_flip(array_map('intval', $opt['ids'])) : null;
    $kandidaten = array_values(array_filter(wartungen_quartal($jahr, $q), fn($w) => (int)$w['techniker_id'] === $mid && $w['status'] === 'zugewiesen'));
    if ($ids !== null) { // Auswahl: Gruppen immer vollständig
        $gr = []; foreach ($kandidaten as $w) if (isset($ids[(int)$w['id']]) && $w['gruppe_id']) $gr[(int)$w['gruppe_id']] = true;
        $kandidaten = array_values(array_filter($kandidaten, fn($w) => isset($ids[(int)$w['id']]) || ($w['gruppe_id'] && isset($gr[(int)$w['gruppe_id']]))));
    }
    $ws = $kandidaten;
    $nicht = []; $fehler = [];
    $firma = firma_koord();
    if (!$firma) return ['tage' => [], 'nicht' => array_map(fn($w) => [$w, 'Firmenadresse konnte nicht geokodiert werden'], $ws), 'quelle' => '', 'fehler' => []];
    // Einheiten (Gruppe = ein Block am selben Tag)
    $einheiten = [];
    foreach ($ws as $w) {
        if ($w['lat'] === null) { $nicht[] = [$w, 'Adresse ohne Koordinaten – in der Pflege prüfen']; continue; }
        $einheiten[$w['gruppe_id'] ? 'g' . $w['gruppe_id'] : 'w' . $w['id']][] = $w;
    }
    $einheiten = array_values($einheiten);
    $punkte = [$firma];
    foreach ($einheiten as $i => $e) {
        foreach ($e as $j => $w) { $einheiten[$i][$j]['_p'] = count($punkte); $punkte[] = ['lat' => (float)$w['lat'], 'lon' => (float)$w['lon']]; }
    }
    $M = fahrt_matrix($punkte);
    $T = fn(int $a, int $b) => $M['min'][$a][$b]; $K = fn(int $a, int $b) => $M['km'][$a][$b];
    // Gruppenmitglieder in sinnvoller Reihenfolge, Arbeitszeit je Einheit
    $U = [];
    foreach ($einheiten as $e) {
        $rest = $e; $folge = [array_shift($rest)];
        while ($rest) { $l = end($folge)['_p']; usort($rest, fn($a, $b) => $T($l, $a['_p']) <=> $T($l, $b['_p'])); $folge[] = array_shift($rest); }
        $arbeit = 0; $intern = 0;
        foreach ($folge as $i => $w) {
            $w['_vor_ort'] = vor_ort_min($w, $T(0, $w['_p'])); $folge[$i] = $w; $arbeit += $w['_vor_ort'];
            if ($i) $intern += $T($folge[$i - 1]['_p'], $w['_p']);
        }
        // Frist: Tickets mit „fällig bis" oder „dringend" (dringend = innerhalb von 10 Tagen)
        $frist = null;
        foreach ($folge as $w) {
            if (!ist_ticket($w)) continue;
            $f = $w['faellig_bis'] ?: null;
            if ($w['prio'] === 'dringend') { $d = (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'); $f = $f ? min($f, $d) : $d; }
            if ($f) $frist = $frist ? min($frist, $f) : $f;
        }
        $U[] = ['ws' => $folge, 'in' => $folge[0]['_p'], 'out' => end($folge)['_p'], 'dauer' => $arbeit + $intern, 'frist' => $frist];
    }
    // Belegung + freie Blöcke je Tag, Wochenkontingent
    $bel = belegung($mid, $tage ? $tage[0]['datum'] : $von, $bis, $fehler);
    $anteil = (float)cfg('max_anteil_wartung'); $woche = [];
    foreach ($tage as $i => $t) {
        $tage[$i]['bloecke'] = freie_bloecke($t['von'], $t['bis'], $bel[$t['datum']] ?? []);
        $kw = (new DateTimeImmutable($t['datum']))->format('o-W');
        $woche[$kw]['max'] = ($woche[$kw]['max'] ?? 0) + ($t['bis'] - $t['von']) * $anteil;
        foreach ($bel[$t['datum']] ?? [] as [$s, $e, $art]) if ($art === 'wartung') $woche[$kw]['used'] = ($woche[$kw]['used'] ?? 0) + ($e - $s);
        $tage[$i]['kw'] = $kw;
    }
    $raster = max(1, (int)cfg('raster_min'));
    $auf = fn(float $m) => (int)(ceil($m / $raster) * $raster);
    $D = count($tage);
    $gesamt = array_sum(array_map(fn($u) => $u['dauer'] + 2 * $T(0, $u['in']), $U));
    $schnitt = $D ? max(60, array_sum(array_map(fn($t) => $t['bloecke'] ? max(array_map(fn($b) => $b[1] - $b[0], $t['bloecke'])) : 0, $tage)) / $D) : 0;
    $n = $schnitt ? max(1, (int)ceil($gesamt / ($schnitt * 0.8))) : 0;
    // Tage gleichmäßig über das Quartal verteilen: erst mit Schätzung, dann mit der tatsächlich benötigten Zahl an Tagen
    $erster = $tage ? $tage[0]['datum'] : $von;
    // darf Einheit $u an diesem Tag liegen? (Frist nicht überschritten; schon überfällige: jeder Tag, aber möglichst früh)
    $erlaubt = fn(int $u, string $datum) => $U[$u]['frist'] === null || $U[$u]['frist'] < $erster || $datum <= $U[$u]['frist'];
    /** einen Tag füllen: Start = erste passende aus $startReihe, dann immer der nächstgelegene Kunde */
    $tagBauen = function (int $ti, array $offen, array $startReihe, array &$woche) use ($U, $T, $K, $tage, $auf, $erlaubt): ?array {
        $tag = $tage[$ti];
        if (!$tag['bloecke']) return null;
        usort($tag['bloecke'], fn($a, $b) => ($b[1] - $b[0]) <=> ($a[1] - $a[0]));
        [$bs, $be] = $tag['bloecke'][0];
        $rest = ($woche[$tag['kw']]['max'] ?? 0) - ($woche[$tag['kw']]['used'] ?? 0);
        $kap = min($be - $bs, $rest);
        if ($kap < 60) return null;
        // Uhrzeit nach einer Einheit (mit gerundeten Ankunftszeiten) – so passt die Prüfung exakt zur späteren Planung
        $nach = function (int $uhr, int $pos, int $u) use ($U, $T, $auf): array {
            foreach ($U[$u]['ws'] as $w) { $uhr = $auf($uhr + $T($pos, $w['_p'])) + $w['_vor_ort']; $pos = $w['_p']; }
            return [$uhr, $pos];
        };
        $passt = fn(int $uhr, int $pos) => $uhr + (int)round($T($pos, 0)) <= $be && $uhr + (int)round($T($pos, 0)) - $bs <= $kap;
        $start = null;
        foreach ($startReihe as $u) { if (!$erlaubt($u, $tag['datum'])) continue; [$uh, $po] = $nach($bs, 0, $u); if ($passt($uh, $po)) { $start = $u; break; } }
        if ($start === null) return null;
        $tour = [$start]; [$uhr, $pos] = $nach($bs, 0, $start);
        // dann immer der nächstgelegene Kunde, solange es in den Tag passt
        while (true) {
            $best = null; $bestT = INF; $bestZ = null;
            foreach ($offen as $u) {
                if (in_array($u, $tour, true) || !$erlaubt($u, $tag['datum'])) continue;
                $t = $T($pos, $U[$u]['in']);
                if ($t >= $bestT) continue;
                $z = $nach($uhr, $pos, $u);
                if ($passt($z[0], $z[1])) { $best = $u; $bestT = $t; $bestZ = $z; }
            }
            if ($best === null) break;
            $tour[] = $best; [$uhr, $pos] = $bestZ;
        }
        // Uhrzeiten vergeben
        $uhr = $bs; $vorher = 0; $stopps = []; $kmSumme = 0;
        foreach ($tour as $u) foreach ($U[$u]['ws'] as $w) {
            $f = $T($vorher, $w['_p']); $kmSumme += $K($vorher, $w['_p']);
            $an = $auf($uhr + $f); $ab = $an + $w['_vor_ort'];
            $stopps[] = ['w' => $w, 'an' => $an, 'ab' => $ab, 'fahrt' => (int)round($f), 'km' => round($K($vorher, $w['_p']), 1)];
            $uhr = $ab; $vorher = $w['_p'];
        }
        $rueck = $T($vorher, 0); $kmSumme += $K($vorher, 0);
        $ende = $uhr + (int)round($rueck);
        $woche[$tag['kw']]['used'] = ($woche[$tag['kw']]['used'] ?? 0) + ($ende - $bs);
        return ['tour' => $tour, 'tag' => ['datum' => $tag['datum'], 'start' => $bs, 'ende' => $ende, 'stopps' => $stopps, 'rueck_min' => (int)round($rueck), 'km' => round($kmSumme, 1)]];
    };
    // Vorlauf: Einheiten mit Frist (Tickets) so früh wie möglich, der Tag wird mit Kunden in der Nähe aufgefüllt
    $vorErg = []; $vorBenutzt = []; $vorOffen = array_keys($U);
    $mitFrist = array_values(array_filter($vorOffen, fn($u) => $U[$u]['frist'] !== null));
    usort($mitFrist, fn($a, $b) => strcmp($U[$a]['frist'], $U[$b]['frist']));
    for ($ti = 0; $ti < $D && $mitFrist; $ti++) {
        $r = $tagBauen($ti, $vorOffen, $mitFrist, $woche);
        if (!$r) continue;
        $vorErg[] = $r['tag']; $vorBenutzt[$ti] = true;
        $vorOffen = array_values(array_diff($vorOffen, $r['tour'])); $mitFrist = array_values(array_diff($mitFrist, $r['tour']));
    }
    // Tage gleichmäßig über das Quartal verteilen: erst mit Schätzung, dann mit der tatsächlich benötigten Zahl an Tagen
    $bauen = function (int $n) use ($U, $T, $tage, $woche, $D, $tagBauen, $vorErg, $vorBenutzt, $vorOffen): array {
        $offen = $vorOffen; $ergebnis = $vorErg; $benutzt = $vorBenutzt;
        $ziele = $n ? array_unique(array_map(fn($i) => (int)floor(($i + 0.5) * $D / $n), range(0, $n - 1))) : [];
        $versuche = array_merge($ziele, range(0, max(0, $D - 1)));
        foreach ($versuche as $ziel) {
            if (!$offen) break;
            // ab Zieltag den nächsten unbenutzten Tag mit Platz suchen
            for ($ti = $ziel; $ti < $D && (isset($benutzt[$ti]) || !$tage[$ti]['bloecke']); $ti++);
            if ($ti >= $D) continue;
            // Start: die am weitesten entfernte Einheit (Fristen zuerst)
            $kand = $offen; usort($kand, fn($a, $b) => (($U[$a]['frist'] === null) <=> ($U[$b]['frist'] === null)) ?: ($T(0, $U[$b]['in']) <=> $T(0, $U[$a]['in'])));
            $r = $tagBauen($ti, $offen, $kand, $woche);
            $benutzt[$ti] = true;
            if (!$r) continue;
            $ergebnis[] = $r['tag'];
            $offen = array_values(array_diff($offen, $r['tour']));
        }
        return [$ergebnis, $offen];
    };
    [$ergebnis, $offen] = $bauen($n);
    for ($runde = 0; $runde < 3 && $ergebnis && count($ergebnis) - count($vorErg) !== $n; $runde++) {
        $n = count($ergebnis) - count($vorErg);
        [$e2, $o2] = $bauen($n);
        if (count($o2) <= count($offen)) { $ergebnis = $e2; $offen = $o2; }
    }
    foreach ($offen as $u) foreach ($U[$u]['ws'] as $w) $nicht[] = [$w, $U[$u]['dauer'] + 2 * $T(0, $U[$u]['in']) > ($schnitt ?: 480) ? 'Zu lang für einen freien Tagesblock'
        : ($U[$u]['frist'] !== null && $U[$u]['frist'] >= $erster ? 'Bis zur Frist (' . datum($U[$u]['frist']) . ') kein freier Platz mehr' : 'Kein freier Tag mehr in diesem Quartal (Wochenkontingent oder Kalender voll)')];
    usort($ergebnis, fn($a, $b) => strcmp($a['datum'], $b['datum']));
    return ['tage' => $ergebnis, 'nicht' => $nicht, 'quelle' => $M['quelle'], 'fehler' => $fehler];
}

/* ====================================================================
   Termininhalt: alles aus der Datenbank, mit Stand-Datum
   ==================================================================== */
function bauteil_text(array $b, ?string $bezug = null): array {
    $t = trim(($b['anzahl'] ? $b['anzahl'] . '× ' : '') . $b['bezeichnung']);
    $warn = false;
    if ($b['eingebaut']) {
        $t .= ', eingebaut ' . monat_jahr($b['eingebaut']);
        if ($b['typ'] === 'Akku' && alter_jahre($b['eingebaut'], $bezug) >= (float)cfg('akku_tausch_jahre')) { $t .= ' → TAUSCHEN'; $warn = true; }
    } else $t .= ', Einbaudatum unbekannt';
    return [$b['typ'] . ': ' . $t, $warn];
}

function termin_inhalt(int $wid): array {
    $w = eine(W_SELECT . ' WHERE w.id = ?', [$wid]);
    $k = eine('SELECT * FROM kunden WHERE id = ?', [$w['kunde_id']]);
    $bezug = $w['beginn'] ? substr($w['beginn'], 0, 10) : null;
    $plz = $k['plz'] ? str_pad((string)$k['plz'], 5, '0', STR_PAD_LEFT) : '';
    $tk = ist_ticket($w);
    $z = $tk ? ['Ticket: ' . ($w['titel'] ?: 'ohne Titel') . " – {$k['name']}", trim("{$k['strasse']}, $plz {$k['ort']}"), "Anlage: {$w['art']}"]
             : ["Wartung {$w['art']} – {$k['name']}", trim("{$k['strasse']}, $plz {$k['ort']}"), "Intervall: {$w['periode']}"];
    if ($tk) {
        if ($w['beschreibung']) { $z[] = ''; $z[] = 'Was ist zu tun:'; $z[] = $w['beschreibung']; $z[] = ''; }
        $z[] = trim(($w['prio'] ? 'Priorität: ' . $w['prio'] . ' · ' : '') . ($w['faellig_bis'] ? 'Fällig bis ' . datum($w['faellig_bis']) . ' · ' : '')
            . 'Eingegangen ' . datum($w['erstellt_am']) . ($w['quelle'] ? ' (' . $w['quelle'] . ')' : '') . ($w['erstellt_von'] ? ', ' . $w['erstellt_von'] : ''));
    }
    if ($k['ansprechpartner'] || $k['telefon']) $z[] = 'Ansprechpartner: ' . trim($k['ansprechpartner'] . ' ' . $k['telefon']);
    if ($w['gruppe_id']) {
        $andere = alle('SELECT k.name, a.art FROM anlagen a JOIN kunden k ON k.id = a.kunde_id WHERE a.gruppe_id = ? AND a.id <> ?', [$w['gruppe_id'], $w['anlage_id']]);
        if ($andere) $z[] = 'Zusammen mit: ' . implode(', ', array_map(fn($a) => "{$a['name']} ({$a['art']})", $andere));
    }
    $marken = [];
    $rest = alle("SELECT * FROM restarbeiten WHERE anlage_id = ? AND status = 'offen' ORDER BY erstellt_am", [$w['anlage_id']]);
    if ($rest) {
        $z[] = ''; $z[] = 'OFFENE RESTARBEITEN (zur Info):';
        foreach ($rest as $r) {
            $z[] = '- Noch zu tun: ' . $r['text'] . ($r['material'] ? " · Material: {$r['material']}" : '') . ($r['dauer_h'] ? ' · ca. ' . std($r['dauer_h']) : '')
                . ' (' . ($r['quelle'] ? $r['quelle'] . ', ' : '') . ($r['bericht_am'] ? 'Bericht ' . datum($r['bericht_am']) : 'eingetragen ' . datum($r['erstellt_am'])) . ', ' . $r['erstellt_von'] . ')';
            if ($r['gemacht']) $z[] = '  Damals gemacht: ' . $r['gemacht'];
        }
        $marken[] = 'Restarbeit';
    }
    $teile = alle('SELECT * FROM bauteile WHERE anlage_id = ? AND aktiv = 1 ORDER BY typ, eingebaut', [$w['anlage_id']]);
    if ($teile) {
        $z[] = ''; $z[] = 'Material / Bauteile:';
        foreach ($teile as $b) { [$t, $warn] = bauteil_text($b, $bezug); $z[] = '- ' . $t; if ($warn) $marken['akku'] = 'Akku tauschen'; }
        $bed = akku_bedarf([(int)$w['anlage_id']], $bezug);
        if ($bed['typen'] || $bed['unklar']) $z[] = 'MITNEHMEN: ' . bedarf_text($bed);
    }
    $notizen = alle('SELECT * FROM notizen WHERE kunde_id = ? AND aktiv = 1 AND (anlage_id IS NULL OR anlage_id = ?) ORDER BY stand DESC', [$k['id'], $w['anlage_id']]);
    if ($notizen) {
        $z[] = ''; $z[] = 'Notizen:';
        foreach ($notizen as $n) {
            $stand = $n['bestaetigt_am'] ? substr($n['bestaetigt_am'], 0, 10) : $n['stand'];
            $alt = alter_jahre($stand, $bezug) >= (float)cfg('notiz_alter_jahre');
            $z[] = '- ' . $n['text'] . ' (Stand ' . monat_jahr($stand) . ($alt ? ' – älter als ' . cfg('notiz_alter_jahre') . ' Jahre, bitte prüfen' : '') . ')';
        }
    }
    // eigene Felder (Kunde und Anlage) mit Stand
    $zus = [];
    foreach (['kunde' => (int)$k['id'], 'anlage' => (int)$w['anlage_id']] as $bereich => $oid) {
        $werte = feld_werte($bereich, $oid);
        foreach (felder($bereich) as $f) {
            $v = $werte[(int)$f['id']] ?? null; if (!$f['im_termin'] || !$v || $v['wert'] === '' || ($f['art'] === 'janein' && $v['wert'] !== '1')) continue;
            $alt = alter_jahre($v['stand'], $bezug) >= (float)cfg('notiz_alter_jahre');
            $zus[] = '- ' . $f['name'] . ': ' . feld_anzeige($f, $v['wert']) . ' (Stand ' . monat_jahr($v['stand']) . ($alt ? ' – bitte prüfen' : '') . ')';
        }
    }
    if ($zus) { $z[] = ''; $z[] = 'Weitere Angaben:'; array_push($z, ...$zus); }
    $z[] = '';
    $z[] = $tk ? 'Geschätzte Dauer vor Ort: ' . ($w['dauer_h'] !== null ? std($w['dauer_h']) : 'nicht angegeben')
               : 'Zeit laut Liste: ' . ($w['zeit_h'] !== null ? std($w['zeit_h']) . ' inkl. Fahrt' : 'nicht hinterlegt');
    $z[] = 'Anlagen-Nr. ' . $w['anlage_id'] . ' · ' . ($tk ? 'Ticket ' : 'Wartung ') . $w['id'];
    $z[] = 'Geplant mit dem WTG Wartungsplaner';
    return ['betreff' => ($tk ? 'Ticket: ' . ($w['titel'] ?: $w['art']) : "Wartung {$w['art']}") . " – {$k['name']}" . ($marken ? ' (' . implode(', ', array_values($marken)) . ')' : ''),
        'text' => implode("\n", $z), 'strasse' => $k['strasse'], 'plz' => $plz, 'ort' => $k['ort'], 'ort_text' => trim("{$k['strasse']}, $plz {$k['ort']}"), 'marken' => array_values($marken)];
}
