#!/bin/bash
# WTG Wartungsplaner einrichten: Import aus Access + Excel, Adressen auf die Karte
cd "$(dirname "$0")" || exit 1
[ -f config.lokal.php ] || cp config.lokal.beispiel.php config.lokal.php
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fehler() { echo; echo "FEHLER: $1"; echo; read -r -p "Enter zum Schließen" _; exit 1; }
command -v php >/dev/null || fehler "PHP fehlt. Im Terminal: brew install php"
command -v mdb-export >/dev/null || fehler "mdbtools fehlen. Im Terminal: brew install mdbtools"
ACCESS=$(ls Import/*.accdb Import/*.mdb 2>/dev/null | head -1)
EXCEL=$(ls Import/*.xlsx 2>/dev/null | head -1)
[ -n "$ACCESS" ] || fehler "Keine Access-Datei im Ordner Import gefunden."
echo "Access: $ACCESS"; [ -n "$EXCEL" ] && echo "Excel:  $EXCEL" || echo "Excel:  keine (Zeiten aus Access)"
NEU=""
if [ -f daten/wtg.db ]; then
  read -r -p "Es gibt schon Daten. Alles neu importieren? Geplante Termine gehen verloren. (j/n) " a
  [ "$a" = "j" ] || { echo "Abgebrochen."; exit 0; }
  NEU="--neu"
fi
STAND=$(echo "$EXCEL" | grep -oE '[0-9]{2}\.[0-9]{2}\.[0-9]{2}' | head -1 | awk -F. '{print "20"$3"-"$2"-"$1}')
echo; php werkzeuge/import.php --access="$ACCESS" ${EXCEL:+--excel="$EXCEL"} ${STAND:+--excel-stand=$STAND} $NEU --testzugaenge || fehler "Import fehlgeschlagen (siehe oben)."
echo; echo "Adressen werden auf der Karte gesucht (OpenStreetMap, ca. 1 Sekunde je Adresse – etwa 10 Minuten)."
echo "Abbrechen ist jederzeit möglich; beim nächsten Mal geht es dort weiter."
php werkzeuge/geokodieren.php
echo; echo "Fertig. Jetzt 2_Starten doppelklicken."; read -r -p "Enter zum Schließen" _
