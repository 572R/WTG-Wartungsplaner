<?php
/** Seitenrahmen, Navigation nach Bereichen, Karten */
declare(strict_types=1);

const BEREICH_SEITEN = [
    'techniker'    => ['termine.php', 'Meine Termine'],
    'pflege'       => ['pflege.php', 'Pflege'],
    'organisation' => ['organisation.php', 'Organisation'],
    'auswertung'   => ['auswertung.php', 'Auswertung'],
    'admin'        => ['verwaltung.php', 'Verwaltung'],
];

function seite_kopf(string $titel, string $aktiv = '', bool $karte = false): void {
    $b = benutzer(); sitzung();
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($titel) ?> · WTG Wartungsplaner</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..100,400..800&family=IBM+Plex+Mono:wght@400;500&display=swap">
<?php if ($karte): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<style>
:root{--bg:#f3f4f2;--surface:#fff;--line:#d9dcd6;--fg:#1d2321;--muted:#5f6862;--accent:#b8261b;--accent-fg:#fff;--accent-soft:#f6e3e0;--ok:#2f7a4b;--ok-soft:#e1f1e6;--warn:#9a6400;--warn-soft:#fbefd6;--info:#2b5f8a;--info-soft:#e2edf6;--bar:#cfd5cf;--bar-hi:#1d2321;
--body:"Archivo",system-ui,"Segoe UI",sans-serif;--mono:"IBM Plex Mono",ui-monospace,Consolas,monospace;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#141816;--surface:#1c211f;--line:#2f3633;--fg:#e7ebe8;--muted:#9aa49e;--accent:#ff6a55;--accent-fg:#1a0d0b;--accent-soft:#3a1f1b;--ok:#6fcf91;--ok-soft:#1b3424;--warn:#f0b54a;--warn-soft:#3a2f17;--info:#8cc2ef;--info-soft:#1c2c3a;--bar:#36403b;--bar-hi:#e7ebe8;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font-family:var(--body);font-size:15px;line-height:1.5;padding:20px max(16px,env(safe-area-inset-right)) 48px max(16px,env(safe-area-inset-left))}
a{color:inherit}
.wrap{max-width:1240px;margin:0 auto;display:grid;gap:20px}
header.kopf{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px;border-bottom:2px solid var(--fg);padding-bottom:12px}
.logo{font-stretch:78%;font-weight:800;font-size:28px;line-height:1;margin:0;text-decoration:none}
.logo span{color:var(--accent)}
nav.haupt{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
nav.haupt a{font-size:14px;text-decoration:none;padding:5px 11px;border-radius:4px;border:1px solid var(--line);background:var(--surface)}
nav.haupt a.on{background:var(--fg);color:var(--bg);border-color:var(--fg)}
.konto{font-size:13px;color:var(--muted);text-align:right}
h1{font-stretch:85%;font-size:24px;margin:0}
h2{font-stretch:85%;font-size:18px;margin:0}
h3{margin:0;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:600}
.cols{display:grid;grid-template-columns:minmax(0,340px) minmax(0,1fr);gap:20px;align-items:start}
.cols2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start}
@media (max-width:920px){.cols,.cols2{grid-template-columns:minmax(0,1fr)}}
.stack{display:grid;gap:16px;min-width:0}
.box{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:16px 18px;display:grid;gap:12px;min-width:0;grid-template-columns:minmax(0,1fr)}
.hint,.muted{color:var(--muted);font-size:13px;margin:0}
label.f{display:grid;gap:4px;font-size:13px;color:var(--muted);font-weight:500}
select,input[type=text],input[type=password],input[type=number],input[type=date],input[type=month],input[type=email],input[type=search],textarea{font:inherit;font-size:15px;color:var(--fg);background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:7px 9px;width:100%;min-width:0}
textarea{min-height:70px;resize:vertical}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
@media (max-width:600px){.grid3,.grid4{grid-template-columns:1fr 1fr}}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn{font:inherit;font-weight:700;font-size:15px;border:0;border-radius:6px;padding:10px 16px;background:var(--accent);color:var(--accent-fg);cursor:pointer;text-align:center;text-decoration:none;display:inline-block}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn.ghost{background:transparent;color:var(--fg);border:1px solid var(--line);font-weight:600;font-size:14px;padding:8px 12px}
.btn.klein{font-size:13px;padding:4px 9px;font-weight:600}
.btn.voll{display:block;width:100%}
.msg{border-radius:4px;padding:10px 12px;font-size:14px}
.msg.ok{background:var(--ok-soft);color:var(--ok)} .msg.err{background:var(--accent-soft);color:var(--accent)} .msg.warn{background:var(--warn-soft);color:var(--warn)} .msg.info{background:var(--info-soft);color:var(--info)}
.msg ul{margin:4px 0 0;padding-left:18px}
.scroll{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:14px}
.tbl th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;padding:6px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
.tbl td{padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}
.tbl tr.vorschlag td{background:var(--info-soft)}
.tbl .r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.mono{font-family:var(--mono);font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap}
.tag{display:inline-block;font-family:var(--mono);font-size:11px;padding:0 6px;border-radius:3px;background:var(--accent-soft);color:var(--accent);margin-right:4px;white-space:nowrap}
.pill{display:inline-block;font-size:12px;font-weight:600;padding:1px 8px;border-radius:999px;white-space:nowrap}
.pill.ok{background:var(--ok-soft);color:var(--ok)} .pill.warn{background:var(--warn-soft);color:var(--warn)} .pill.err{background:var(--accent-soft);color:var(--accent)} .pill.info{background:var(--info-soft);color:var(--info)} .pill.neutral{background:var(--bg);color:var(--muted);border:1px solid var(--line)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:12px 14px}
.kpi b{display:block;font-size:24px;font-stretch:85%;font-variant-numeric:tabular-nums}
.kpi span{font-size:12px;color:var(--muted)}
.balken{height:10px;background:var(--bg);border-radius:5px;overflow:hidden;display:flex;min-width:80px}
.balken i{display:block;height:100%}
.b1{background:var(--fg)}.b2{background:var(--info)}.b3{background:var(--bar)}.b4{background:var(--warn)}
.legende{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--muted)}
.legende i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}
.karte{height:320px;border-radius:6px;border:1px solid var(--line);z-index:0}
.karte.gross{height:460px}
.tag-tour{border-top:1px solid var(--line);padding-top:10px;display:grid;gap:6px}
.stopp{display:grid;grid-template-columns:auto 96px minmax(0,1fr) auto;gap:4px 10px;align-items:start;font-size:14px}
.stopp .nr{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:var(--fg);color:var(--bg);font-size:11px;font-weight:700}
.fahrt{font-size:12px;color:var(--muted);padding-left:32px}
.kunde-kopf{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start}
details>summary{cursor:pointer}
.alt{color:var(--warn);font-weight:600}
.leer{color:var(--muted);padding:6px 0;margin:0}
.monat{display:grid;gap:4px}
.mkopf{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;padding:2px 6px}
.mtag{display:flex;flex-direction:column;gap:2px;min-height:96px;padding:5px 6px;border:1px solid var(--line);border-radius:4px;text-decoration:none;background:var(--surface);min-width:0;font-size:12px;line-height:1.3}
.mtag{overflow:hidden}.mtag .pill{white-space:normal;overflow-wrap:anywhere}.mtag:hover{border-color:var(--fg)}.mtag.fremd{opacity:.45}.mtag.heute{box-shadow:inset 0 0 0 2px var(--accent)}.mtag.frei{background:var(--bg)}
.mzahl{font-weight:700;font-variant-numeric:tabular-nums}
.mzeile{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mzeile i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px}
.mzeile.abw{background:var(--warn-soft);color:var(--warn);border-radius:3px;padding:0 4px}.mzeile.frist{color:var(--accent);font-weight:600}
@media (max-width:700px){.monat{gap:2px}.mtag{min-height:64px;font-size:11px;padding:3px}.mzeile .hint{display:none}}
.lage{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;align-items:start}
.lzeile{display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:6px;font-size:14px}
.lzahl{min-width:34px;font-size:18px;font-stretch:85%;font-variant-numeric:tabular-nums;color:var(--accent)}
.login{max-width:400px;margin:6vh auto 0;width:100%}
footer{color:var(--muted);font-size:12px;text-align:center}
@media print{nav.haupt,.konto,.no-print,.leaflet-control-container{display:none!important}body{background:#fff;color:#000;padding:0;font-size:12px}.box{border-color:#bbb;break-inside:avoid}.cols,.cols2{grid-template-columns:1fr}details:not([open]) form{display:none}.btn{display:none}}
</style>
</head>
<body>
<div class="wrap">
<header class="kopf">
  <div>
    <a class="logo" href="./">WTG <span>Wartungsplaner</span></a>
    <?php if ($b): ?>
    <nav class="haupt">
      <?php foreach (BEREICH_SEITEN as $rolle => [$url, $name]): if (!darf($rolle)) continue; ?>
        <a href="<?= $url ?>" class="<?= $aktiv === $rolle ? 'on' : '' ?>"><?= h($name) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
  <?php if ($b): ?><div class="konto"><?= h($b['name']) ?><br><a href="login.php?abmelden=1">Abmelden</a></div><?php endif; ?>
</header>
<?php if ($flash): ?><div class="msg <?= h($flash[0]) ?>"><?= $flash[1] ?></div><?php endif; ?>
<?php }

function seite_fuss(string $text = ''): void { ?>
<?php if ($text !== ''): ?><footer><?= $text ?></footer><?php endif; ?>
</div>
<script>addEventListener('beforeprint', () => document.querySelectorAll('details.druck-offen').forEach(d => d.open = true));</script>
</body>
</html>
<?php }

/** Karte: Punkte [{lat,lon,label,nr}] und Linien [[[lat,lon],...]] */
function karte(string $id, array $punkte, array $linien = [], bool $gross = false): void {
    $firma = firma_koord();
    $daten = ['punkte' => $punkte, 'linien' => $linien, 'firma' => $firma, 'kacheln' => cfg('osm')['kacheln']];
    ?>
<div id="<?= h($id) ?>" class="karte<?= $gross ? ' gross' : '' ?>"></div>
<script>
(function(){
  const d = <?= json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  if (!window.L) { document.getElementById(<?= json_encode($id) ?>).innerHTML = '<p class="hint" style="padding:12px">Karte nicht verfügbar (keine Internetverbindung zu OpenStreetMap).</p>'; return; }
  const m = L.map(<?= json_encode($id) ?>, {scrollWheelZoom:false});
  L.tileLayer(d.kacheln, {maxZoom:18, attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).addTo(m);
  const pts = [];
  const farben = ['#b8261b','#2b5f8a','#2f7a4b','#9a6400','#6b3fa0','#0f766e','#a21caf','#475569'];
  d.linien.forEach((l, i) => { if (l.length > 1) L.polyline(l, {color: farben[i % farben.length], weight:3, opacity:.8}).addTo(m); });
  if (d.firma) { L.circleMarker([d.firma.lat, d.firma.lon], {radius:7, color:'#1d2321', fillColor:'#1d2321', fillOpacity:1}).bindTooltip('Firma').addTo(m); pts.push([d.firma.lat, d.firma.lon]); }
  d.punkte.forEach(p => {
    const icon = L.divIcon({className:'', html:'<div style="background:'+(p.farbe||'#b8261b')+';color:#fff;border-radius:50%;width:22px;height:22px;display:grid;place-items:center;font:700 11px system-ui;border:2px solid #fff;box-shadow:0 0 0 1px #0003">'+(p.nr ?? '')+'</div>', iconSize:[22,22], iconAnchor:[11,11]});
    L.marker([p.lat, p.lon], {icon}).bindTooltip(p.label).addTo(m); pts.push([p.lat, p.lon]);
  });
  if (pts.length) m.fitBounds(pts, {padding:[24,24], maxZoom:14}); else m.setView([52.26, 10.52], 10);
})();
</script>
<?php }

/** kleines Status-Abzeichen für Wartungen */
function status_pill(string $s): string {
    return match ($s) {
        'offen' => '<span class="pill warn">offen</span>',
        'zugewiesen' => '<span class="pill info">zugewiesen</span>',
        'terminiert' => '<span class="pill neutral">terminiert</span>',
        'erledigt' => '<span class="pill ok">erledigt</span>',
        default => '<span class="pill neutral">' . h($s) . '</span>',
    };
}

/** Kennzeichen „Ticket" mit Titel (leer bei Wartungen) */
function ticket_marke(array $w): string {
    if (($w['typ'] ?? 'wartung') !== 'ticket') return '';
    return '<span class="pill info">Ticket</span> <b>' . h($w['titel'] ?: '') . '</b>' . (($w['prio'] ?? '') === 'dringend' ? ' <span class="pill err">dringend</span>' : '')
        . (!empty($w['faellig_bis']) ? ' <span class="hint">bis ' . datum($w['faellig_bis']) . '</span>' : '') . ' · ';
}
/** Akku-Bedarf als kleine Zeile (leer, wenn nichts fällig) */
function bedarf_zeile(array $anlageIds, ?string $bezug): string {
    $b = akku_bedarf($anlageIds, $bezug);
    if (!$b['typen'] && !$b['unklar']) return '';
    return '<p class="hint">🔋 <b>Akkus mitnehmen:</b> ' . h(bedarf_text($b)) . '</p>';
}
