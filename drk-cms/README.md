# DRK-CMS für Ortsvereine

Ein schlankes Content-Management-System für DRK-Ortsvereine: öffentliche Website plus interne Verwaltung für Mitglieder, Unterstützer und den Blutspendedienst.

* **PHP 8.1+**, keine Frameworks, keine externen Abhängigkeiten
* **SQLite** (Standard, keine Einrichtung nötig) oder **MySQL/MariaDB**
* Läuft auf praktisch jedem Webhosting-Paket (Apache mit `.htaccess`)

---

## Funktionen

### Website
| | |
|---|---|
| **Seiten & Bereiche** | Hauptseiten bilden die Menüpunkte, Unterseiten erscheinen im Aufklappmenü (z. B. *Über uns › Bereitschaft, Jugendrotkreuz, Vorstand*). |
| **3 Layouts** | *Standard* (Lesebreite), *Breit* (z. B. Startseite), *Mit Seitenleiste* (Navigation innerhalb eines Bereichs) |
| **Kopfbild & Akzentfarbe** | pro Seite wählbar |
| **15 Bausteine** | Text · Bild & Text · Bild · Zwei Spalten · Kacheln · Unterseiten als Kacheln · Hinweisbox · Schaltfläche · Fragen & Antworten · Ansprechpartner/in · Zahlen & Fakten · **Blutspendetermine (automatisch)** · **Unterstützer/Sponsoren (automatisch)** · **Aktuelles/News (automatisch)** · Adresse/Anfahrt |
| **Einfache Formatierung** | `## Überschrift`, `**fett**`, `*kursiv*`, `- Liste`, `[Link](https://…)` – kein HTML nötig |
| **Aktuelles / News** | Meldungen mit Bild, Kurzfassung, Bereich und Autor/in; wichtige Meldungen oben anheften; Veröffentlichung im Voraus planen (erscheint erst ab dem Datum); Archivseite mit Seitenzahlen; Filter nach Bereich (z. B. nur JRK-Meldungen auf der JRK-Seite); RSS-Feed unter `feed.php` |
| **Entwürfe & Vorschau** | Unveröffentlichte Seiten sind nur für angemeldete Redakteure sichtbar |
| **Mobilfreundlich** | responsives Layout mit Menü-Schaltfläche |

### Verwaltung (interner Bereich unter `admin.php`)
| Modul | Inhalt |
|---|---|
| **Aktuelles** | Meldungen schreiben, planen, anheften, als Entwurf speichern, durchsuchen |
| **Mitglieder** | Stammdaten, Status (aktiv, fördernd, passiv, JRK, ausgetreten), Bereiche, Funktion, Datenschutz-Einwilligung, Filter nach Bereich und Qualifikation, CSV-Export (Excel-tauglich) |
| **Profile** | Qualifikationen (Sanitäter, Hygienebelehrung …), Verfügbarkeit, „Über mich“. Helfer/innen pflegen ihr Profil unter *Mein Profil* selbst. |
| **Unterstützer** | Privatpersonen, Firmen, Stiftungen, Art und Höhe der Unterstützung, Logo, optionale Nennung auf der Website |
| **Blutspende – Termine** | Datum, Ort, erwartete und tatsächliche Spender/innen, Erstspender, Jahresstatistik |
| **Blutspende – Menü** | Rezepte mit Portionen pro Termin einplanen, Menü früherer Termine übernehmen |
| **Blutspende – Einkaufsliste** | wird **automatisch** aus Menü und Portionen berechnet, gleiche Zutaten werden zusammengefasst (g/kg, ml/l), nach Abteilungen sortiert, druckbar mit Abhak-Kästchen |
| **Blutspende – Personaleinteilung** | Flexibles Schichtsystem (siehe unten): feste und flexible Schichten, persönliche Zeiten im 15-Minuten-Raster, mehrere Aufgaben pro Person, Zeitplan mit Lücken, Ablauf pro Person, druckbarer Dienstplan |
| **Blutspende – Helfer-Profile** | Profile direkt hier anlegen, bearbeiten (inkl. Foto), archivieren und wiederherstellen; vorhandene Mitglieder per Auswahl ins Team aufnehmen; Löschen nur mit Rolle *Verwaltung*. Übersicht aller Helfer/innen des Blutspende-Teams als Profilkarten: Foto, Kontakt, Qualifikationen, Verfügbarkeit, Anzahl Einsätze und nächster Einsatz. Filter nach Qualifikation, Detailansicht mit Einsatzhistorie und häufigsten Aufgaben, druckbare Telefonliste |
| **Blutspende – Stellenbeschreibungen** | Aufgaben direkt in der Übersicht bearbeiten, neu anlegen, kopieren, archivieren, wiederherstellen und löschen. Jede Aufgabe kann als *Standard-Schicht* markiert werden (Uhrzeit und Personenzahl) und wird dann bei neuen Terminen automatisch eingeplant. Beschreibung jeder Aufgabe (Anmeldung, Küche, Ruheraum …): Kurzbeschreibung, Aufgaben, abhakbare Checkliste, Anforderungen, erforderliche Qualifikation, Zeitaufwand, Ansprechpartner/in und wichtige Hinweise (z. B. Hygiene). 7 Vorlagen passend zu den Standard-Schichten. Automatisch mit ⓘ in Personaleinteilung und Selbst-Eintragung verlinkt, einzeln oder als Einweisungsmappe druckbar. Helfer/innen dürfen lesen, bearbeiten nur das Blutspende-Team |
| **Ehrenamtsstunden** | Nach dem Dienst Ist-Zeiten je Einteilung erfassen (oder „wie geplant übernehmen“), Nicht-Erscheinen markieren, spontane Hilfe nachtragen. Jahresauswertung pro Person, CSV-Export, Druckliste und **Bescheinigung über ehrenamtliche Tätigkeit** |
| **Selbst-Eintragung** | Helfer/innen tragen sich unter *Mein Profil* selbst in offene Schichten ein |
| **Rezepte** | Zutaten mit Menge, Einheit, Einkaufsabteilung, Allergene, vegetarisch, Zubereitung, Erfahrungs-Notizen |
| **Bilder & Dateien** | Upload von JPG/PNG/GIF/WebP/PDF, große Handyfotos werden automatisch verkleinert |
| **Benutzer & Rollen** | siehe unten |
| **Protokoll** | wer hat wann Mitgliederdaten geändert oder exportiert |
| **Übersicht** | nächste Termine mit Besetzungsstand, Geburtstage der nächsten 14 Tage, eigene Einsätze |

### Rollen
| Rolle | darf |
|---|---|
| `admin` | alles, inkl. Benutzer und Einstellungen |
| `redaktion` | Seiten, Aktuelles und Medien |
| `verwaltung` | Mitglieder und Unterstützer |
| `blutspende` | Termine, Rezepte, Menü, Einkauf, Personaleinteilung, Helfer-Profile (nur lesen) |
| `helfer` | eigenes Profil, Selbst-Eintragung in Schichten, Stellenbeschreibungen lesen |

Ein Benutzer kann mehrere Rollen haben. Wird ein Benutzerkonto mit einem Mitgliederprofil verknüpft, sieht die Person ihre eigenen Einsätze.

### Schichtsystem Blutspende

| Begriff | Bedeutung |
|---|---|
| **Schicht** | Aufgabe mit Zeitfenster (z. B. *Anmeldung 15:30–19:30*) und Bedarf = wie viele Personen **gleichzeitig** gebraucht werden |
| **feste Schicht** | alle Eingeteilten arbeiten die ganze Schichtzeit (z. B. Aufbau, Abbau) |
| **flexible Schicht** | jede Einteilung hat eigene Zeiten innerhalb des Zeitfensters, im 15-Minuten-Raster (z. B. Anna 15:30–17:30, Gül 17:30–19:30) |
| **Einteilung** | eine Person in einer Schicht. Eine Person kann mehrere Einteilungen haben (z. B. erst Anmeldung, dann Imbiss). Überschneidungen werden mit ⚠ markiert |
| **Besetzung** | wird je 15 Minuten berechnet. „x / y besetzt“ heißt: x Plätze sind über die ganze Schichtzeit lückenlos besetzt. Lücken werden mit Uhrzeit angezeigt |
| **Ist-Zeiten** | nach dem Dienst erfasste tatsächliche Zeiten → Grundlage der Ehrenamtsstunden (zeitgleiche Einsätze zählen nur einmal) |

Ob eine Aufgabe fest oder flexibel ist, wird in der Stellenbeschreibung (für neue Termine) oder direkt an der Schicht festgelegt.

---

## Installation

1. **Dateien hochladen:** Den Inhalt des Ordners `drk-cms/` per FTP in das Webverzeichnis kopieren.
2. **Schreibrechte:** Die Ordner `data/` und `uploads/` müssen für den Webserver beschreibbar sein.
3. **Aufrufen:** `https://ihre-domain.de/admin.php` öffnen. Der Einrichtungsassistent fragt nach dem Vereinsnamen und legt das erste Administratorkonto an. Beispielseiten, -rezepte und eine Schicht-Vorlage werden automatisch erstellt.
4. **Anpassen:** Unter *Einstellungen* Kontaktdaten, Bereiche, Qualifikationen und das Logo hinterlegen. **Impressum und Datenschutzerklärung ausfüllen!**

### Optional: MySQL statt SQLite
`config.sample.php` nach `config.php` kopieren und die MySQL-Zeilen aktivieren. Die Tabellen werden beim ersten Aufruf automatisch angelegt.

### Optional: schöne Adressen
Wenn der Server `mod_rewrite` unterstützt, in `config.php` `'pretty_urls' => true` setzen. Dann lauten die Adressen `/blutspende` statt `/index.php?seite=blutspende`.

### Update einer bestehenden Installation
Neue Tabellen (z. B. `news`) und neue Spalten (z. B. das Profilfoto bei Mitgliedern) werden beim nächsten Aufruf automatisch angelegt. Beispielseiten werden nur bei der Ersteinrichtung erstellt. Stellenbeschreibungen lassen sich unter *Blutspende › Stellenbeschreibungen* per Knopfdruck aus Vorlagen anlegen. Bei bereits vorhandenen Aufgaben einmal „Bei neuen Blutspendeterminen automatisch als Schicht anlegen“ anhaken – bis dahin gilt die alte Schicht-Vorlage aus den Einstellungen. Für den Newsbereich einmalig eine Seite „Aktuelles“ anlegen und den Baustein **Aktuelles / News** mit „Blättern erlauben: Ja“ hinzufügen. Auf der Startseite zeigt ein zweiter News-Baustein (z. B. 3 Kacheln) die neuesten Meldungen.

### Lokal ausprobieren
```bash
cd drk-cms
php -S localhost:8000
# Browser: http://localhost:8000/admin.php
```

### Tests
```bash
python3 tests/smoke_test.py
```
Der Test startet einen eigenen PHP-Server mit leerer Datenbank und prüft Einrichtung, alle Bausteine, Aktuelles (inkl. Planung, Entwürfe, Archiv, RSS), Mitglieder, Unterstützer, Rezepte, Einkaufslisten-Berechnung, Personaleinteilung, Selbst-Eintragung, Rollenrechte, CSRF- und XSS-Schutz.

---

## Sicherheit & Datenschutz

Im System umgesetzt:
* Passwörter mit `password_hash` (bcrypt/argon), mindestens 10 Zeichen
* CSRF-Token bei allen Formularen, alle Ausgaben werden maskiert (XSS-Schutz), alle Datenbankabfragen mit Prepared Statements
* Rollenbasierte Rechte, Sperre interner Ordner per `.htaccess`, keine Skriptausführung in `uploads/`
* Hochgeladene Dateien werden am Inhalt geprüft und erhalten zufällige Namen
* Änderungsprotokoll für personenbezogene Daten, CSV-Export ist gegen Formel-Injection in Excel geschützt
* Feld „Datenschutz-Einwilligung am“ beim Mitglied, Website-Nennung von Unterstützern nur per Opt-in

**Vom Verein zu erledigen:**
* **HTTPS** beim Hoster aktivieren (Pflicht für den Login)
* **Auftragsverarbeitungsvertrag (AVV)** mit dem Webhoster abschließen
* **Regelmäßige Backups** von `data/` (bzw. der MySQL-Datenbank) und `uploads/`
* Mitgliederdaten nur so lange speichern wie nötig (Aufbewahrungsfristen beachten), ausgetretene Mitglieder nach Fristablauf löschen
* Datenschutzerklärung und Impressum mit dem Kreis-/Landesverband abstimmen
* Für Logo und Gestaltung die Vorgaben des DRK-Markenportals beachten

---

## Aufbau

```
drk-cms/
├── index.php              Öffentliche Website
├── admin.php              Verwaltung (Router, Login, Ersteinrichtung)
├── feed.php               RSS-Feed „Aktuelles“
├── config.sample.php      Beispielkonfiguration
├── assets/                CSS und JavaScript
├── templates/             HTML-Gerüste für Website und Verwaltung
├── src/
│   ├── bootstrap.php      Start, Sitzung, Sicherheits-Header
│   ├── db.php             Datenbankverbindung & Tabellen
│   ├── helpers.php        Hilfsfunktionen, Formulare, CSRF
│   ├── auth.php           Anmeldung & Rollen
│   ├── markdown.php       sichere Textformatierung
│   ├── blocks.php         Seitenbausteine (hier neue Bausteine ergänzen)
│   ├── blutspende.php     Einkaufslisten-Berechnung, Schichtvorlagen
│   ├── news.php           Aktuelles: Listen, Kurzfassung, Archiv
│   ├── setup.php          Startinhalte bei der Ersteinrichtung
│   └── admin/             ein Modul pro Menüpunkt
├── data/                  SQLite-Datenbank (geschützt)
├── uploads/               hochgeladene Bilder/PDFs
└── tests/smoke_test.py    End-to-End-Test
```

**Neuen Baustein hinzufügen:** In `src/blocks.php` in `block_types()` Felder definieren und in `render_block()` die HTML-Ausgabe ergänzen.

**Neues Datenbankfeld:** In `src/db.php` in der Tabellendefinition und in der Liste `$added` ergänzen. Bestehende Installationen erhalten die Spalte dann automatisch.
