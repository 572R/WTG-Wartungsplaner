<?php
/**
 * Bereich "Pflege": Kunden aufrufen, prüfen, Änderungen eintragen (mit Datum und Namen), Prüfliste
 */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('pflege');
const PERIODEN = ['1. Quartal', '2. Quartal', '3. Quartal', '4. Quartal', '1. Halbjahr', '2. Halbjahr', 'Jahreswartung', '2-jährlich'];

/** Formular für eigene Felder (Kunde oder Anlage) */
function felder_formular(string $bereich, int $objektId, int $kundeId): void {
    $fl = felder($bereich); if (!$fl) return;
    $w = feld_werte($bereich, $objektId); ?>
    <details<?= $bereich === 'kunde' ? ' open' : '' ?>><summary class="<?= $bereich === 'kunde' ? '' : 'btn ghost klein' ?>"><?= $bereich === 'kunde' ? '' : 'Weitere Angaben' ?></summary>
    <form method="post" class="stack" style="margin-top:6px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="felder_speichern"><input type="hidden" name="kunde_id" value="<?= $kundeId ?>">
      <input type="hidden" name="bereich" value="<?= $bereich ?>"><input type="hidden" name="objekt_id" value="<?= $objektId ?>">
      <?php foreach ($fl as $f): $v = $w[(int)$f['id']] ?? null; $alt = $v && alter_jahre($v['stand']) >= (float)cfg('notiz_alter_jahre'); $name = 'f[' . $f['id'] . ']'; ?>
        <label class="f"><?= h($f['name']) ?><?php if ($v): ?> <span class="hint <?= $alt ? 'alt' : '' ?>">· Stand <?= monat_jahr($v['stand']) ?><?= $alt ? ' – bitte prüfen' : '' ?>, <?= h($v['geaendert_von']) ?></span><?php endif; ?>
          <?php if ($f['art'] === 'janein'): ?><span><input type="checkbox" name="<?= $name ?>" value="1"<?= ($v['wert'] ?? '') === '1' ? ' checked' : '' ?>> ja</span>
          <?php elseif ($f['art'] === 'auswahl'): ?><select name="<?= $name ?>"><option value="">–</option><?php foreach (array_map('trim', explode(',', (string)$f['optionen'])) as $o): ?><option<?= ($v['wert'] ?? '') === $o ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select>
          <?php else: ?><input type="<?= $f['art'] === 'datum' ? 'date' : 'text' ?>" name="<?= $name ?>" value="<?= h($v['wert'] ?? '') ?>"<?= $f['art'] === 'zahl' ? ' inputmode="decimal"' : '' ?>><?php endif; ?></label>
      <?php endforeach; ?>
      <div class="row"><button class="btn klein">Speichern</button><label class="hint"><input type="checkbox" name="bestaetigen" value="1"> alle Angaben als aktuell bestätigen</label></div>
    </form></details>
<?php }

/** Akku-Eintrag durch einheitliche Typ-Einträge ersetzen (alter Eintrag bleibt im Verlauf) */
function akku_ersetzen(array $b, array $teile): void {
    if (count($teile) === 1) { aendern('bauteile', (int)$b['id'], ['bezeichnung' => array_key_first($teile), 'anzahl' => reset($teile),
        'bemerkung' => trim(($b['bemerkung'] ? $b['bemerkung'] . ' · ' : '') . 'vorher „' . $b['bezeichnung'] . '"')]); return; }
    aendern('bauteile', (int)$b['id'], ['aktiv' => 0, 'ersetzt_am' => jetzt(), 'bemerkung' => trim(($b['bemerkung'] ? $b['bemerkung'] . ' · ' : '') . 'aufgeteilt')]);
    foreach ($teile as $typ => $n) einfuegen('bauteile', ['anlage_id' => $b['anlage_id'], 'typ' => 'Akku', 'bezeichnung' => $typ, 'anzahl' => $n, 'eingebaut' => $b['eingebaut'],
        'aktiv' => 1, 'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'bemerkung' => 'aufgeteilt aus „' . $b['bezeichnung'] . '"']);
}
/** Akku-Typ-Anzeige mit Formular zum Aufteilen */
function akku_typ_zelle(array $b, string $zurueck): string {
    if ($b['typ'] !== 'Akku') return '';
    $z = akku_teile($b['bezeichnung'], (int)$b['anzahl']);
    if ($z['klar']) return implode(' ', array_map(fn($k, $v) => '<span class="pill neutral">' . $v . '× ' . h($k) . '</span>', array_keys($z['teile']), $z['teile']));
    return '<details><summary class="pill warn">Typ unklar</summary><form method="post" class="row" style="margin-top:6px;flex-wrap:nowrap">' . csrf_feld()
        . '<input type="hidden" name="aktion" value="akku_aufteilen"><input type="hidden" name="id" value="' . (int)$b['id'] . '"><input type="hidden" name="zurueck" value="' . h($zurueck) . '">'
        . '<input type="text" name="text" placeholder="z. B. 2x 12 Ah, 1x 7 Ah" required style="min-width:170px"><button class="btn ghost klein">Übernehmen</button></form></details>';
}
function pflege_nav(string $on): void {
    echo '<div class="row">';
    foreach (['suche' => ['pflege.php', 'Kundensuche'], 'tickets' => ['pflege.php?ansicht=tickets', 'Tickets'], 'akkus' => ['pflege.php?ansicht=akkus', 'Akkus'], 'pruef' => ['pflege.php?ansicht=pruef', 'Prüfliste']] as $k => [$u, $n])
        echo '<a class="btn ' . ($k === $on ? '' : 'ghost') . '" href="' . $u . '">' . $n . '</a>';
    echo '</div>';
}
const TICKET_QUELLEN = ['Telefon', 'E-Mail', 'Stundennachweis', 'Wartung', 'Kunde vor Ort', 'Sonstiges'];
/** Formularfelder eines Tickets (neu oder bearbeiten) */
function ticket_felder(?array $t, array $anlagen, array $mit): void { ?>
    <label class="f">Was ist zu tun? (kurz)<input type="text" name="titel" required value="<?= h($t['titel'] ?? '') ?>" placeholder="z. B. Melder Flur EG defekt"></label>
    <label class="f">Beschreibung<textarea name="beschreibung" placeholder="Details, Ansprechpartner vor Ort, Zugang …"><?= h($t['beschreibung'] ?? '') ?></textarea></label>
    <div class="grid3">
      <?php if (!$t): ?><label class="f">Anlage<select name="anlage_id" required><?php foreach ($anlagen as $a): if (!$a['aktiv']) continue; ?><option value="<?= $a['id'] ?>"><?= h($a['art']) ?> (Nr. <?= $a['id'] ?>)</option><?php endforeach; ?></select></label><?php endif; ?>
      <label class="f">Priorität<select name="prio"><?php foreach (['normal', 'dringend'] as $p): ?><option<?= ($t['prio'] ?? 'normal') === $p ? ' selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></label>
      <label class="f">Fällig bis (optional)<input type="date" name="faellig_bis" value="<?= h($t['faellig_bis'] ?? '') ?>"></label>
      <label class="f">Dauer vor Ort (Std.)<input type="text" name="dauer_h" inputmode="decimal" value="<?= isset($t['dauer_h']) && $t['dauer_h'] !== null ? h(str_replace('.', ',', (string)(float)$t['dauer_h'])) : '' ?>" placeholder="z. B. 1,5"></label>
      <label class="f">Eingang über<select name="quelle"><option value="">–</option><?php foreach (TICKET_QUELLEN as $qq): ?><option<?= ($t['quelle'] ?? '') === $qq ? ' selected' : '' ?>><?= $qq ?></option><?php endforeach; ?></select></label>
      <?php if (!$t): ?><label class="f">Direkt zuweisen (optional)<select name="techniker_id"><option value="">– später in der Organisation –</option><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"><?= h($n) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    </div>
<?php }

/* ---------- Aktionen ---------- */
if (post()) {
    $a = $_POST['aktion'] ?? ''; $kid = (int)($_POST['kunde_id'] ?? 0); $zur = 'pflege.php?k=' . $kid;
    switch ($a) {
        case 'kunde_speichern':
            $alt = eine('SELECT * FROM kunden WHERE id = ?', [$kid]);
            $neu = ['name' => trim($_POST['name']), 'strasse' => trim($_POST['strasse']), 'plz' => trim($_POST['plz']), 'ort' => trim($_POST['ort']),
                    'ansprechpartner' => trim($_POST['ansprechpartner']) ?: null, 'telefon' => trim($_POST['telefon']) ?: null];
            aendern('kunden', $kid, $neu);
            $msg = 'Kundendaten gespeichert.';
            if (norm($alt['strasse']) !== norm($neu['strasse']) || norm($alt['plz']) !== norm($neu['plz']) || norm($alt['ort']) !== norm($neu['ort'])) {
                $st = kunde_geokodieren(eine('SELECT * FROM kunden WHERE id = ?', [$kid]));
                $msg .= $st === 'adresse' ? ' Neue Adresse auf der Karte gefunden.' : ' Adresse konnte nicht genau gefunden werden – bitte prüfen.';
            }
            weiter($zur, $msg);
        case 'geo_neu':
            q('DELETE FROM geo_cache WHERE k = ?', ['a|' . norm($_POST['strasse'] ?? '') . '|' . norm($_POST['plz'] ?? '') . '|' . norm($_POST['ort'] ?? '')]);
            $st = kunde_geokodieren(eine('SELECT * FROM kunden WHERE id = ?', [$kid]));
            weiter($zur, ['adresse' => 'Adresse gefunden.', 'plz' => 'Nur die Postleitzahl wurde gefunden.', 'fehlt' => 'Adresse nicht gefunden (OpenStreetMap erreichbar?).'][$st], $st === 'adresse' ? 'ok' : 'warn');
        case 'anlage_speichern':
            $aid = (int)$_POST['anlage_id'];
            $gid = $_POST['gruppe_id'] === 'neu' ? einfuegen('gruppen', ['name' => trim($_POST['gruppe_neu'] ?: 'Gruppe')]) : ((int)$_POST['gruppe_id'] ?: null);
            aendern('anlagen', $aid, ['art' => trim($_POST['art']), 'perioden' => implode('|', array_intersect(PERIODEN, (array)($_POST['perioden'] ?? []))),
                'zeit_h' => $_POST['zeit_h'] !== '' ? (float)str_replace(',', '.', $_POST['zeit_h']) : null,
                'vor_ort_h' => $_POST['vor_ort_h'] !== '' ? (float)str_replace(',', '.', $_POST['vor_ort_h']) : null,
                'zeit_quelle' => 'Pflege ' . wer() . ', ' . date('d.m.Y'),
                'gruppe_id' => $gid, 'stammtechniker_id' => (int)$_POST['stammtechniker_id'] ?: null, 'aktiv' => isset($_POST['aktiv']) ? 1 : 0]);
            weiter($zur . '#anlage-' . $aid, 'Anlage gespeichert. Geänderte Intervalle gelten für neu erzeugte Wartungsjahre.');
        case 'anlage_neu':
            $aid = einfuegen('anlagen', ['kunde_id' => $kid, 'art' => trim($_POST['art']) ?: 'Anlage', 'perioden' => 'Jahreswartung', 'aktiv' => 1]);
            weiter($zur . '#anlage-' . $aid, 'Anlage angelegt.');
        case 'bauteil_neu':
            $aid = (int)$_POST['anlage_id']; $mon = trim((string)$_POST['eingebaut']);
            $akkuUnklar = $_POST['typ'] === 'Akku' && !akku_teile(trim($_POST['bezeichnung']), 1)['klar'];
            foreach ((array)($_POST['ersetzt'] ?? []) as $bid) aendern('bauteile', (int)$bid, ['aktiv' => 0, 'ersetzt_am' => jetzt()]);
            einfuegen('bauteile', ['anlage_id' => $aid, 'typ' => $_POST['typ'], 'bezeichnung' => trim($_POST['bezeichnung']), 'anzahl' => max(1, (int)$_POST['anzahl']),
                'eingebaut' => $mon !== '' ? substr($mon, 0, 7) . '-01' : null, 'aktiv' => 1, 'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'bemerkung' => trim($_POST['bemerkung'] ?? '') ?: null]);
            $bz = trim($_POST['bezeichnung']); if ($bz !== '' && !in_array($bz, liste('bauteil_bezeichnung', false), true)) einfuegen('listen', ['liste' => 'bauteil_bezeichnung', 'wert' => $bz, 'reihenfolge' => 999, 'aktiv' => 1]);
            weiter($zur . '#anlage-' . $aid, 'Bauteil eingetragen' . (!empty($_POST['ersetzt']) ? ', das alte steht jetzt im Verlauf.' : '.')
                . ($akkuUnklar ? ' Hinweis: Der Akku-Typ ist nicht eindeutig lesbar (z. B. „7,2 Ah" oder „2x 12 Ah, 1x 7 Ah") – er fehlt sonst im Akku-Bedarf.' : ''), $akkuUnklar ? 'warn' : 'ok');
        case 'bauteil_entfernen':
            aendern('bauteile', (int)$_POST['id'], ['aktiv' => 0, 'ersetzt_am' => jetzt()]);
            weiter($zur, 'Bauteil entfernt (bleibt im Verlauf).');
        case 'felder_speichern':
            $bereich = $_POST['bereich'] === 'anlage' ? 'anlage' : 'kunde'; $oid = (int)$_POST['objekt_id']; $alt = feld_werte($bereich, $oid); $n = 0;
            $bestaetigen = isset($_POST['bestaetigen']);
            foreach (felder($bereich) as $f) {
                $fid = (int)$f['id']; $neu = $f['art'] === 'janein' ? (isset($_POST['f'][$fid]) ? '1' : '0') : trim((string)($_POST['f'][$fid] ?? ''));
                $vorher = $alt[$fid]['wert'] ?? null;
                if ($vorher === null && ($neu === '' || ($f['art'] === 'janein' && $neu === '0' && !isset($_POST['f'][$fid])))) continue;
                if ($vorher !== null && $vorher === $neu && !$bestaetigen) continue;
                $d = ['wert' => $neu, 'stand' => date('Y-m-d'), 'geaendert_am' => jetzt(), 'geaendert_von' => wer()];
                if (isset($alt[$fid])) aendern('feld_werte', (int)$alt[$fid]['id'], $d); else einfuegen('feld_werte', $d + ['feld_id' => $fid, 'objekt_id' => $oid]);
                $n++;
            }
            weiter($zur . ($bereich === 'anlage' ? '#anlage-' . $oid : '#angaben'), $n ? "$n Angaben gespeichert." : 'Keine Änderung.');
        case 'notiz_neu':
            einfuegen('notizen', ['kunde_id' => $kid, 'anlage_id' => (int)$_POST['anlage_id'] ?: null, 'text' => trim($_POST['text']), 'stand' => $_POST['stand'] ?: date('Y-m-d'),
                'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'aktiv' => 1]);
            weiter($zur . '#notizen', 'Notiz gespeichert.');
        case 'notiz_bestaetigen':
            aendern('notizen', (int)$_POST['id'], ['bestaetigt_am' => jetzt(), 'bestaetigt_von' => wer()]);
            weiter($zur . '#notizen', 'Als „noch aktuell" bestätigt.');
        case 'notiz_ersetzen':
            $n = eine('SELECT * FROM notizen WHERE id = ?', [(int)$_POST['id']]);
            aendern('notizen', (int)$n['id'], ['aktiv' => 0]);
            einfuegen('notizen', ['kunde_id' => $n['kunde_id'], 'anlage_id' => $n['anlage_id'], 'text' => trim($_POST['text']), 'stand' => date('Y-m-d'), 'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'aktiv' => 1]);
            weiter($zur . '#notizen', 'Notiz aktualisiert, die alte Fassung steht im Verlauf.');
        case 'notiz_loeschen':
            aendern('notizen', (int)$_POST['id'], ['aktiv' => 0]);
            weiter($zur . '#notizen', 'Notiz entfernt.');
        case 'rest_neu':
            einfuegen('restarbeiten', ['anlage_id' => (int)$_POST['anlage_id'], 'text' => trim($_POST['text']), 'material' => trim($_POST['material']) ?: null,
                'dauer_h' => $_POST['dauer_h'] !== '' ? (float)str_replace(',', '.', $_POST['dauer_h']) : null, 'status' => 'offen', 'erstellt_am' => jetzt(), 'erstellt_von' => wer(),
                'gemacht' => trim((string)($_POST['gemacht'] ?? '')) ?: null, 'quelle' => trim((string)($_POST['quelle'] ?? '')) ?: null, 'bericht_am' => ($_POST['bericht_am'] ?? '') ?: null]);
            weiter($zur . '#anlage-' . (int)$_POST['anlage_id'], 'Restarbeit dokumentiert. Sie steht zur Info im nächsten Termin dieser Anlage (wird nicht eigens geplant).');
        case 'rest_status':
            $neuSt = $_POST['status'] === 'erledigt' ? 'erledigt' : 'offen';
            aendern('restarbeiten', (int)$_POST['id'], ['status' => $neuSt, 'erledigt_am' => $neuSt === 'erledigt' ? jetzt() : null, 'erledigt_von' => $neuSt === 'erledigt' ? wer() : null]);
            weiter($_POST['zurueck'] ?? $zur, 'Restarbeit ' . ($neuSt === 'erledigt' ? 'erledigt.' : 'wieder offen.'));
        case 'ticket_neu':
            $aid = (int)$_POST['anlage_id'];
            if (!eine('SELECT id FROM anlagen WHERE id = ? AND kunde_id = ?', [$aid, $kid])) weiter($zur . '#tickets', 'Bitte eine Anlage wählen.', 'warn');
            $titel = trim((string)$_POST['titel']); $f = trim((string)($_POST['faellig_bis'] ?? ''));
            [$tj, $tq] = quartal_von(max($f !== '' ? $f : date('Y-m-d'), date('Y-m-d')));
            $mid = (int)($_POST['techniker_id'] ?? 0);
            $wid = einfuegen('wartungen', ['anlage_id' => $aid, 'jahr' => $tj, 'periode' => 'Ticket', 'quartal' => $tq, 'typ' => 'ticket', 'titel' => $titel,
                'beschreibung' => trim((string)$_POST['beschreibung']) ?: null, 'prio' => $_POST['prio'] ?? 'normal', 'faellig_bis' => $f !== '' ? $f : null,
                'dauer_h' => ($_POST['dauer_h'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['dauer_h']) : null, 'quelle' => trim((string)($_POST['quelle'] ?? '')) ?: null,
                'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'techniker_id' => $mid ?: null, 'status' => $mid ? 'zugewiesen' : 'offen']);
            $err = dokument_speichern($_FILES['datei'] ?? null, $kid, $aid, $wid, 'Ticket: ' . $titel);
            weiter($zur . '#tickets', 'Ticket angelegt. Es steht jetzt in der Organisation (' . quartal_name($tq) . " $tj) zum " . ($mid ? 'Terminieren' : 'Zuweisen') . ' bereit.' . ($err ? ' Datei nicht gespeichert: ' . h($err) : ''), $err ? 'warn' : 'ok');
        case 'ticket_speichern':
            $t = eine("SELECT * FROM wartungen WHERE id = ? AND typ = 'ticket'", [(int)$_POST['id']]);
            if (!$t) weiter($zur, 'Ticket nicht gefunden.', 'err');
            $f = trim((string)($_POST['faellig_bis'] ?? ''));
            $d = ['titel' => trim((string)$_POST['titel']), 'beschreibung' => trim((string)$_POST['beschreibung']) ?: null, 'prio' => $_POST['prio'] ?? 'normal',
                'faellig_bis' => $f !== '' ? $f : null, 'dauer_h' => ($_POST['dauer_h'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['dauer_h']) : null,
                'quelle' => trim((string)($_POST['quelle'] ?? '')) ?: null];
            if (in_array($t['status'], ['offen', 'zugewiesen'], true)) { [$d['jahr'], $d['quartal']] = quartal_von(max($f !== '' ? $f : date('Y-m-d'), date('Y-m-d'))); }
            aendern('wartungen', (int)$t['id'], $d);
            weiter($_POST['zurueck'] ?? $zur . '#tickets', 'Ticket gespeichert.');
        case 'ticket_storno':
            $n = q("UPDATE wartungen SET status = 'storniert', erledigt_am = ?, erledigt_von = ? WHERE id = ? AND typ = 'ticket' AND status IN ('offen','zugewiesen')", [jetzt(), wer(), (int)$_POST['id']])->rowCount();
            weiter($_POST['zurueck'] ?? $zur . '#tickets', $n ? 'Ticket storniert (bleibt in der Liste sichtbar).' : 'Nur offene oder zugewiesene Tickets können storniert werden – terminierte zuerst in der Organisation „Zurück in den Topf".', $n ? 'ok' : 'warn');
        case 'dokument_neu':
            if (empty($_FILES['datei']) || $_FILES['datei']['error'] === UPLOAD_ERR_NO_FILE) weiter($zur . '#dokumente', 'Bitte eine Datei auswählen.', 'warn');
            $err = dokument_speichern($_FILES['datei'], $kid, (int)($_POST['anlage_id'] ?? 0) ?: null, (int)($_POST['wartung_id'] ?? 0) ?: null, (string)($_POST['titel'] ?? ''));
            weiter($zur . '#dokumente', $err ? h($err) : 'Datei abgelegt.', $err ? 'err' : 'ok');
        case 'dokument_entfernen':
            q('UPDATE dokumente SET aktiv = 0 WHERE id = ? AND kunde_id = ?', [(int)$_POST['id'], $kid]);
            weiter($zur . '#dokumente', 'Datei aus der Akte entfernt (bleibt im Ablageordner erhalten).');
        case 'akku_aufteilen':
            $b = eine("SELECT * FROM bauteile WHERE id = ? AND aktiv = 1", [(int)$_POST['id']]);
            $z = akku_teile((string)$_POST['text'], 1);
            if (!$b || !$z['klar']) weiter($_POST['zurueck'] ?? $zur, 'Nicht verstanden. Bitte so schreiben: „2x 12 Ah, 1x 7 Ah".', 'warn');
            akku_ersetzen($b, $z['teile']);
            weiter($_POST['zurueck'] ?? $zur, 'Akku-Eintrag aufgeteilt: ' . h(implode(', ', array_map(fn($k, $v) => "{$v}× {$k}", array_keys($z['teile']), $z['teile']))) . '.');
        case 'akku_alle_aufteilen':
            $n = 0;
            foreach (alle("SELECT * FROM bauteile WHERE aktiv = 1 AND typ = 'Akku'") as $b) {
                $z = akku_teile($b['bezeichnung'], (int)$b['anzahl']);
                if (!$z['klar'] || (count($z['teile']) === 1 && array_key_first($z['teile']) === $b['bezeichnung'])) continue;
                akku_ersetzen($b, $z['teile']); $n++;
            }
            weiter('pflege.php?ansicht=akkus', "$n Akku-Einträge auf einheitliche Typen gebracht.");
        case 'meldung_erledigt':
            aendern('meldungen', (int)$_POST['id'], ['status' => 'erledigt', 'bearbeitet_am' => jetzt(), 'bearbeitet_von' => wer()]);
            weiter('pflege.php?ansicht=pruef#meldungen', 'Meldung als bearbeitet markiert.');
        case 'hinweis':
            $h = eine('SELECT * FROM import_hinweise WHERE id = ?', [(int)$_POST['id']]);
            if (($_POST['wie'] ?? '') === 'falsch' && $h['anlage_id']) aendern('anlagen', (int)$h['anlage_id'], ['zeit_h' => null, 'zeit_quelle' => 'Zuordnung verworfen']);
            q('UPDATE import_hinweise SET erledigt = 1 WHERE id = ?', [$h['id']]);
            weiter('pflege.php?ansicht=pruef#import', ($_POST['wie'] ?? '') === 'falsch' ? 'Zuordnung verworfen – Zeit bitte bei der richtigen Anlage eintragen.' : 'Erledigt.');
    }
    weiter('pflege.php');
}

$mit = mitarbeiter_namen();
$ansicht = isset($_GET['k']) ? 'kunde' : ($_GET['ansicht'] ?? 'suche');

/* ====================================================================
   Kundenakte
   ==================================================================== */
if ($ansicht === 'kunde') {
    $k = eine('SELECT * FROM kunden WHERE id = ?', [(int)$_GET['k']]);
    if (!$k) weiter('pflege.php', 'Kunde nicht gefunden.', 'err');
    $anl = alle('SELECT a.*, g.name AS gruppe FROM anlagen a LEFT JOIN gruppen g ON g.id = a.gruppe_id WHERE a.kunde_id = ? ORDER BY a.aktiv DESC, a.art', [$k['id']]);
    $gruppen = alle('SELECT id, name FROM gruppen ORDER BY name');
    $notizen = alle('SELECT n.*, a.art FROM notizen n LEFT JOIN anlagen a ON a.id = n.anlage_id WHERE n.kunde_id = ? ORDER BY n.aktiv DESC, n.stand DESC', [$k['id']]);
    $hist = alle(W_SELECT . ' WHERE a.kunde_id = ? AND (w.status <> \'offen\' OR w.jahr <= ?) ORDER BY w.jahr DESC, w.quartal DESC LIMIT 30', [$k['id'], (int)date('Y')]);
    $fahrt = fahrt_firma_min($k);
    $tickets = alle(W_SELECT . " WHERE a.kunde_id = ? AND w.typ = 'ticket' ORDER BY CASE WHEN w.status IN ('erledigt','storniert') THEN 1 ELSE 0 END, w.erstellt_am DESC", [$k['id']]);
    $doks = alle('SELECT d.*, a.art FROM dokumente d LEFT JOIN anlagen a ON a.id = d.anlage_id WHERE d.kunde_id = ? AND d.aktiv = 1 ORDER BY d.erstellt_am DESC', [$k['id']]);
    seite_kopf($k['name'], 'pflege', true);
    ?>
    <div class="kunde-kopf">
      <div><p class="hint"><a href="pflege.php">← Kundensuche</a></p><h1><?= h($k['name']) ?></h1>
        <p class="muted"><?= h($k['strasse']) ?>, <?= h($k['plz']) ?> <?= h($k['ort']) ?> · Kunden-Nr. <?= (int)$k['id'] ?>
        <?php if ($fahrt !== null): ?> · ca. <?= (int)round($fahrt) ?> Min. ab Firma<?php endif; ?></p></div>
      <div class="row"><?php if ($k['geo_status'] !== 'adresse'): ?><span class="pill warn"><?= h($k['geo_hinweis'] ?: 'Noch nicht auf der Karte') ?></span><?php endif; ?>
        <a class="btn ghost klein" href="#tickets">+ Ticket</a><a class="btn ghost klein" href="#dokumente">PDF ablegen</a><a class="btn ghost klein" href="akte.php?k=<?= $k['id'] ?>" target="_blank">Akte als PDF</a></div>
    </div>
    <div class="cols">
      <div class="stack">
        <form class="box" method="post">
          <h2>Stammdaten</h2><?= csrf_feld() ?><input type="hidden" name="aktion" value="kunde_speichern"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>">
          <label class="f">Name<input type="text" name="name" value="<?= h($k['name']) ?>" required></label>
          <label class="f">Straße<input type="text" name="strasse" value="<?= h($k['strasse']) ?>"></label>
          <div class="grid2"><label class="f">PLZ<input type="text" name="plz" value="<?= h($k['plz']) ?>"></label><label class="f">Ort<input type="text" name="ort" value="<?= h($k['ort']) ?>"></label></div>
          <label class="f">Ansprechpartner<input type="text" name="ansprechpartner" value="<?= h($k['ansprechpartner']) ?>"></label>
          <label class="f">Telefon<input type="text" name="telefon" value="<?= h($k['telefon']) ?>"></label>
          <button class="btn" type="submit">Speichern</button>
        </form>
        <?php if ($k['lat'] !== null) karte('k-karte', [['lat' => (float)$k['lat'], 'lon' => (float)$k['lon'], 'label' => $k['name'], 'nr' => '']]); ?>
        <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="aktion" value="geo_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>">
          <?php foreach (['strasse', 'plz', 'ort'] as $f): ?><input type="hidden" name="<?= $f ?>" value="<?= h($k[$f]) ?>"><?php endforeach; ?>
          <button class="btn ghost klein" type="submit">Adresse neu auf der Karte suchen</button></form>

        <?php if (felder('kunde')): ?><div class="box" id="angaben"><h2>Weitere Angaben</h2><?php felder_formular('kunde', (int)$k['id'], (int)$k['id']); ?></div><?php endif; ?>
        <div class="box" id="notizen">
          <h2>Notizen</h2>
          <?php $aktiv = array_filter($notizen, fn($n) => $n['aktiv']); if (!$aktiv): ?><p class="leer">Noch keine Notizen.</p><?php endif; ?>
          <?php foreach ($aktiv as $n): $stand = $n['bestaetigt_am'] ? substr($n['bestaetigt_am'], 0, 10) : $n['stand']; $alt = alter_jahre($stand) >= (float)cfg('notiz_alter_jahre'); ?>
            <div style="border-top:1px solid var(--line);padding-top:8px">
              <div><?= nl2br(h($n['text'])) ?></div>
              <p class="hint"><?= $n['art'] ? '<span class="tag">' . h($n['art']) . '</span>' : 'ganzer Kunde · ' ?>
                Stand <span class="<?= $alt ? 'alt' : '' ?>"><?= monat_jahr($stand) ?><?= $alt ? ' – bitte prüfen' : '' ?></span>
                · <?= h($n['erstellt_von']) ?><?= $n['bestaetigt_am'] ? ' · bestätigt ' . datum($n['bestaetigt_am']) . ' von ' . h($n['bestaetigt_von']) : '' ?></p>
              <div class="row">
                <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="notiz_bestaetigen"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $n['id'] ?>"><button class="btn ghost klein">Noch aktuell</button></form>
                <details><summary class="btn ghost klein">Ändern</summary>
                  <form method="post" class="stack" style="margin-top:6px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="notiz_ersetzen"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $n['id'] ?>">
                    <textarea name="text" required><?= h($n['text']) ?></textarea><button class="btn klein">Speichern</button></form></details>
                <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="notiz_loeschen"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $n['id'] ?>"><button class="btn ghost klein">Entfernen</button></form>
              </div>
            </div>
          <?php endforeach; ?>
          <details><summary class="btn ghost klein">+ Notiz hinzufügen</summary>
            <form method="post" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="notiz_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>">
              <label class="f">Text<textarea name="text" required placeholder="z. B. 10 Fenster, 2 Etagen, 3 Türen"></textarea></label>
              <div class="grid2"><label class="f">Gilt für<select name="anlage_id"><option value="">ganzen Kunden</option><?php foreach ($anl as $a): ?><option value="<?= $a['id'] ?>"><?= h($a['art']) ?></option><?php endforeach; ?></select></label>
                <label class="f">Stand (wann erhoben)<input type="date" name="stand" value="<?= date('Y-m-d') ?>"></label></div>
              <button class="btn klein">Speichern</button></form></details>
          <?php $inaktiv = array_filter($notizen, fn($n) => !$n['aktiv']); if ($inaktiv): ?>
            <details><summary class="hint">Verlauf (<?= count($inaktiv) ?> ältere Fassungen)</summary>
              <?php foreach ($inaktiv as $n): ?><p class="hint"><s><?= h($n['text']) ?></s> · Stand <?= monat_jahr($n['stand']) ?>, <?= h($n['erstellt_von']) ?></p><?php endforeach; ?></details>
          <?php endif; ?>
        </div>
        <div class="box" id="dokumente"><h2>Dokumente (PDF)</h2>
          <?php if (!$doks): ?><p class="leer">Noch keine Dateien abgelegt.</p><?php endif; ?>
          <?php foreach ($doks as $d): ?><div class="row" style="justify-content:space-between;border-top:1px solid var(--line);padding-top:6px">
            <span><a href="dokument.php?id=<?= $d['id'] ?>" target="_blank"><b><?= h($d['titel']) ?></b></a><br><span class="hint"><?= $d['art'] ? h($d['art']) . ' · ' : '' ?><?= $d['wartung_id'] ? 'zu Nr. ' . (int)$d['wartung_id'] . ' · ' : '' ?><?= dateigroesse((int)$d['groesse']) ?> · <?= datum($d['erstellt_am']) ?>, <?= h($d['erstellt_von']) ?></span></span>
            <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="dokument_entfernen"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn ghost klein" onclick="return confirm('Datei aus der Akte entfernen?')">Entfernen</button></form></div><?php endforeach; ?>
          <details<?= !$doks ? ' open' : '' ?>><summary class="btn ghost klein">+ PDF / Foto ablegen</summary>
            <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="dokument_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>">
              <input type="file" name="datei" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*" required>
              <label class="f">Bezeichnung<input type="text" name="titel" placeholder="z. B. Stundennachweis 4711, Wartungsbericht 2026"></label>
              <label class="f">Gehört zu<select name="anlage_id"><option value="">ganzem Kunden</option><?php foreach ($anl as $a): ?><option value="<?= $a['id'] ?>"><?= h($a['art']) ?></option><?php endforeach; ?></select></label>
              <button class="btn klein">Ablegen</button>
              <p class="hint">PDF, JPG oder PNG, höchstens <?= h(ini_get('upload_max_filesize')) ?>. Die Datei liegt geschützt auf dem Server und ist nur angemeldet abrufbar.</p></form></details>
        </div>
      </div>

      <div class="stack">
        <div class="box" id="tickets"><h2>Tickets</h2>
          <p class="hint">Aufträge außerhalb der Wartung (Störung, Reparatur, Erweiterung). Sie werden in der Organisation wie Wartungen zugewiesen und in Touren eingeplant.</p>
          <?php if (!$tickets): ?><p class="leer">Keine Tickets.</p><?php endif; ?>
          <?php foreach ($tickets as $t): ?>
            <div style="border-top:1px solid var(--line);padding-top:8px" class="stack">
              <div class="kunde-kopf"><span><b><?= h($t['titel']) ?></b> <span class="tag"><?= h($t['art']) ?></span><?= $t['prio'] === 'dringend' ? ' <span class="pill err">dringend</span>' : '' ?></span>
                <span><?= status_pill($t['status']) ?><?= $t['beginn'] ? ' <span class="mono">' . datum($t['beginn'], true) . ' ' . substr($t['beginn'], 11, 5) . '</span>' : '' ?></span></div>
              <?php if ($t['beschreibung']): ?><div class="hint" style="white-space:pre-wrap"><?= h($t['beschreibung']) ?></div><?php endif; ?>
              <p class="hint">Nr. <?= $t['id'] ?> · eingegangen <?= datum($t['erstellt_am']) ?><?= $t['quelle'] ? ' (' . h($t['quelle']) . ')' : '' ?>, <?= h($t['erstellt_von']) ?><?= $t['faellig_bis'] ? ' · fällig bis <b>' . datum($t['faellig_bis']) . '</b>' : '' ?>
                <?= $t['dauer_h'] !== null ? ' · ca. ' . std($t['dauer_h']) . ' vor Ort' : '' ?> · <?= $t['techniker_id'] ? h($mit[(int)$t['techniker_id']] ?? '') : 'noch niemand zugewiesen' ?> · <?= quartal_name((int)$t['quartal']) ?> <?= $t['jahr'] ?></p>
              <?php if (in_array($t['status'], ['offen', 'zugewiesen'], true)): ?>
              <div class="row"><details><summary class="btn ghost klein">Bearbeiten</summary>
                <form method="post" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="ticket_speichern"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <?php ticket_felder($t, $anl, $mit); ?><button class="btn klein">Speichern</button></form></details>
                <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="ticket_storno"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn ghost klein" onclick="return confirm('Ticket stornieren?')">Stornieren</button></form></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (array_filter($anl, fn($a) => $a['aktiv'])): ?>
          <details<?= ($_GET['neu'] ?? '') === 'ticket' ? ' open' : '' ?>><summary class="btn ghost klein">+ Neues Ticket</summary>
            <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="ticket_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>">
              <?php ticket_felder(null, $anl, $mit); ?>
              <label class="f">PDF / Foto anhängen (optional)<input type="file" name="datei" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*"></label>
              <button class="btn klein">Ticket anlegen</button></form></details>
          <?php else: ?><p class="hint">Für ein Ticket braucht der Kunde mindestens eine aktive Anlage.</p><?php endif; ?>
        </div>
      <?php foreach ($anl as $a):
          $teile = alle('SELECT * FROM bauteile WHERE anlage_id = ? ORDER BY aktiv DESC, typ, eingebaut DESC', [$a['id']]);
          $rest = alle('SELECT * FROM restarbeiten WHERE anlage_id = ? ORDER BY status DESC, erstellt_am DESC', [$a['id']]);
          $vo = vor_ort_min($a, $fahrt); ?>
        <div class="box" id="anlage-<?= $a['id'] ?>">
          <div class="kunde-kopf"><h2><span class="tag"><?= h($a['art']) ?></span> Anlage <?= $a['id'] ?><?= $a['aktiv'] ? '' : ' <span class="pill neutral">inaktiv</span>' ?></h2>
            <span class="muted"><?= h(str_replace('|', ', ', (string)$a['perioden'])) ?></span></div>
          <p class="hint">Zeit laut Liste: <b><?= $a['zeit_h'] !== null ? std($a['zeit_h']) : '<span class="alt">fehlt</span>' ?></b> inkl. Fahrt
            → vor Ort ca. <?= std($vo / 60) ?><?= $a['vor_ort_h'] !== null ? ' (fest eingetragen)' : '' ?>
            · Stammtechniker: <b><?= h($mit[(int)$a['stammtechniker_id']] ?? '–') ?></b>
            <?= $a['gruppe'] ? ' · Gruppe: <b>' . h($a['gruppe']) . '</b>' : '' ?></p>
          <details><summary class="btn ghost klein">Anlage bearbeiten</summary>
            <form method="post" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="anlage_speichern"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="anlage_id" value="<?= $a['id'] ?>">
              <div class="grid3"><label class="f">Anlagenart<input type="text" name="art" value="<?= h($a['art']) ?>" list="art-liste"></label>
                <label class="f">Zeit inkl. Fahrt (Std.)<input type="text" name="zeit_h" value="<?= $a['zeit_h'] !== null ? h(str_replace('.', ',', (string)(float)$a['zeit_h'])) : '' ?>" inputmode="decimal"></label>
                <label class="f">Vor Ort fest (Std., optional)<input type="text" name="vor_ort_h" value="<?= $a['vor_ort_h'] !== null ? h(str_replace('.', ',', (string)(float)$a['vor_ort_h'])) : '' ?>" inputmode="decimal"></label></div>
              <div class="row"><?php $per = explode('|', (string)$a['perioden']); foreach (PERIODEN as $p): ?><label class="hint"><input type="checkbox" name="perioden[]" value="<?= h($p) ?>"<?= in_array($p, $per, true) ? ' checked' : '' ?>> <?= h($p) ?></label><?php endforeach; ?></div>
              <div class="grid2"><label class="f">Stammtechniker<select name="stammtechniker_id"><option value="">–</option><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"<?= (int)$a['stammtechniker_id'] === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
                <label class="f">Gruppe (immer zusammen planen)<select name="gruppe_id"><option value="">keine</option><?php foreach ($gruppen as $g): ?><option value="<?= $g['id'] ?>"<?= (int)$a['gruppe_id'] === (int)$g['id'] ? ' selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?><option value="neu">+ neue Gruppe …</option></select></label></div>
              <label class="f">Name der neuen Gruppe (nur bei „+ neue Gruppe")<input type="text" name="gruppe_neu"></label>
              <label class="hint"><input type="checkbox" name="aktiv" value="1"<?= $a['aktiv'] ? ' checked' : '' ?>> Anlage aktiv (wird gewartet)</label>
              <button class="btn klein">Speichern</button></form></details>

          <?php felder_formular('anlage', (int)$a['id'], (int)$k['id']); ?>
          <h3>Bauteile</h3>
          <?php $akt = array_filter($teile, fn($b) => $b['aktiv']); if (!$akt): ?><p class="leer">Keine Bauteile erfasst.</p><?php else: ?>
          <div class="scroll"><table class="tbl"><tr><th>Typ</th><th>Bezeichnung</th><th class="r">Anzahl</th><th>Akku-Typ</th><th>Eingebaut</th><th>Erfasst</th><th></th></tr>
          <?php foreach ($akt as $b): $warn = $b['typ'] === 'Akku' && $b['eingebaut'] && alter_jahre($b['eingebaut']) >= (float)cfg('akku_tausch_jahre'); ?>
            <tr><td><?= h($b['typ']) ?></td><td><?= h($b['bezeichnung']) ?><?= $b['bemerkung'] ? '<br><span class="hint">' . h($b['bemerkung']) . '</span>' : '' ?></td><td class="r"><?= (int)$b['anzahl'] ?></td><td><?= akku_typ_zelle($b, 'pflege.php?k=' . $k['id'] . '#anlage-' . $a['id']) ?></td>
              <td><?= $b['eingebaut'] ? monat_jahr($b['eingebaut']) : '<span class="alt">unbekannt</span>' ?><?= $warn ? ' <span class="pill warn">tauschen</span>' : '' ?></td>
              <td class="hint"><?= datum($b['erstellt_am']) ?>, <?= h($b['erstellt_von']) ?></td>
              <td><form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="bauteil_entfernen"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn ghost klein">Entfernen</button></form></td></tr>
          <?php endforeach; ?></table></div><?php endif; ?>
          <details><summary class="btn ghost klein">+ Tausch / Änderung eintragen</summary>
            <form method="post" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="bauteil_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="anlage_id" value="<?= $a['id'] ?>">
              <div class="grid4"><label class="f">Typ<select name="typ"><?php foreach (liste('bauteil_typ') as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select></label>
                <label class="f">Bezeichnung / Akku-Typ<input type="text" name="bezeichnung" list="bez-liste" required placeholder="z. B. 7,2 Ah, BP1"></label>
                <label class="f">Anzahl<input type="number" name="anzahl" value="1" min="1"></label>
                <label class="f">Eingebaut<input type="month" name="eingebaut" value="<?= date('Y-m') ?>"></label></div>
              <?php if ($akt): ?><div class="hint">Ersetzt (kommt in den Verlauf):<?php foreach ($akt as $b): ?> <label><input type="checkbox" name="ersetzt[]" value="<?= $b['id'] ?>"> <?= h($b['anzahl'] . '× ' . $b['bezeichnung']) ?> (<?= $b['eingebaut'] ? monat_jahr($b['eingebaut']) : '?' ?>)</label><?php endforeach; ?></div><?php endif; ?>
              <label class="f">Bemerkung<input type="text" name="bemerkung" placeholder="z. B. laut Stundennachweis Nr. …"></label>
              <button class="btn klein">Eintragen</button></form></details>
          <?php $verl = array_filter($teile, fn($b) => !$b['aktiv']); if ($verl): ?>
            <details><summary class="hint">Verlauf (<?= count($verl) ?>)</summary><?php foreach ($verl as $b): ?><p class="hint"><s><?= h($b['typ'] . ' ' . $b['anzahl'] . '× ' . $b['bezeichnung']) ?>, eingebaut <?= $b['eingebaut'] ? monat_jahr($b['eingebaut']) : '?' ?></s> – ersetzt <?= datum($b['ersetzt_am']) ?></p><?php endforeach; ?></details>
          <?php endif; ?>

          <h3>Restarbeiten</h3>
          <?php if (!$rest): ?><p class="leer">Keine.</p><?php endif; ?>
          <?php foreach ($rest as $r): ?>
            <div class="row" style="justify-content:space-between">
              <span><?= $r['status'] === 'offen' ? '<span class="pill warn">offen</span>' : '<span class="pill ok">erledigt</span>' ?> <b>Noch zu tun:</b> <?= h($r['text']) ?>
                <?= $r['gemacht'] ? '<br><span class="hint"><b>Gemacht:</b> ' . h($r['gemacht']) . '</span>' : '' ?>
                <br><span class="hint"><?= $r['quelle'] ? h($r['quelle']) . ' · ' : '' ?><?= $r['bericht_am'] ? 'Bericht vom ' . datum($r['bericht_am']) . ' · ' : '' ?><?= $r['material'] ? h($r['material']) . ' · ' : '' ?><?= $r['dauer_h'] ? 'ca. ' . std($r['dauer_h']) . ' · ' : '' ?>dokumentiert <?= datum($r['erstellt_am']) ?>, <?= h($r['erstellt_von']) ?></span></span>
              <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="rest_status"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="status" value="<?= $r['status'] === 'offen' ? 'erledigt' : 'offen' ?>"><button class="btn ghost klein"><?= $r['status'] === 'offen' ? 'Erledigt' : 'Wieder öffnen' ?></button></form>
            </div>
          <?php endforeach; ?>
          <details><summary class="btn ghost klein">+ Restarbeit aus Bericht dokumentieren</summary>
            <form method="post" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="rest_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>"><input type="hidden" name="anlage_id" value="<?= $a['id'] ?>">
              <p class="hint">Nur zur Information: steht im nächsten Termin dieser Anlage, wird aber nicht eigens geplant. Soll etwas eigens eingeplant werden, ein Ticket anlegen.</p>
              <label class="f">Was wurde gemacht? (laut Bericht)<textarea name="gemacht" placeholder="z. B. Wartung durchgeführt, Melder 3 OG ausgelöst, Akku geprüft"></textarea></label>
              <label class="f">Was muss noch gemacht werden?<input type="text" name="text" required placeholder="z. B. Melder Flur EG tauschen"></label>
              <div class="grid4"><label class="f">Bericht vom<input type="date" name="bericht_am" value="<?= date('Y-m-d') ?>"></label><label class="f">Quelle<input type="text" name="quelle" placeholder="z. B. Stundennachweis 4711"></label>
                <label class="f">Material<input type="text" name="material"></label><label class="f">Zusätzliche Zeit (Std.)<input type="text" name="dauer_h" inputmode="decimal"></label></div>
              <button class="btn klein">Dokumentieren</button></form></details>
        </div>
      <?php endforeach; ?>
        <form class="box" method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="anlage_neu"><input type="hidden" name="kunde_id" value="<?= $k['id'] ?>">
          <div class="row"><input type="text" name="art" placeholder="Anlagenart, z. B. EMA" style="max-width:240px" list="art-liste"><button class="btn ghost klein">+ Anlage hinzufügen</button></div></form>

        <div class="box"><h2>Wartungen</h2>
          <?php if (!$hist): ?><p class="leer">Noch keine.</p><?php else: ?>
          <div class="scroll"><table class="tbl"><tr><th>Jahr</th><th>Periode</th><th>Anlage</th><th>Techniker</th><th>Termin</th><th>Status</th></tr>
          <?php foreach ($hist as $w): ?><tr><td><?= $w['jahr'] ?></td><td><?= h($w['periode']) ?></td><td><?= ist_ticket($w) ? '<span class="pill info">Ticket</span> ' . h($w['titel']) : h($w['art']) ?></td><td><?= h($mit[(int)$w['techniker_id']] ?? '–') ?></td>
            <td class="mono"><?= $w['beginn'] ? datum($w['beginn'], true) . ' ' . substr($w['beginn'], 11, 5) : '–' ?></td><td><?= status_pill($w['status']) ?><?= $w['ist_h'] ? ' <span class="hint">' . std($w['ist_h']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
          </table></div><?php endif; ?></div>
      </div>
    </div>
    <datalist id="bez-liste"><?php foreach (array_unique(array_merge(liste('akku_typ'), liste('bauteil_bezeichnung'))) as $b): ?><option value="<?= h($b) ?>"><?php endforeach; ?></datalist>
    <datalist id="art-liste"><?php foreach (liste('anlagenart') as $b): ?><option value="<?= h($b) ?>"><?php endforeach; ?></datalist>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Tickets: alle Aufträge außerhalb der Wartung, informativ wie eine Wartung
   ==================================================================== */
if ($ansicht === 'tickets') {
    $st = $_GET['status'] ?? 'laufend';
    $alle = alle(W_SELECT . " WHERE w.typ = 'ticket' ORDER BY CASE WHEN w.prio = 'dringend' THEN 0 ELSE 1 END, COALESCE(w.faellig_bis, '9999'), w.erstellt_am");
    $zaehl = ['laufend' => 0, 'offen' => 0, 'zugewiesen' => 0, 'terminiert' => 0, 'erledigt' => 0, 'storniert' => 0, 'alle' => count($alle)];
    foreach ($alle as $t) { $zaehl[$t['status']] = ($zaehl[$t['status']] ?? 0) + 1; if (in_array($t['status'], ['offen', 'zugewiesen', 'terminiert'], true)) $zaehl['laufend']++; }
    $sicht = array_filter($alle, fn($t) => $st === 'alle' || $t['status'] === $st || ($st === 'laufend' && in_array($t['status'], ['offen', 'zugewiesen', 'terminiert'], true)));
    $kl = alle('SELECT id, name, ort FROM kunden ORDER BY name');
    seite_kopf('Tickets', 'pflege');
    pflege_nav('tickets'); ?>
    <div class="box"><h2>Neues Ticket</h2>
      <form method="get" action="pflege.php" class="row" onsubmit="const o=[...document.querySelectorAll('#kunden-liste option')].find(x=>x.value===this.kunde.value); if(o){location.href='pflege.php?k='+o.dataset.id+'&neu=ticket#tickets'; return false;}">
        <input type="search" name="q" id="kunde" list="kunden-liste" placeholder="Kunde suchen (Name oder Ort)" style="flex:1;min-width:240px" required><button class="btn">Weiter zum Kunden</button></form>
      <datalist id="kunden-liste"><?php foreach ($kl as $k): ?><option value="<?= h($k['name'] . ' · ' . $k['ort']) ?>" data-id="<?= $k['id'] ?>"><?php endforeach; ?></datalist>
      <p class="hint">Ein Ticket gehört immer zu einer Anlage eines Kunden. Es erscheint danach in der Organisation zum Zuweisen und wird in den Touren wie eine Wartung eingeplant.</p>
    </div>
    <div class="box">
      <div class="row"><?php foreach (['laufend' => 'Laufend', 'offen' => 'Offen', 'zugewiesen' => 'Zugewiesen', 'terminiert' => 'Terminiert', 'erledigt' => 'Erledigt', 'storniert' => 'Storniert', 'alle' => 'Alle'] as $k => $n): ?>
        <a class="btn <?= $st === $k ? '' : 'ghost' ?> klein" href="pflege.php?ansicht=tickets&status=<?= $k ?>"><?= $n ?> <?= $zaehl[$k] ?></a><?php endforeach; ?></div>
      <div class="scroll"><table class="tbl"><tr><th>Nr.</th><th>Eingang</th><th>Kunde</th><th>Anlage</th><th>Was</th><th>Fällig</th><th class="r">Dauer</th><th>Mitarbeiter</th><th>Status</th></tr>
      <?php foreach ($sicht as $t): $ueber = $t['faellig_bis'] && $t['faellig_bis'] < date('Y-m-d') && in_array($t['status'], ['offen', 'zugewiesen'], true); ?>
        <tr><td class="mono"><?= $t['id'] ?></td><td class="hint"><?= datum($t['erstellt_am']) ?><?= $t['quelle'] ? '<br>' . h($t['quelle']) : '' ?></td>
          <td><a href="pflege.php?k=<?= $t['kunde_id'] ?>#tickets"><?= h($t['kunde']) ?></a><br><span class="hint"><?= h($t['ort']) ?></span></td><td><span class="tag"><?= h($t['art']) ?></span></td>
          <td><b><?= h($t['titel']) ?></b><?= $t['prio'] === 'dringend' ? ' <span class="pill err">dringend</span>' : '' ?><?= $t['beschreibung'] ? '<br><span class="hint">' . h(mb_strimwidth($t['beschreibung'], 0, 90, '…')) . '</span>' : '' ?></td>
          <td><?= $t['faellig_bis'] ? '<span class="' . ($ueber ? 'alt' : '') . '">' . datum($t['faellig_bis']) . '</span>' : '–' ?></td>
          <td class="r"><?= $t['dauer_h'] !== null ? zahl($t['dauer_h'], 2) : '<span class="alt" title="Dauer fehlt – geplant wird mit der Zeit der Anlage">?</span>' ?></td>
          <td><?= h($mit[(int)$t['techniker_id']] ?? '–') ?></td>
          <td><?= status_pill($t['status']) ?><?= $t['beginn'] ? '<br><span class="mono">' . datum($t['beginn'], true) . ' ' . substr($t['beginn'], 11, 5) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$sicht): ?><tr><td colspan="9" class="leer">Keine Tickets in dieser Ansicht.</td></tr><?php endif; ?>
      </table></div>
      <?php if (darf('organisation')): ?><p class="hint">Zuweisen und planen: <a href="organisation.php?status=offen&typ=ticket">Organisation → nur Tickets</a>.</p><?php endif; ?>
    </div>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Akkus nach Typ: Bestand, fällig, Bedarf für ein Quartal (je Mitarbeiter)
   ==================================================================== */
if ($ansicht === 'akkus') {
    [$jA, $qA] = aktuelles_quartal();
    $jahr = (int)($_GET['j'] ?? $jA); $qu = max(1, min(4, (int)($_GET['q'] ?? $qA))); $filter = (string)($_GET['typ'] ?? '');
    [$qv, $qb] = quartal_von_bis($jahr, $qu);
    $rows = alle("SELECT b.*, a.art, a.kunde_id, k.name AS kunde, k.ort FROM bauteile b JOIN anlagen a ON a.id = b.anlage_id JOIN kunden k ON k.id = a.kunde_id WHERE b.aktiv = 1 AND b.typ = 'Akku' AND a.aktiv = 1 ORDER BY k.name");
    $typen = []; $unklar = []; $ohneDatum = 0; $treffer = [];
    foreach ($rows as $b) {
        $z = akku_teile($b['bezeichnung'], (int)$b['anzahl']);
        if (!$z['klar']) { $unklar[] = $b; continue; }
        if (!$b['eingebaut']) $ohneDatum++;
        foreach ($z['teile'] as $t => $n) {
            $typen[$t] ??= ['anlagen' => [], 'stueck' => 0, 'faellig' => 0, 'faellig_q' => 0, 'unbekannt' => 0];
            $typen[$t]['anlagen'][$b['anlage_id']] = true; $typen[$t]['stueck'] += $n;
            if (!$b['eingebaut']) $typen[$t]['unbekannt'] += $n;
            elseif (akku_faellig($b)) $typen[$t]['faellig'] += $n;
            if ($b['eingebaut'] && akku_faellig($b, $qb)) $typen[$t]['faellig_q'] += $n;
            if ($filter === $t) $treffer[] = $b + ['_n' => $n];
        }
    }
    uksort($typen, fn($a, $b) => (float)str_replace(',', '.', $a) <=> (float)str_replace(',', '.', $b));
    // Bedarf: Wartungen/Tickets des Quartals, die noch nicht erledigt sind, je Mitarbeiter
    $ws = array_filter(wartungen_quartal($jahr, $qu), fn($w) => in_array($w['status'], ['offen', 'zugewiesen', 'terminiert'], true));
    $proM = []; foreach ($ws as $w) $proM[(int)$w['techniker_id']][] = $w;
    $bedarf = []; $spalten = [];
    foreach ($proM as $mid => $liste) {
        $sum = ['typen' => [], 'unklar' => []];
        foreach ($liste as $w) { $bd = akku_bedarf([(int)$w['anlage_id']], $w['beginn'] ? substr($w['beginn'], 0, 10) : $qb);
            foreach ($bd['typen'] as $t => $n) { $sum['typen'][$t] = ($sum['typen'][$t] ?? 0) + $n; $spalten[$t] = true; } array_push($sum['unklar'], ...$bd['unklar']); }
        $bedarf[$mid] = $sum;
    }
    uksort($spalten, fn($a, $b) => (float)str_replace(',', '.', $a) <=> (float)str_replace(',', '.', $b));
    seite_kopf('Akkus', 'pflege');
    pflege_nav('akkus'); ?>
    <div class="box"><div class="row" style="justify-content:space-between"><h2>Akku-Bestand nach Typ</h2>
      <a class="btn ghost klein no-print" href="javascript:print()">Drucken / als PDF</a></div>
      <div class="scroll"><table class="tbl"><tr><th>Typ</th><th class="r">Anlagen</th><th class="r">Stück</th><th class="r">Jetzt fällig (≥ <?= cfg('akku_tausch_jahre') ?> J.)</th><th class="r">Fällig bis Ende Q<?= $qu ?>/<?= $jahr ?></th><th class="r">Einbau unbekannt</th><th></th></tr>
      <?php foreach ($typen as $t => $d): ?><tr<?= $filter === $t ? ' class="vorschlag"' : '' ?>><td><b><?= h($t) ?></b></td><td class="r"><?= count($d['anlagen']) ?></td><td class="r"><?= $d['stueck'] ?></td>
        <td class="r"><?= $d['faellig'] ? '<span class="alt">' . $d['faellig'] . '</span>' : 0 ?></td><td class="r"><?= $d['faellig_q'] ?></td><td class="r"><?= $d['unbekannt'] ?: '' ?></td>
        <td><a class="hint" href="pflege.php?<?= http_build_query(['ansicht' => 'akkus', 'typ' => $t, 'j' => $jahr, 'q' => $qu]) ?>#liste">Anlagen zeigen</a></td></tr><?php endforeach; ?>
      </table></div>
      <p class="hint">Die Typen werden aus der Bezeichnung gelesen (z. B. „2x12AH 1x7Ah“ = 2× 12 Ah und 1× 7 Ah). <?= count($unklar) ?> Einträge sind nicht eindeutig lesbar, <?= $ohneDatum ?> Einträge ohne Einbaudatum.</p>
    </div>

    <div class="box"><div class="row" style="justify-content:space-between"><h2>Bedarf zum Mitnehmen – <?= quartal_name($qu) ?> <?= $jahr ?></h2>
      <form method="get" class="row no-print"><input type="hidden" name="ansicht" value="akkus"><select style="width:auto" name="j" onchange="this.form.submit()"><?php for ($y = $jA; $y <= $jA + 1; $y++): ?><option<?= $y === $jahr ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
        <select style="width:auto" name="q" onchange="this.form.submit()"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"<?= $i === $qu ? ' selected' : '' ?>><?= quartal_name($i) ?></option><?php endfor; ?></select></form></div>
      <?php if (!$spalten): ?><p class="leer">Für dieses Quartal sind keine Akkus fällig.</p><?php else: ?>
      <div class="scroll"><table class="tbl"><tr><th>Mitarbeiter</th><?php foreach ($spalten as $t => $_): ?><th class="r"><?= h($t) ?></th><?php endforeach; ?><th>Außerdem prüfen</th></tr>
      <?php $summe = []; foreach ($bedarf as $mid => $bd): if (!$bd['typen'] && !$bd['unklar']) continue; ?><tr><td><b><?= h($mit[$mid] ?? 'noch nicht zugewiesen') ?></b></td>
        <?php foreach ($spalten as $t => $_): $n = $bd['typen'][$t] ?? 0; $summe[$t] = ($summe[$t] ?? 0) + $n; ?><td class="r"><?= $n ?: '' ?></td><?php endforeach; ?><td class="hint"><?= h(implode('; ', $bd['unklar'])) ?></td></tr><?php endforeach; ?>
      <tr><td><b>Summe</b></td><?php foreach ($spalten as $t => $_): ?><td class="r"><b><?= $summe[$t] ?? 0 ?></b></td><?php endforeach; ?><td></td></tr>
      </table></div><?php endif; ?>
      <p class="hint">Alle offenen, zugewiesenen und terminierten Wartungen und Tickets des Quartals, deren Anlage einen Akku hat, der zum Termin (oder Quartalsende) älter als <?= cfg('akku_tausch_jahre') ?> Jahre ist. Pro Tag steht der Bedarf auch im Kalender und in den Terminen.</p>
    </div>

    <?php if ($unklar): ?><div class="box"><div class="row" style="justify-content:space-between"><h2>Nicht eindeutig lesbar (<?= count($unklar) ?>)</h2>
      <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="akku_alle_aufteilen"><button class="btn ghost klein" onclick="return confirm('Alle lesbaren Sammelbezeichnungen (z. B. „2x12AH 1x7Ah“) in einzelne Typ-Einträge aufteilen? Die alten Einträge bleiben im Verlauf.')">Alle lesbaren vereinheitlichen</button></form></div>
      <p class="hint">Bitte die richtige Aufteilung eintragen, z. B. „2x 12 Ah, 1x 7 Ah“. Der alte Eintrag bleibt im Verlauf der Anlage.</p>
      <div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Anlage</th><th>Bezeichnung</th><th class="r">Anzahl</th><th>Eingebaut</th><th>Typ festlegen</th></tr>
      <?php foreach ($unklar as $b): ?><tr><td><a href="pflege.php?k=<?= $b['kunde_id'] ?>#anlage-<?= $b['anlage_id'] ?>"><?= h($b['kunde']) ?></a></td><td><?= h($b['art']) ?></td><td><?= h($b['bezeichnung'] ?: '(leer)') ?></td><td class="r"><?= (int)$b['anzahl'] ?></td><td><?= $b['eingebaut'] ? monat_jahr($b['eingebaut']) : '–' ?></td>
        <td><?= akku_typ_zelle($b, 'pflege.php?ansicht=akkus') ?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?>

    <?php if ($filter !== ''): ?><div class="box" id="liste"><h2>Anlagen mit <?= h($filter) ?> (<?= count($treffer) ?>)</h2>
      <div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Ort</th><th>Anlage</th><th class="r">Stück</th><th>Eingebaut</th><th></th></tr>
      <?php usort($treffer, fn($a, $b) => strcmp((string)$a['eingebaut'], (string)$b['eingebaut'])); foreach ($treffer as $b): ?><tr><td><a href="pflege.php?k=<?= $b['kunde_id'] ?>#anlage-<?= $b['anlage_id'] ?>"><?= h($b['kunde']) ?></a></td><td><?= h($b['ort']) ?></td><td><?= h($b['art']) ?></td><td class="r"><?= $b['_n'] ?></td>
        <td><?= $b['eingebaut'] ? monat_jahr($b['eingebaut']) : '<span class="alt">unbekannt</span>' ?></td><td><?= akku_faellig($b) ? '<span class="pill warn">tauschen</span>' : '' ?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Prüfliste
   ==================================================================== */
if ($ansicht === 'pruef') {
    seite_kopf('Prüfliste', 'pflege');
    $meld = alle("SELECT m.*, k.name AS kunde FROM meldungen m LEFT JOIN kunden k ON k.id = m.kunde_id WHERE m.status = 'offen' ORDER BY m.erstellt_am DESC");
    $rest = alle("SELECT r.*, a.art, k.id AS kid, k.name AS kunde FROM restarbeiten r JOIN anlagen a ON a.id = r.anlage_id JOIN kunden k ON k.id = a.kunde_id WHERE r.status = 'offen' ORDER BY r.erstellt_am DESC");
    $grenzeAkku = (new DateTimeImmutable())->modify('-' . (int)cfg('akku_tausch_jahre') . ' years')->format('Y-m-d');
    $akkus = alle("SELECT b.*, a.art, k.id AS kid, k.name AS kunde FROM bauteile b JOIN anlagen a ON a.id = b.anlage_id JOIN kunden k ON k.id = a.kunde_id WHERE b.aktiv = 1 AND b.typ = 'Akku' AND b.eingebaut <= ? ORDER BY b.eingebaut", [$grenzeAkku]);
    $grenzeNotiz = (new DateTimeImmutable())->modify('-' . (int)cfg('notiz_alter_jahre') . ' years')->format('Y-m-d');
    $notizen = alle("SELECT n.*, k.name AS kunde FROM notizen n JOIN kunden k ON k.id = n.kunde_id WHERE n.aktiv = 1 AND COALESCE(SUBSTR(n.bestaetigt_am,1,10), n.stand) <= ? ORDER BY n.stand", [$grenzeNotiz]);
    $ohneZeit = alle('SELECT a.*, k.name AS kunde FROM anlagen a JOIN kunden k ON k.id = a.kunde_id WHERE a.aktiv = 1 AND a.zeit_h IS NULL ORDER BY k.name');
    $geo = alle("SELECT * FROM kunden WHERE geo_status IS NULL OR geo_status <> 'adresse' ORDER BY geo_status, name");
    $imp = alle('SELECT * FROM import_hinweise WHERE erledigt = 0 ORDER BY art, id');
    $abschnitte = [['meldungen', 'Meldungen der Techniker', count($meld)], ['rest', 'Offene Restarbeiten', count($rest)], ['akkus', 'Akkus älter als ' . cfg('akku_tausch_jahre') . ' Jahre', count($akkus)],
        ['notizen', 'Notizen älter als ' . cfg('notiz_alter_jahre') . ' Jahre', count($notizen)], ['zeit', 'Anlagen ohne Zeit', count($ohneZeit)], ['geo', 'Adressen ungenau', count($geo)], ['import', 'Hinweise aus dem Import', count($imp)]];
    ?>
    <?php pflege_nav('pruef'); ?>
    <div class="kpis"><?php foreach ($abschnitte as [$id, $t, $n]): ?><a class="kpi" href="#<?= $id ?>" style="text-decoration:none"><b><?= $n ?></b><span><?= h($t) ?></span></a><?php endforeach; ?></div>
    <div class="box" id="meldungen"><h2>Meldungen der Techniker</h2>
      <p class="hint">Hinweise, die Techniker beim Abschließen eines Termins geschrieben haben. In der Kundenakte einpflegen, dann als bearbeitet markieren.</p>
      <?php if (!$meld): ?><p class="leer">Keine offenen Meldungen.</p><?php endif; ?>
      <?php foreach ($meld as $m): ?><div class="row" style="justify-content:space-between;border-top:1px solid var(--line);padding-top:8px">
        <span><a href="pflege.php?k=<?= (int)$m['kunde_id'] ?>"><b><?= h($m['kunde']) ?></b></a>: <?= h($m['text']) ?> <span class="hint">· <?= datum($m['erstellt_am']) ?>, <?= h($m['erstellt_von']) ?></span></span>
        <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="meldung_erledigt"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn ghost klein">Bearbeitet</button></form></div><?php endforeach; ?>
    </div>
    <div class="box" id="rest"><h2>Offene Restarbeiten</h2>
      <p class="hint">Nur zur Information – sie stehen im nächsten Termin der Anlage. Zum eigenen Einplanen in der Kundenakte ein Ticket anlegen.</p>
      <?php if (!$rest): ?><p class="leer">Keine.</p><?php else: ?><div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Anlage</th><th>Gemacht</th><th>Noch zu tun</th><th>Bericht</th><th>Dokumentiert</th></tr>
      <?php foreach ($rest as $r): ?><tr><td><a href="pflege.php?k=<?= $r['kid'] ?>"><?= h($r['kunde']) ?></a></td><td><?= h($r['art']) ?></td><td class="hint"><?= h($r['gemacht'] ?? '') ?></td><td><?= h($r['text']) ?><?= $r['material'] ? ' · ' . h($r['material']) : '' ?></td>
        <td class="hint"><?= h($r['quelle'] ?? '') ?><?= $r['bericht_am'] ? ' ' . datum($r['bericht_am']) : '' ?></td><td class="hint"><?= datum($r['erstellt_am']) ?>, <?= h($r['erstellt_von']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
    </div>
    <div class="box" id="akkus"><h2>Akkus älter als <?= cfg('akku_tausch_jahre') ?> Jahre (<?= count($akkus) ?>)</h2>
      <p class="hint">Erscheinen im nächsten Wartungstermin als „Akku tauschen". Nach dem Tausch in der Kundenakte eintragen.</p>
      <details><summary class="btn ghost klein">Liste anzeigen</summary><div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Anlage</th><th>Akku</th><th>Eingebaut</th></tr>
      <?php foreach ($akkus as $b): ?><tr><td><a href="pflege.php?k=<?= $b['kid'] ?>"><?= h($b['kunde']) ?></a></td><td><?= h($b['art']) ?></td><td><?= (int)$b['anzahl'] ?>× <?= h($b['bezeichnung']) ?></td><td><?= monat_jahr($b['eingebaut']) ?></td></tr><?php endforeach; ?></table></div></details>
    </div>
    <div class="box" id="notizen"><h2>Notizen älter als <?= cfg('notiz_alter_jahre') ?> Jahre (<?= count($notizen) ?>)</h2>
      <?php if (!$notizen): ?><p class="leer">Keine.</p><?php endif; ?>
      <?php foreach ($notizen as $n): ?><p><a href="pflege.php?k=<?= $n['kunde_id'] ?>#notizen"><b><?= h($n['kunde']) ?></b></a>: <?= h($n['text']) ?> <span class="hint">· Stand <?= monat_jahr($n['stand']) ?></span></p><?php endforeach; ?>
    </div>
    <div class="box" id="zeit"><h2>Anlagen ohne Zeit (<?= count($ohneZeit) ?>)</h2>
      <p class="hint">Diese werden mit <?= std(cfg('standard_zeit_h')) ?> geplant, bis eine Zeit eingetragen ist.</p>
      <details><summary class="btn ghost klein">Liste anzeigen</summary><div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Anlage</th></tr>
      <?php foreach ($ohneZeit as $a): ?><tr><td><a href="pflege.php?k=<?= $a['kunde_id'] ?>#anlage-<?= $a['id'] ?>"><?= h($a['kunde']) ?></a></td><td><?= h($a['art']) ?></td></tr><?php endforeach; ?></table></div></details>
    </div>
    <div class="box" id="geo"><h2>Adressen ungenau (<?= count($geo) ?>)</h2>
      <p class="hint">Ohne genaue Lage werden Fahrzeiten ungenau. Adresse in der Kundenakte prüfen und „neu auf der Karte suchen".</p>
      <details><summary class="btn ghost klein">Liste anzeigen</summary><div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Adresse</th><th>Status</th></tr>
      <?php foreach ($geo as $k): ?><tr><td><a href="pflege.php?k=<?= $k['id'] ?>"><?= h($k['name']) ?></a></td><td><?= h($k['strasse']) ?>, <?= h($k['plz']) ?> <?= h($k['ort']) ?></td><td><?= h($k['geo_hinweis'] ?? 'noch nicht gesucht') ?></td></tr><?php endforeach; ?></table></div></details>
    </div>
    <div class="box" id="import"><h2>Hinweise aus dem Import (<?= count($imp) ?>)</h2>
      <p class="hint">„Ähnlich zugeordnet": Stimmt die Zuordnung, auf „Passt" klicken. Wenn nicht, „Falsch" – dann wird die Zeit bei dieser Anlage wieder entfernt.</p>
      <?php $arten = ['unscharf' => 'Ähnlich zugeordnet – bitte prüfen', 'ohne_zuordnung' => 'Zeile aus der Liste ohne passende Anlage', 'zeit_fehlt' => 'Keine Zeit in der Liste', 'intervall' => 'Anzahl Wartungen weicht ab', 'gruppe' => 'Gruppen-Hinweis ohne Partner', 'akku' => 'Akku ohne passende Adresse', 'ohne_techniker' => 'Ohne Mitarbeiter'];
      foreach ($arten as $art => $titel): $liste = array_filter($imp, fn($i) => $i['art'] === $art); if (!$liste) continue; ?>
        <details<?= $art === 'unscharf' ? ' open' : '' ?>><summary><b><?= h($titel) ?></b> (<?= count($liste) ?>)</summary>
        <?php foreach ($liste as $i): $kid = $i['anlage_id'] ? wert('SELECT kunde_id FROM anlagen WHERE id = ?', [$i['anlage_id']]) : null; ?>
          <div class="row" style="justify-content:space-between;border-top:1px solid var(--line);padding:6px 0">
            <span style="flex:1;min-width:240px"><?= h($i['text']) ?><?= $kid ? ' <a class="hint" href="pflege.php?k=' . $kid . '">→ Kunde</a>' : '' ?></span>
            <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="aktion" value="hinweis"><input type="hidden" name="id" value="<?= $i['id'] ?>">
              <?php if ($art === 'unscharf'): ?><button class="btn ghost klein" name="wie" value="ok">Passt</button><button class="btn ghost klein" name="wie" value="falsch">Falsch</button>
              <?php else: ?><button class="btn ghost klein" name="wie" value="ok">Erledigt</button><?php endif; ?></form>
          </div>
        <?php endforeach; ?></details>
      <?php endforeach; ?>
    </div>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Kundensuche
   ==================================================================== */
$such = trim((string)($_GET['q'] ?? ''));
$p = '%' . $such . '%';
$gc = ist_mysql() ? "GROUP_CONCAT(a.art SEPARATOR ', ')" : "GROUP_CONCAT(a.art, ', ')";
$kunden = alle("SELECT k.*, (SELECT COUNT(*) FROM anlagen a WHERE a.kunde_id = k.id AND a.aktiv = 1) AS n_anl,
    (SELECT $gc FROM anlagen a WHERE a.kunde_id = k.id AND a.aktiv = 1) AS arten
    FROM kunden k WHERE ? = '' OR k.name LIKE ? OR k.ort LIKE ? OR k.strasse LIKE ? OR k.plz LIKE ? ORDER BY k.name LIMIT 150", [$such, $p, $p, $p, $p]);
seite_kopf('Pflege', 'pflege');
?>
<?php pflege_nav('suche'); ?>
<form class="box" method="get">
  <h2>Kunde aufrufen</h2>
  <div class="row"><input type="search" name="q" id="q" value="<?= h($such) ?>" placeholder="Name, Ort, Straße oder PLZ" autofocus style="flex:1;min-width:220px"><button class="btn">Suchen</button></div>
  <p class="hint">Typischer Ablauf: Stundennachweis liegt vor → Kunde suchen → Akku-Tausch, Notiz oder Restarbeit eintragen. Jede Änderung wird mit Datum und Namen gespeichert.</p>
</form>
<div class="box">
  <h3><?= $such === '' ? 'Alle Kunden (A–Z)' : count($kunden) . ' Treffer' ?></h3>
  <div class="scroll"><table class="tbl"><tr><th>Kunde</th><th>Adresse</th><th>Anlagen</th><th></th></tr>
  <?php foreach ($kunden as $k): ?><tr><td><a href="pflege.php?k=<?= $k['id'] ?>"><b><?= h($k['name']) ?></b></a></td><td><?= h($k['strasse']) ?>, <?= h($k['plz']) ?> <?= h($k['ort']) ?></td>
    <td><?= h((string)$k['arten']) ?></td><td><?= $k['geo_status'] !== 'adresse' ? '<span class="pill warn">Lage ungenau</span>' : '' ?></td></tr><?php endforeach; ?>
  </table></div>
</div>
<?php seite_fuss();
