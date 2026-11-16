<?php
// Türsteher für den eingebauten PHP-Webserver (php -S): sperrt Daten, Einstellungen und interne Ordner.
// Auf einem richtigen Webserver (Apache) übernimmt das die .htaccess.
$pfad = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#(^|/)(inc|daten|werkzeuge|Import)(/|$)#i', $pfad) || preg_match('#(^|/)(config(\.lokal)?\.php|router\.php)$#i', $pfad)
    || preg_match('#\.(db|sqlite|log|txt|md|accdb|mdb|xlsx|command|bat)$#i', $pfad) || str_contains($pfad, '..')) {
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit("Kein Zugriff.\n");
}
return false; // alles andere normal ausliefern
