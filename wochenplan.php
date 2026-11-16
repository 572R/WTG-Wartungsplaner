<?php
/** Bereich "Organisation": Wochenplan (Techniker × Tage) und Tagesansicht mit Karte aller Touren */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('organisation');

$farben = ['#b8261b', '#2b5f8a', '#2f7a4b', '#9a6400', '#6b3fa0', '#0f766e', '#a21caf', '#475569'];
$mitarb = mitarbeiter_liste();
$farbe = []; foreach ($mitarb as $i => $m) $farbe[(int)$m['id']] = $farben[$i % 8];
$abwesend = function (string $von, string $bis): array {
    $o = [];
    foreach (alle('SELECT * FROM abwesenheiten WHERE bis >= ? AND von <= ?', [$von, $bis]) as $a)
        for ($d = new DateTimeImmutable(max($a['von'], $von)); $d->format('Y-m-d') <= min($a['bis'], $bis); $d = $d->modify('+1 day')) $o[(int)$a['mitarbeiter_id']][$d->format('Y-m-d')] = $a['art'];
    return $o;
};

/** Umschalter Monat / Woche / Tag / Liste und Sprung zu einem genauen Datum */
function kal_nav(string $on, string $datum): void {
    $mo = (new DateTimeImmutable($datum))->modify('monday this week')->format('Y-m-d'); ?>
    <div class="row no-print" style="justify-content:space-between;background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:8px 10px">
      <div class="row">
        <a class="btn <?= $on === 'monat' ? '' : 'ghost' ?> klein" href="wochenplan.php?monat=<?= substr($datum, 0, 7) ?>">Monat</a>
        <a class="btn <?= $on === 'woche' ? '' : 'ghost' ?> klein" href="wochenplan.php?woche=<?= $mo ?>">Woche</a>
        <a class="btn <?= $on === 'tag' ? '' : 'ghost' ?> klein" href="wochenplan.php?tag=<?= $datum ?>">Tag</a>
        <a class="btn <?= $on === 'liste' ? '' : 'ghost' ?> klein" href="wochenplan.php?<?= http_build_query(['liste' => 1, 'von' => $datum, 'bis' => (new DateTimeImmutable($datum))->modify('+13 days')->format('Y-m-d')]) ?>">Liste nach Datum</a>
      </div>
      <form method="get" class="row"><label class="hint" for="springe">Genaues Datum</label><input id="springe" type="date" name="tag" value="<?= h($datum) ?>" style="width:160px" required><button class="btn ghost klein">Zeigen</button></form>
    </div>
<?php }
$termineZeile = fn(array $w) => ticket_marke($w) . h($w['kunde']);

/* ====================================================================
   Monatsansicht: jeder Tag mit Terminen je Mitarbeiter, Abwesenheiten, Feiertagen und fälligen Tickets
   ==================================================================== */
if (!empty($_GET['monat'])) {
    $m1 = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}$/', (string)$_GET['monat']) ? $_GET['monat'] . '-01' : date('Y-m-01'));
    $mEnde = $m1->modify('last day of this month');
    $start = $m1->modify('monday this week'); $ende = $mEnde->modify('sunday this week');
    $nurMid = (int)($_GET['mid'] ?? 0);
    $ws = alle(W_SELECT . " WHERE w.beginn >= ? AND w.beginn <= ? AND w.status IN ('terminiert','erledigt')" . ($nurMid ? ' AND w.techniker_id = ' . $nurMid : '') . ' ORDER BY w.beginn',
        [$start->format('Y-m-d') . ' 00:00:00', $ende->format('Y-m-d') . ' 23:59:59']);
    $proTag = []; foreach ($ws as $w) $proTag[substr($w['beginn'], 0, 10)][(int)$w['techniker_id']][] = $w;
    $faellig = []; foreach (alle(W_SELECT . " WHERE w.typ = 'ticket' AND w.status IN ('offen','zugewiesen') AND w.faellig_bis >= ? AND w.faellig_bis <= ?", [$start->format('Y-m-d'), $ende->format('Y-m-d')]) as $t) $faellig[$t['faellig_bis']][] = $t;
    $abw = $abwesend($start->format('Y-m-d'), $ende->format('Y-m-d'));
    $mitNamen = mitarbeiter_namen();
    $wochenende = (bool)array_filter(array_keys($proTag), fn($d) => (int)(new DateTimeImmutable($d))->format('N') >= 6);
    $spalten = $wochenende ? 7 : 5; $heute = date('Y-m-d');
    seite_kopf('Kalender ' . MONATE[(int)$m1->format('n') - 1] . ' ' . $m1->format('Y'), 'organisation');
    kal_nav('monat', $m1->format('Y-m') === date('Y-m') ? $heute : $m1->format('Y-m-d')); ?>
    <div class="row" style="justify-content:space-between">
      <div class="row"><a class="btn ghost klein no-print" href="wochenplan.php?<?= http_build_query(['monat' => $m1->modify('-1 month')->format('Y-m'), 'mid' => $nurMid ?: null]) ?>">←</a>
        <h1><?= MONATE[(int)$m1->format('n') - 1] ?> <?= $m1->format('Y') ?></h1>
        <a class="btn ghost klein no-print" href="wochenplan.php?<?= http_build_query(['monat' => $m1->modify('+1 month')->format('Y-m'), 'mid' => $nurMid ?: null]) ?>">→</a></div>
      <form method="get" class="row no-print"><input type="hidden" name="monat" value="<?= $m1->format('Y-m') ?>">
        <select style="width:auto" name="mid" onchange="this.form.submit()"><option value="">alle Mitarbeiter</option><?php foreach ($mitarb as $m): ?><option value="<?= $m['id'] ?>"<?= $nurMid === (int)$m['id'] ? ' selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select>
        <a class="btn ghost klein" href="javascript:print()">Drucken / als PDF</a></form>
    </div>
    <div class="box" style="padding:10px">
      <div class="monat" style="grid-template-columns:repeat(<?= $spalten ?>,minmax(0,1fr))">
        <?php for ($i = 1; $i <= $spalten; $i++): ?><div class="mkopf"><?= WT[$i] ?></div><?php endfor; ?>
        <?php for ($d = $start; $d <= $ende; $d = $d->modify('+1 day')): $n = (int)$d->format('N'); if ($n > $spalten) continue;
            $iso = $d->format('Y-m-d'); $ft = feiertage((int)$d->format('Y'))[$iso] ?? null; $imMonat = $d->format('m') === $m1->format('m'); ?>
          <a class="mtag<?= $imMonat ? '' : ' fremd' ?><?= $iso === $heute ? ' heute' : '' ?><?= $ft ? ' frei' : '' ?>" href="wochenplan.php?tag=<?= $iso ?>">
            <span class="mzahl"><?= $d->format('j') ?><?= $d->format('j') === '1' ? '. ' . mb_substr(MONATE[(int)$d->format('n') - 1], 0, 3) : '' ?></span>
            <?php if ($ft): ?><span class="pill neutral"><?= h($ft) ?></span><?php endif; ?>
            <?php foreach ($proTag[$iso] ?? [] as $mid => $liste): ?>
              <?php if ($nurMid): foreach ($liste as $w): ?><span class="mzeile"><span class="mono"><?= substr($w['beginn'], 11, 5) ?></span> <?= $w['status'] === 'erledigt' ? '✓ ' : '' ?><?= ist_ticket($w) ? '<b>T</b> ' : '' ?><?= h(mb_strimwidth($w['kunde'], 0, 22, '…')) ?></span><?php endforeach;
                    else: $t = count(array_filter($liste, 'ist_ticket')); ?>
                <span class="mzeile"><i style="background:<?= $farbe[$mid] ?? '#475569' ?>"></i><?= h($mitNamen[$mid] ?? '?') ?> <b><?= count($liste) ?></b><?= $t ? ' <span class="hint">(' . $t . ' T)</span>' : '' ?> <span class="hint"><?= h(mb_strimwidth(implode(', ', array_unique(array_column($liste, 'ort'))), 0, 28, '…')) ?></span></span>
              <?php endif; endforeach; ?>
            <?php foreach ($abw as $mid => $tage): if (!isset($tage[$iso]) || ($nurMid && $mid !== $nurMid)) continue; ?><span class="mzeile abw"><?= h($mitNamen[$mid] ?? '') ?>: <?= h($tage[$iso]) ?></span><?php endforeach; ?>
            <?php foreach ($faellig[$iso] ?? [] as $t): ?><span class="mzeile frist">⚑ fällig: <?= h(mb_strimwidth($t['titel'] . ' – ' . $t['kunde'], 0, 34, '…')) ?></span><?php endforeach; ?>
          </a>
        <?php endfor; ?>
      </div>
      <p class="hint">Zahl = Termine an diesem Tag (T = davon Tickets). Gelb = abwesend, rot ⚑ = Ticket mit Frist an diesem Tag, noch nicht terminiert. Mit Mitarbeiter-Auswahl stehen die genauen Uhrzeiten im Kalender. Klick auf einen Tag öffnet die Tagesansicht mit Karte.</p>
    </div>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Liste nach Datum: alle Termine von–bis, genau sortiert
   ==================================================================== */
if (!empty($_GET['liste'])) {
    $von = (string)($_GET['von'] ?? date('Y-m-d')); $bis = (string)($_GET['bis'] ?? $von);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) || $bis < $von) $bis = $von;
    $nurMid = (int)($_GET['mid'] ?? 0); $typ = in_array($_GET['typ'] ?? '', ['wartung', 'ticket'], true) ? $_GET['typ'] : '';
    $ws = alle(W_SELECT . " WHERE w.beginn >= ? AND w.beginn <= ? AND w.status IN ('terminiert','erledigt')" . ($nurMid ? ' AND w.techniker_id = ' . $nurMid : '') . ($typ ? " AND w.typ = '$typ'" : '') . ' ORDER BY w.beginn, w.techniker_id', ["$von 00:00:00", "$bis 23:59:59"]);
    $offeneT = $typ === 'wartung' ? [] : alle(W_SELECT . " WHERE w.typ = 'ticket' AND w.status IN ('offen','zugewiesen') AND w.faellig_bis IS NOT NULL AND w.faellig_bis <= ?" . ($nurMid ? ' AND w.techniker_id = ' . $nurMid : '') . ' ORDER BY w.faellig_bis', [$bis]);
    $mitNamen = mitarbeiter_namen();
    seite_kopf('Termine nach Datum', 'organisation');
    kal_nav('liste', $von); ?>
    <form method="get" class="box no-print"><input type="hidden" name="liste" value="1">
      <div class="grid4"><label class="f">Von<input type="date" name="von" value="<?= h($von) ?>"></label><label class="f">Bis<input type="date" name="bis" value="<?= h($bis) ?>"></label>
        <label class="f">Mitarbeiter<select name="mid"><option value="">alle</option><?php foreach ($mitarb as $m): ?><option value="<?= $m['id'] ?>"<?= $nurMid === (int)$m['id'] ? ' selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select></label>
        <label class="f">Art<select name="typ"><option value="">Wartungen + Tickets</option><option value="wartung"<?= $typ === 'wartung' ? ' selected' : '' ?>>nur Wartungen</option><option value="ticket"<?= $typ === 'ticket' ? ' selected' : '' ?>>nur Tickets</option></select></label></div>
      <div class="row"><button class="btn klein">Anzeigen</button><a class="btn ghost klein" href="javascript:print()">Drucken / als PDF</a></div></form>
    <div class="box"><h2>Termine <?= datum($von, true) ?> – <?= datum($bis, true) ?><?= $nurMid ? ' · ' . h($mitNamen[$nurMid] ?? '') : '' ?> (<?= count($ws) ?>)</h2>
      <div class="scroll"><table class="tbl"><tr><th>Datum</th><th>Zeit</th><th>Mitarbeiter</th><th>Kunde</th><th>Ort</th><th>Anlage</th><th>Status</th></tr>
      <?php $vorher = ''; foreach ($ws as $w): $d = substr($w['beginn'], 0, 10); ?>
        <tr><td><?= $d !== $vorher ? '<b><a href="wochenplan.php?tag=' . $d . '">' . datum($d, true) . '</a></b>' : '' ?></td><td class="mono"><?= substr($w['beginn'], 11, 5) ?>–<?= substr($w['ende'], 11, 5) ?></td>
          <td><i style="display:inline-block;width:9px;height:9px;border-radius:2px;background:<?= $farbe[(int)$w['techniker_id']] ?? '#475569' ?>"></i> <?= h($mitNamen[(int)$w['techniker_id']] ?? '') ?></td>
          <td><?= $termineZeile($w) ?></td><td><span class="mono"><?= h($w['plz']) ?></span> <?= h($w['ort']) ?></td><td><span class="tag"><?= h($w['art']) ?></span></td><td><?= status_pill($w['status']) ?></td></tr>
      <?php $vorher = $d; endforeach; ?>
      <?php if (!$ws): ?><tr><td colspan="7" class="leer">Keine Termine in diesem Zeitraum.</td></tr><?php endif; ?>
      </table></div></div>
    <?php if ($offeneT): ?><div class="box"><h2>Tickets mit Frist bis <?= datum($bis) ?>, noch ohne Termin (<?= count($offeneT) ?>)</h2>
      <div class="scroll"><table class="tbl"><tr><th>Fällig</th><th>Ticket</th><th>Kunde</th><th>Mitarbeiter</th><th>Status</th></tr>
      <?php foreach ($offeneT as $t): ?><tr><td class="<?= $t['faellig_bis'] < date('Y-m-d') ? 'alt' : '' ?>"><?= datum($t['faellig_bis'], true) ?></td><td><b><?= h($t['titel']) ?></b><?= $t['prio'] === 'dringend' ? ' <span class="pill err">dringend</span>' : '' ?></td><td><?= h($t['kunde']) ?>, <?= h($t['ort']) ?></td><td><?= h($mitNamen[(int)$t['techniker_id']] ?? '–') ?></td><td><?= status_pill($t['status']) ?></td></tr><?php endforeach; ?>
      </table></div></div><?php endif; ?>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Tagesansicht
   ==================================================================== */
if (!empty($_GET['tag'])) {
    $tag = (new DateTimeImmutable((string)$_GET['tag']))->format('Y-m-d');
    $ws = alle(W_SELECT . " WHERE w.beginn >= ? AND w.beginn <= ? AND w.status IN ('terminiert','erledigt') ORDER BY w.techniker_id, w.beginn", ["$tag 00:00:00", "$tag 23:59:59"]);
    $abw = $abwesend($tag, $tag);
    $proTech = []; foreach ($ws as $w) $proTech[(int)$w['techniker_id']][] = $w;
    $firma = firma_koord(); $punkte = []; $linien = [];
    foreach ($proTech as $mid => $liste) {
        $l = $firma ? [[$firma['lat'], $firma['lon']]] : [];
        foreach ($liste as $i => $w) if ($w['lat'] !== null) { $punkte[] = ['lat' => (float)$w['lat'], 'lon' => (float)$w['lon'], 'nr' => $i + 1, 'farbe' => $farbe[$mid] ?? '#475569', 'label' => (mitarbeiter_namen()[$mid] ?? '') . ' · ' . substr($w['beginn'], 11, 5) . ' ' . $w['kunde']]; $l[] = [(float)$w['lat'], (float)$w['lon']]; }
        if ($firma) $l[] = [$firma['lat'], $firma['lon']];
        $linien[] = $l;
    }
    $mo = (new DateTimeImmutable($tag))->modify('monday this week')->format('Y-m-d');
    seite_kopf('Tagesansicht', 'organisation', true);
    kal_nav('tag', $tag);
    ?>
    <div class="row" style="justify-content:space-between">
      <div class="row"><a class="btn ghost klein" href="wochenplan.php?tag=<?= (new DateTimeImmutable($tag))->modify('-1 day')->format('Y-m-d') ?>">←</a>
        <h1><?= datum($tag, true) ?><?= isset(feiertage((int)substr($tag, 0, 4))[$tag]) ? ' · ' . h(feiertage((int)substr($tag, 0, 4))[$tag]) : '' ?></h1>
        <a class="btn ghost klein" href="wochenplan.php?tag=<?= (new DateTimeImmutable($tag))->modify('+1 day')->format('Y-m-d') ?>">→</a></div>
      <a class="btn ghost" href="wochenplan.php?woche=<?= $mo ?>">Zur Woche</a>
    </div>
    <?php karte('tag-alle', $punkte, $linien, true); ?>
    <div class="legende"><?php foreach ($mitarb as $m): ?><span><i style="background:<?= $farbe[(int)$m['id']] ?>"></i><?= h($m['name']) ?></span><?php endforeach; ?></div>
    <div class="cols2">
    <?php foreach ($mitarb as $m): $mid = (int)$m['id']; $liste = $proTech[$mid] ?? []; ?>
      <div class="box"><h2 style="color:<?= $farbe[$mid] ?>"><?= h($m['name']) ?></h2>
        <?php if (isset($abw[$mid][$tag])): ?><p class="pill warn"><?= h($abw[$mid][$tag]) ?></p><?php endif; ?>
        <?php if (!$liste): ?><p class="leer">Keine Wartungen.</p><?php endif; ?>
        <?php foreach ($liste as $i => $w): ?>
          <div class="stopp" style="grid-template-columns:auto 96px minmax(0,1fr) auto"><span class="nr" style="background:<?= $farbe[$mid] ?>"><?= $i + 1 ?></span>
            <span class="mono"><?= substr($w['beginn'], 11, 5) ?>–<?= substr($w['ende'], 11, 5) ?></span>
            <span><span class="tag"><?= h($w['art']) ?></span><?= ticket_marke($w) ?><?= darf('pflege') ? '<a href="pflege.php?k=' . $w['kunde_id'] . '">' . h($w['kunde']) . '</a>' : h($w['kunde']) ?> <span class="hint"><?= h($w['ort']) ?></span></span>
            <span><?= $w['status'] === 'erledigt' ? '<span class="pill ok">erledigt</span>' : '' ?></span></div>
        <?php endforeach; ?>
        <?= $liste ? bedarf_zeile(array_column($liste, 'anlage_id'), $tag) : '' ?>
        <?php if ($liste): ?><a class="hint" href="termine.php?<?= http_build_query(['mid' => $mid, 'tag' => $tag]) ?>">Termine im Detail / abschließen →</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?php seite_fuss(); exit;
}

/* ====================================================================
   Wochenansicht
   ==================================================================== */
$mo = (new DateTimeImmutable((string)($_GET['woche'] ?? 'today')))->modify('monday this week');
$so = $mo->modify('+6 days');
$ws = alle(W_SELECT . " WHERE w.beginn >= ? AND w.beginn <= ? AND w.status IN ('terminiert','erledigt') ORDER BY w.beginn", [$mo->format('Y-m-d') . ' 00:00:00', $so->format('Y-m-d') . ' 23:59:59']);
$abw = $abwesend($mo->format('Y-m-d'), $so->format('Y-m-d'));
$zelle = []; foreach ($ws as $w) $zelle[(int)$w['techniker_id']][substr($w['beginn'], 0, 10)][] = $w;
$tage = []; for ($i = 0; $i < 7; $i++) { $d = $mo->modify("+$i days"); $iso = $d->format('Y-m-d'); if ($i < 5 || array_filter($ws, fn($w) => str_starts_with($w['beginn'], $iso))) $tage[] = $d; }
$heute = date('Y-m-d');
seite_kopf('Wochenplan', 'organisation');
kal_nav('woche', $mo->format('Y-m-d') <= $heute && $heute <= $so->format('Y-m-d') ? $heute : $mo->format('Y-m-d'));
?>
<div class="row" style="justify-content:space-between">
  <div class="row">
    <a class="btn ghost klein" href="wochenplan.php?woche=<?= $mo->modify('-7 days')->format('Y-m-d') ?>">← Vorwoche</a>
    <h1>KW <?= $mo->format('W') ?> · <?= $mo->format('d.m.') ?>–<?= $so->format('d.m.Y') ?></h1>
    <a class="btn ghost klein" href="wochenplan.php?woche=<?= $mo->modify('+7 days')->format('Y-m-d') ?>">Nächste Woche →</a>
    <a class="btn ghost klein" href="wochenplan.php">Heute</a>
    <form method="get" class="row"><input type="date" name="woche" value="<?= $mo->format('Y-m-d') ?>" style="width:150px" onchange="this.form.submit()"><noscript><button class="btn ghost klein">Zeigen</button></noscript></form>
  </div>
  <div class="row"><a class="btn ghost" href="organisation.php">1. Zuweisen</a><a class="btn ghost" href="touren.php">2. Touren planen</a><a class="btn" href="wochenplan.php">Wochenplan</a><?php if (outlook_aktiv()): ?><a class="btn ghost" href="abgleich.php">Outlook-Abgleich</a><?php endif; ?></div>
</div>
<div class="box">
  <div class="scroll"><table class="tbl" style="table-layout:fixed;min-width:<?= 160 + 190 * count($tage) ?>px">
    <tr><th style="width:140px">Mitarbeiter</th>
      <?php foreach ($tage as $d): $iso = $d->format('Y-m-d'); $ft = feiertage((int)$d->format('Y'))[$iso] ?? null; ?>
        <th style="<?= $iso === $heute ? 'color:var(--accent)' : '' ?>"><a href="wochenplan.php?tag=<?= $iso ?>"><?= WT[(int)$d->format('N')] ?> <?= $d->format('d.m.') ?></a><?= $ft ? '<br><span class="pill neutral">' . h($ft) . '</span>' : '' ?></th>
      <?php endforeach; ?></tr>
    <?php foreach ($mitarb as $m): $mid = (int)$m['id']; ?>
      <tr><td><b><?= h($m['name']) ?></b><br><span class="hint"><?= count(array_merge(...array_values($zelle[$mid] ?? [[]]))) ?> Termine</span></td>
      <?php foreach ($tage as $d): $iso = $d->format('Y-m-d'); $liste = $zelle[$mid][$iso] ?? []; $ab = $abw[$mid][$iso] ?? null;
          $min = array_sum(array_map(fn($w) => (strtotime($w['ende']) - strtotime($w['beginn'])) / 60 + (int)$w['fahrt_min'], $liste)); ?>
        <td style="<?= $ab ? 'background:var(--warn-soft)' : '' ?>">
          <?php if ($ab): ?><span class="pill warn"><?= h($ab) ?></span><?php endif; ?>
          <?php foreach ($liste as $w): ?>
            <div style="font-size:13px;line-height:1.35;margin-bottom:4px"><span class="mono"><?= substr($w['beginn'], 11, 5) ?></span> <?= $w['status'] === 'erledigt' ? '✓ ' : '' ?><?= ist_ticket($w) ? '<span class="pill info">T</span> ' : '' ?><?= h(mb_strimwidth($w['kunde'], 0, 26, '…')) ?><br><span class="hint"><?= h($w['ort']) ?></span></div>
          <?php endforeach; ?>
          <?php if ($liste): ?><a class="hint" href="wochenplan.php?tag=<?= $iso ?>"><?= std(round($min / 60, 1)) ?> inkl. Fahrt · Karte</a><?php endif; ?>
        </td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="hint">Gelb = abwesend laut Abwesenheiten. ✓ = erledigt. Klick auf einen Tag zeigt alle Touren dieses Tages auf der Karte.</p>
</div>
<?php seite_fuss();
