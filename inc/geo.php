<?php
/**
 * OpenStreetMap: Adressen -> Koordinaten (Nominatim), Fahrzeiten (OSRM)
 * Alles wird zwischengespeichert. Ohne Verbindung: Luftlinie × Umwegfaktor.
 */
declare(strict_types=1);

function osm_http(string $url): ?array {
    $o = cfg('osm');
    $ch = http_neu($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: de'],
        CURLOPT_USERAGENT => 'WTG-Wartungsplaner/1.0 (' . $o['kontakt'] . ')']);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false || $code !== 200) return null;
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : null;
}

/** Nominatim: max. 1 Anfrage pro Sekunde */
function nominatim(array $params): ?array {
    static $letzte = 0.0;
    $warte = (float)(cfg('osm')['pause_s'] ?? 1.1) - (microtime(true) - $letzte);
    if ($warte > 0) usleep((int)($warte * 1e6));
    $letzte = microtime(true);
    $j = osm_http(rtrim(cfg('osm')['nominatim_url'], '/') . '/search?' . http_build_query($params + ['country' => 'de', 'format' => 'jsonv2', 'limit' => 1]));
    if (!$j || empty($j[0]['lat'])) return null;
    return ['lat' => (float)$j[0]['lat'], 'lon' => (float)$j[0]['lon']];
}

/** Koordinaten einer Adresse. Rückgabe: [lat, lon, status] mit status 'adresse' | 'plz' | 'fehlt' */
function geokodieren(string $strasse, string $plz, string $ort): array {
    $k = 'a|' . norm($strasse) . '|' . norm($plz) . '|' . norm($ort);
    if ($c = eine('SELECT * FROM geo_cache WHERE k = ?', [$k])) return [(float)$c['lat'], (float)$c['lon'], $c['quelle']];
    $str = trim(preg_replace('/\s+/', ' ', $strasse));
    $r = $str !== '' ? nominatim(['street' => $str, 'postalcode' => $plz, 'city' => $ort]) : null;
    $quelle = 'adresse';
    if (!$r && $str !== '') { $r = nominatim(['street' => $str, 'city' => $ort]); }
    if (!$r && $plz !== '') { $r = nominatim(['postalcode' => $plz, 'city' => $ort]); $quelle = 'plz'; }
    if (!$r && $plz !== '') { $r = nominatim(['postalcode' => $plz]); $quelle = 'plz'; }
    if (!$r) return [null, null, 'fehlt'];
    q('INSERT INTO geo_cache (k, lat, lon, quelle) VALUES (?,?,?,?)', [$k, $r['lat'], $r['lon'], $quelle]);
    return [$r['lat'], $r['lon'], $quelle];
}

function kunde_geokodieren(array $k): string {
    [$lat, $lon, $st] = geokodieren((string)$k['strasse'], (string)$k['plz'], (string)$k['ort']);
    aendern('kunden', (int)$k['id'], ['lat' => $lat, 'lon' => $lon, 'geo_status' => $st,
        'geo_hinweis' => $st === 'plz' ? 'Nur Postleitzahl gefunden – Lage ungenau' : ($st === 'fehlt' ? 'Adresse nicht gefunden' : null)]);
    return $st;
}

function firma_koord(): ?array {
    static $f = false;
    if ($f !== false) return $f;
    $c = cfg('firma');
    $k = 'a|' . norm($c['strasse']) . '|' . norm($c['plz']) . '|' . norm($c['ort']);
    $r = eine('SELECT lat, lon FROM geo_cache WHERE k = ?', [$k]);
    if (!$r) { [$lat, $lon] = geokodieren($c['strasse'], $c['plz'], $c['ort']); $r = $lat === null ? null : ['lat' => $lat, 'lon' => $lon]; }
    return $f = $r ? ['lat' => (float)$r['lat'], 'lon' => (float)$r['lon']] : null;
}

/* ---------- Fahrzeiten ---------- */
function luftlinie_km(array $a, array $b): float {
    $r = 6371; $p1 = deg2rad($a['lat']); $p2 = deg2rad($b['lat']);
    $dp = $p2 - $p1; $dl = deg2rad($b['lon'] - $a['lon']);
    $x = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($x)));
}
function schaetzung(array $a, array $b): array {
    $o = cfg('osm'); $km = luftlinie_km($a, $b) * $o['umwegfaktor'];
    return ['min' => $km / $o['kmh'] * 60, 'km' => $km, 'quelle' => 'schaetzung'];
}
function punkt_schluessel(array $p): string { return sprintf('%.4f,%.4f', $p['lat'], $p['lon']); }

/**
 * Fahrzeit-Matrix (Minuten, km) zwischen Punkten [['lat','lon'], …].
 * Nutzt den Zwischenspeicher, fragt fehlende Paare bei OSRM ab (Blöcke à 45 × 45), sonst Schätzung.
 * Rückgabe: ['min' => [i][j], 'km' => [i][j], 'quelle' => 'osrm'|'gemischt'|'schaetzung']
 */
function fahrt_matrix(array $punkte): array {
    $n = count($punkte); $min = []; $km = [];
    $keys = array_map('punkt_schluessel', $punkte);
    // benötigte Paare aus dem Zwischenspeicher laden
    $paare = [];
    for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) if ($keys[$i] !== $keys[$j]) $paare["{$keys[$i]}>{$keys[$j]}"] = true;
    $cache = [];
    foreach (array_chunk(array_keys($paare), 400) as $teil) {
        foreach (alle('SELECT k, min, km FROM fahrt_cache WHERE quelle = \'osrm\' AND k IN (' . implode(',', array_fill(0, count($teil), '?')) . ')', $teil) as $r) $cache[$r['k']] = $r;
    }
    $fehlt = [];
    for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
        if ($keys[$i] === $keys[$j]) { $min[$i][$j] = 0.0; $km[$i][$j] = 0.0; continue; }
        $c = $cache["{$keys[$i]}>{$keys[$j]}"] ?? null;
        if ($c) { $min[$i][$j] = (float)$c['min']; $km[$i][$j] = (float)$c['km']; } else $fehlt[$i] = true;
    }
    if ($fehlt) osrm_bloecke($punkte, $keys, array_keys($fehlt), $min, $km);
    // Lücken (kein Routing erreichbar) mit Luftlinien-Schätzung füllen
    $geschaetzt = 0; $gesamt = 0;
    for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
        if ($i === $j) continue;
        $gesamt++;
        if (!isset($min[$i][$j])) { $s = schaetzung($punkte[$i], $punkte[$j]); $min[$i][$j] = $s['min']; $km[$i][$j] = $s['km']; $geschaetzt++; }
    }
    $quelle = $geschaetzt === 0 ? 'osrm' : ($geschaetzt === $gesamt ? 'schaetzung' : 'gemischt');
    return ['min' => $min, 'km' => $km, 'quelle' => $quelle];
}

function osrm_bloecke(array $punkte, array $keys, array $quellen, array &$min, array &$km): bool {
    $url = rtrim(cfg('osm')['osrm_url'], '/');
    if ($url === '') return false;
    $alle = array_keys($punkte);
    $insert = db()->prepare('INSERT INTO fahrt_cache (k, min, km, quelle) VALUES (?,?,?,?)');
    $alleOk = true;
    foreach (array_chunk($quellen, 45) as $src) {
        foreach (array_chunk($alle, 45) as $dst) {
            $idx = array_values(array_unique(array_merge($src, $dst)));
            $pos = array_flip($idx);
            $coords = implode(';', array_map(fn($i) => sprintf('%.6f,%.6f', $punkte[$i]['lon'], $punkte[$i]['lat']), $idx));
            $j = osm_http("$url/table/v1/driving/$coords?annotations=duration,distance&sources=" . implode(';', array_map(fn($i) => $pos[$i], $src)) . '&destinations=' . implode(';', array_map(fn($i) => $pos[$i], $dst)));
            if (!$j || ($j['code'] ?? '') !== 'Ok') { $alleOk = false; continue; }
            foreach ($src as $a => $i) foreach ($dst as $b => $jx) {
                $d = $j['durations'][$a][$b] ?? null; $m = $j['distances'][$a][$b] ?? null;
                if ($d === null) continue;
                $min[$i][$jx] = $d / 60; $km[$i][$jx] = ($m ?? 0) / 1000;
                if ($keys[$i] !== $keys[$jx]) {
                    $k = "{$keys[$i]}>{$keys[$jx]}";
                    try { $insert->execute([$k, $d / 60, ($m ?? 0) / 1000, 'osrm']); } catch (Throwable $e) { q('UPDATE fahrt_cache SET min = ?, km = ?, quelle = ? WHERE k = ?', [$d / 60, ($m ?? 0) / 1000, 'osrm', $k]); }
                }
            }
        }
    }
    return $alleOk;
}

/** Fahrzeit Firma -> Kunde (Minuten), aus Cache oder geschätzt; null ohne Koordinaten */
function fahrt_firma_min(array $kunde): ?float {
    $f = firma_koord();
    if (!$f || $kunde['lat'] === null) return null;
    $p = ['lat' => (float)$kunde['lat'], 'lon' => (float)$kunde['lon']];
    $c = eine('SELECT min FROM fahrt_cache WHERE k = ?', [punkt_schluessel($f) . '>' . punkt_schluessel($p)]);
    return $c ? (float)$c['min'] : schaetzung($f, $p)['min'];
}
