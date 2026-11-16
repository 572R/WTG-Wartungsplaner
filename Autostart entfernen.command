#!/bin/bash
# WTG Wartungsplaner: Autostart wieder entfernen (die Daten bleiben erhalten)
LABEL="de.wtg.wartungsplaner"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || launchctl unload "$PLIST" 2>/dev/null
rm -f "$PLIST"
pkill -f "php -S localhost:8000" 2>/dev/null
echo "Autostart entfernt. Der Wartungsplaner läuft nicht mehr im Hintergrund."
echo "Ihre Daten im Ordner WTG-Wartungsplaner/daten bleiben erhalten."
echo "Zum Starten von Hand wieder 2_Starten doppelklicken."
echo; read -r -p "Enter zum Schließen" _
