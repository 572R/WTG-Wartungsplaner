<?php
/**
 * Bereich "Organisation", Stufe 2: zugewiesene Wartungen zu Tagestouren zusammenstellen (nach Nähe) und eintragen
 */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('organisation');
@set_time_limit(300);
[$jA, $qA] = aktuelles_quartal();
$jahr = (int)($_REQUEST['j'] ?? $jA); $q = max(1, min(4, (int)($_REQUEST['q'] ?? $qA)));
$mit = mitarbeiter_namen();
$mid = (int)($_REQUEST['mid'] ?? array_key_first($mit));
$basis = 'touren.php?' . http_build_query(['j' => $jahr, 'q' => $q, 'mid' => $mid]);
$sk = "$mid|$jahr|$q";

if (post()) {
    switch ($_POST['aktion'] ?? '') {
        case 'planen':
            $wt = array_values(array_intersect([1, 2, 3, 4, 5, 6], array_map('intval', (array)($_POST['wochentage'] ?? []))));
            $opt = ['von' => $_POST['von'] ?: null, 'bis' => $_POST['bis'] ?: null, 'wochentage' => $wt ?: [1, 2, 3, 4, 5],
                    'ids' => isset($_POST['auswahl_aktiv']) ? (array)($_POST['ids'] ?? []) : null];
            if ($opt['ids'] === []) weiter($basis, 'Bitte mindestens eine Wartung auswählen.', 'warn');
            $p = touren_planen($mid, $jahr, $q, $opt);
            $_SESSION['touren_opt'][$sk] = $opt;
            $_SESSION['touren'][$sk] = ['quelle' => $p['quelle'], 'fehler' => $p['fehler'], 'zeit' => time(),
                'tage' => array_map(fn($t) => ['datum' => $t['datum'], 'start' => $t['start'], 'ende' => $t['ende'], 'km' => $t['km'], 'rueck' => $t['rueck_min'],
                    'stopps' => array_map(fn($s) => ['id' => (int)$s['w']['id'], 'an' => $s['an'], 'ab' => $s['ab'], 'fahrt' => $s['fahrt'], 'km' => $s['km']], $t['stopps'])], $p['tage']),
                'nicht' => array_map(fn($n) => ['id' => (int)$n[0]['id'], 'grund' => $n[1]], $p['nicht'])];
            weiter($basis . '#vorschlag');
        case 'verwerfen':
            unset($_SESSION['touren'][$sk]); weiter($basis);
        case 'uebernehmen':
            $v = $_SESSION['touren'][$sk] ?? null; if (!$v) weiter($basis, 'Vorschlag abgelaufen – bitte neu erstellen.', 'warn');
            $sel = array_flip((array)($_POST['sel'] ?? [])); $ok = 0; $ol = 0; $prob = [];
            foreach ($v['tage'] as $t) foreach ($t['stopps'] as $i => $s) {
                if (!isset($sel[$s['id']])) continue;
                $w = eine("SELECT * FROM wartungen WHERE id = ? AND status = 'zugewiesen' AND techniker_id = ?", [$s['id'], $mid]);
                if (!$w) continue;
                $upd = ['beginn' => $t['datum'] . ' ' . hm($s['an']) . ':00', 'ende' => $t['datum'] . ' ' . hm($s['ab']) . ':00', 'tour_pos' => $i + 1, 'fahrt_min' => $s['fahrt'], 'status' => 'terminiert'];
                aendern('wartungen', (int)$w['id'], $upd); $ok++;
                if (outlook_aktiv()) {
                    try { $oid = outlook_eintragen(array_merge($w, $upd)); aendern('wartungen', (int)$w['id'], ['outlook_id' => $oid, 'outlook_fehler' => null]); $ol++; }
                    catch (Throwable $e) { aendern('wartungen', (int)$w['id'], ['outlook_fehler' => $e->getMessage()]); $prob[] = h($e->getMessage()); }
                }
            }
            unset($_SESSION['touren'][$sk], $_SESSION['outlook_cache']);
            weiter($basis . '#geplant', "$ok Termine eingetragen" . (outlook_aktiv() ? ", davon $ol in Outlook." : '.') . ($prob ? '<ul><li>' . implode('</li><li>', array_slice(array_unique($prob), 0, 5)) . '</li></ul>' : ''), $prob ? 'warn' : 'ok');
        case 'entfernen':
            $ids = array_map('intval', (array)($_POST['ids'] ?? [])); $n = 0;
            foreach ($ids as $id) {
                $w = eine("SELECT * FROM wartungen WHERE id = ? AND status = 'terminiert'", [$id]); if (!$w) continue;
                if ($w['outlook_id'] && outlook_aktiv()) { try { outlook_loeschen((int)$w['techniker_id'], $w['outlook_id']); } catch (Throwable $e) { weiter($basis, 'Outlook: ' . h($e->getMessage()), 'err'); } }
                aendern('wartungen', $id, ['beginn' => null, 'ende' => null, 'tour_pos' => null, 'fahrt_min' => null, 'outlook_id' => null, 'outlook_fehler' => null, 'status' => 'zugewiesen']); $n++;
            }
            unset($_SESSION['outlook_cache']);
            weiter($basis . '#geplant', "$n Termine entfernt" . (outlook_aktiv() ? ' (auch aus Outlook)' : '') . '. Sie sind wieder „zugewiesen".');
        case 'outlook_nachtragen':
            $n = 0; $prob = [];
            foreach (alle("SELECT * FROM wartungen WHERE techniker_id = ? AND status = 'terminiert' AND outlook_id IS NULL AND jahr = ?", [$mid, $jahr]) as $w) {
                try { aendern('wartungen', (int)$w['id'], ['outlook_id' => outlook_eintragen($w), 'outlook_fehler' => null]); $n++; }
                catch (Throwable $e) { aendern('wartungen', (int)$w['id'], ['outlook_fehler' => $e->getMessage()]); $prob[] = $e->getMessage(); }
            }
            weiter($basis . '#geplant', "$n Termine in Outlook nachgetragen." . ($prob ? ' Fehler: ' . h($prob[0]) : ''), $prob ? 'warn' : 'ok');
    }
}

$ws = alle(W_SELECT . ' WHERE w.techniker_id = ? AND w.jahr = ? AND (w.quartal = ? OR (w.beginn >= ? AND w.beginn <= ?) OR (w.quartal < ? AND w.status = \'zugewiesen\')) ORDER BY w.beginn, k.plz', array_merge([$mid, $jahr, $q], array_map(fn($d) => $d, [quartal_von_bis($jahr, $q)[0] . ' 00:00:00', quartal_von_bis($jahr, $q)[1] . ' 23:59:59']), [$q]));
$nachId = []; foreach ($ws as $w) $nachId[(int)$w['id']] = $w;
$zugewiesen = array_filter($ws, fn($w) => $w['status'] === 'zugewiesen');
$geplant = array_filter($ws, fn($w) => in_array($w['status'], ['terminiert', 'erledigt'], true));
$v = $_SESSION['touren'][$sk] ?? null;
$farben = ['#b8261b', '#2b5f8a', '#2f7a4b', '#9a6400', '#6b3fa0', '#0f766e', '#a21caf', '#475569'];
$firma = firma_koord();
$ohneOutlook = array_filter($geplant, fn($w) => $w['status'] === 'terminiert' && !$w['outlook_id']);

seite_kopf('Touren planen', 'organisation', true);
?>
<div class="row" style="justify-content:space-between">
  <form method="get" class="row">
    <select name="j" onchange="this.form.submit()"><?php for ($y = $jA - 1; $y <= $jA + 2; $y++): ?><option<?= $y === $jahr ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
    <select name="q" onchange="this.form.submit()"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"<?= $i === $q ? ' selected' : '' ?>><?= quartal_name($i) ?></option><?php endfor; ?></select>
    <select name="mid" onchange="this.form.submit()"><?php foreach ($mit as $id => $n): ?><option value="<?= $id ?>"<?= $id === $mid ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn ghost">Anzeigen</button></noscript>
  </form>
  <div class="row"><a class="btn ghost" href="organisation.php?<?= http_build_query(['j' => $jahr, 'q' => $q]) ?>">1. Zuweisen</a><a class="btn" href="<?= h($basis) ?>">2. Touren planen</a><a class="btn ghost" href="wochenplan.php">Wochenplan</a><?php if (outlook_aktiv()): ?><a class="btn ghost" href="abgleich.php">Outlook-Abgleich</a><?php endif; ?></div>
</div>

<div class="kpis">
  <div class="kpi"><b><?= count($zugewiesen) ?></b><span>zugewiesen, noch ohne Termin</span></div>
  <div class="kpi"><b><?= count($geplant) ?></b><span>terminiert / erledigt</span></div>
  <div class="kpi"><b><?= std(array_sum(array_map('zeit_gesamt_min', $zugewiesen)) / 60) ?></b><span>offene Zeit laut Liste</span></div>
</div>

<?php if (!$v): ?>
<form class="box" method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="planen"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><input type="hidden" name="mid" value="<?= $mid ?>">
  <h2>Touren für <?= h($mit[$mid] ?? '') ?> vorschlagen</h2>
  <p class="hint">Jede Tour startet an der Firma (<?= h(cfg('firma')['strasse'] . ', ' . cfg('firma')['ort']) ?>), fährt zuerst zum entferntesten Kunden, der noch passt, und dann jeweils zum <b>nächstgelegenen</b>. Gruppen bleiben zusammen.
    Berücksichtigt werden Arbeitszeiten, Feiertage, Abwesenheiten<?= outlook_aktiv() ? ', Outlook-Termine' : '' ?> und höchstens <?= (int)(cfg('max_anteil_wartung') * 100) ?> % Wartung pro Woche. Fahrzeiten von OpenStreetMap.</p>
  <?php $o = $_SESSION['touren_opt'][$sk] ?? []; [$qv, $qb] = quartal_von_bis($jahr, $q); $morgen = (new DateTimeImmutable('tomorrow'))->format('Y-m-d'); ?>
  <div class="grid3">
    <label class="f">Von<input type="date" name="von" value="<?= h($o['von'] ?? max($qv, $morgen)) ?>"></label>
    <label class="f">Bis<input type="date" name="bis" value="<?= h($o['bis'] ?? $qb) ?>"></label>
    <div class="f"><span class="hint">Nur an diesen Wochentagen</span><div class="row"><?php foreach ([1, 2, 3, 4, 5, 6] as $d): ?><label class="hint"><input type="checkbox" name="wochentage[]" value="<?= $d ?>"<?= in_array($d, $o['wochentage'] ?? [1, 2, 3, 4, 5], true) ? ' checked' : '' ?>> <?= WT[$d] ?></label><?php endforeach; ?></div></div>
  </div>
  <details><summary class="btn ghost klein">Nur bestimmte Wartungen einplanen …</summary>
    <label class="hint" style="display:block;margin:8px 0"><input type="checkbox" name="auswahl_aktiv" value="1" id="auswahl_aktiv"> Nur die angehakten Wartungen einplanen (sonst alle <?= count($zugewiesen) ?>)</label>
    <div class="scroll" style="max-height:320px;overflow-y:auto"><table class="tbl"><tr><th><input type="checkbox" id="alle_w" aria-label="alle"></th><th>Kunde</th><th>Ort</th><th>Anlage</th><th>Periode</th><th class="r">Zeit</th></tr>
      <?php foreach ($zugewiesen as $w): ?><tr><td><input type="checkbox" name="ids[]" value="<?= $w['id'] ?>" class="wsel" checked></td><td><?= ticket_marke($w) ?><?= h($w['kunde']) ?></td><td><span class="mono"><?= h($w['plz']) ?></span> <?= h($w['ort']) ?></td><td><span class="tag"><?= h($w['art']) ?></span></td><td><?= h($w['periode']) ?><?= $w['gruppe'] ? ' · Gruppe' : '' ?></td><td class="r"><?= zahl(zeit_gesamt_min($w) / 60, 2) ?></td></tr><?php endforeach; ?>
    </table></div>
    <p class="hint">Gruppen werden immer vollständig geplant, wenn ein Mitglied angehakt ist.</p>
  </details>
  <script>
    document.getElementById('alle_w')?.addEventListener('change', e => document.querySelectorAll('.wsel').forEach(x => x.checked = e.target.checked));
    document.querySelectorAll('.wsel').forEach(x => x.addEventListener('change', () => document.getElementById('auswahl_aktiv').checked = true));
  </script>
  <button class="btn" <?= $zugewiesen ? '' : 'disabled' ?>>Touren vorschlagen</button>
  <?php if (!$zugewiesen): ?><p class="hint">Zuerst unter „1. Zuweisen" Wartungen an <?= h($mit[$mid] ?? '') ?> geben.</p><?php endif; ?>
</form>
<?php else:
    $punkte = []; $linien = [];
    foreach ($v['tage'] as $ti => $t) {
        $l = $firma ? [[$firma['lat'], $firma['lon']]] : [];
        foreach ($t['stopps'] as $si => $s) { $w = $nachId[$s['id']] ?? null; if (!$w) continue;
            $punkte[] = ['lat' => (float)$w['lat'], 'lon' => (float)$w['lon'], 'nr' => $ti + 1, 'farbe' => $farben[$ti % 8], 'label' => datum($t['datum'], true) . ' · ' . ($si + 1) . '. ' . $w['kunde']];
            $l[] = [(float)$w['lat'], (float)$w['lon']]; }
        if ($firma) $l[] = [$firma['lat'], $firma['lon']];
        $linien[] = $l;
    } ?>
<form class="box" method="post" id="vorschlag"><?= csrf_feld() ?><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><input type="hidden" name="mid" value="<?= $mid ?>">
  <?php $o = $_SESSION['touren_opt'][$sk] ?? []; ?>
  <div class="row" style="justify-content:space-between"><h2>Vorschlag: <?= count($v['tage']) ?> Tage für <?= h($mit[$mid]) ?></h2>
    <?php if ($o): ?><span class="hint">Zeitraum <?= datum($o['von'] ?? quartal_von_bis($jahr, $q)[0]) ?>–<?= datum($o['bis'] ?? quartal_von_bis($jahr, $q)[1]) ?> · <?= h(implode(', ', array_map(fn($d) => WT[$d], $o['wochentage']))) ?><?= $o['ids'] !== null ? ' · ' . count($o['ids']) . ' ausgewählte Wartungen' : '' ?></span><?php endif; ?>
    <span class="hint">Fahrzeiten: <?= ['osrm' => 'OpenStreetMap-Routing', 'gemischt' => 'teils Routing, teils geschätzt', 'schaetzung' => 'geschätzt (Luftlinie × ' . zahl(cfg('osm')['umwegfaktor']) . ') – Routing nicht erreichbar'][$v['quelle']] ?? $v['quelle'] ?></span></div>
  <?php if ($v['fehler']): ?><div class="msg err">Outlook: <?= h(implode(' · ', array_unique($v['fehler']))) ?></div><?php endif; ?>
  <?php if ($v['nicht']): ?><details class="msg warn"><summary><?= count($v['nicht']) ?> Wartung(en) nicht untergebracht – anzeigen</summary><ul>
    <?php foreach ($v['nicht'] as $n): $w = $nachId[$n['id']] ?? null; if (!$w) continue; ?><li><?= ticket_marke($w) ?><?= h($w['kunde']) ?> (<?= h($w['art']) ?>, <?= h($w['ort']) ?>): <?= h($n['grund']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <?php karte('karte-vorschlag', $punkte, $linien, true); ?>
  <p class="hint">Haken entfernen, was nicht übernommen werden soll. Die Uhrzeiten der übrigen Termine bleiben wie vorgeschlagen.</p>
  <?php foreach ($v['tage'] as $ti => $t): ?>
    <div class="tag-tour">
      <div class="row" style="justify-content:space-between">
        <label class="row"><input type="checkbox" checked data-tag="<?= $ti ?>"> <b style="color:<?= $farben[$ti % 8] ?>">Tag <?= $ti + 1 ?></b> · <b><?= datum($t['datum'], true) ?></b>, <?= hm($t['start']) ?>–<?= hm($t['ende']) ?> Uhr</label>
        <span class="hint"><?= count($t['stopps']) ?> <?= count($t['stopps']) === 1 ? 'Kunde' : 'Kunden' ?> · <?= zahl($t['km'], 0) ?> km · Arbeit <?= std(array_sum(array_map(fn($s) => $s['ab'] - $s['an'], $t['stopps'])) / 60) ?> · Fahrt <?= std((array_sum(array_column($t['stopps'], 'fahrt')) + $t['rueck']) / 60) ?></span>
      </div>
      <?php foreach ($t['stopps'] as $si => $s): $w = $nachId[$s['id']] ?? null; if (!$w) continue; ?>
        <div class="fahrt">↓ <?= $s['fahrt'] ?> Min. · <?= zahl($s['km'], 1) ?> km</div>
        <label class="stopp"><input type="checkbox" name="sel[]" value="<?= $s['id'] ?>" checked data-tag-stopp="<?= $ti ?>">
          <span class="mono"><?= hm($s['an']) ?>–<?= hm($s['ab']) ?></span>
          <span><span class="tag"><?= h($w['art']) ?></span><?= ticket_marke($w) ?><b><?= h($w['kunde']) ?></b> <span class="hint"><?= h($w['strasse']) ?>, <?= h($w['ort']) ?><?= $w['gruppe'] ? ' · Gruppe' : '' ?><?= $w['quartal'] < $q ? ' · aus Q' . $w['quartal'] : '' ?></span></span>
          <span class="hint"><?= std(($s['ab'] - $s['an']) / 60) ?> vor Ort</span></label>
      <?php endforeach; ?>
      <div class="fahrt">↓ <?= $t['rueck'] ?> Min. zurück zur Firma</div>
      <?= bedarf_zeile(array_map(fn($s) => (int)($nachId[$s['id']]['anlage_id'] ?? 0), $t['stopps']), $t['datum']) ?>
    </div>
  <?php endforeach; ?>
  <div class="row">
    <button class="btn" name="aktion" value="uebernehmen"><?= outlook_aktiv() ? 'Übernehmen und in Outlook eintragen' : 'Übernehmen' ?></button>
    <button class="btn ghost" name="aktion" value="verwerfen" formnovalidate>Verwerfen</button>
  </div>
</form>
<script>
document.querySelectorAll('[data-tag]').forEach(t => t.addEventListener('change', () => document.querySelectorAll('[data-tag-stopp="' + t.dataset.tag + '"]').forEach(s => s.checked = t.checked)));
</script>
<?php endif; ?>

<div class="box" id="geplant">
  <div class="row" style="justify-content:space-between"><h2>Geplante Termine <?= h($mit[$mid] ?? '') ?> · <?= quartal_name($q) ?> <?= $jahr ?></h2>
  <?php if ($ohneOutlook && outlook_aktiv()): ?><form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="outlook_nachtragen"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><input type="hidden" name="mid" value="<?= $mid ?>"><button class="btn ghost klein"><?= count($ohneOutlook) ?> fehlen in Outlook – nachtragen</button></form><?php endif; ?></div>
  <?php if (!$geplant): ?><p class="leer">Noch keine.</p><?php else:
    $tage = []; foreach ($geplant as $w) $tage[substr($w['beginn'], 0, 10)][] = $w; ksort($tage); ?>
  <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="entfernen"><input type="hidden" name="j" value="<?= $jahr ?>"><input type="hidden" name="q" value="<?= $q ?>"><input type="hidden" name="mid" value="<?= $mid ?>">
  <div class="scroll"><table class="tbl"><tr><th></th><th>Tag</th><th>Zeit</th><th>Kunde</th><th>Ort</th><th>Status</th></tr>
  <?php foreach ($tage as $d => $liste): foreach ($liste as $i => $w): ?>
    <tr><td><?php if ($w['status'] === 'terminiert'): ?><input type="checkbox" name="ids[]" value="<?= $w['id'] ?>" aria-label="auswählen"><?php endif; ?></td>
      <td><?= $i === 0 ? '<b>' . datum($d, true) . '</b>' : '' ?></td><td class="mono"><?= substr($w['beginn'], 11, 5) ?>–<?= substr($w['ende'], 11, 5) ?></td>
      <td><span class="tag"><?= h($w['art']) ?></span><?= ticket_marke($w) ?><?= h($w['kunde']) ?></td><td><?= h($w['ort']) ?></td>
      <td><?= status_pill($w['status']) ?><?= $w['outlook_id'] ? ' <span class="pill ok">Outlook</span>' : ($w['outlook_fehler'] ? ' <span class="pill err" title="' . h($w['outlook_fehler']) . '">Outlook-Fehler</span>' : '') ?></td></tr>
  <?php endforeach; endforeach; ?></table></div>
  <button class="btn ghost klein">Ausgewählte Termine entfernen<?= outlook_aktiv() ? ' (auch aus Outlook)' : '' ?></button></form>
  <?php endif; ?>
</div>
<?php seite_fuss();
