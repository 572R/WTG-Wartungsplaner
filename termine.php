<?php
/**
 * Bereich "Meine Termine": Termine mit allen Infos, Tagesroute, Abschluss mit echter Dauer und Hinweis ans Büro
 */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
// Techniker sehen ihre Termine; das Büro (Organisation) darf die Termine jedes Technikers öffnen und abschließen
$b = darf('organisation') ? nur('organisation') : nur('techniker');
$mit = mitarbeiter_namen();
// Techniker sehen sich selbst; Organisation/Admin dürfen jeden auswählen
$mid = (int)($b['mitarbeiter_id'] ?? 0);
if ((darf('organisation') || in_array('admin', rollen(), true)) && isset($_GET['mid'])) $mid = (int)$_GET['mid'];
if (!$mid && $mit) $mid = (int)array_key_first($mit);

if (post()) {
    $w = eine(W_SELECT . ' WHERE w.id = ? AND w.techniker_id = ?', [(int)$_POST['id'], $mid]);
    if (!$w) weiter('termine.php', 'Termin nicht gefunden.', 'err');
    if (($_POST['aktion'] ?? '') === 'erledigt') {
        $ist = $_POST['ist_h'] !== '' ? (float)str_replace(',', '.', $_POST['ist_h']) : null;
        $gemacht = trim((string)($_POST['gemacht'] ?? '')); $nochZuTun = trim((string)($_POST['noch'] ?? ''));
        aendern('wartungen', (int)$w['id'], ['status' => 'erledigt', 'ist_h' => $ist, 'erledigt_am' => jetzt(), 'erledigt_von' => wer(), 'bericht' => $gemacht !== '' ? $gemacht : null]);
        // Bericht: was noch gemacht werden muss, wird als Restarbeit (nur Info) bei der Anlage dokumentiert
        if ($nochZuTun !== '') einfuegen('restarbeiten', ['anlage_id' => $w['anlage_id'], 'text' => $nochZuTun, 'gemacht' => $gemacht !== '' ? $gemacht : null,
            'quelle' => (ist_ticket($w) ? 'Ticket ' : 'Wartung ') . $w['id'] . ' am ' . datum($w['beginn']), 'bericht_am' => substr((string)$w['beginn'], 0, 10) ?: date('Y-m-d'),
            'status' => 'offen', 'erstellt_am' => jetzt(), 'erstellt_von' => wer()]);
        $derr = dokument_speichern($_FILES['datei'] ?? null, (int)$w['kunde_id'], (int)$w['anlage_id'], (int)$w['id'], 'Bericht ' . datum($w['beginn']) . ' – ' . auftrag_name($w));
        foreach ((array)($_POST['rest'] ?? []) as $rid) q("UPDATE restarbeiten SET status = 'erledigt', erledigt_am = ?, erledigt_von = ?, wartung_id = ? WHERE id = ? AND anlage_id = ?", [jetzt(), wer(), $w['id'], (int)$rid, $w['anlage_id']]);
        $text = trim((string)($_POST['hinweis'] ?? ''));
        if ($text !== '') einfuegen('meldungen', ['kunde_id' => $w['kunde_id'], 'anlage_id' => $w['anlage_id'], 'wartung_id' => $w['id'], 'text' => $text, 'erstellt_am' => jetzt(), 'erstellt_von' => wer(), 'status' => 'offen']);
        weiter('termine.php?' . http_build_query(['mid' => $mid, 'tag' => substr($w['beginn'], 0, 10)]), (ist_ticket($w) ? 'Ticket' : 'Wartung') . ' als erledigt gespeichert' . ($text !== '' ? ', Hinweis ans Büro gesendet' : '')
            . ($nochZuTun !== '' ? ', Restarbeit dokumentiert' : '') . ($derr ? '. Datei nicht gespeichert: ' . h($derr) : '.'), $derr ? 'warn' : 'ok');
    }
    if (($_POST['aktion'] ?? '') === 'wieder_offen') {
        aendern('wartungen', (int)$w['id'], ['status' => 'terminiert', 'erledigt_am' => null, 'erledigt_von' => null, 'ist_h' => null]); // Bericht bleibt erhalten
        weiter('termine.php?' . http_build_query(['mid' => $mid, 'tag' => substr($w['beginn'], 0, 10)]), 'Wieder geöffnet.');
    }
}

$heute = date('Y-m-d');
$offenAlt = alle(W_SELECT . " WHERE w.techniker_id = ? AND w.status = 'terminiert' AND w.beginn < ? ORDER BY w.beginn", [$mid, $heute]);
$kommend = alle(W_SELECT . " WHERE w.techniker_id = ? AND w.status IN ('terminiert','erledigt') AND w.beginn >= ? ORDER BY w.beginn LIMIT 200", [$mid, $heute]);
$tage = []; foreach ($kommend as $w) $tage[substr($w['beginn'], 0, 10)][] = $w;
$tag = (string)($_GET['tag'] ?? (array_key_first($tage) ?? $heute));
$amTag = alle(W_SELECT . " WHERE w.techniker_id = ? AND w.beginn >= ? AND w.beginn <= ? ORDER BY w.beginn", [$mid, "$tag 00:00:00", "$tag 23:59:59"]);
$firma = firma_koord();

seite_kopf('Termine', darf('techniker') && !darf('organisation') ? 'techniker' : 'organisation', true);
?>
<div class="row" style="justify-content:space-between">
  <h1><?= h($mit[$mid] ?? '') ?></h1>
  <?php if (darf('organisation') || in_array('admin', rollen(), true)): ?>
  <form method="get" class="row"><select name="mid" onchange="this.form.submit()"><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"<?= $id === $mid ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select><noscript><button class="btn ghost">Anzeigen</button></noscript></form>
  <?php endif; ?>
</div>
<?php if ($offenAlt): ?>
  <div class="msg warn"><b><?= count($offenAlt) ?> vergangene Termine noch nicht abgeschlossen:</b>
    <?php foreach ($offenAlt as $w): ?> <a href="termine.php?<?= http_build_query(['mid' => $mid, 'tag' => substr($w['beginn'], 0, 10)]) ?>"><?= datum($w['beginn']) ?> <?= h($w['kunde']) ?></a> ·<?php endforeach; ?></div>
<?php endif; ?>

<div class="cols">
  <div class="stack">
    <div class="box"><h2>Nächste Tage</h2>
      <?php if (!$tage): ?><p class="leer">Keine geplanten Termine.</p><?php endif; ?>
      <?php foreach (array_slice($tage, 0, 20, true) as $d => $liste): ?>
        <a href="termine.php?<?= http_build_query(['mid' => $mid, 'tag' => $d]) ?>" class="row" style="justify-content:space-between;text-decoration:none;padding:6px 8px;border-radius:4px;<?= $d === $tag ? 'background:var(--fg);color:var(--bg)' : '' ?>">
          <b><?= datum($d, true) ?></b><span><?= count($liste) ?> <?= count($liste) === 1 ? 'Kunde' : 'Kunden' ?> · <?= h(implode(', ', array_unique(array_column($liste, 'ort')))) ?></span></a>
      <?php endforeach; ?>
    </div>
    <?php if (!outlook_aktiv()): $t = substr(hash_hmac('sha256', 'kal|' . $mid, geheimnis()), 0, 24); ?>
    <div class="box"><h2>In Outlook</h2>
      <p class="hint">Kalender abonnieren: Adresse kopieren, in Outlook <b>Kalender hinzufügen → Aus dem Internet</b>.</p>
      <input type="text" readonly value="<?= h((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/kalender.php?m=' . $mid . '&t=' . $t) ?>" onclick="this.select()">
      <a class="btn ghost klein" href="kalender.php?<?= http_build_query(['m' => $mid, 't' => $t, 'dl' => 1]) ?>">.ics herunterladen</a></div>
    <?php else: ?><div class="msg ok">Ihre Termine stehen direkt in Ihrem Outlook-Kalender.</div><?php endif; ?>
  </div>

  <div class="stack">
    <div class="box">
      <div class="row" style="justify-content:space-between"><h2><?= datum($tag, true) ?></h2><?php if ($amTag): ?><a class="btn ghost klein no-print" href="javascript:print()">Tagesplan drucken / als PDF</a><?php endif; ?></div>
      <?= $amTag ? bedarf_zeile(array_column($amTag, 'anlage_id'), $tag) : '' ?>
      <?php if (!$amTag): ?><p class="leer">An diesem Tag keine Termine.</p><?php else:
        $p = []; $l = $firma ? [[$firma['lat'], $firma['lon']]] : [];
        foreach ($amTag as $i => $w) if ($w['lat'] !== null) { $p[] = ['lat' => (float)$w['lat'], 'lon' => (float)$w['lon'], 'nr' => $i + 1, 'label' => ($i + 1) . '. ' . $w['kunde'] . ' ' . substr($w['beginn'], 11, 5)]; $l[] = [(float)$w['lat'], (float)$w['lon']]; }
        if ($firma) $l[] = [$firma['lat'], $firma['lon']];
        karte('tageskarte', $p, [$l]);
        $osmRoute = 'https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=' . rawurlencode(implode(';', array_map(fn($x) => $x[0] . ',' . $x[1], $l))); ?>
        <a class="hint" href="<?= h($osmRoute) ?>" target="_blank" rel="noopener">Route auf OpenStreetMap öffnen ↗</a>
      <?php endif; ?>
    </div>
    <?php foreach ($amTag as $i => $w): $inh = termin_inhalt((int)$w['id']); $rest = alle("SELECT * FROM restarbeiten WHERE anlage_id = ? AND status = 'offen'", [$w['anlage_id']]); ?>
      <div class="box">
        <div class="kunde-kopf"><div><span class="mono"><?= substr($w['beginn'], 11, 5) ?>–<?= substr($w['ende'], 11, 5) ?></span>
          <h2><span class="tag"><?= $i + 1 ?></span><span class="tag"><?= h($w['art']) ?></span><?= ticket_marke($w) ?><?= h($w['kunde']) ?></h2>
          <p class="hint"><?= h($w['strasse']) ?>, <?= h($w['plz']) ?> <?= h($w['ort']) ?><?= $w['fahrt_min'] ? ' · ' . (int)$w['fahrt_min'] . ' Min. Anfahrt' : '' ?></p></div>
          <div><?= status_pill($w['status']) ?> <?php foreach ($inh['marken'] as $m): ?><span class="pill warn"><?= h($m) ?></span> <?php endforeach; ?></div></div>
        <details class="druck-offen"><summary class="hint">Alle Infos: Ansprechpartner, Material, Notizen</summary><pre style="white-space:pre-wrap;font-family:var(--body);margin:8px 0 0;padding:10px;background:var(--bg);border-radius:4px"><?= h($inh['text']) ?></pre></details>
        <?php $doks = alle('SELECT * FROM dokumente WHERE kunde_id = ? AND aktiv = 1 AND (anlage_id IS NULL OR anlage_id = ?) ORDER BY erstellt_am DESC LIMIT 5', [$w['kunde_id'], $w['anlage_id']]);
        if ($doks): ?><p class="hint no-print">Dokumente: <?php foreach ($doks as $d): ?><a href="dokument.php?id=<?= $d['id'] ?>" target="_blank"><?= h($d['titel']) ?></a> · <?php endforeach; ?></p><?php endif; ?>
        <?php if ($w['status'] === 'terminiert'): ?>
        <details<?= $w['beginn'] < $heute ? ' open' : '' ?>><summary class="btn klein">Abschließen</summary>
          <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:8px"><?= csrf_feld() ?><input type="hidden" name="aktion" value="erledigt"><input type="hidden" name="id" value="<?= $w['id'] ?>">
            <label class="f">Tatsächliche Zeit vor Ort (Std.)<input type="text" name="ist_h" inputmode="decimal" placeholder="z. B. 1,5"></label>
            <label class="f">Bericht: Was wurde gemacht?<textarea name="gemacht" placeholder="z. B. Wartung durchgeführt, 2× 7,2 Ah getauscht"></textarea></label>
            <label class="f">Was muss noch gemacht werden? (wird als Restarbeit zur Info dokumentiert)<input type="text" name="noch" placeholder="leer lassen, wenn alles erledigt ist"></label>
            <label class="f">Stundennachweis / Bericht als PDF oder Foto (optional)<input type="file" name="datei" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*"></label>
            <?php if ($rest): ?><div class="hint">Restarbeiten erledigt:<?php foreach ($rest as $r): ?><br><label><input type="checkbox" name="rest[]" value="<?= $r['id'] ?>"> <?= h($r['text']) ?></label><?php endforeach; ?></div><?php endif; ?>
            <label class="f">Hinweis fürs Büro (z. B. Akku 7 Ah getauscht, neue Restarbeit, Notiz veraltet)<textarea name="hinweis"></textarea></label>
            <button class="btn"><?= ist_ticket($w) ? 'Ticket erledigt' : 'Wartung erledigt' ?></button></form></details>
        <?php elseif ($w['status'] === 'erledigt'): ?>
          <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="aktion" value="wieder_offen"><input type="hidden" name="id" value="<?= $w['id'] ?>">
            <span class="hint">Erledigt <?= datum($w['erledigt_am']) ?><?= $w['ist_h'] ? ', ' . std($w['ist_h']) : '' ?><?= $w['bericht'] ? ' · Bericht: ' . h($w['bericht']) : '' ?></span><button class="btn ghost klein no-print">Wieder öffnen</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php seite_fuss();
