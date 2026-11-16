<?php
/** Kundenakte als Druckansicht – im Browser „Drucken → Als PDF sichern" ergibt das PDF */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
nur('pflege');
$k = eine('SELECT * FROM kunden WHERE id = ?', [(int)($_GET['k'] ?? 0)]);
if (!$k) weiter('pflege.php', 'Kunde nicht gefunden.', 'err');
$mit = mitarbeiter_namen();
$anl = alle('SELECT a.*, g.name AS gruppe FROM anlagen a LEFT JOIN gruppen g ON g.id = a.gruppe_id WHERE a.kunde_id = ? ORDER BY a.aktiv DESC, a.art', [$k['id']]);
$notizen = alle('SELECT n.*, a.art FROM notizen n LEFT JOIN anlagen a ON a.id = n.anlage_id WHERE n.kunde_id = ? AND n.aktiv = 1 ORDER BY n.stand DESC', [$k['id']]);
$hist = alle(W_SELECT . " WHERE a.kunde_id = ? AND w.status <> 'offen' ORDER BY COALESCE(w.beginn, w.erstellt_am) DESC LIMIT 40", [$k['id']]);
$tickets = alle(W_SELECT . " WHERE a.kunde_id = ? AND w.typ = 'ticket' AND w.status IN ('offen','zugewiesen','terminiert') ORDER BY w.erstellt_am", [$k['id']]);
$doks = alle('SELECT * FROM dokumente WHERE kunde_id = ? AND aktiv = 1 ORDER BY erstellt_am DESC', [$k['id']]);
seite_kopf('Akte ' . $k['name'], 'pflege');
?>
<style>.akte h2{border-bottom:1px solid var(--line);padding-bottom:4px}.akte .box{gap:8px}@media print{header.kopf{border:0;padding:0}.logo{font-size:16px}}</style>
<div class="row no-print" style="justify-content:space-between"><a class="hint" href="pflege.php?k=<?= $k['id'] ?>">← zurück zur Kundenakte</a>
  <span class="row"><span class="hint">Im Druckfenster als Ziel „Als PDF sichern" wählen.</span><button class="btn" onclick="print()">Drucken / als PDF speichern</button></span></div>
<div class="akte stack">
  <div><h1><?= h($k['name']) ?></h1>
    <p><?= h($k['strasse']) ?>, <?= h($k['plz']) ?> <?= h($k['ort']) ?> · Kunden-Nr. <?= (int)$k['id'] ?><?= $k['ansprechpartner'] || $k['telefon'] ? ' · ' . h(trim($k['ansprechpartner'] . ' ' . $k['telefon'])) : '' ?></p>
    <p class="hint">Stand <?= date('d.m.Y H:i') ?> · erstellt von <?= h(wer()) ?> · WTG Wartungsplaner</p></div>
  <?php $kw = feld_werte('kunde', (int)$k['id']); $zeilen = []; foreach (felder('kunde') as $f) { $v = $kw[(int)$f['id']] ?? null; if ($v && $v['wert'] !== '') $zeilen[] = h($f['name']) . ': <b>' . h(feld_anzeige($f, $v['wert'])) . '</b> <span class="hint">(Stand ' . monat_jahr($v['stand']) . ')</span>'; }
  if ($zeilen): ?><div class="box"><h2>Weitere Angaben</h2><p><?= implode(' · ', $zeilen) ?></p></div><?php endif; ?>
  <?php foreach ($anl as $a): $teile = alle('SELECT * FROM bauteile WHERE anlage_id = ? AND aktiv = 1 ORDER BY typ, eingebaut', [$a['id']]);
      $rest = alle("SELECT * FROM restarbeiten WHERE anlage_id = ? ORDER BY status DESC, erstellt_am DESC", [$a['id']]); ?>
    <div class="box"><h2><?= h($a['art']) ?> · Anlage <?= $a['id'] ?><?= $a['aktiv'] ? '' : ' (inaktiv)' ?></h2>
      <p>Intervall: <?= h(str_replace('|', ', ', (string)$a['perioden'])) ?> · Zeit inkl. Fahrt: <?= $a['zeit_h'] !== null ? std($a['zeit_h']) : 'fehlt' ?> · Stammtechniker: <?= h($mit[(int)$a['stammtechniker_id']] ?? '–') ?><?= $a['gruppe'] ? ' · Gruppe: ' . h($a['gruppe']) : '' ?></p>
      <?php $aw = feld_werte('anlage', (int)$a['id']); $z = []; foreach (felder('anlage') as $f) { $v = $aw[(int)$f['id']] ?? null; if ($v && $v['wert'] !== '') $z[] = h($f['name']) . ': ' . h(feld_anzeige($f, $v['wert'])); } if ($z): ?><p><?= implode(' · ', $z) ?></p><?php endif; ?>
      <?php if ($teile): ?><div class="scroll"><table class="tbl"><tr><th>Typ</th><th>Bezeichnung</th><th class="r">Anzahl</th><th>Eingebaut</th><th></th></tr>
        <?php foreach ($teile as $b): ?><tr><td><?= h($b['typ']) ?></td><td><?= h($b['bezeichnung']) ?></td><td class="r"><?= (int)$b['anzahl'] ?></td><td><?= $b['eingebaut'] ? monat_jahr($b['eingebaut']) : 'unbekannt' ?></td><td><?= akku_faellig($b) ? 'tauschen' : '' ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
      <?php if ($rest): ?><h3>Restarbeiten (Info)</h3><?php foreach ($rest as $r): ?><p>[<?= $r['status'] ?>] <b>Noch zu tun:</b> <?= h($r['text']) ?><?= $r['gemacht'] ? ' · <b>Gemacht:</b> ' . h($r['gemacht']) : '' ?> <span class="hint">(<?= $r['quelle'] ? h($r['quelle']) . ', ' : '' ?><?= datum($r['bericht_am'] ?: $r['erstellt_am']) ?>)</span></p><?php endforeach; endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($notizen): ?><div class="box"><h2>Notizen</h2><?php foreach ($notizen as $n): $st = $n['bestaetigt_am'] ? substr($n['bestaetigt_am'], 0, 10) : $n['stand']; ?><p><?= $n['art'] ? '[' . h($n['art']) . '] ' : '' ?><?= nl2br(h($n['text'])) ?> <span class="hint">(Stand <?= monat_jahr($st) ?>, <?= h($n['erstellt_von']) ?>)</span></p><?php endforeach; ?></div><?php endif; ?>
  <?php if ($tickets): ?><div class="box"><h2>Offene Tickets</h2><?php foreach ($tickets as $t): ?><p><b><?= h($t['titel']) ?></b> (<?= h($t['art']) ?>, <?= $t['status'] ?><?= $t['faellig_bis'] ? ', fällig bis ' . datum($t['faellig_bis']) : '' ?>)<?= $t['beschreibung'] ? '<br>' . nl2br(h($t['beschreibung'])) : '' ?></p><?php endforeach; ?></div><?php endif; ?>
  <div class="box"><h2>Wartungen und Tickets (zuletzt)</h2>
    <?php if (!$hist): ?><p class="leer">Noch keine.</p><?php else: ?><div class="scroll"><table class="tbl"><tr><th>Termin</th><th>Was</th><th>Techniker</th><th>Status</th><th>Bericht</th></tr>
    <?php foreach ($hist as $w): ?><tr><td class="mono"><?= $w['beginn'] ? datum($w['beginn']) : '–' ?></td><td><?= h(auftrag_name($w)) ?><?= ist_ticket($w) ? '' : ' · ' . h($w['periode']) . ' ' . $w['jahr'] ?></td><td><?= h($mit[(int)$w['techniker_id']] ?? '–') ?></td>
      <td><?= h($w['status']) ?><?= $w['ist_h'] ? ', ' . std($w['ist_h']) : '' ?></td><td class="hint"><?= h($w['bericht'] ?? '') ?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>
  <?php if ($doks): ?><div class="box"><h2>Abgelegte Dokumente</h2><?php foreach ($doks as $d): ?><p><?= h($d['titel']) ?> <span class="hint">(<?= h($d['original']) ?>, <?= datum($d['erstellt_am']) ?>)</span></p><?php endforeach; ?></div><?php endif; ?>
</div>
<script>if (location.hash === '#drucken') print();</script>
<?php seite_fuss();
