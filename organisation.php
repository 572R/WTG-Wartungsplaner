<?php
/**
 * Bereich "Organisation", Stufe 1: offene Wartungen ansehen, Mitarbeitern zuweisen, Auslastung, Abwesenheiten
 */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('organisation');
[$jA, $qA] = aktuelles_quartal();
$jahr = (int)($_REQUEST['j'] ?? $jA); $q = max(1, min(4, (int)($_REQUEST['q'] ?? $qA)));
$basis = 'organisation.php?' . http_build_query(['j' => $jahr, 'q' => $q]);

/* ---------- Aktionen ---------- */
if (post()) {
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $mitGruppe = function (array $ids) use ($jahr): array {
        if (!$ids) return [];
        $g = alle("SELECT DISTINCT a.gruppe_id, w.quartal FROM wartungen w JOIN anlagen a ON a.id = w.anlage_id WHERE w.id IN (" . implode(',', $ids) . ") AND a.gruppe_id IS NOT NULL AND w.typ = 'wartung'");
        foreach ($g as $r) foreach (alle("SELECT w.id FROM wartungen w JOIN anlagen a ON a.id = w.anlage_id WHERE a.gruppe_id = ? AND w.jahr = ? AND w.quartal = ? AND w.status IN ('offen','zugewiesen') AND w.typ = 'wartung'", [$r['gruppe_id'], $jahr, $r['quartal']]) as $x) $ids[] = (int)$x['id'];
        return array_values(array_unique($ids));
    };
    switch ($_POST['aktion'] ?? '') {
        case 'zuweisen':
            $mid = (int)$_POST['mid'];
            if (!$mid || !$ids) weiter($basis, 'Bitte Wartungen anhaken und einen Mitarbeiter wählen.', 'warn');
            $alle = $mitGruppe($ids);
            q("UPDATE wartungen SET techniker_id = ?, status = 'zugewiesen' WHERE status IN ('offen','zugewiesen') AND id IN (" . implode(',', $alle) . ')', [$mid]);
            $plus = count($alle) - count($ids);
            weiter($basis, count($alle) . ' Wartungen an ' . h(mitarbeiter_namen()[$mid]) . ' zugewiesen' . ($plus > 0 ? " (davon $plus aus derselben Gruppe automatisch dazu)" : '') . '.');
        case 'aufheben':
            if ($ids) q("UPDATE wartungen SET techniker_id = NULL, status = 'offen' WHERE status = 'zugewiesen' AND id IN (" . implode(',', $ids) . ')');
            weiter($basis, 'Zuweisung aufgehoben (bereits terminierte bleiben unverändert).');
        case 'erledigt':
            $n = 0;
            if ($ids) $n = q("UPDATE wartungen SET status = 'erledigt', erledigt_am = ?, erledigt_von = ? WHERE status = 'terminiert' AND id IN (" . implode(',', $ids) . ')', [jetzt(), wer()])->rowCount();
            weiter($basis . '&status=erledigt', "$n Wartungen als erledigt markiert.");
        case 'topf':
        case 'termin_weg':
            $n = 0; $prob = [];
            foreach ($ids ? alle('SELECT * FROM wartungen WHERE id IN (' . implode(',', $ids) . ')') : [] as $w) {
                if ($w['outlook_id'] && outlook_aktiv() && $w['techniker_id']) { try { outlook_loeschen((int)$w['techniker_id'], $w['outlook_id']); } catch (Throwable $e) { $prob[] = $e->getMessage(); } }
                $d = ['beginn' => null, 'ende' => null, 'tour_pos' => null, 'fahrt_min' => null, 'outlook_id' => null, 'outlook_fehler' => null, 'ist_h' => null, 'erledigt_am' => null, 'erledigt_von' => null];
                $d += $_POST['aktion'] === 'topf' ? ['status' => 'offen', 'techniker_id' => null] : ['status' => $w['techniker_id'] ? 'zugewiesen' : 'offen'];
                aendern('wartungen', (int)$w['id'], $d); $n++;
            }
            unset($_SESSION['outlook_cache']);
            weiter($basis, $_POST['aktion'] === 'topf' ? "$n Wartungen zurück in den Topf (offen, ohne Mitarbeiter und Termin)" . (outlook_aktiv() ? ', Outlook-Einträge entfernt.' : '.')
                : "$n Termine entfernt – die Wartungen bleiben ihrem Mitarbeiter zugewiesen und können neu terminiert werden.", $prob ? 'warn' : 'ok');
        case 'stamm':
            $wo = $ids ? ' AND w.id IN (' . implode(',', $ids) . ')' : '';
            $n = 0;
            foreach (alle("SELECT w.id, a.stammtechniker_id FROM wartungen w JOIN anlagen a ON a.id = w.anlage_id WHERE w.jahr = ? AND w.quartal <= ? AND w.status = 'offen' AND a.stammtechniker_id IS NOT NULL$wo", [$jahr, $q]) as $r) {
                q("UPDATE wartungen SET techniker_id = ?, status = 'zugewiesen' WHERE id = ?", [$r['stammtechniker_id'], $r['id']]); $n++;
            }
            weiter($basis, "$n Wartungen ihrem Stammtechniker zugewiesen.");
        case 'abw_neu':
            if ($_POST['von'] && $_POST['bis'] >= $_POST['von']) einfuegen('abwesenheiten', ['mitarbeiter_id' => (int)$_POST['mid'], 'von' => $_POST['von'], 'bis' => $_POST['bis'], 'art' => $_POST['art']]);
            weiter($basis . '#abwesend', 'Abwesenheit eingetragen.');
        case 'abw_loeschen':
            q('DELETE FROM abwesenheiten WHERE id = ?', [(int)$_POST['id']]);
            weiter($basis . '#abwesend', 'Abwesenheit entfernt.');
        case 'jahr_erzeugen':
            $n = wartungen_erzeugen((int)$_POST['jahr']);
            weiter($basis, "$n Wartungen für {$_POST['jahr']} angelegt.");
    }
}

/* ---------- Daten ---------- */
$mit = mitarbeiter_namen();
$fehler = [];
$ausl = auslastung($jahr, $q, $fehler);
$liste = wartungen_quartal($jahr, $q);
$filter = ['status' => $_GET['status'] ?? 'offen', 'mid' => (int)($_GET['mid'] ?? 0), 'such' => trim((string)($_GET['such'] ?? '')), 'typ' => in_array($_GET['typ'] ?? '', ['wartung', 'ticket'], true) ? $_GET['typ'] : ''];
$vorschlag = [];
if (!empty($_GET['vorschlag'])) {
    $vorschlag = array_flip(zuweisungs_vorschlag((int)$_GET['vorschlag'], $jahr, $q, (float)str_replace(',', '.', (string)($_GET['std'] ?? 20))));
    $filter['status'] = 'offen'; $filter['mid'] = 0;
}
$sicht = array_values(array_filter($liste, function ($w) use ($filter, $vorschlag) {
    if ($vorschlag) return isset($vorschlag[(int)$w['id']]) || $w['status'] === 'offen';
    if ($filter['typ'] !== '' && ($w['typ'] ?? 'wartung') !== $filter['typ']) return false;
    if ($filter['status'] === 'ueberfaellig') { if (!($w['status'] === 'terminiert' && $w['beginn'] < date('Y-m-d'))) return false; }
    elseif ($filter['status'] !== 'alle' && $w['status'] !== $filter['status']) return false;
    if ($filter['mid'] && (int)$w['techniker_id'] !== $filter['mid'] && (int)$w['stammtechniker_id'] !== $filter['mid']) return false;
    if ($filter['such'] !== '' && !str_contains(mb_strtolower($w['kunde'] . ' ' . $w['ort'] . ' ' . $w['plz'] . ' ' . ($w['gruppe'] ?? '') . ' ' . ($w['titel'] ?? '')), mb_strtolower($filter['such']))) return false;
    return true;
}));
if ($vorschlag) usort($sicht, fn($a, $b) => (isset($vorschlag[(int)$b['id']]) <=> isset($vorschlag[(int)$a['id']])) ?: strcmp((string)$a['plz'], (string)$b['plz']));
$zaehl = ['offen' => 0, 'zugewiesen' => 0, 'terminiert' => 0, 'erledigt' => 0];
foreach ($liste as $w) if ($filter['typ'] === '' || ($w['typ'] ?? 'wartung') === $filter['typ']) $zaehl[$w['status']] = ($zaehl[$w['status']] ?? 0) + 1;
$nTickets = count(array_filter($liste, fn($w) => ist_ticket($w) && in_array($w['status'], ['offen', 'zugewiesen'], true)));
$zaehl['ueberfaellig'] = count(array_filter($liste, fn($w) => $w['status'] === 'terminiert' && $w['beginn'] < date('Y-m-d')));
$hatJahr = (int)wert('SELECT COUNT(*) FROM wartungen WHERE jahr = ?', [$jahr]);

seite_kopf('Organisation', 'organisation');
?>
<div class="row" style="justify-content:space-between">
  <form method="get" class="row">
    <select name="j" onchange="this.form.submit()"><?php for ($y = $jA - 1; $y <= $jA + 2; $y++): ?><option<?= $y === $jahr ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
    <select name="q" onchange="this.form.submit()"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"<?= $i === $q ? ' selected' : '' ?>><?= quartal_name($i) ?></option><?php endfor; ?></select>
    <noscript><button class="btn ghost">Anzeigen</button></noscript>
  </form>
  <div class="row"><a class="btn" href="organisation.php?<?= http_build_query(['j' => $jahr, 'q' => $q]) ?>">1. Zuweisen</a><a class="btn ghost" href="touren.php?<?= http_build_query(['j' => $jahr, 'q' => $q]) ?>">2. Touren planen</a><a class="btn ghost" href="wochenplan.php?monat=<?= date('Y-m') ?>">Kalender</a><a class="btn ghost" href="wochenplan.php">Wochenplan</a><?php if (outlook_aktiv()): ?><a class="btn ghost" href="abgleich.php">Outlook-Abgleich</a><?php endif; ?></div>
</div>
<?php if (!$hatJahr): ?>
  <form class="msg warn" method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="jahr_erzeugen"><input type="hidden" name="jahr" value="<?= $jahr ?>">
    Für <?= $jahr ?> gibt es noch keine Wartungen. <button class="btn klein">Wartungen für <?= $jahr ?> anlegen</button></form>
<?php endif; ?>
<?php if ($fehler): ?><div class="msg err">Outlook-Kalender teilweise nicht lesbar:<ul><?php foreach (array_unique($fehler) as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="box">
  <div class="row" style="justify-content:space-between"><h2>Auslastung <?= quartal_name($q) ?> <?= $jahr ?></h2>
    <div class="legende"><span><i class="b1"></i>terminiert</span><span><i class="b2"></i>zugewiesen</span><span><i class="b3"></i>noch frei</span></div></div>
  <div class="scroll"><table class="tbl">
    <tr><th>Mitarbeiter</th><th class="r">Verfügbar</th><th class="r">Zugewiesen</th><th class="r">Terminiert</th><th class="r">Frei</th><th style="width:30%"></th><th>Vorschlag</th></tr>
    <?php foreach ($ausl as $mid => $a): $v = max(1, $a['verfuegbar']); ?>
      <tr><td><b><?= h($a['name']) ?></b><?= $a['abwesend'] ? '<br><span class="hint">' . std($a['abwesend'] / 60) . ' abwesend</span>' : '' ?></td>
        <td class="r"><?= std($a['verfuegbar'] / 60) ?></td><td class="r"><?= std($a['zugewiesen'] / 60) ?></td><td class="r"><?= std($a['terminiert'] / 60) ?></td>
        <td class="r"><?= $a['frei'] < 0 ? '<span class="pill err">' . std($a['frei'] / 60) . '</span>' : std($a['frei'] / 60) ?></td>
        <td><div class="balken"><i class="b1" style="width:<?= min(100, $a['terminiert'] / $v * 100) ?>%"></i><i class="b2" style="width:<?= max(0, min(100 - $a['terminiert'] / $v * 100, $a['zugewiesen'] / $v * 100)) ?>%"></i></div></td>
        <td><form method="get" class="row" style="flex-wrap:nowrap"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><input type="hidden" name="vorschlag" value="<?= $mid ?>">
          <input type="number" name="std" value="<?= max(4, min(60, (int)round(max(0, $a['frei']) / 60))) ?>" min="1" style="width:70px" aria-label="Stunden"><button class="btn ghost klein">Std. vorschlagen</button></form></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="hint">Verfügbar = Arbeitszeit ab heute − Abwesenheit<?= outlook_aktiv() ? ' − Outlook-Termine' : '' ?>, davon höchstens <?= (int)(cfg('max_anteil_wartung') * 100) ?> % für Wartungen. Zeiten inkl. Fahrt laut Lukas' Liste.</p>
</div>

<form class="box" method="post" id="liste">
  <?= csrf_feld() ?><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>">
  <?php if ($vorschlag): ?><div class="msg info">Vorschlag für <b><?= h($mit[(int)$_GET['vorschlag']] ?? '') ?></b>: <?= count($vorschlag) ?> Wartungen (blau, schon angehakt) – zuerst Stammkunden, dann in der Nähe der bereits zugewiesenen, Gruppen vollständig. Prüfen, anpassen, dann „Zuweisen".
    <a href="<?= $basis ?>">Vorschlag verwerfen</a></div><?php endif; ?>
  <div class="row" style="justify-content:space-between">
    <div class="row">
      <?php foreach (['offen' => 'Offen', 'zugewiesen' => 'Zugewiesen', 'terminiert' => 'Terminiert', 'ueberfaellig' => 'Überfällig', 'erledigt' => 'Erledigt', 'alle' => 'Alle'] as $s => $n): if ($s === 'ueberfaellig' && !$zaehl['ueberfaellig']) continue; ?>
        <a class="btn <?= $filter['status'] === $s && !$vorschlag ? '' : 'ghost' ?> klein" href="<?= h($basis . '&' . http_build_query(['status' => $s, 'mid' => $filter['mid'] ?: null, 'such' => $filter['such'] ?: null, 'typ' => $filter['typ'] ?: null])) ?>"><?= $n ?> <?= $s === 'alle' ? array_sum(array_intersect_key($zaehl, array_flip(['offen', 'zugewiesen', 'terminiert', 'erledigt']))) : ($zaehl[$s] ?? 0) ?></a>
      <?php endforeach; ?>
      <span class="hint">·</span>
      <?php foreach (['' => 'Wartungen + Tickets', 'wartung' => 'Nur Wartungen', 'ticket' => 'Nur Tickets' . ($nTickets ? " ($nTickets offen)" : '')] as $t => $n): ?>
        <a class="btn <?= $filter['typ'] === $t ? '' : 'ghost' ?> klein" href="<?= h($basis . '&' . http_build_query(['status' => $filter['status'], 'mid' => $filter['mid'] ?: null, 'such' => $filter['such'] ?: null, 'typ' => $t ?: null])) ?>"><?= h($n) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="row">
      <input type="search" form="filter" name="such" value="<?= h($filter['such']) ?>" placeholder="Kunde, Ort, PLZ" style="width:180px">
      <select form="filter" name="mid"><option value="">alle Mitarbeiter</option><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"<?= $filter['mid'] === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
      <button form="filter" class="btn ghost klein">Filtern</button>
    </div>
  </div>
  <div class="row" style="background:var(--bg);padding:8px;border-radius:6px">
    <b id="anz">0</b>&nbsp;ausgewählt ·
    <select name="mid" aria-label="Mitarbeiter"><option value="">Mitarbeiter wählen …</option><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"<?= (int)($_GET['vorschlag'] ?? 0) === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
    <button class="btn klein" name="aktion" value="zuweisen">Zuweisen</button>
    <button class="btn ghost klein" name="aktion" value="aufheben">Zuweisung aufheben</button>
    <button class="btn ghost klein" name="aktion" value="stamm" title="Ausgewählte (oder ohne Auswahl: alle offenen bis zu diesem Quartal) dem Stammtechniker zuweisen">Stammtechniker übernehmen</button>
  </div>
  <div class="row" style="background:var(--bg);padding:8px;border-radius:6px">
    <span class="hint">Für terminierte/erledigte:</span>
    <button class="btn ghost klein" name="aktion" value="erledigt">Als erledigt markieren</button>
    <button class="btn ghost klein" name="aktion" value="termin_weg" onclick="return confirm('Termine der ausgewählten Wartungen entfernen<?= outlook_aktiv() ? ' (auch aus Outlook)' : '' ?>? Sie bleiben zugewiesen.')">Termin entfernen</button>
    <button class="btn ghost klein" name="aktion" value="topf" onclick="return confirm('Ausgewählte zurück in den Topf (offen, ohne Mitarbeiter und Termin)?')">Zurück in den Topf</button>
  </div>
  <div class="scroll"><table class="tbl">
    <tr><th><input type="checkbox" id="alle" aria-label="Alle"></th><th>Kunde</th><th>Ort</th><th>Anlage</th><th>Periode</th><th>Gruppe</th><th class="r">Zeit</th><th>Stamm</th><th>Zugewiesen</th><th>Status</th></tr>
    <?php foreach ($sicht as $w): $vs = isset($vorschlag[(int)$w['id']]); ?>
      <tr class="<?= $vs ? 'vorschlag' : '' ?>">
        <td><input type="checkbox" name="ids[]" value="<?= $w['id'] ?>"<?= $vs ? ' checked' : '' ?> aria-label="auswählen"></td>
        <td><?= darf('pflege') ? '<a href="pflege.php?k=' . $w['kunde_id'] . '">' . h($w['kunde']) . '</a>' : h($w['kunde']) ?></td>
        <td><span class="mono"><?= h($w['plz']) ?></span> <?= h($w['ort']) ?></td>
        <td><span class="tag"><?= h($w['art']) ?></span><?= ist_ticket($w) ? '<br><span class="pill info">Ticket</span> ' . h($w['titel']) . ($w['prio'] === 'dringend' ? ' <span class="pill err">dringend</span>' : '') : '' ?></td>
        <td><?= ist_ticket($w) ? ($w['faellig_bis'] ? 'fällig bis ' . datum($w['faellig_bis']) : 'Ticket') : h($w['periode']) ?><?= (int)$w['quartal'] < $q ? ' <span class="pill warn">aus Q' . $w['quartal'] . '</span>' : '' ?></td>
        <td class="hint"><?= h($w['gruppe'] ?? '') ?></td>
        <td class="r"><?= ist_ticket($w) && $w['dauer_h'] !== null ? zahl(zeit_gesamt_min($w) / 60, 2) : ($w['zeit_h'] !== null ? zahl($w['zeit_h'], 2) : '<span class="alt" title="keine Zeit hinterlegt">' . zahl(cfg('standard_zeit_h'), 2) . '*</span>') ?></td>
        <td><?= h($mit[(int)$w['stammtechniker_id']] ?? '–') ?></td>
        <td><?= h($mit[(int)$w['techniker_id']] ?? '') ?></td>
        <td><?= status_pill($w['status']) ?><?= $w['beginn'] ? ' <span class="mono">' . datum($w['beginn']) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$sicht): ?><tr><td colspan="10" class="leer">Keine Wartungen in dieser Ansicht.</td></tr><?php endif; ?>
  </table></div>
  <p class="hint">Gehört eine Wartung zu einer Gruppe, werden beim Zuweisen alle Wartungen der Gruppe in diesem Quartal mit zugewiesen.
    Tickets aus der Pflege stehen hier wie Wartungen (blaues „Ticket"); mit Frist oder „dringend" plant die Tourenplanung sie zuerst und vor der Frist ein.
    „Zurück in den Topf" macht aus jeder Wartung wieder eine offene – z. B. wenn ein Termin nicht geschafft wurde. Nicht geschaffte Wartungen aus früheren Quartalen erscheinen automatisch mit „aus Q…" im nächsten Quartal.</p>
</form>
<form id="filter" method="get"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><input type="hidden" name="status" value="<?= h($filter['status']) ?>"><input type="hidden" name="typ" value="<?= h($filter['typ']) ?>"></form>

<div class="box" id="abwesend">
  <h2>Abwesenheiten (Urlaub, Schulung, Krankheit)</h2>
  <p class="hint"><?= outlook_aktiv() ? 'Abwesenheiten aus Outlook werden zusätzlich automatisch berücksichtigt.' : 'Solange Outlook nicht angebunden ist, hier eintragen – sie fließen in Auslastung und Tourenplanung ein.' ?></p>
  <?php $abw = alle('SELECT * FROM abwesenheiten WHERE bis >= ? ORDER BY von', [date('Y-m-d')]); ?>
  <?php if ($abw): ?><div class="scroll"><table class="tbl"><tr><th>Mitarbeiter</th><th>Von</th><th>Bis</th><th>Art</th><th></th></tr>
    <?php foreach ($abw as $a): ?><tr><td><?= h($mit[(int)$a['mitarbeiter_id']] ?? '') ?></td><td><?= datum($a['von'], true) ?></td><td><?= datum($a['bis'], true) ?></td><td><?= h($a['art']) ?></td>
      <td><form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="abw_loeschen"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><button class="btn ghost klein">Entfernen</button></form></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
  <form method="post" class="grid4"><?= csrf_feld() ?><input type="hidden" name="aktion" value="abw_neu"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>">
    <label class="f">Mitarbeiter<select name="mid"><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label class="f">Von<input type="date" name="von" required></label><label class="f">Bis<input type="date" name="bis" required></label>
    <label class="f">Art<select name="art"><option>Urlaub</option><option>Schulung</option><option>Krank</option><option>Sonstiges</option></select></label>
    <button class="btn klein" style="grid-column:1/-1;justify-self:start">Eintragen</button></form>
</div>
<script>
const boxes = () => [...document.querySelectorAll('#liste input[name="ids[]"]')];
const zaehl = () => document.getElementById('anz').textContent = boxes().filter(b => b.checked).length;
document.getElementById('alle').addEventListener('change', e => { boxes().forEach(b => b.checked = e.target.checked); zaehl(); });
boxes().forEach(b => b.addEventListener('change', zaehl)); zaehl();
</script>
<?php seite_fuss();
