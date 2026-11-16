<?php
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
$b = benutzer();
if (!$b) { header('Location: login.php'); exit; }
// Startseite: Übersicht der eigenen Bereiche mit dem Wichtigsten
[$j, $q] = aktuelles_quartal();
seite_kopf('Start');
?>
<h1>Guten Tag, <?= h($b['name']) ?></h1>
<div class="kpis">
<?php if (darf('techniker') && $b['mitarbeiter_id']):
    $n = (int)wert("SELECT COUNT(*) FROM wartungen WHERE techniker_id = ? AND status = 'terminiert' AND beginn >= ?", [$b['mitarbeiter_id'], date('Y-m-d')]); ?>
  <a class="kpi" href="termine.php" style="text-decoration:none"><b><?= $n ?></b><span>Meine nächsten Termine</span></a>
<?php endif; if (darf('pflege')):
    $m = (int)wert("SELECT COUNT(*) FROM meldungen WHERE status = 'offen'"); $r = (int)wert("SELECT COUNT(*) FROM restarbeiten WHERE status = 'offen'"); ?>
  <a class="kpi" href="pflege.php?ansicht=pruef" style="text-decoration:none"><b><?= $m ?></b><span>Meldungen der Techniker</span></a>
  <a class="kpi" href="pflege.php?ansicht=pruef" style="text-decoration:none"><b><?= $r ?></b><span>Offene Restarbeiten</span></a>
<?php endif; if (darf('organisation')):
    $o = (int)wert("SELECT COUNT(*) FROM wartungen WHERE jahr = ? AND quartal <= ? AND status = 'offen'", [$j, $q]);
    $z = (int)wert("SELECT COUNT(*) FROM wartungen WHERE jahr = ? AND quartal <= ? AND status = 'zugewiesen'", [$j, $q]); ?>
  <a class="kpi" href="organisation.php" style="text-decoration:none"><b><?= $o ?></b><span>Noch nicht zugewiesen (bis Q<?= $q ?>/<?= $j ?>)</span></a>
  <a class="kpi" href="touren.php" style="text-decoration:none"><b><?= $z ?></b><span>Zugewiesen, noch ohne Termin</span></a>
<?php endif; ?>
</div>
<?php if (darf('organisation') || darf('pflege')):
    $heute = date('Y-m-d'); $jetztMin = (int)date('G') * 60 + (int)date('i');
    $mitNamen = mitarbeiter_namen();
    // 1. Was gerade getan wird
    $heuteWs = alle(W_SELECT . " WHERE w.beginn >= ? AND w.beginn <= ? AND w.status IN ('terminiert','erledigt') ORDER BY w.beginn", ["$heute 00:00:00", "$heute 23:59:59"]);
    $proM = []; foreach ($heuteWs as $w) $proM[(int)$w['techniker_id']][] = $w;
    $abwHeute = []; foreach (alle('SELECT * FROM abwesenheiten WHERE von <= ? AND bis >= ?', [$heute, $heute]) as $a) $abwHeute[(int)$a['mitarbeiter_id']] = $a['art'];
    $nichtAbg = (int)wert("SELECT COUNT(*) FROM wartungen WHERE status = 'terminiert' AND beginn < ?", [$heute]);
    // 2. Was noch fehlt (Angaben, ohne die nicht sauber geplant werden kann)
    $akkuUnklar = 0; $akkuOhne = 0;
    foreach (alle("SELECT b.bezeichnung, b.anzahl, b.eingebaut FROM bauteile b JOIN anlagen a ON a.id = b.anlage_id WHERE b.aktiv = 1 AND b.typ = 'Akku' AND a.aktiv = 1") as $b) { if (!akku_teile($b['bezeichnung'], (int)$b['anzahl'])['klar']) $akkuUnklar++; if (!$b['eingebaut']) $akkuOhne++; }
    $fehlt = [
        ['organisation', (int)wert("SELECT COUNT(*) FROM wartungen WHERE typ = 'wartung' AND jahr = ? AND quartal <= ? AND status = 'offen'", [$j, $q]), 'Wartungen ohne Mitarbeiter (bis Q' . $q . ')', 'organisation.php?status=offen&typ=wartung'],
        ['organisation', (int)wert("SELECT COUNT(*) FROM wartungen WHERE typ = 'ticket' AND status = 'offen'"), 'Tickets ohne Mitarbeiter', 'organisation.php?status=offen&typ=ticket'],
        ['organisation', (int)wert("SELECT COUNT(*) FROM wartungen WHERE typ = 'ticket' AND status IN ('offen','zugewiesen') AND faellig_bis < ?", [$heute]), 'Tickets über der Frist, noch ohne Termin', 'pflege.php?ansicht=tickets'],
        ['organisation', $nichtAbg, 'Vergangene Termine nicht abgeschlossen', 'organisation.php?status=ueberfaellig'],
        ['pflege', (int)wert("SELECT COUNT(*) FROM wartungen WHERE typ = 'ticket' AND status IN ('offen','zugewiesen') AND dauer_h IS NULL"), 'Tickets ohne geschätzte Dauer', 'pflege.php?ansicht=tickets'],
        ['pflege', (int)wert('SELECT COUNT(*) FROM anlagen WHERE aktiv = 1 AND zeit_h IS NULL'), 'Anlagen ohne Zeit (geplant mit ' . std(cfg('standard_zeit_h')) . ')', 'pflege.php?ansicht=pruef#zeit'],
        ['pflege', (int)wert("SELECT COUNT(*) FROM kunden WHERE geo_status IS NULL OR geo_status <> 'adresse'"), 'Adressen nicht genau auf der Karte', 'pflege.php?ansicht=pruef#geo'],
        ['pflege', $akkuUnklar, 'Akku-Einträge ohne eindeutigen Typ', 'pflege.php?ansicht=akkus'],
        ['pflege', $akkuOhne, 'Akkus ohne Einbaudatum', 'pflege.php?ansicht=akkus'],
        ['pflege', (int)wert("SELECT COUNT(*) FROM meldungen WHERE status = 'offen'"), 'Meldungen der Techniker unbearbeitet', 'pflege.php?ansicht=pruef#meldungen'],
        ['pflege', (int)wert('SELECT COUNT(*) FROM import_hinweise WHERE erledigt = 0'), 'Hinweise aus dem Import', 'pflege.php?ansicht=pruef#import'],
    ];
    if (outlook_aktiv()) $fehlt[] = ['admin', (int)wert("SELECT COUNT(*) FROM mitarbeiter WHERE aktiv = 1 AND (email IS NULL OR email = '')"), 'Mitarbeiter ohne Outlook-Postfach', 'verwaltung.php'];
    $fehlt = array_values(array_filter($fehlt, fn($f) => $f[1] > 0 && darf($f[0])));
    // 3. Was noch planbar ist (aktuelles Quartal)
    $ausl = darf('organisation') ? auslastung($j, $q) : [];
    $offenMin = 0; foreach (wartungen_quartal($j, $q) as $w) if ($w['status'] === 'offen') $offenMin += zeit_gesamt_min($w);
?>
<div class="lage">
  <div class="box"><h2>Gerade – heute, <?= datum($heute, true) ?></h2>
    <?php if (!$proM && !$abwHeute): ?><p class="leer">Heute sind keine Termine geplant.</p><?php endif; ?>
    <?php foreach (mitarbeiter_liste() as $m): $mid = (int)$m['id']; $l = $proM[$mid] ?? []; if (!$l && !isset($abwHeute[$mid])) continue;
        $erl = count(array_filter($l, fn($w) => $w['status'] === 'erledigt')); $jetzt = null; $naechst = null;
        foreach ($l as $w) { $b0 = minuten(substr($w['beginn'], 11, 5)); $e0 = minuten(substr($w['ende'], 11, 5)); if ($b0 <= $jetztMin && $jetztMin < $e0) $jetzt = $w; elseif ($b0 > $jetztMin && !$naechst) $naechst = $w; } ?>
      <div class="lzeile"><b><?= h($m['name']) ?></b>
        <?php if (isset($abwHeute[$mid])): ?><span class="pill warn"><?= h($abwHeute[$mid]) ?></span><?php else: ?>
        <span class="hint"><?= $erl ?>/<?= count($l) ?> erledigt</span>
        <span><?= $jetzt ? 'jetzt: ' . h($jetzt['kunde']) . ' <span class="hint">bis ' . substr($jetzt['ende'], 11, 5) . '</span>' : ($naechst ? 'als Nächstes ' . substr($naechst['beginn'], 11, 5) . ': ' . h($naechst['kunde']) : ($l ? '<span class="hint">Tour beendet</span>' : '')) ?></span><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if (darf('organisation')): ?><a class="hint" href="wochenplan.php?tag=<?= $heute ?>">Tagesansicht mit Karte →</a><?php endif; ?>
  </div>
  <div class="box"><h2>Was noch fehlt</h2>
    <?php if (!$fehlt): ?><p class="msg ok">Alle Angaben vollständig.</p><?php endif; ?>
    <?php foreach ($fehlt as [$r, $n, $t, $u]): ?><a class="lzeile" href="<?= $u ?>" style="text-decoration:none"><b class="lzahl"><?= $n ?></b><span><?= h($t) ?></span></a><?php endforeach; ?>
  </div>
  <?php if (darf('organisation')): ?>
  <div class="box"><h2>Noch planbar – <?= quartal_name($q) ?> <?= $j ?></h2>
    <p class="hint">Freie Zeit ab heute (Wartungsanteil) und was schon zugewiesen ist, aber noch keinen Termin hat. Noch nicht zugewiesen: <b><?= std(round($offenMin / 60, 1)) ?></b></p>
    <?php foreach ($ausl as $mid => $a): $v = max(1, $a['verfuegbar']); ?>
      <div class="lzeile"><b><?= h($a['name']) ?></b><div class="balken" style="flex:1"><i class="b1" style="width:<?= min(100, $a['terminiert'] / $v * 100) ?>%"></i><i class="b2" style="width:<?= max(0, min(100 - $a['terminiert'] / $v * 100, $a['zugewiesen'] / $v * 100)) ?>%"></i></div>
        <span class="hint" style="min-width:150px;text-align:right"><?= $a['zugewiesen'] ? std(round($a['zugewiesen'] / 60, 1)) . ' ohne Termin · ' : '' ?><?= $a['frei'] < 0 ? '<span class="alt">' . std(round($a['frei'] / 60, 1)) . '</span>' : std(round($a['frei'] / 60, 1)) . ' frei' ?></span></div>
    <?php endforeach; ?>
    <div class="legende"><span><i class="b1"></i>terminiert</span><span><i class="b2"></i>zugewiesen, ohne Termin</span><span><i class="b3"></i>frei</span></div>
    <div class="row"><a class="btn ghost klein" href="organisation.php">Zuweisen</a><a class="btn ghost klein" href="touren.php">Touren planen</a><a class="btn ghost klein" href="wochenplan.php?monat=<?= date('Y-m') ?>">Kalender</a></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="box">
  <h2>Ihre Bereiche</h2>
  <div class="row">
  <?php foreach (BEREICH_SEITEN as $rolle => [$url, $name]): if (!darf($rolle)) continue; ?><a class="btn ghost" href="<?= $url ?>"><?= h($name) ?></a><?php endforeach; ?>
  </div>
</div>
<?php seite_fuss();
