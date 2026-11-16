# WTG-Wartungplaner
WTG WARTUNGSPLANER – PROTOTYP ZUM TESTEN
========================================

Was der Prototyp kann
---------------------
Nach der Anmeldung sieht jeder nur seine Bereiche:

  Meine Termine   Techniker: Termine mit allen Infos (Material, Akkus, Notizen
                  mit Stand-Datum, Restarbeiten), Tagesroute auf der Karte,
                  "Abschließen" mit echter Zeit und Hinweis ans Büro
  Pflege          Büro: Kunde aufrufen, Akku-/Batterietausch, Notizen und
                  Restarbeiten eintragen – alles mit Datum und Namen, alte
                  Stände bleiben im Verlauf. Prüfliste mit allem, was fehlt
                  oder veraltet ist.
  Organisation    1. Zuweisen: offene Wartungen je Quartal, Auslastung je
                     Techniker, Vorschlag "passende Wartungen für X Std."
                  2. Touren planen: Tagestouren ab der Firma, immer zum
                     nächstgelegenen Kunden (OpenStreetMap), Gruppen bleiben
                     zusammen, Eintrag in Outlook
                  Abwesenheiten (Urlaub usw.)
  Auswertung      Fortschritt je Quartal, Stunden, Auslastung
  Verwaltung      Benutzer und Bereiche, Postfächer, Werkzeuge




A) AUF DEM MAC
--------------
Einmalig, falls noch nicht geschehen (Terminal):
    brew install php mdbtools

1. Diesen Ordner "WTG-Wartungsplaner" z. B. in den Benutzerordner legen.
2. In den Unterordner "Import" eine KOPIE der Access-Datei legen und
   (optional) die Excel-Liste von Lukas.
3. "1_Einrichten.command" doppelklicken
   (beim ersten Mal ggf. Rechtsklick -> Öffnen -> Öffnen).
   Das dauert ca. 10 Minuten, weil OpenStreetMap höchstens eine Adresse
   pro Sekunde beantwortet. Abbrechen ist möglich, es geht später weiter.
4. "2_Starten.command" doppelklicken. Der Browser öffnet sich.
5. Anmelden – alle Test-Zugänge haben das Passwort "test":
      buero   Pflege + Organisation
      chef    Auswertung + Organisation
      admin   alles
      oskar, tibor, lukas, lasse, yannik, sebastian   Meine Termine


AUTOSTART AUF DEM MAC (ohne Terminal-Fenster)
---------------------------------------------
 1. Den Ordner WTG-Wartungsplaner direkt in den Benutzerordner legen
    (nicht Schreibtisch, Dokumente, Downloads oder iCloud).
 2. "Autostart einrichten.command" doppelklicken (beim ersten Mal
    Rechtsklick -> Öffnen). Der Wartungsplaner läuft dann im Hintergrund
    und startet bei jeder Anmeldung am Mac von selbst.
 3. In Safari http://localhost:8000 -> Ablage -> Zum Dock hinzufügen.
 Rückgängig: "Autostart entfernen.command". Die Daten bleiben erhalten.

B) AUF WINDOWS
--------------
PHP wie in der früheren Anleitung nach C:\php (mit extension=zip,
pdo_sqlite, pdo_odbc, curl, mbstring, openssl) und die Microsoft Access
Database Engine (64-Bit) installieren. Dann wie oben, nur mit
"1_Einrichten.bat" und "2_Starten.bat".


C) VORSCHLAG FÜR EINEN TESTRUNDGANG
-----------------------------------
 1. Als "buero": Organisation -> Quartal wählen.
    Oben die Auslastung je Techniker.
 2. Bei einem Techniker "Std. vorschlagen" -> die passenden Wartungen sind
    blau markiert -> "Zuweisen". Oder "Stammtechniker übernehmen".
 3. "2. Touren planen" -> Techniker wählen -> "Touren vorschlagen".
    Auf der Karte die Tagestouren, darunter Tag für Tag mit Uhrzeiten und
    Fahrzeiten. Haken entfernen, was nicht passt -> "Übernehmen".
 4. Abmelden, als "oskar" anmelden: Meine Termine -> einen Termin
    "Abschließen", Zeit und einen Hinweis eintragen.
 5. Als "buero": Pflege -> Prüfliste -> der Hinweis steht unter
    "Meldungen". Kunde öffnen -> "Tausch / Änderung eintragen",
    Restarbeit oder Notiz eintragen.
 6. Organisation -> Touren -> der nächste Termin dieses Kunden enthält die
    Restarbeit, den neuen Akku und die Notiz mit Stand-Datum.
 7. Als "chef": Auswertung.
 8. Als "buero": Organisation -> Wochenplan; als "admin": Verwaltung ->
    Auswahllisten und Eigene Felder ausprobieren.


D) WAS DER IMPORT MACHT
-----------------------
 - Jede Anlage (Kunde + Anlagenart) bekommt eine feste Nummer.
 - Zeiten aus Liste werden den Anlagen zugeordnet. Abkürzungen
   wie "BS", "Gö", "WF" werden erkannt. Unsichere Zuordnungen stehen unter
   Pflege -> Prüfliste -> "Hinweise aus dem Import" zum Bestätigen.
 - Gruppen aus den zusammengefassten Zellen ("NUR zusammen terminieren")
   und aus Hinweisen wie "NUR zusammen mit Rathaus Peine".
 - Akkus aus der Access-Tabelle "Akku" (über die Straße zugeordnet).
 - Wartungen für das laufende und das nächste Jahr.
 - Zeiten enthalten die Fahrt von Firma <-> Kunde. Die App rechnet
   diese heraus und plant mit der reinen Zeit vor Ort plus den echten
   Wegen zwischen den Kunden.


E) MICROSOFT 365 (OPTIONAL)
---------------------------
Ohne Microsoft 365 funktioniert alles; Urlaub wird dann unter
Organisation -> Abwesenheiten eingetragen, die Techniker können ihre
Termine als Kalender abonnieren.

Mit Microsoft 365 (App-Registrierung wie in der früheren Anleitung, zusätzlich
für die Anmeldung):
 1. In der App-Registrierung unter "Authentifizierung" -> Plattform "Web"
    -> Umleitungs-URI: http://localhost:8000/login.php?ms=zurueck
 2. API-Berechtigungen: Microsoft Graph -> Anwendungsberechtigungen ->
    Calendars.ReadWrite (Administratorzustimmung). Für die Anmeldung
    zusätzlich delegierte Berechtigungen openid, profile, email.
 3. Zugriff per Zugriffsrichtlinie auf die Techniker-Postfächer begrenzen.
 4. In config.lokal.php eintragen: tenant_id, client_id, client_secret,
    outlook_aktiv => true, login_aktiv => true.
 5. Als "admin": Verwaltung -> bei jedem Mitarbeiter die E-Mail-Adresse
    (Postfach) eintragen, bei jedem Benutzer die E-Mail für die Anmeldung.
    "Outlook-Postfächer testen".
 Zum TESTEN bei allen Mitarbeitern die EIGENE Adresse eintragen –
 dann landen alle Testtermine nur im eigenen Kalender.


F) GRENZEN DES PROTOTYPS
------------------------
 - Getestet mit echten Daten, SQLite und Nachbauten von
   OpenStreetMap und Microsoft 365. Mit den echten Diensten noch nicht.
 - "Mit Microsoft anmelden" und MySQL sind eingebaut, aber ungetestet.
 - Eine Gruppe, die länger als ein Arbeitstag dauert, wird nicht
   automatisch auf mehrere Tage verteilt (wird als "zu lang" gemeldet).
 - Wird ein Termin in Outlook verschoben, merkt die App das nicht.
 - Der öffentliche OSRM-Server (Fahrzeiten) ist nur zum Testen gedacht.
   Für den Dauerbetrieb einen eigenen Server oder einen Anbieter nehmen
   (in config.php: osm -> osrm_url). Ist er nicht erreichbar, schätzt die
   App die Fahrzeit aus der Luftlinie.
 - Datensicherung: die gesamte Datenbank ist die Datei daten/wtg.db.
