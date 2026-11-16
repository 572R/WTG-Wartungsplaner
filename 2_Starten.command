#!/bin/bash
# WTG Wartungsplaner auf diesem Rechner starten
cd "$(dirname "$0")" || exit 1
[ -f config.lokal.php ] || cp config.lokal.beispiel.php config.lokal.php
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
# Läuft er schon (z. B. per Autostart)? Dann nur den Browser öffnen.
if curl -s -o /dev/null "http://localhost:8000/login.php"; then open "http://localhost:8000"; exit 0; fi
command -v php >/dev/null || { echo "PHP fehlt. Im Terminal: brew install php"; read -r _; exit 1; }
[ -f daten/wtg.db ] || { echo "Noch keine Daten. Bitte zuerst 1_Einrichten doppelklicken."; read -r _; exit 1; }
echo; echo "  WTG Wartungsplaner läuft:  http://localhost:8000"
echo "  Anmelden z. B. mit  buero / test  (weitere Zugänge stehen auf der Anmeldeseite)"
echo "  Dieses Fenster offen lassen. Beenden mit ctrl+C."
echo "  Ohne dieses Fenster: einmal \"Autostart einrichten\" doppelklicken."; echo
(sleep 1; open "http://localhost:8000") &
php -d upload_max_filesize=32M -d post_max_size=40M -S localhost:8000 router.php
