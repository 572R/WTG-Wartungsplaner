#!/bin/bash
# WTG Wartungsplaner: startet ab jetzt automatisch im Hintergrund, sobald Sie sich am Mac anmelden.
# Danach einfach http://localhost:8000 öffnen (oder das Dock-Symbol, siehe unten).
cd "$(dirname "$0")" || exit 1
APP="$(pwd)"
LABEL="de.wtg.wartungsplaner"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
ende() { echo; read -r -p "Enter zum Schließen" _; exit "${1:-0}"; }

echo "WTG Wartungsplaner – Autostart einrichten"
echo "Ordner: $APP"; echo

# 1. Prüfungen
[ -f "$APP/router.php" ] && [ -f "$APP/index.php" ] || { echo "FEHLER: Diese Datei muss im Ordner WTG-Wartungsplaner liegen (neben 2_Starten)."; ende 1; }
[ -f "$APP/daten/wtg.db" ] || { echo "FEHLER: Noch keine Daten. Bitte zuerst 1_Einrichten doppelklicken."; ende 1; }
case "$APP" in
  "$HOME/Desktop"*|"$HOME/Documents"*|"$HOME/Downloads"*|"$HOME/Library/Mobile Documents"*|*"/iCloud"*)
    echo "FEHLER: Der Ordner liegt auf dem Schreibtisch, in Dokumente, Downloads oder iCloud."
    echo "Dort darf macOS Hintergrunddiensten keinen Zugriff geben."
    echo
    echo "Bitte den Ordner WTG-Wartungsplaner direkt in Ihren Benutzerordner verschieben"
    echo "(Finder → Gehe zu → Benutzerordner, oder Taste ⇧⌘H) und diese Datei dort erneut doppelklicken."
    ende 1;;
esac
PHP="$(command -v php 2>/dev/null)"
for p in /opt/homebrew/bin/php /usr/local/bin/php; do [ -z "$PHP" ] && [ -x "$p" ] && PHP="$p"; done
[ -n "$PHP" ] || { echo "FEHLER: PHP nicht gefunden. Im Terminal: brew install php"; ende 1; }

# 2. Laufenden Server beenden (z. B. aus 2_Starten), alten Autostart entfernen
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || launchctl unload "$PLIST" 2>/dev/null
pkill -f "php -S localhost:8000" 2>/dev/null
sleep 1

# 3. Hintergrunddienst anlegen
mkdir -p "$HOME/Library/LaunchAgents"
xml() { printf '%s' "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'; }
cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>$(xml "$PHP")</string>
    <string>-d</string><string>upload_max_filesize=32M</string>
    <string>-d</string><string>post_max_size=40M</string>
    <string>-S</string>
    <string>localhost:8000</string>
    <string>router.php</string>
  </array>
  <key>WorkingDirectory</key><string>$(xml "$APP")</string>
  <key>EnvironmentVariables</key>
  <dict><key>PATH</key><string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string></dict>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>StandardOutPath</key><string>$(xml "$APP/daten/server.log")</string>
  <key>StandardErrorPath</key><string>$(xml "$APP/daten/server.log")</string>
</dict>
</plist>
PLIST
plutil -lint "$PLIST" >/dev/null 2>&1 || { echo "FEHLER: Die Startdatei ist ungültig: $PLIST"; ende 1; }

# 4. Starten
launchctl bootstrap "gui/$(id -u)" "$PLIST" 2>/dev/null || launchctl load -w "$PLIST"
for i in 1 2 3 4 5 6 7 8 9 10; do
  curl -s -o /dev/null "http://localhost:8000/login.php" && break; sleep 1
done
if curl -s -o /dev/null "http://localhost:8000/login.php"; then
  echo "Fertig. Der Wartungsplaner läuft jetzt im Hintergrund und startet"
  echo "ab sofort automatisch, wenn Sie sich am Mac anmelden."
  echo
  echo "Adresse:  http://localhost:8000"
  echo
  echo "Tipp – Symbol im Dock (macOS Sonoma oder neuer):"
  echo "  Safari öffnet sich gleich. Dort Menü  Ablage → Zum Dock hinzufügen."
  echo "  Danach startet ein Klick auf das Symbol den Wartungsplaner wie ein Programm."
  echo "  (Ältere macOS-Versionen: in Safari ein Lesezeichen anlegen.)"
  open -a Safari "http://localhost:8000" 2>/dev/null || open "http://localhost:8000"
else
  echo "Der Dienst wurde angelegt, antwortet aber noch nicht."
  echo "Hinweise stehen in: $APP/daten/server.log"
  tail -5 "$APP/daten/server.log" 2>/dev/null
fi
echo
echo "Wichtig: Wird der Ordner verschoben, diese Datei danach einfach erneut doppelklicken."
ende 0
