<?php
/**
 * WTG Wartungsplaner – Einstellungen
 * Für einen Test auf dem eigenen Rechner werden einzelne Werte in config.lokal.php überschrieben.
 */
return [
    // --- Datenbank: SQLite-Datei (Test) oder MySQL (Webhoster) ---
    'db_dsn'  => 'sqlite:' . __DIR__ . '/daten/wtg.db',
    'db_user' => '',
    'db_pass' => '',

    // Geheimer Schlüssel (lange Zufallsfolge) für Kalender-Links und Sitzungen
    'geheimer_schluessel' => 'BITTE-AENDERN-lange-zufaellige-zeichenfolge-1234567890',

    // Testbetrieb: zeigt auf der Anmeldeseite die Test-Zugänge an
    'testbetrieb' => false,

    // --- Firma = Start und Ende jeder Tour ---
    'firma' => ['name' => 'Firma', 'strasse' => 'Scharrnstraße 2', 'plz' => '38100', 'ort' => 'Braunschweig'],

    // --- Arbeitszeiten je Wochentag (1 = Montag … 7 = Sonntag) ---
    'arbeitszeiten' => [
        1 => ['08:00', '16:00'], 2 => ['08:00', '16:00'], 3 => ['08:00', '16:00'],
        4 => ['08:00', '16:00'], 5 => ['08:00', '13:00'],
    ],
    'bundesland_feiertage' => 'NI',   // Niedersachsen

    // --- Planung ---
    'max_anteil_wartung' => 0.6,  // höchstens 60 % der Arbeitszeit einer Woche mit Wartungen verplanen
    'standard_zeit_h'    => 2.0,  // wenn keine Zeit hinterlegt ist (inkl. Fahrt, wie Lukas' Liste)
    'min_vor_ort_min'    => 30,   // reine Arbeitszeit vor Ort mindestens
    'raster_min'         => 15,   // Termine beginnen auf volle 15 Minuten
    'erinnerung_min'     => 1440, // Outlook-Erinnerung (Minuten vorher), 0 = keine

    // --- Hinweise im Termin ---
    'akku_tausch_jahre'  => 4,    // Akku älter als … Jahre -> "tauschen"
    'notiz_alter_jahre'  => 3,    // Notiz älter als … Jahre -> "bitte prüfen"

    // --- OpenStreetMap ---
    'osm' => [
        // Adressen -> Koordinaten (Nominatim). Öffentlicher Dienst: max. 1 Anfrage pro Sekunde.
        'nominatim_url' => 'https://nominatim.openstreetmap.org',
        // Fahrzeiten (OSRM). Der öffentliche Demo-Server ist nur für Tests gedacht;
        // für den Dauerbetrieb einen eigenen OSRM-Server oder einen Anbieter eintragen.
        'osrm_url'      => 'https://router.project-osrm.org',
        // Pflicht bei Nominatim: Kontakt-E-Mail im Absender
        'kontakt'       => 'it@ihre-firma.de',
        // Rückfall ohne Routing: Luftlinie × Umwegfaktor bei Durchschnittstempo
        'umwegfaktor'   => 1.3,
        'kmh'           => 50,
        // Kartenkacheln für die Karten in der App
        'kacheln'       => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    ],

    // --- Microsoft 365 (Outlook-Kalender + Anmeldung) ---
    'microsoft' => [
        'outlook_aktiv' => false,  // Kalender lesen und Termine eintragen
        'login_aktiv'   => false,  // "Mit Microsoft anmelden"
        'tenant_id'     => '',
        'client_id'     => '',
        'client_secret' => '',
        'belegt_status' => ['busy', 'oof', 'tentative'],
        'kategorie'     => 'Wartung',
    ],

    // Auswertung pro Person anzeigen (sonst nur Team-Summen). Bei Betriebsrat bitte vorher abstimmen.
    'auswertung_pro_person' => true,
];
