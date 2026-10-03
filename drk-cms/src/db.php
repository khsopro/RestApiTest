<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $pdo = new PDO((string)cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if (db_driver($pdo) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    migrate($pdo);
    return $pdo;
}

function db_driver(?PDO $pdo = null): string
{
    return ($pdo ?? db())->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/**
 * Legt fehlende Tabellen an. Neue Tabellen einfach unten ergänzen –
 * vorhandene Daten bleiben erhalten.
 */
function migrate(PDO $pdo): void
{
    $sqlite = db_driver($pdo) === 'sqlite';
    $id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $end = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $ref = $sqlite ? 'INTEGER' : 'INT';

    $tables = [
        'einstellungen' => "schluessel VARCHAR(100) PRIMARY KEY, wert TEXT",

        'benutzer' => "id $id,
            benutzername VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(200),
            email VARCHAR(200),
            passwort_hash VARCHAR(255) NOT NULL,
            rollen VARCHAR(200) NOT NULL DEFAULT '',
            mitglied_id $ref NULL,
            aktiv INTEGER NOT NULL DEFAULT 1,
            letzter_login VARCHAR(20),
            erstellt VARCHAR(20)",

        'medien' => "id $id,
            dateiname VARCHAR(255) NOT NULL,
            original VARCHAR(255),
            mime VARCHAR(100),
            groesse INTEGER,
            alt_text VARCHAR(255),
            erstellt VARCHAR(20)",

        'seiten' => "id $id,
            parent_id $ref NULL,
            titel VARCHAR(200) NOT NULL,
            menue_titel VARCHAR(100),
            slug VARCHAR(190) NOT NULL UNIQUE,
            im_menue INTEGER NOT NULL DEFAULT 1,
            sortierung INTEGER NOT NULL DEFAULT 0,
            layout VARCHAR(30) NOT NULL DEFAULT 'standard',
            farbe VARCHAR(30) NOT NULL DEFAULT 'rot',
            hero_bild $ref NULL,
            hero_text TEXT,
            beschreibung TEXT,
            veroeffentlicht INTEGER NOT NULL DEFAULT 1,
            ist_startseite INTEGER NOT NULL DEFAULT 0,
            erstellt VARCHAR(20),
            aktualisiert VARCHAR(20)",

        'seiten_bloecke' => "id $id,
            seite_id $ref NOT NULL,
            sortierung INTEGER NOT NULL DEFAULT 0,
            typ VARCHAR(40) NOT NULL,
            daten TEXT,
            FOREIGN KEY (seite_id) REFERENCES seiten(id) ON DELETE CASCADE",

        'mitglieder' => "id $id,
            mitgliedsnummer VARCHAR(50),
            anrede VARCHAR(30),
            vorname VARCHAR(100) NOT NULL,
            nachname VARCHAR(100) NOT NULL,
            geburtsdatum VARCHAR(10),
            strasse VARCHAR(200),
            plz VARCHAR(10),
            ort VARCHAR(100),
            telefon VARCHAR(50),
            mobil VARCHAR(50),
            email VARCHAR(200),
            eintritt VARCHAR(10),
            austritt VARCHAR(10),
            status VARCHAR(30) NOT NULL DEFAULT 'aktiv',
            bereiche VARCHAR(255),
            funktion VARCHAR(200),
            qualifikationen TEXT,
            verfuegbarkeit TEXT,
            profil_text TEXT,
            foto $ref NULL,
            bs_archiviert INTEGER NOT NULL DEFAULT 0,
            datenschutz_einwilligung VARCHAR(10),
            notizen TEXT,
            erstellt VARCHAR(20),
            aktualisiert VARCHAR(20)",

        'unterstuetzer' => "id $id,
            typ VARCHAR(30) NOT NULL DEFAULT 'Privatperson',
            name VARCHAR(200) NOT NULL,
            ansprechpartner VARCHAR(200),
            strasse VARCHAR(200),
            plz VARCHAR(10),
            ort VARCHAR(100),
            telefon VARCHAR(50),
            email VARCHAR(200),
            webseite VARCHAR(255),
            art VARCHAR(100),
            betrag REAL,
            seit VARCHAR(10),
            oeffentlich INTEGER NOT NULL DEFAULT 0,
            logo $ref NULL,
            notizen TEXT,
            erstellt VARCHAR(20),
            aktualisiert VARCHAR(20)",

        'bs_termine' => "id $id,
            datum VARCHAR(10) NOT NULL,
            beginn VARCHAR(5),
            ende VARCHAR(5),
            ort VARCHAR(200) NOT NULL,
            adresse VARCHAR(255),
            erwartete_spender INTEGER,
            tatsaechliche_spender INTEGER,
            erstspender INTEGER,
            oeffentlich INTEGER NOT NULL DEFAULT 1,
            hinweis TEXT,
            notizen TEXT,
            erstellt VARCHAR(20)",

        'rezepte' => "id $id,
            name VARCHAR(200) NOT NULL,
            kategorie VARCHAR(100),
            portionen INTEGER NOT NULL DEFAULT 10,
            zubereitung TEXT,
            allergene VARCHAR(255),
            vegetarisch INTEGER NOT NULL DEFAULT 0,
            notizen TEXT,
            erstellt VARCHAR(20),
            aktualisiert VARCHAR(20)",

        'rezept_zutaten' => "id $id,
            rezept_id $ref NOT NULL,
            sortierung INTEGER NOT NULL DEFAULT 0,
            menge REAL,
            einheit VARCHAR(30),
            name VARCHAR(200) NOT NULL,
            abteilung VARCHAR(100),
            FOREIGN KEY (rezept_id) REFERENCES rezepte(id) ON DELETE CASCADE",

        'bs_menue' => "id $id,
            termin_id $ref NOT NULL,
            rezept_id $ref NOT NULL,
            portionen INTEGER NOT NULL DEFAULT 10,
            FOREIGN KEY (termin_id) REFERENCES bs_termine(id) ON DELETE CASCADE,
            FOREIGN KEY (rezept_id) REFERENCES rezepte(id) ON DELETE CASCADE",

        'bs_schichten' => "id $id,
            termin_id $ref NOT NULL,
            aufgabe VARCHAR(100) NOT NULL,
            von VARCHAR(5),
            bis VARCHAR(5),
            benoetigt INTEGER NOT NULL DEFAULT 1,
            qualifikation VARCHAR(100),
            flexibel INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (termin_id) REFERENCES bs_termine(id) ON DELETE CASCADE",

        'bs_einteilung' => "id $id,
            schicht_id $ref NOT NULL,
            mitglied_id $ref NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'zugesagt',
            von VARCHAR(5),
            bis VARCHAR(5),
            ist_von VARCHAR(5),
            ist_bis VARCHAR(5),
            nicht_erschienen INTEGER NOT NULL DEFAULT 0,
            erfasst_am VARCHAR(20),
            erfasst_von VARCHAR(100),
            FOREIGN KEY (schicht_id) REFERENCES bs_schichten(id) ON DELETE CASCADE,
            FOREIGN KEY (mitglied_id) REFERENCES mitglieder(id) ON DELETE CASCADE",

        'news' => "id $id,
            titel VARCHAR(200) NOT NULL,
            slug VARCHAR(190) NOT NULL UNIQUE,
            datum VARCHAR(10) NOT NULL,
            kategorie VARCHAR(100),
            teaser TEXT,
            inhalt TEXT,
            bild $ref NULL,
            autor VARCHAR(200),
            angeheftet INTEGER NOT NULL DEFAULT 0,
            veroeffentlicht INTEGER NOT NULL DEFAULT 1,
            erstellt VARCHAR(20),
            aktualisiert VARCHAR(20)",

        'bs_stellen' => "id $id,
            titel VARCHAR(100) NOT NULL,
            kurz VARCHAR(255),
            aufgaben TEXT,
            ablauf TEXT,
            anforderungen TEXT,
            qualifikation VARCHAR(100),
            zeitaufwand VARCHAR(100),
            hinweise TEXT,
            ansprechpartner VARCHAR(200),
            standard INTEGER NOT NULL DEFAULT 0,
            std_von VARCHAR(10),
            std_bis VARCHAR(10),
            std_anzahl INTEGER NOT NULL DEFAULT 1,
            std_flexibel INTEGER NOT NULL DEFAULT 1,
            archiviert INTEGER NOT NULL DEFAULT 0,
            sortierung INTEGER NOT NULL DEFAULT 0,
            aktualisiert VARCHAR(20)",

        'protokoll' => "id $id,
            zeit VARCHAR(20) NOT NULL,
            benutzer VARCHAR(100),
            aktion VARCHAR(200),
            objekt VARCHAR(200)",
    ];

    foreach ($tables as $name => $cols) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($cols)$end");
    }

    // Spalten, die nach Version 1.0 hinzugekommen sind (für bestehende Installationen)
    $added = [
        ['mitglieder', 'foto', "$ref NULL"],
        ['mitglieder', 'bs_archiviert', 'INTEGER NOT NULL DEFAULT 0'],
        ['bs_stellen', 'standard', 'INTEGER NOT NULL DEFAULT 0'],
        ['bs_stellen', 'std_von', 'VARCHAR(10)'],
        ['bs_stellen', 'std_bis', 'VARCHAR(10)'],
        ['bs_stellen', 'std_anzahl', 'INTEGER NOT NULL DEFAULT 1'],
        ['bs_stellen', 'archiviert', 'INTEGER NOT NULL DEFAULT 0'],
        ['bs_stellen', 'std_flexibel', 'INTEGER NOT NULL DEFAULT 1'],
        ['bs_schichten', 'flexibel', 'INTEGER NOT NULL DEFAULT 1'],
        ['bs_einteilung', 'von', 'VARCHAR(5)'],
        ['bs_einteilung', 'bis', 'VARCHAR(5)'],
        ['bs_einteilung', 'ist_von', 'VARCHAR(5)'],
        ['bs_einteilung', 'ist_bis', 'VARCHAR(5)'],
        ['bs_einteilung', 'nicht_erschienen', 'INTEGER NOT NULL DEFAULT 0'],
        ['bs_einteilung', 'erfasst_am', 'VARCHAR(20)'],
        ['bs_einteilung', 'erfasst_von', 'VARCHAR(100)'],
    ];
    foreach ($added as [$table, $column, $definition]) {
        $existing = $sqlite
            ? array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name')
            : array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
        if (!in_array($column, $existing, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
}
