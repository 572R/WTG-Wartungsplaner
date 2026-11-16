<?php
/**
 * Einstellungen für den TEST auf dem eigenen Rechner.
 * VORLAGE: wird beim Einrichten nach config.lokal.php kopiert. Eigene Schlüssel nur dort eintragen –
 * config.lokal.php wird nicht mit hochgeladen (.gitignore).
 * Überschreibt einzelne Werte aus config.php.
 */
return [
    // zeigt auf der Anmeldeseite die Test-Zugänge (Passwort jeweils "test")
    'testbetrieb' => true,

    // OpenStreetMap möchte eine Kontaktadresse im Absender. Bitte eine eigene E-Mail-Adresse eintragen.
    'osm' => ['kontakt' => 'IHRE.ADRESSE@IHRE-FIRMA.DE'],

    // Microsoft 365 – erst eintragen, wenn die App in Microsoft 365 registriert ist (siehe LIESMICH.txt, Teil E)
    'microsoft' => [
        'outlook_aktiv' => false,
        'login_aktiv'   => false,
        'tenant_id'     => '',
        'client_id'     => '',
        'client_secret' => '',
    ],
];
