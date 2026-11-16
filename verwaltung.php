<?php
/** Bereich "Verwaltung": Benutzer und Bereiche, Mitarbeiter/Postfächer, Werkzeuge, Einstellungen */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('admin');
@set_time_limit(300);

if (post()) {
    switch ($_POST['aktion'] ?? '') {
        case 'benutzer':
            $id = (int)$_POST['id'];
            $d = ['login' => mb_strtolower(trim($_POST['login'])), 'name' => trim($_POST['name']), 'email' => mb_strtolower(trim($_POST['email'])) ?: null,
                  'rollen' => implode(',', array_intersect(array_keys(ROLLEN), (array)($_POST['rollen'] ?? []))), 'mitarbeiter_id' => (int)$_POST['mitarbeiter_id'] ?: null, 'aktiv' => isset($_POST['aktiv']) ? 1 : 0];
            if (trim((string)$_POST['passwort']) !== '') $d['pw_hash'] = password_hash((string)$_POST['passwort'], PASSWORD_DEFAULT);
            if ($d['login'] === '') weiter('verwaltung.php', 'Anmeldename fehlt.', 'err');
            if ($id) aendern('benutzer', $id, $d); else einfuegen('benutzer', $d);
            if ($id === (int)benutzer()['id'] && !str_contains($d['rollen'], 'admin')) aendern('benutzer', $id, ['rollen' => $d['rollen'] . ',admin']);
            weiter('verwaltung.php#benutzer', 'Benutzer gespeichert.');
        case 'mitarbeiter':
            $id = (int)$_POST['id'];
            $d = ['name' => trim($_POST['name']), 'email' => mb_strtolower(trim($_POST['email'])) ?: null, 'aktiv' => isset($_POST['aktiv']) ? 1 : 0];
            if ($id) aendern('mitarbeiter', $id, $d); else einfuegen('mitarbeiter', $d);
            weiter('verwaltung.php#mitarbeiter', 'Mitarbeiter gespeichert.');
        case 'ausscheiden':
            $mid = (int)$_POST['mid']; $nach = (int)$_POST['nachfolger'] ?: null; $heute = date('Y-m-d');
            $name = (string)wert('SELECT name FROM mitarbeiter WHERE id = ?', [$mid]); $probleme = []; $n = 0;
            foreach (alle("SELECT * FROM wartungen WHERE techniker_id = ? AND (status = 'zugewiesen' OR (status = 'terminiert' AND beginn >= ?))", [$mid, $heute]) as $w) {
                if ($w['outlook_id'] && outlook_aktiv()) { try { outlook_loeschen($mid, $w['outlook_id']); } catch (Throwable $e) { $probleme[] = $e->getMessage(); } }
                aendern('wartungen', (int)$w['id'], ['techniker_id' => $nach, 'status' => $nach ? 'zugewiesen' : 'offen', 'beginn' => null, 'ende' => null, 'tour_pos' => null, 'fahrt_min' => null, 'outlook_id' => null, 'outlook_fehler' => null]);
                $n++;
            }
            q('UPDATE anlagen SET stammtechniker_id = ? WHERE stammtechniker_id = ?', [$nach, $mid]);
            q('UPDATE mitarbeiter SET aktiv = 0 WHERE id = ?', [$mid]);
            q('UPDATE benutzer SET aktiv = 0 WHERE mitarbeiter_id = ?', [$mid]);
            unset($_SESSION['outlook_cache']);
            weiter('verwaltung.php#mitarbeiter', h($name) . " ist jetzt inaktiv. $n offene Wartungen " . ($nach ? 'an ' . h(mitarbeiter_namen()[$nach]) . ' übergeben (zum Neu-Terminieren)' : 'zurück in „offen"') . ', Stammkunden ' . ($nach ? 'ebenfalls übergeben.' : 'ohne Stammtechniker.')
                . ($probleme ? ' Outlook: ' . h($probleme[0]) : ''), $probleme ? 'warn' : 'ok');
        case 'mitarbeiter_loeschen':
            $mid = (int)$_POST['mid'];
            if ((int)wert("SELECT COUNT(*) FROM wartungen WHERE techniker_id = ? AND status IN ('terminiert','erledigt')", [$mid]))
                weiter('verwaltung.php#mitarbeiter', 'Dieser Mitarbeiter hat erledigte oder geplante Wartungen und bleibt für die Auswertung als „inaktiv" erhalten.', 'warn');
            q('UPDATE wartungen SET techniker_id = NULL, status = \'offen\' WHERE techniker_id = ?', [$mid]);
            q('UPDATE anlagen SET stammtechniker_id = NULL WHERE stammtechniker_id = ?', [$mid]);
            q('UPDATE benutzer SET mitarbeiter_id = NULL WHERE mitarbeiter_id = ?', [$mid]);
            q('DELETE FROM abwesenheiten WHERE mitarbeiter_id = ?', [$mid]);
            q('DELETE FROM mitarbeiter WHERE id = ?', [$mid]);
            weiter('verwaltung.php#mitarbeiter', 'Mitarbeiter gelöscht.');
        case 'liste_neu':
            $w = trim((string)$_POST['wert']);
            if ($w !== '' && !(int)wert('SELECT COUNT(*) FROM listen WHERE liste = ? AND wert = ?', [$_POST['liste'], $w]))
                einfuegen('listen', ['liste' => $_POST['liste'], 'wert' => $w, 'reihenfolge' => 999, 'aktiv' => 1]);
            weiter('verwaltung.php#listen', 'Eintrag hinzugefügt.');
        case 'liste_aendern':
            $e = eine('SELECT * FROM listen WHERE id = ?', [(int)$_POST['id']]); $neu = trim((string)$_POST['wert']);
            if (isset($_POST['umschalten'])) { aendern('listen', (int)$e['id'], ['aktiv' => $e['aktiv'] ? 0 : 1]); weiter('verwaltung.php#listen', $e['aktiv'] ? 'Ausgeblendet (vorhandene Einträge bleiben).' : 'Wieder eingeblendet.'); }
            if ($neu === '' || $neu === $e['wert']) weiter('verwaltung.php#listen');
            aendern('listen', (int)$e['id'], ['wert' => $neu]);
            $n = match ($e['liste']) {
                'bauteil_typ' => q('UPDATE bauteile SET typ = ? WHERE typ = ?', [$neu, $e['wert']])->rowCount(),
                'bauteil_bezeichnung' => q('UPDATE bauteile SET bezeichnung = ? WHERE bezeichnung = ?', [$neu, $e['wert']])->rowCount(),
                'anlagenart' => q('UPDATE anlagen SET art = ? WHERE art = ?', [$neu, $e['wert']])->rowCount(),
                'akku_typ' => q("UPDATE bauteile SET bezeichnung = ? WHERE typ = 'Akku' AND bezeichnung = ?", [$neu, $e['wert']])->rowCount(),
                default => 0 };
            weiter('verwaltung.php#listen', 'Umbenannt' . ($n ? ", $n vorhandene Einträge angepasst." : '.'));
        case 'feld':
            $id = (int)$_POST['id'];
            if (isset($_POST['loeschen'])) { q('DELETE FROM feld_werte WHERE feld_id = ?', [$id]); q('DELETE FROM felder WHERE id = ?', [$id]); weiter('verwaltung.php#felder', 'Feld und seine Werte gelöscht.'); }
            $d = ['bereich' => $_POST['bereich'] === 'anlage' ? 'anlage' : 'kunde', 'name' => trim($_POST['name']), 'art' => in_array($_POST['art'], ['text', 'zahl', 'datum', 'janein', 'auswahl'], true) ? $_POST['art'] : 'text',
                  'optionen' => trim((string)($_POST['optionen'] ?? '')) ?: null, 'im_termin' => isset($_POST['im_termin']) ? 1 : 0, 'reihenfolge' => (int)($_POST['reihenfolge'] ?? 0), 'aktiv' => isset($_POST['aktiv']) || !$id ? 1 : 0];
            if ($d['name'] === '') weiter('verwaltung.php#felder', 'Name fehlt.', 'err');
            if ($id) aendern('felder', $id, $d); else einfuegen('felder', $d);
            weiter('verwaltung.php#felder', 'Feld gespeichert. Es erscheint jetzt in der Kundenakte' . ($d['im_termin'] ? ' und im Termin.' : '.'));
        case 'geo':
            $n = 0; $st = ['adresse' => 0, 'plz' => 0, 'fehlt' => 0]; $ende = time() + 40;
            foreach (alle("SELECT * FROM kunden WHERE geo_status IS NULL ORDER BY id LIMIT 30") as $k) { if (time() > $ende) break; $st[kunde_geokodieren($k)]++; $n++; }
            $rest = (int)wert('SELECT COUNT(*) FROM kunden WHERE geo_status IS NULL');
            weiter('verwaltung.php#werkzeuge', "$n Adressen gesucht: {$st['adresse']} gefunden, {$st['plz']} nur PLZ, {$st['fehlt']} nicht gefunden. Noch offen: $rest." . ($rest ? ' Nochmals klicken oder werkzeuge/geokodieren.php laufen lassen.' : ''));
        case 'jahr':
            weiter('verwaltung.php#werkzeuge', wartungen_erzeugen((int)$_POST['jahr']) . " Wartungen für {$_POST['jahr']} angelegt.");
        case 'outlook_test':
            $erg = [];
            foreach (mitarbeiter_liste() as $m) { try { outlook_belegt((int)$m['id'], date('Y-m-d'), date('Y-m-d')); $erg[] = '✓ ' . h($m['name']); } catch (Throwable $e) { $erg[] = '✗ ' . h($m['name']) . ': ' . h($e->getMessage()); } }
            weiter('verwaltung.php#werkzeuge', 'Outlook-Test:<ul><li>' . implode('</li><li>', $erg) . '</li></ul>', str_contains(implode('', $erg), '✗') ? 'warn' : 'ok');
    }
}

$benutzer = alle('SELECT * FROM benutzer ORDER BY aktiv DESC, name');
$mitarb = mitarbeiter_liste(false);
$mit = mitarbeiter_namen();
$geo = ['gesamt' => (int)wert('SELECT COUNT(*) FROM kunden'), 'ok' => (int)wert("SELECT COUNT(*) FROM kunden WHERE geo_status = 'adresse'"), 'plz' => (int)wert("SELECT COUNT(*) FROM kunden WHERE geo_status = 'plz'"), 'offen' => (int)wert('SELECT COUNT(*) FROM kunden WHERE geo_status IS NULL')];
$benutzerForm = function (?array $u) use ($mit) { $r = $u ? explode(',', $u['rollen']) : ['techniker']; ?>
  <form method="post" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="benutzer"><input type="hidden" name="id" value="<?= (int)($u['id'] ?? 0) ?>">
    <div class="grid3"><label class="f">Anmeldename<input type="text" name="login" value="<?= h($u['login'] ?? '') ?>" required></label>
      <label class="f">Name<input type="text" name="name" value="<?= h($u['name'] ?? '') ?>" required></label>
      <label class="f">E-Mail (für Anmeldung mit Microsoft)<input type="email" name="email" value="<?= h($u['email'] ?? '') ?>"></label></div>
    <div class="row"><?php foreach (ROLLEN as $k => $n): ?><label class="hint"><input type="checkbox" name="rollen[]" value="<?= $k ?>"<?= in_array($k, $r, true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div>
    <div class="grid3"><label class="f">Ist Techniker<select name="mitarbeiter_id"><option value="">–</option><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"<?= (int)($u['mitarbeiter_id'] ?? 0) === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
      <label class="f"><?= $u ? 'Neues Passwort (leer = unverändert)' : 'Passwort' ?><input type="password" name="passwort" autocomplete="new-password"></label>
      <label class="hint" style="align-self:end"><input type="checkbox" name="aktiv" value="1"<?= !$u || $u['aktiv'] ? ' checked' : '' ?>> aktiv</label></div>
    <button class="btn klein">Speichern</button></form>
<?php };
seite_kopf('Verwaltung', 'admin');
?>
<div class="box" id="benutzer"><h2>Benutzer und Bereiche</h2>
  <p class="hint">Jeder sieht nach dem Anmelden nur seine Bereiche. „Verwaltung" sieht alles.</p>
  <div class="scroll"><table class="tbl"><tr><th>Name</th><th>Anmeldename</th><th>Bereiche</th><th>Techniker</th><th></th></tr>
  <?php foreach ($benutzer as $u): ?><tr><td><b><?= h($u['name']) ?></b><?= $u['aktiv'] ? '' : ' <span class="pill neutral">inaktiv</span>' ?><br><span class="hint"><?= h($u['email']) ?></span></td><td class="mono"><?= h($u['login']) ?></td>
    <td><?= h(implode(', ', array_map(fn($r) => ROLLEN[$r] ?? $r, array_filter(explode(',', $u['rollen']))))) ?></td><td><?= h($mit[(int)$u['mitarbeiter_id']] ?? '') ?></td>
    <td><details><summary class="btn ghost klein">Bearbeiten</summary><?php $benutzerForm($u); ?></details></td></tr><?php endforeach; ?></table></div>
  <details><summary class="btn ghost klein">+ Benutzer anlegen</summary><?php $benutzerForm(null); ?></details>
</div>

<div class="box" id="mitarbeiter"><h2>Mitarbeiter (Techniker) und Postfächer</h2>
  <p class="hint">Jeder Mitarbeiter ist eine Zeile – ein neuer Kollege erscheint sofort überall (Auslastung, Touren, Wochenplan), ohne neue Spalten.
    Die E-Mail-Adresse ist das Outlook-Postfach, in das die Wartungstermine eingetragen werden.</p>
  <?php foreach ($mitarb as $m):
      $offen = (int)wert("SELECT COUNT(*) FROM wartungen WHERE techniker_id = ? AND (status = 'zugewiesen' OR (status = 'terminiert' AND beginn >= ?))", [$m['id'], date('Y-m-d')]);
      $stamm = (int)wert('SELECT COUNT(*) FROM anlagen WHERE stammtechniker_id = ?', [$m['id']]);
      $historie = (int)wert("SELECT COUNT(*) FROM wartungen WHERE techniker_id = ? AND status IN ('terminiert','erledigt')", [$m['id']]); ?>
    <div style="border-top:1px solid var(--line);padding-top:8px" class="stack">
    <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="aktion" value="mitarbeiter"><input type="hidden" name="id" value="<?= $m['id'] ?>">
      <input type="text" name="name" value="<?= h($m['name']) ?>" style="max-width:160px"><input type="email" name="email" value="<?= h($m['email']) ?>" placeholder="postfach@ihre-firma.de" style="max-width:280px">
      <label class="hint"><input type="checkbox" name="aktiv" value="1"<?= $m['aktiv'] ? ' checked' : '' ?>> aktiv</label><button class="btn ghost klein">Speichern</button>
      <span class="hint"><?= $stamm ?> Stammanlagen · <?= $offen ?> offene Wartungen</span></form>
    <?php if ($m['aktiv']): ?>
      <details><summary class="hint">Mitarbeiter scheidet aus …</summary>
        <form method="post" class="stack" style="margin-top:6px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="ausscheiden"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
          <p class="hint">Die <?= $offen ?> offenen und zukünftigen Wartungen<?= outlook_aktiv() ? ' (auch aus seinem Outlook entfernt)' : '' ?> und die <?= $stamm ?> Stammanlagen gehen an:</p>
          <div class="row"><select name="nachfolger" style="max-width:260px"><option value="">niemanden – zurück in „offen"</option><?php foreach ($mitarb as $x) if ($x['aktiv'] && $x['id'] !== $m['id']): ?><option value="<?= $x['id'] ?>"><?= h($x['name']) ?></option><?php endif; ?></select>
            <button class="btn klein" onclick="return confirm('<?= h(addslashes($m['name'])) ?> wirklich ausscheiden lassen?')">Ausscheiden lassen</button></div>
          <p class="hint">Erledigte Wartungen bleiben ihm zugeordnet (für die Auswertung). Sein Zugang wird gesperrt.</p></form></details>
    <?php else: ?>
      <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="aktion" value="mitarbeiter_loeschen"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
        <?php if ($historie): ?><span class="hint">Inaktiv – bleibt wegen <?= $historie ?> erledigter/geplanter Wartungen für die Auswertung erhalten.</span>
        <?php else: ?><button class="btn ghost klein" onclick="return confirm('<?= h(addslashes($m['name'])) ?> endgültig löschen?')">Endgültig löschen</button><?php endif; ?></form>
    <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <form method="post" class="row" style="border-top:1px solid var(--line);padding-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="mitarbeiter"><input type="hidden" name="id" value="0"><input type="hidden" name="aktiv" value="1">
    <input type="text" name="name" placeholder="Neuer Mitarbeiter" style="max-width:160px" required><input type="email" name="email" placeholder="postfach@ihre-firma.de" style="max-width:280px"><button class="btn ghost klein">+ Anlegen</button></form>
</div>

<div class="box" id="listen"><h2>Auswahllisten</h2>
  <p class="hint">Diese Werte stehen in der Pflege zur Auswahl. Neue Akku-Typen einfach hier ergänzen. Umbenennen passt alle vorhandenen Einträge mit an; „Ausblenden" lässt vorhandene Einträge stehen.</p>
  <div class="cols2">
  <?php foreach (['bauteil_typ' => 'Bauteil-Typen', 'bauteil_bezeichnung' => 'Akku-, Batterie- und Bauteil-Bezeichnungen', 'akku_typ' => 'Akku-Typen (Kapazität)', 'anlagenart' => 'Anlagenarten'] as $liste => $titel): liste($liste); ?>
    <div class="stack"><h3><?= h($titel) ?></h3>
      <details><summary class="hint"><?= count(liste($liste)) ?> Einträge anzeigen</summary>
      <?php foreach (alle('SELECT * FROM listen WHERE liste = ? ORDER BY aktiv DESC, reihenfolge, wert', [$liste]) as $e): ?>
        <form method="post" class="row" style="flex-wrap:nowrap"><?= csrf_feld() ?><input type="hidden" name="aktion" value="liste_aendern"><input type="hidden" name="id" value="<?= $e['id'] ?>">
          <input type="text" name="wert" value="<?= h($e['wert']) ?>"<?= $e['aktiv'] ? '' : ' style="opacity:.5"' ?>><button class="btn ghost klein">Umbenennen</button>
          <button class="btn ghost klein" name="umschalten" value="1"><?= $e['aktiv'] ? 'Ausblenden' : 'Einblenden' ?></button></form>
      <?php endforeach; ?></details>
      <form method="post" class="row" style="flex-wrap:nowrap"><?= csrf_feld() ?><input type="hidden" name="aktion" value="liste_neu"><input type="hidden" name="liste" value="<?= $liste ?>">
        <input type="text" name="wert" placeholder="neu, z. B. <?= $liste === 'bauteil_bezeichnung' ? '26 Ah' : ($liste === 'anlagenart' ? 'RWA' : ($liste === 'akku_typ' ? '45 Ah' : 'Rauchschalter')) ?>" required><button class="btn ghost klein">+ Hinzufügen</button></form>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<div class="box" id="felder"><h2>Eigene Felder</h2>
  <p class="hint">Zusätzliche Angaben zu Kunden oder Anlagen, z. B. „Anzahl Fenster", „Etagen", „VdS-Anlage", „Schlüssel beim Hausmeister".
    Sie erscheinen in der Kundenakte (mit Stand-Datum, wer es eingetragen hat) und auf Wunsch im Termintext.</p>
  <?php $fl = alle('SELECT * FROM felder ORDER BY bereich, reihenfolge, name');
  $feldForm = function (?array $f) { ?>
    <form method="post" class="grid4" style="align-items:end"><?= csrf_feld() ?><input type="hidden" name="aktion" value="feld"><input type="hidden" name="id" value="<?= (int)($f['id'] ?? 0) ?>">
      <label class="f">Name<input type="text" name="name" value="<?= h($f['name'] ?? '') ?>" required></label>
      <label class="f">Gilt für<select name="bereich"><option value="kunde">Kunde</option><option value="anlage"<?= ($f['bereich'] ?? '') === 'anlage' ? ' selected' : '' ?>>Anlage</option></select></label>
      <label class="f">Art<select name="art"><?php foreach (['text' => 'Text', 'zahl' => 'Zahl', 'datum' => 'Datum', 'janein' => 'Ja/Nein', 'auswahl' => 'Auswahl'] as $k => $n): ?><option value="<?= $k ?>"<?= ($f['art'] ?? 'text') === $k ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
      <label class="f">Auswahl-Werte (mit Komma)<input type="text" name="optionen" value="<?= h($f['optionen'] ?? '') ?>" placeholder="nur bei Art „Auswahl“"></label>
      <label class="hint"><input type="checkbox" name="im_termin" value="1"<?= !$f || $f['im_termin'] ? ' checked' : '' ?>> im Termin anzeigen</label>
      <?php if ($f): ?><label class="hint"><input type="checkbox" name="aktiv" value="1"<?= $f['aktiv'] ? ' checked' : '' ?>> aktiv</label><?php endif; ?>
      <div class="row"><button class="btn ghost klein"><?= $f ? 'Speichern' : '+ Feld anlegen' ?></button>
        <?php if ($f): ?><button class="btn ghost klein" name="loeschen" value="1" onclick="return confirm('Feld und alle eingetragenen Werte löschen?')">Löschen</button><?php endif; ?></div>
    </form>
  <?php };
  foreach ($fl as $f): ?><div style="border-top:1px solid var(--line);padding-top:8px"><?php $feldForm($f); ?></div><?php endforeach; ?>
  <div style="border-top:1px solid var(--line);padding-top:8px"><?php $feldForm(null); ?></div>
</div>

<div class="box" id="werkzeuge"><h2>Werkzeuge</h2>
  <p>Adressen auf der Karte: <b><?= $geo['ok'] ?></b> genau, <?= $geo['plz'] ?> nur PLZ, <?= $geo['offen'] ?> noch nicht gesucht (von <?= $geo['gesamt'] ?>).</p>
  <div class="row">
    <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="geo"><button class="btn ghost klein" <?= $geo['offen'] ? '' : 'disabled' ?>>Nächste 30 Adressen suchen (OSM)</button></form>
    <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="aktion" value="jahr"><input type="number" name="jahr" value="<?= date('Y') + 1 ?>" style="width:90px"><button class="btn ghost klein">Wartungen für Jahr anlegen</button></form>
    <?php if (outlook_aktiv()): ?><form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="outlook_test"><button class="btn ghost klein">Outlook-Postfächer testen</button></form><?php endif; ?>
  </div>
  <p class="hint">Viele Adressen auf einmal: im Terminal <span class="mono">php werkzeuge/geokodieren.php</span> (ca. 1 Sekunde je Adresse).</p>
</div>

<div class="box"><h2>Einstellungen</h2>
  <p class="hint">Werden in <span class="mono">config.php</span> geändert.</p>
  <table class="tbl">
    <tr><td>Firma (Tourstart)</td><td><?= h(implode(', ', cfg('firma'))) ?></td></tr>
    <tr><td>Arbeitszeiten</td><td><?= h(implode(' · ', array_map(fn($d, $z) => WT[$d] . ' ' . $z[0] . '–' . $z[1], array_keys(cfg('arbeitszeiten')), cfg('arbeitszeiten')))) ?></td></tr>
    <tr><td>Höchstanteil Wartung</td><td><?= (int)(cfg('max_anteil_wartung') * 100) ?> % der Arbeitszeit je Woche</td></tr>
    <tr><td>Akku tauschen nach</td><td><?= cfg('akku_tausch_jahre') ?> Jahren</td></tr>
    <tr><td>Notiz prüfen nach</td><td><?= cfg('notiz_alter_jahre') ?> Jahren</td></tr>
    <tr><td>Routing</td><td><?= h(cfg('osm')['osrm_url']) ?></td></tr>
    <tr><td>Microsoft 365</td><td>Outlook <?= outlook_aktiv() ? 'aktiv' : 'aus' ?> · Anmeldung <?= !empty(ms()['login_aktiv']) ? 'aktiv' : 'aus' ?></td></tr>
  </table>
</div>
<?php seite_fuss();
