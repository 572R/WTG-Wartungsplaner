<?php
/**
 * Adressen aller Kunden über OpenStreetMap (Nominatim) in Koordinaten umrechnen.
 * Max. 1 Anfrage pro Sekunde: bei ca. 500 Adressen etwa 10–15 Minuten. Kann jederzeit abgebrochen
 * und erneut gestartet werden – bereits gefundene Adressen werden übersprungen.
 *   php werkzeuge/geokodieren.php [--alle]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/inc/app.php';
require dirname(__DIR__) . '/inc/geo.php';
$alle = in_array('--alle', $argv, true);
$f = firma_koord();
echo $f ? sprintf("Firma: %.5f, %.5f\n", $f['lat'], $f['lon']) : "WARNUNG: Firmenadresse nicht gefunden – bitte in config.php prüfen.\n";
$liste = alle('SELECT * FROM kunden' . ($alle ? '' : " WHERE geo_status IS NULL OR geo_status = 'fehlt'") . ' ORDER BY id');
$n = count($liste); $st = ['adresse' => 0, 'plz' => 0, 'fehlt' => 0];
foreach ($liste as $i => $k) {
    $s = kunde_geokodieren($k); $st[$s]++;
    printf("\r%d/%d  gefunden: %d  nur PLZ: %d  fehlt: %d   ", $i + 1, $n, $st['adresse'], $st['plz'], $st['fehlt']);
}
echo "\nFertig.\n";
if ($st['fehlt']) echo "Nicht gefundene Adressen stehen in der App unter Pflege → Prüfliste.\n";
// Fahrzeiten Firma -> alle Kunden vorab holen (für die Vor-Ort-Zeit)
$pkt = [$f]; foreach (alle('SELECT lat, lon FROM kunden WHERE lat IS NOT NULL') as $k) $pkt[] = ['lat' => (float)$k['lat'], 'lon' => (float)$k['lon']];
if ($f && count($pkt) > 1) {
    echo "Fahrzeiten Firma → Kunden … ";
    $url = rtrim(cfg('osm')['osrm_url'], '/'); $ok = 0;
    foreach (array_chunk(array_slice($pkt, 1), 90) as $teil) {
        $m = fahrt_matrix(array_merge([$f], $teil)); if ($m['quelle'] !== 'schaetzung') $ok++;
    }
    echo $ok ? "fertig (OSRM)\n" : "OSRM nicht erreichbar – es wird mit Luftlinie geschätzt\n";
}
