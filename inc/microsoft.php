<?php
/**
 * Microsoft 365: Outlook-Kalender (Graph) und Anmeldung mit Microsoft (OpenID Connect)
 * Kalender: liest nur Beginn/Ende/Status, schreibt eigene Wartungstermine.
 */
declare(strict_types=1);

const GRAPH_TZ = 'W. Europe Standard Time';
final class MsFehler extends RuntimeException {}

function ms(): array {
    $m = cfg('microsoft');
    $m['login_url'] = $m['login_url'] ?? 'https://login.microsoftonline.com';
    $m['graph_url'] = $m['graph_url'] ?? 'https://graph.microsoft.com/v1.0';
    return $m;
}
function outlook_aktiv(): bool { return !empty(ms()['outlook_aktiv']); }

function ms_post(string $url, array $felder): array {
    $ch = http_neu($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => http_build_query($felder)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new MsFehler("Microsoft nicht erreichbar: $err");
    $j = json_decode((string)$raw, true) ?: [];
    if ($code !== 200) throw new MsFehler('Microsoft-Anmeldung fehlgeschlagen: ' . ($j['error_description'] ?? $j['error'] ?? "HTTP $code"));
    return $j;
}

function graph_token(): string {
    static $t = null, $bis = 0;
    if ($t && time() < $bis - 60) return $t;
    $m = ms();
    $j = ms_post(rtrim($m['login_url'], '/') . '/' . rawurlencode($m['tenant_id']) . '/oauth2/v2.0/token', [
        'client_id' => $m['client_id'], 'client_secret' => $m['client_secret'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']);
    $t = $j['access_token']; $bis = time() + (int)($j['expires_in'] ?? 3600);
    return $t;
}

function graph(string $methode, string $pfad, ?array $body = null, array $kopf = []): array {
    $url = str_starts_with($pfad, 'http') ? $pfad : rtrim(ms()['graph_url'], '/') . $pfad;
    for ($v = 1; ; $v++) {
        $ch = http_neu($url);
        $h = array_merge(['Authorization: Bearer ' . graph_token(), 'Accept: application/json'], $kopf);
        if ($body !== null) $h[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h, CURLOPT_HEADER => true]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $err = curl_error($ch); curl_close($ch);
        if ($raw === false) throw new MsFehler("Microsoft 365 nicht erreichbar: $err");
        if (in_array($code, [429, 503, 504], true) && $v < 4) { sleep(preg_match('/^Retry-After:\s*(\d+)/mi', substr($raw, 0, $hs), $m) ? min(10, (int)$m[1]) : 2 * $v); continue; }
        $j = json_decode(substr($raw, $hs), true) ?? [];
        if ($code >= 400) {
            $msg = $j['error']['message'] ?? "HTTP $code";
            if (in_array($code, [401, 403], true)) $msg .= ' – Berechtigung Calendars.ReadWrite mit Administratorzustimmung prüfen, bzw. ob das Postfach durch die Zugriffsrichtlinie gesperrt ist.';
            if ($code === 404) $msg .= ' – Postfach-Adresse des Mitarbeiters prüfen.';
            throw new MsFehler($msg, $code);
        }
        return $j;
    }
}

function postfach(int $mitarbeiterId): string {
    $e = (string)wert('SELECT email FROM mitarbeiter WHERE id = ?', [$mitarbeiterId]);
    if ($e === '') throw new MsFehler('Für diesen Mitarbeiter ist keine E-Mail-Adresse (Postfach) hinterlegt.');
    return $e;
}

/** Belegte Zeiten: Liste ['Y-m-d', vonMin, bisMin, 'oof'|'busy'] */
function outlook_belegt(int $mid, string $von, string $bis): array {
    $q = http_build_query(['startDateTime' => $von . 'T00:00:00', 'endDateTime' => $bis . 'T23:59:59', '$select' => 'start,end,showAs,isAllDay,isCancelled,categories', '$top' => 250]);
    $url = '/users/' . rawurlencode(postfach($mid)) . '/calendarView?' . $q; $out = [];
    $kat = ms()['kategorie'];
    while ($url) {
        $j = graph('GET', $url, null, ['Prefer: outlook.timezone="' . GRAPH_TZ . '"']);
        foreach ($j['value'] ?? [] as $e) {
            if (!empty($e['isCancelled']) || !in_array($e['showAs'] ?? 'busy', ms()['belegt_status'], true)) continue;
            if (in_array($kat, $e['categories'] ?? [], true)) continue; // eigene Wartungstermine kennt die App selbst
            $s = new DateTimeImmutable(substr($e['start']['dateTime'], 0, 19)); $en = new DateTimeImmutable(substr($e['end']['dateTime'], 0, 19));
            for ($d = $s->setTime(0, 0); $d < $en; $d = $d->modify('+1 day')) {
                $te = $d->modify('+1 day'); $a = max($s, $d); $b = min($en, $te);
                if ($a >= $b) continue;
                $out[] = [$d->format('Y-m-d'), (int)$a->format('G') * 60 + (int)$a->format('i'), $b == $te ? 1440 : (int)$b->format('G') * 60 + (int)$b->format('i'), $e['showAs'] ?? 'busy'];
            }
        }
        $url = $j['@odata.nextLink'] ?? null;
    }
    return $out;
}

/**
 * Für den Abgleich: Termine mit Betreff und Ort (nur auf der Seite "Outlook-Abgleich", nicht bei der Planung)
 * Rückgabe: Liste ['id','betreff','ort','beginn','ende','ganztag','status','kategorien']
 */
function outlook_termine(int $mid, string $von, string $bis): array {
    $q = http_build_query(['startDateTime' => $von . 'T00:00:00', 'endDateTime' => $bis . 'T23:59:59', '$select' => 'id,subject,location,start,end,showAs,isAllDay,isCancelled,categories', '$top' => 250]);
    $url = '/users/' . rawurlencode(postfach($mid)) . '/calendarView?' . $q; $out = [];
    while ($url) {
        $j = graph('GET', $url, null, ['Prefer: outlook.timezone="' . GRAPH_TZ . '"']);
        foreach ($j['value'] ?? [] as $e) {
            if (!empty($e['isCancelled'])) continue;
            $loc = $e['location'] ?? []; $adr = $loc['address'] ?? [];
            $out[] = ['id' => (string)($e['id'] ?? ''), 'betreff' => (string)($e['subject'] ?? ''),
                'ort' => trim(($loc['displayName'] ?? '') . ' ' . implode(' ', array_filter([$adr['street'] ?? '', $adr['postalCode'] ?? '', $adr['city'] ?? '']))),
                'beginn' => str_replace('T', ' ', substr($e['start']['dateTime'], 0, 19)), 'ende' => str_replace('T', ' ', substr($e['end']['dateTime'], 0, 19)),
                'ganztag' => !empty($e['isAllDay']), 'status' => $e['showAs'] ?? 'busy', 'kategorien' => $e['categories'] ?? []];
        }
        $url = $j['@odata.nextLink'] ?? null;
    }
    return $out;
}

function outlook_eintragen(array $w): string {
    $t = termin_inhalt((int)$w['id']);
    $body = ['subject' => $t['betreff'], 'body' => ['contentType' => 'text', 'content' => $t['text']],
        'start' => ['dateTime' => str_replace(' ', 'T', $w['beginn']), 'timeZone' => GRAPH_TZ],
        'end' => ['dateTime' => str_replace(' ', 'T', $w['ende']), 'timeZone' => GRAPH_TZ],
        'location' => ['displayName' => $t['ort_text'], 'address' => ['street' => $t['strasse'], 'postalCode' => $t['plz'], 'city' => $t['ort'], 'countryOrRegion' => 'Deutschland']],
        'categories' => [ms()['kategorie']], 'showAs' => 'busy',
        'isReminderOn' => (int)cfg('erinnerung_min') > 0, 'reminderMinutesBeforeStart' => max(0, (int)cfg('erinnerung_min')),
        'transactionId' => 'wtg-w' . $w['id'] . '-' . substr(md5($w['beginn']), 0, 8)];
    $j = graph('POST', '/users/' . rawurlencode(postfach((int)$w['techniker_id'])) . '/events', $body);
    if (empty($j['id'])) throw new MsFehler('Keine Termin-ID von Microsoft erhalten.');
    return $j['id'];
}
function outlook_loeschen(int $mid, string $oid): void {
    try { graph('DELETE', '/users/' . rawurlencode(postfach($mid)) . '/events/' . rawurlencode($oid)); }
    catch (MsFehler $e) { if ($e->getCode() !== 404) throw $e; }
}

/* ---------- Anmeldung mit Microsoft (OpenID Connect, Autorisierungscode) ---------- */
function ms_login_url(string $redirect): string {
    sitzung(); $_SESSION['ms_state'] = bin2hex(random_bytes(16)); $_SESSION['ms_nonce'] = bin2hex(random_bytes(16));
    $m = ms();
    return rtrim($m['login_url'], '/') . '/' . rawurlencode($m['tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => $m['client_id'], 'response_type' => 'code', 'redirect_uri' => $redirect, 'response_mode' => 'query',
        'scope' => 'openid profile email', 'state' => $_SESSION['ms_state'], 'nonce' => $_SESSION['ms_nonce']]);
}
/** Liefert die E-Mail-Adresse des angemeldeten Microsoft-Kontos */
function ms_login_abschliessen(string $redirect): string {
    sitzung();
    if (!hash_equals($_SESSION['ms_state'] ?? '', (string)($_GET['state'] ?? ''))) throw new MsFehler('Anmeldung abgelaufen. Bitte erneut versuchen.');
    if (!empty($_GET['error'])) throw new MsFehler('Microsoft: ' . ($_GET['error_description'] ?? $_GET['error']));
    $m = ms();
    $j = ms_post(rtrim($m['login_url'], '/') . '/' . rawurlencode($m['tenant_id']) . '/oauth2/v2.0/token', [
        'client_id' => $m['client_id'], 'client_secret' => $m['client_secret'], 'grant_type' => 'authorization_code',
        'code' => (string)($_GET['code'] ?? ''), 'redirect_uri' => $redirect, 'scope' => 'openid profile email']);
    // Das ID-Token kommt direkt vom Microsoft-Tokenendpunkt (TLS) und wird daher nicht zusätzlich signaturgeprüft.
    $teile = explode('.', (string)($j['id_token'] ?? ''));
    $c = json_decode(base64_decode(strtr($teile[1] ?? '', '-_', '+/')), true) ?: [];
    if (($c['nonce'] ?? '') !== ($_SESSION['ms_nonce'] ?? '') || ($c['aud'] ?? '') !== $m['client_id']) throw new MsFehler('Ungültige Antwort von Microsoft.');
    return mb_strtolower((string)($c['email'] ?? $c['preferred_username'] ?? ''));
}
