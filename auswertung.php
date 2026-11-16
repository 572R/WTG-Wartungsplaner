<?php
/** Bereich "Auswertung": Fortschritt der Wartungen, Stunden, Auslastung, offene Punkte */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('auswertung');
[$jA, $qA] = aktuelles_quartal();
$jahr = (int)($_GET['j'] ?? $jA); $q = max(1, min(4, (int)($_GET['q'] ?? $qA)));
$mit = mitarbeiter_namen();

$st = []; foreach (alle('SELECT quartal, status, COUNT(*) AS n FROM wartungen WHERE jahr = ? AND typ = \'wartung\' GROUP BY quartal, status', [$jahr]) as $r) $st[(int)$r['quartal']][$r['status']] = (int)$r['n'];
$summe = fn(string $s) => array_sum(array_map(fn($x) => $x[$s] ?? 0, $st));
$gesamt = array_sum(array_map('array_sum', $st));
$tick = []; foreach (alle("SELECT status, COUNT(*) AS n FROM wartungen WHERE typ = 'ticket' AND jahr = ? GROUP BY status", [$jahr]) as $r) $tick[$r['status']] = (int)$r['n'];
$restOffen = (int)wert("SELECT COUNT(*) FROM restarbeiten WHERE status = 'offen'");
$akkusFaellig = (int)wert("SELECT COUNT(*) FROM bauteile WHERE aktiv = 1 AND typ = 'Akku' AND eingebaut <= ?", [(new DateTimeImmutable())->modify('-' . (int)cfg('akku_tausch_jahre') . ' years')->format('Y-m-d')]);
$fehler = [];
$ausl = auslastung($jahr, $q, $fehler, false);
[$von, $bis] = quartal_von_bis($jahr, $q);
$ist = [];
foreach (alle("SELECT techniker_id, status, beginn, ende, fahrt_min, ist_h FROM wartungen WHERE beginn >= ? AND beginn <= ? AND status IN ('terminiert','erledigt')", ["$von 00:00:00", "$bis 23:59:59"]) as $w) {
    $m = (int)$w['techniker_id']; $dauer = (strtotime($w['ende']) - strtotime($w['beginn'])) / 60;
    $ist[$m]['plan'] = ($ist[$m]['plan'] ?? 0) + $dauer; $ist[$m]['fahrt'] = ($ist[$m]['fahrt'] ?? 0) + (int)$w['fahrt_min'];
    if ($w['status'] === 'erledigt') { $ist[$m]['n'] = ($ist[$m]['n'] ?? 0) + 1; $ist[$m]['soll'] = ($ist[$m]['soll'] ?? 0) + $dauer; if ($w['ist_h'] !== null) { $ist[$m]['ist'] = ($ist[$m]['ist'] ?? 0) + $w['ist_h'] * 60; $ist[$m]['soll_mit'] = ($ist[$m]['soll_mit'] ?? 0) + $dauer; } }
}
seite_kopf('Auswertung', 'auswertung');
?>
<form method="get" class="row">
  <select name="j" onchange="this.form.submit()"><?php for ($y = $jA - 1; $y <= $jA + 1; $y++): ?><option<?= $y === $jahr ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
  <select name="q" onchange="this.form.submit()"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"<?= $i === $q ? ' selected' : '' ?>><?= quartal_name($i) ?></option><?php endfor; ?></select>
  <noscript><button class="btn ghost">Anzeigen</button></noscript>
</form>
<div class="kpis">
  <div class="kpi"><b><?= $gesamt ?></b><span>Wartungen <?= $jahr ?></span></div>
  <div class="kpi"><b><?= $summe('erledigt') ?></b><span>erledigt (<?= $gesamt ? round($summe('erledigt') / $gesamt * 100) : 0 ?> %)</span></div>
  <div class="kpi"><b><?= $summe('terminiert') ?></b><span>terminiert</span></div>
  <div class="kpi"><b><?= $summe('offen') + $summe('zugewiesen') ?></b><span>noch ohne Termin</span></div>
  <div class="kpi"><b><?= ($tick['erledigt'] ?? 0) ?>/<?= array_sum($tick) - ($tick['storniert'] ?? 0) ?></b><span>Tickets <?= $jahr ?> erledigt</span></div>
  <div class="kpi"><b><?= $restOffen ?></b><span>offene Restarbeiten</span></div>
  <div class="kpi"><b><?= $akkusFaellig ?></b><span>Akkus fällig</span></div>
</div>

<div class="box"><h2>Fortschritt je Quartal <?= $jahr ?></h2>
  <div class="legende"><span><i class="b1"></i>erledigt</span><span><i class="b2"></i>terminiert</span><span><i class="b4"></i>zugewiesen</span><span><i class="b3"></i>offen</span></div>
  <div class="scroll"><table class="tbl"><tr><th>Quartal</th><th class="r">Fällig</th><th class="r">Offen</th><th class="r">Zugewiesen</th><th class="r">Terminiert</th><th class="r">Erledigt</th><th style="width:40%"></th></tr>
  <?php for ($i = 1; $i <= 4; $i++): $x = $st[$i] ?? []; $n = max(1, array_sum($x)); ?>
    <tr><td><b><?= quartal_name($i) ?></b></td><td class="r"><?= array_sum($x) ?></td><td class="r"><?= $x['offen'] ?? 0 ?></td><td class="r"><?= $x['zugewiesen'] ?? 0 ?></td><td class="r"><?= $x['terminiert'] ?? 0 ?></td><td class="r"><?= $x['erledigt'] ?? 0 ?></td>
      <td><div class="balken"><i class="b1" style="width:<?= ($x['erledigt'] ?? 0) / $n * 100 ?>%"></i><i class="b2" style="width:<?= ($x['terminiert'] ?? 0) / $n * 100 ?>%"></i><i class="b4" style="width:<?= ($x['zugewiesen'] ?? 0) / $n * 100 ?>%"></i></div></td></tr>
  <?php endfor; ?></table></div>
  <p class="hint">„Fällig" = Zielquartal der Wartung (Halbjahres- und Jahreswartungen werden nach Region auf die Quartale verteilt).</p>
</div>

<div class="box"><h2>Stunden <?= quartal_name($q) ?> <?= $jahr ?><?= cfg('auswertung_pro_person') ? ' je Mitarbeiter' : ' (Team)' ?></h2>
  <?php if ($fehler): ?><div class="msg err">Outlook teilweise nicht lesbar: <?= h(implode(' · ', array_unique($fehler))) ?></div><?php endif; ?>
  <?php
  $zeilen = [];
  foreach ($ausl as $m => $a) $zeilen[$a['name']] = ['arbeit' => $a['arbeit'], 'abw' => $a['abwesend'], 'termine' => $a['termine'], 'plan' => $ist[$m]['plan'] ?? 0, 'fahrt' => $ist[$m]['fahrt'] ?? 0, 'n' => $ist[$m]['n'] ?? 0, 'ist' => $ist[$m]['ist'] ?? null, 'soll_mit' => $ist[$m]['soll_mit'] ?? null];
  if (!cfg('auswertung_pro_person')) { $t = []; foreach ($zeilen as $z) foreach ($z as $k => $v) $t[$k] = ($t[$k] ?? 0) + (float)$v; $zeilen = ['Team gesamt' => $t]; }
  ?>
  <div class="scroll"><table class="tbl"><tr><th></th><th class="r">Arbeitszeit</th><th class="r">Urlaub/abwesend</th><?= outlook_aktiv() ? '<th class="r">Andere Termine</th>' : '' ?><th class="r">Wartung verplant</th><th class="r">davon Fahrt</th><th class="r">Anteil</th><th class="r">Erledigt</th><th class="r">Ist / geplant</th></tr>
  <?php foreach ($zeilen as $name => $z): $netto = max(1, $z['arbeit'] - $z['abw']); ?>
    <tr><td><b><?= h($name) ?></b></td><td class="r"><?= std($z['arbeit'] / 60) ?></td><td class="r"><?= std($z['abw'] / 60) ?></td><?= outlook_aktiv() ? '<td class="r">' . std($z['termine'] / 60) . '</td>' : '' ?>
      <td class="r"><?= std(($z['plan'] + $z['fahrt']) / 60) ?></td><td class="r"><?= std($z['fahrt'] / 60) ?></td>
      <td class="r"><?= round(($z['plan'] + $z['fahrt']) / $netto * 100) ?> %</td><td class="r"><?= (int)$z['n'] ?></td>
      <td class="r"><?= $z['ist'] ? round($z['ist'] / max(1, $z['soll_mit']) * 100) . ' %' : '–' ?></td></tr>
  <?php endforeach; ?></table></div>
  <p class="hint">Anteil = verplante Wartungszeit inkl. Fahrt ÷ (Arbeitszeit − Abwesenheit). Ziel: höchstens <?= (int)(cfg('max_anteil_wartung') * 100) ?> %.
    „Ist / geplant" vergleicht die von den Technikern gemeldete Zeit vor Ort mit der geplanten – über 100 % heißt: Zeiten in der Liste sind zu knapp.
    <?= cfg('auswertung_pro_person') ? 'Hinweis: Auswertungen je Person können mitbestimmungspflichtig sein (Betriebsrat). Umschalten in config.php: auswertung_pro_person.' : '' ?></p>
</div>
<?php seite_fuss();
