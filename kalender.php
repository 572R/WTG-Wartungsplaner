<?php
/** Kalender (.ics) eines Mitarbeiters aus den terminierten Wartungen – für Abo/Download, wenn Outlook nicht angebunden ist */
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
$mid = (int)($_GET['m'] ?? 0);
if (!$mid || !hash_equals(substr(hash_hmac('sha256', 'kal|' . $mid, geheimnis()), 0, 24), (string)($_GET['t'] ?? ''))) {
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit("Kein gültiger Kalender-Link.\n");
}
$esc = fn(string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
$fold = function (string $l): string { if (strlen($l) <= 75) return $l; $o = []; $c = ''; $m = 75; foreach (mb_str_split($l) as $ch) { if (strlen($c) + strlen($ch) > $m) { $o[] = $c; $c = ''; $m = 74; } $c .= $ch; } $o[] = $c; return implode("\r\n ", $o); };
$name = (string)wert('SELECT name FROM mitarbeiter WHERE id = ?', [$mid]);
$L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WTG//Wartungsplaner//DE', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . $esc("Wartungen $name"), 'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
    'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin', 'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
    'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD', 'END:VTIMEZONE'];
$stamp = gmdate('Ymd\THis\Z'); $f = fn($s) => str_replace(['-', ':', ' '], ['', '', 'T'], $s);
foreach (alle("SELECT id, beginn, ende FROM wartungen WHERE techniker_id = ? AND status IN ('terminiert','erledigt') AND beginn >= ? ORDER BY beginn", [$mid, date('Y-m-d', strtotime('-60 days'))]) as $w) {
    $t = termin_inhalt((int)$w['id']);
    array_push($L, 'BEGIN:VEVENT', "UID:wtg-w{$w['id']}@wtg-wartungsplaner", "DTSTAMP:$stamp", 'DTSTART;TZID=Europe/Berlin:' . $f($w['beginn']), 'DTEND;TZID=Europe/Berlin:' . $f($w['ende']),
        'SUMMARY:' . $esc($t['betreff']), 'LOCATION:' . $esc($t['ort_text']), 'DESCRIPTION:' . $esc($t['text']), 'CATEGORIES:Wartung', 'END:VEVENT');
}
$L[] = 'END:VCALENDAR';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="Wartungen_' . preg_replace('/[^A-Za-z0-9]/', '_', $name) . '.ics"');
echo (isset($_GET['dl']) ? "\xEF\xBB\xBF" : '') . implode("\r\n", array_map($fold, $L)) . "\r\n";
