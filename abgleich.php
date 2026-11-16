<?php
/**
 * Bereich "Organisation": Outlook-Abgleich
 * Findet Termine, die von Hand in Outlook eingetragen wurden (Wartung, Service, Krankheit, Urlaub),
 * und schlägt vor, sie zu übernehmen – damit die Wartung als terminiert/erledigt zählt bzw. die Abwesenheit bekannt ist.
 * Liest dafür Betreff und Ort der Termine (nur hier, nicht bei der normalen Planung).
 */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('organisation');
@set_time_limit(300);
$mit = mitarbeiter_namen();
$von = (string)($_REQUEST['von'] ?? date('Y-m-d', strtotime('-60 days')));
$bis = (string)($_REQUEST['bis'] ?? date('Y-m-d', strtotime('+60 days')));
$mids = array_map('intval', (array)($_REQUEST['mids'] ?? array_keys(array_filter(mitarbeiter_liste(), fn($m) => $m['email']))));
if (!$mids) $mids = array_column(mitarbeiter_liste(), 'id');
$basis = 'abgleich.php?' . http_build_query(['von' => $von, 'bis' => $bis, 'mids' => $mids]);

if (post()) {
    $e = json_decode((string)($_POST['e'] ?? ''), true) ?: [];
    switch ($_POST['aktion'] ?? '') {
        case 'wartung':
            $w = eine('SELECT * FROM wartungen WHERE id = ?', [(int)$_POST['wid']]);
            if (!$w || !$e) weiter($basis, 'Nicht gefunden.', 'err');
            $beginn = $e['ganztag'] ? substr($e['beginn'], 0, 10) . ' ' . (cfg('arbeitszeiten')[(int)date('N', strtotime($e['beginn']))][0] ?? '08:00') . ':00' : $e['beginn'];
            $ende = $e['ganztag'] ? substr($beginn, 0, 11) . date('H:i:s', strtotime($beginn) + 2 * 3600) : $e['ende'];
            $vorbei = strtotime($ende) < time();
            aendern('wartungen', (int)$w['id'], ['techniker_id' => (int)$e['mid'], 'beginn' => $beginn, 'ende' => $ende, 'outlook_id' => $e['id'], 'outlook_fehler' => null,
                'status' => $vorbei ? 'erledigt' : 'terminiert', 'erledigt_am' => $vorbei ? jetzt() : null, 'erledigt_von' => $vorbei ? 'Outlook-Abgleich (' . wer() . ')' : null]);
            weiter($basis, 'Übernommen: Wartung ist jetzt ' . ($vorbei ? 'erledigt' : 'terminiert') . ' (' . datum($beginn) . ').');
        case 'abwesend':
            $d1 = substr($e['beginn'], 0, 10); $d2 = $e['ganztag'] ? date('Y-m-d', strtotime($e['ende']) - 1) : substr($e['ende'], 0, 10);
            if (!(int)wert('SELECT COUNT(*) FROM abwesenheiten WHERE mitarbeiter_id = ? AND von = ? AND bis = ?', [(int)$e['mid'], $d1, $d2]))
                einfuegen('abwesenheiten', ['mitarbeiter_id' => (int)$e['mid'], 'von' => $d1, 'bis' => max($d1, $d2), 'art' => $_POST['art'] ?? 'Sonstiges']);
            einfuegen('outlook_ignoriert', ['event_id' => $e['id'], 'am' => jetzt(), 'von' => wer()]);
            weiter($basis, 'Als Abwesenheit eingetragen.');
        case 'ignorieren':
            try { einfuegen('outlook_ignoriert', ['event_id' => $e['id'], 'am' => jetzt(), 'von' => wer()]); } catch (Throwable $x) {}
            weiter($basis, 'Ausgeblendet.');
    }
}

seite_kopf('Outlook-Abgleich', 'organisation');
?>
<div class="row" style="justify-content:space-between"><h1>Outlook-Abgleich</h1>
  <div class="row"><a class="btn ghost" href="organisation.php">1. Zuweisen</a><a class="btn ghost" href="touren.php">2. Touren planen</a><a class="btn ghost" href="wochenplan.php">Wochenplan</a><a class="btn" href="abgleich.php">Outlook-Abgleich</a></div></div>
<?php if (!outlook_aktiv()): ?>
  <div class="msg warn">Outlook ist noch nicht angebunden (config.lokal.php: outlook_aktiv). Ohne Anbindung gibt es nichts abzugleichen.</div>
<?php seite_fuss(); exit; endif; ?>
<form class="box" method="get">
  <p class="hint">Findet Termine, die <b>von Hand</b> in Outlook eingetragen wurden – z. B. eine Wartung „auf dem Weg", Service, Krankheit oder Urlaub – und schlägt vor, sie zu übernehmen.
    Dafür werden auf dieser Seite <b>Betreff und Ort</b> der Termine gelesen (sonst nur „belegt/frei"). Termine, die der Wartungsplaner selbst eingetragen hat, werden übersprungen.</p>
  <div class="grid3"><label class="f">Von<input type="date" name="von" value="<?= h($von) ?>"></label><label class="f">Bis<input type="date" name="bis" value="<?= h($bis) ?>"></label>
    <div class="f"><span class="hint">Mitarbeiter</span><div class="row"><?php foreach (mitarbeiter_liste() as $m): ?><label class="hint"><input type="checkbox" name="mids[]" value="<?= $m['id'] ?>"<?= in_array((int)$m['id'], $mids, true) ? ' checked' : '' ?>> <?= h($m['name']) ?></label><?php endforeach; ?></div></div></div>
  <button class="btn">Kalender durchsuchen</button>
</form>
<?php
$eigene = array_flip(array_filter(array_column(alle('SELECT outlook_id FROM wartungen WHERE outlook_id IS NOT NULL'), 'outlook_id')));
$ignoriert = array_flip(array_column(alle('SELECT event_id FROM outlook_ignoriert'), 'event_id'));
$kat = ms()['kategorie'];
// Kandidaten: offene/zugewiesene Wartungen der betroffenen Jahre
$jahre = range((int)substr($von, 0, 4), (int)substr($bis, 0, 4));
$kand = alle(W_SELECT . " WHERE w.status IN ('offen','zugewiesen') AND w.jahr IN (" . implode(',', $jahre) . ')');
$woerter = fn(string $s) => array_values(array_filter(preg_split('/[^a-z0-9]+/', str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($s))), fn($w) => strlen($w) >= 4 && !in_array($w, ['wartung', 'stadt', 'gmbh', 'strasse', 'str', 'kunde', 'termin', 'service', 'buero', 'team', 'reparatur', 'pruefung', 'besprechung', 'teambesprechung', 'firma', 'halle', 'markt', 'haus', 'schule', 'rathaus'], true)));
$treffer = []; $abwes = []; $sonst = 0; $fehler = [];
foreach ($mids as $mid) {
    try { $ev = outlook_termine($mid, $von, $bis); } catch (Throwable $e) { $fehler[] = ($mit[$mid] ?? '') . ': ' . $e->getMessage(); continue; }
    foreach ($ev as $e) {
        if (isset($eigene[$e['id']]) || isset($ignoriert[$e['id']]) || in_array($kat, $e['kategorien'], true)) continue;
        $e['mid'] = $mid;
        if ($e['status'] === 'oof' || preg_match('/krank|urlaub|abwesend|schulung|frei\b/i', $e['betreff'])) { $abwes[] = $e; continue; }
        $text = norm($e['betreff'] . ' ' . $e['ort']); $tw = $woerter($e['betreff'] . ' ' . $e['ort']);
        if (!$tw) { $sonst++; continue; }
        $best = [];
        foreach ($kand as $w) {
            $kw = $woerter($w['kunde']); if (!$kw) continue;
            $hit = count(array_filter($kw, fn($x) => str_contains($text, $x)));
            if (!$hit) continue;
            $score = $hit / count($kw);
            if ($w['strasse'] && str_contains($text, norm(preg_replace('/\s*\d.*$/', '', $w['strasse'])))) $score += 0.4;
            if ($w['ort'] && str_contains($text, norm($w['ort']))) $score += 0.1;
            if ((int)$w['techniker_id'] === $mid || (int)$w['stammtechniker_id'] === $mid) $score += 0.1;
            if (preg_match('/' . preg_quote(mb_strtolower($w['art']), '/') . '/', mb_strtolower($e['betreff']))) $score += 0.1;
            if ($score >= 0.8) $best[] = [$score, $w];
        }
        if (!$best) { $sonst++; continue; }
        usort($best, fn($a, $b) => $b[0] <=> $a[0]);
        $treffer[] = [$e, array_slice($best, 0, 4)];
    }
}
usort($treffer, fn($a, $b) => strcmp($a[0]['beginn'], $b[0]['beginn']));
?>
<?php if ($fehler): ?><div class="msg err">Nicht lesbar:<ul><?php foreach ($fehler as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="box"><h2>Von Hand eingetragene Wartungen (<?= count($treffer) ?>)</h2>
  <p class="hint">Liegt der Termin in der Vergangenheit, wird die Wartung als <b>erledigt</b> übernommen, sonst als <b>terminiert</b>. Passt der Vorschlag nicht, eine andere Wartung wählen oder ausblenden.</p>
  <?php if (!$treffer): ?><p class="leer">Keine gefunden.</p><?php endif; ?>
  <?php foreach ($treffer as [$e, $best]): $ej = json_encode($e, JSON_UNESCAPED_UNICODE); ?>
    <div style="border-top:1px solid var(--line);padding-top:8px" class="stack">
      <div><b><?= h($mit[$e['mid']] ?? '') ?></b> · <span class="mono"><?= datum($e['beginn'], true) ?> <?= $e['ganztag'] ? 'ganztägig' : substr($e['beginn'], 11, 5) . '–' . substr($e['ende'], 11, 5) ?></span>
        · „<?= h($e['betreff']) ?>"<?= $e['ort'] ? ' · ' . h($e['ort']) : '' ?></div>
      <form method="post" class="row"><?= csrf_feld() ?><input type="hidden" name="e" value="<?= h($ej) ?>">
        <select name="wid" style="max-width:520px"><?php foreach ($best as [$sc, $w]): ?><option value="<?= $w['id'] ?>"><?= h($w['kunde']) ?> · <?= h($w['art']) ?> · <?= h($w['periode']) ?> <?= $w['jahr'] ?> (<?= h($w['ort']) ?>, <?= min(100, (int)round($sc * 100)) ?> %)</option><?php endforeach; ?></select>
        <button class="btn klein" name="aktion" value="wartung">Übernehmen</button><button class="btn ghost klein" name="aktion" value="ignorieren">Ausblenden</button></form>
    </div>
  <?php endforeach; ?>
</div>
<div class="box"><h2>Abwesenheiten aus Outlook (<?= count($abwes) ?>)</h2>
  <p class="hint">Werden bei Planung und Auslastung schon automatisch berücksichtigt. Übernehmen trägt sie zusätzlich unter Abwesenheiten ein (für Wochenplan und Auswertung).</p>
  <?php if (!$abwes): ?><p class="leer">Keine.</p><?php endif; ?>
  <?php foreach ($abwes as $e): ?>
    <form method="post" class="row" style="border-top:1px solid var(--line);padding-top:6px"><?= csrf_feld() ?><input type="hidden" name="e" value="<?= h(json_encode($e, JSON_UNESCAPED_UNICODE)) ?>">
      <span style="flex:1;min-width:260px"><b><?= h($mit[$e['mid']] ?? '') ?></b> · <?= datum($e['beginn'], true) ?><?= substr($e['ende'], 0, 10) > substr($e['beginn'], 0, 10) && !($e['ganztag'] && date('Y-m-d', strtotime($e['ende']) - 1) === substr($e['beginn'], 0, 10)) ? '–' . datum($e['ganztag'] ? date('Y-m-d', strtotime($e['ende']) - 1) : $e['ende']) : '' ?> · „<?= h($e['betreff']) ?>"</span>
      <select name="art" style="max-width:140px"><?php $vor = preg_match('/krank/i', $e['betreff']) ? 'Krank' : (preg_match('/schulung/i', $e['betreff']) ? 'Schulung' : (preg_match('/urlaub/i', $e['betreff']) ? 'Urlaub' : 'Sonstiges')); foreach (['Urlaub', 'Krank', 'Schulung', 'Sonstiges'] as $a): ?><option<?= $a === $vor ? ' selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select>
      <button class="btn ghost klein" name="aktion" value="abwesend">Übernehmen</button><button class="btn ghost klein" name="aktion" value="ignorieren">Ausblenden</button></form>
  <?php endforeach; ?>
</div>
<p class="hint"><?= $sonst ?> weitere Termine ohne Bezug zu einer offenen Wartung (z. B. Besprechungen) – werden bei der Planung nur als „belegt" berücksichtigt.</p>
<?php seite_fuss();
