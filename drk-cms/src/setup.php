<?php
declare(strict_types=1);

/** Wird bei der Ersteinrichtung einmalig ausgeführt und legt Startinhalte an. */
function seed_defaults(string $vereinsname): void
{
    $settings = [
        'seitentitel'   => $vereinsname,
        'untertitel'    => 'Aus Liebe zum Menschen.',
        'kontakt_text'  => "DRK-Ortsverein\nMusterstraße 1\n12345 Musterstadt\n\nTelefon: 01234 567890\nE-Mail: info@drk-musterstadt.de",
        'fusszeile'     => 'Deutsches Rotes Kreuz – Ortsverein',
        'notruf_hinweis' => 'Im Notfall: 112',
        'bereiche'      => "Vorstand\nBereitschaft\nJugendrotkreuz\nBlutspende\nWohlfahrts- und Sozialarbeit\nSeniorenarbeit\nAusbildung",
        'qualifikationen' => "Erste-Hilfe-Kurs\nSanitätshelfer/in\nSanitäter/in\nRettungssanitäter/in\nNotfallsanitäter/in\nErste-Hilfe-Ausbilder/in\nBetreuungsdienst\nFührerschein C1\nHygienebelehrung (§ 43 IfSG)\nJugendgruppenleiter/in (Juleica)",
        'bs_standard_schichten' => "Aufbau | 13:30 | beginn | 4 |\nAnmeldung | beginn | ende | 2 |\nArztzimmer/Labor-Unterstützung | beginn | ende | 1 | Sanitätshelfer/in\nRuheraum/Betreuung | beginn | ende | 2 | Erste-Hilfe-Kurs\nKüche | 13:00 | ende | 3 | Hygienebelehrung (§ 43 IfSG)\nImbiss-Ausgabe | beginn | ende | 2 | Hygienebelehrung (§ 43 IfSG)\nAbbau | ende | 21:00 | 4 |",
        'rezept_kategorien' => "Salat\nWarmes Gericht\nBelegte Brötchen\nDessert\nKuchen\nGetränke",
        'einkauf_abteilungen' => "Obst & Gemüse\nBrot & Backwaren\nKühlregal\nFleisch & Wurst\nKäse\nTrockenware\nKonserven\nGewürze & Öl\nGetränke\nTiefkühl\nVerbrauchsmaterial",
    ];
    foreach ($settings as $k => $v) {
        set_setting($k, $v);
    }

    $page = function (string $titel, string $slug, ?int $parent, int $sort, array $blocks, array $extra = []): int {
        $id = insert('seiten', $extra + [
            'parent_id' => $parent, 'titel' => $titel, 'slug' => $slug, 'sortierung' => $sort,
            'im_menue' => 1, 'layout' => 'standard', 'farbe' => 'rot', 'veroeffentlicht' => 1,
            'ist_startseite' => 0, 'erstellt' => now(), 'aktualisiert' => now(),
        ]);
        foreach ($blocks as $i => [$typ, $daten]) {
            insert('seiten_bloecke', ['seite_id' => $id, 'sortierung' => $i, 'typ' => $typ, 'daten' => json_encode($daten, JSON_UNESCAPED_UNICODE)]);
        }
        return $id;
    };

    $page('Willkommen', 'start', null, 0, [
        ['kacheln', ['titel' => 'Unsere Bereiche', 'eintraege' => "Bereitschaft | Sanitätsdienste, Katastrophenschutz und Betreuung bei Veranstaltungen. | bereitschaft\nJugendrotkreuz | Erste Hilfe lernen, Gemeinschaft erleben und sich für andere einsetzen. | jugendrotkreuz\nBlutspende | Jede Spende kann bis zu drei Leben retten. Hier finden Sie unsere Termine. | blutspende\nMitmachen | Werden Sie aktives Mitglied, Fördermitglied oder unterstützen Sie uns. | mitmachen"]],
        ['news', ['titel' => 'Aktuelles', 'anzahl' => '3', 'kategorie' => '', 'darstellung' => 'kacheln', 'archiv' => 'nein']],
        ['blutspendetermine', ['titel' => 'Nächste Blutspendetermine', 'anzahl' => '3', 'text' => '[Alle Termine ansehen](blutspende)']],
        ['zahlen', ['titel' => 'Wir in Zahlen', 'eintraege' => "100+ | Mitglieder\n20 | Sanitätsdienste pro Jahr\n6 | Blutspendetermine pro Jahr"]],
        ['unterstuetzer', ['titel' => 'Mit freundlicher Unterstützung von', 'text' => '']],
    ], ['ist_startseite' => 1, 'im_menue' => 0, 'layout' => 'breit', 'menue_titel' => 'Start',
        'hero_text' => "Ihr DRK vor Ort – helfen, wo Hilfe gebraucht wird.",
        'beschreibung' => 'Herzlich willkommen beim DRK-Ortsverein.']);

    $about = $page('Über uns', 'ueber-uns', null, 10, [
        ['text', ['titel' => 'Wer wir sind', 'inhalt' => "Der DRK-Ortsverein ist Teil der weltweiten Rotkreuz- und Rothalbmondbewegung.\n\nUnsere Arbeit beruht auf den sieben Grundsätzen:\n\n- Menschlichkeit\n- Unparteilichkeit\n- Neutralität\n- Unabhängigkeit\n- Freiwilligkeit\n- Einheit\n- Universalität"]],
        ['unterseiten', ['titel' => 'Unsere Gemeinschaften']],
    ], ['beschreibung' => 'Unser Ortsverein, unsere Gemeinschaften und unser Vorstand.']);
    $page('Bereitschaft', 'bereitschaft', $about, 1, [
        ['text', ['titel' => 'Die Bereitschaft', 'inhalt' => "Unsere Bereitschaft übernimmt Sanitätsdienste bei Veranstaltungen, unterstützt im Katastrophenschutz und betreut Menschen in Notlagen.\n\n**Dienstabende:** jeden 2. und 4. Dienstag im Monat, 19:30 Uhr"]],
        ['kontakt', ['name' => 'Vorname Nachname', 'funktion' => 'Bereitschaftsleitung', 'telefon' => '01234 567890', 'email' => 'bereitschaft@drk-musterstadt.de', 'bild' => '', 'text' => '']],
    ], ['layout' => 'seitenleiste', 'beschreibung' => 'Sanitätsdienst, Katastrophenschutz und Betreuung.']);
    $page('Jugendrotkreuz', 'jugendrotkreuz', $about, 2, [
        ['text', ['titel' => 'Das Jugendrotkreuz', 'inhalt' => "Im Jugendrotkreuz (JRK) treffen sich Kinder und Jugendliche zwischen 6 und 27 Jahren. Wir lernen Erste Hilfe, machen Ausflüge und setzen uns für andere ein.\n\n**Gruppenstunde:** freitags, 17:00 Uhr"]],
    ], ['layout' => 'seitenleiste', 'beschreibung' => 'Erste Hilfe, Gemeinschaft und Spaß für Kinder und Jugendliche.']);
    $page('Vorstand', 'vorstand', $about, 3, [
        ['kontakt', ['name' => 'Vorname Nachname', 'funktion' => '1. Vorsitzende/r', 'telefon' => '', 'email' => 'vorstand@drk-musterstadt.de', 'bild' => '', 'text' => '']],
    ], ['layout' => 'seitenleiste', 'beschreibung' => 'Unser Vorstand und Ihre Ansprechpartner.']);

    $page('Aktuelles', 'aktuelles', null, 5, [
        ['news', ['titel' => '', 'anzahl' => '10', 'kategorie' => '', 'darstellung' => 'liste', 'archiv' => 'ja']],
    ], ['beschreibung' => 'Neuigkeiten aus unserem Ortsverein.']);

    $news = [
        ['Neue Sanitätsrucksäcke für die Bereitschaft', 'Bereitschaft', 0, 'Dank einer großzügigen Spende konnten wir zwei neue Sanitätsrucksäcke anschaffen.',
            "Dank einer großzügigen Spende der örtlichen Sparkasse konnte unsere Bereitschaft zwei neue Sanitätsrucksäcke anschaffen.\n\n## Was ist neu?\n\n- moderne Notfallausrüstung\n- leichteres Gewicht für lange Dienste\n\nHerzlichen Dank an alle Unterstützer!"],
        ['Blutspender/innen gesucht', 'Blutspende', 1, '',
            "Die Blutkonserven werden knapp. Bitte kommen Sie zu unserem nächsten Termin – jede Spende zählt!\n\n[Alle Termine ansehen](blutspende)"],
        ['JRK-Gruppe startet nach den Ferien', 'Jugendrotkreuz', 0, '',
            "Ab September trifft sich unsere Jugendrotkreuz-Gruppe wieder freitags um 17 Uhr. Neue Mitglieder zwischen 6 und 16 Jahren sind herzlich willkommen."],
    ];
    foreach ($news as $i => [$titel, $kat, $pin, $teaser, $inhalt]) {
        insert('news', ['titel' => $titel, 'slug' => slugify($titel), 'datum' => date('Y-m-d', strtotime('-' . ($i * 9) . ' days')),
            'kategorie' => $kat, 'teaser' => $teaser, 'inhalt' => $inhalt, 'autor' => 'Redaktion', 'angeheftet' => $pin,
            'veroeffentlicht' => 1, 'erstellt' => now(), 'aktualisiert' => now()]);
    }

    $page('Blutspende', 'blutspende', null, 20, [
        ['hinweis', ['stil' => 'wichtig', 'inhalt' => "**Blut spenden rettet Leben!** Bitte bringen Sie Ihren Personalausweis und – falls vorhanden – Ihren Blutspendeausweis mit."]],
        ['blutspendetermine', ['titel' => 'Unsere Blutspendetermine', 'anzahl' => '10', 'text' => 'Nach der Spende laden wir Sie herzlich zu unserem Imbiss ein!']],
        ['akkordeon', ['titel' => 'Häufige Fragen', 'eintraege' => "Wer darf Blut spenden? | Gesunde Menschen ab 18 Jahren mit mindestens 50 kg Körpergewicht. Erstspender/innen bis zum 65. Geburtstag.\nWie lange dauert eine Spende? | Insgesamt etwa eine Stunde, die eigentliche Blutentnahme nur ca. 10 Minuten.\nWie oft darf ich spenden? | Frauen bis zu 4-mal, Männer bis zu 6-mal innerhalb von 12 Monaten."]],
    ], ['beschreibung' => 'Termine und Informationen rund um die Blutspende.']);

    $page('Mitmachen', 'mitmachen', null, 30, [
        ['zwei_spalten', ['titel' => 'So können Sie helfen', 'links' => "### Aktiv mitmachen\nOb Sanitätsdienst, Jugendarbeit oder Blutspende-Team – bei uns findet jede/r eine passende Aufgabe.", 'rechts' => "### Fördermitglied werden\nMit einem regelmäßigen Beitrag unterstützen Sie unsere ehrenamtliche Arbeit vor Ort."]],
        ['button', ['text' => 'Kontakt aufnehmen', 'link' => 'kontakt', 'stil' => 'primaer']],
    ], ['beschreibung' => 'Aktiv werden, fördern oder spenden.']);

    $page('Kontakt', 'kontakt', null, 40, [
        ['karte', ['titel' => 'So erreichen Sie uns', 'adresse' => "**DRK-Ortsverein**\nMusterstraße 1\n12345 Musterstadt\n\nTelefon: 01234 567890\nE-Mail: [info@drk-musterstadt.de](mailto:info@drk-musterstadt.de)", 'link' => '']],
    ], ['beschreibung' => 'Adresse und Ansprechpartner.']);

    $page('Impressum', 'impressum', null, 90, [
        ['hinweis', ['stil' => 'wichtig', 'inhalt' => 'Bitte das Impressum vollständig ausfüllen (Angaben nach § 5 DDG, Vertretungsberechtigte, Registergericht und Vereinsregisternummer).']],
        ['text', ['titel' => 'Angaben gemäß § 5 DDG', 'inhalt' => "DRK-Ortsverein …\nMusterstraße 1\n12345 Musterstadt\n\nVertreten durch: …\nVereinsregister: …"]],
    ], ['im_menue' => 0]);
    $page('Datenschutz', 'datenschutz', null, 91, [
        ['hinweis', ['stil' => 'wichtig', 'inhalt' => 'Bitte die Datenschutzerklärung mit dem Kreisverband bzw. Landesverband abstimmen und hier einfügen.']],
    ], ['im_menue' => 0]);

    // Beispielrezepte für den Blutspende-Imbiss
    seed_job_descriptions();

    $recipe = function (string $name, string $kat, int $portionen, string $zub, array $zutaten, int $veg = 0, string $allergene = ''): void {
        $id = insert('rezepte', ['name' => $name, 'kategorie' => $kat, 'portionen' => $portionen, 'zubereitung' => $zub,
            'allergene' => $allergene, 'vegetarisch' => $veg, 'erstellt' => now(), 'aktualisiert' => now()]);
        foreach ($zutaten as $i => [$menge, $einheit, $zname, $abt]) {
            insert('rezept_zutaten', ['rezept_id' => $id, 'sortierung' => $i, 'menge' => $menge, 'einheit' => $einheit, 'name' => $zname, 'abteilung' => $abt]);
        }
    };
    $recipe('Nudelsalat', 'Salat', 20, "Nudeln kochen und abkühlen lassen.\nGemüse klein schneiden, mit Mayonnaise und Joghurt vermengen.\nMindestens 2 Stunden durchziehen lassen, kühl lagern.", [
        [1000, 'g', 'Nudeln (Spirelli)', 'Trockenware'],
        [500, 'g', 'Erbsen (TK)', 'Tiefkühl'],
        [2, 'Glas', 'Gewürzgurken', 'Konserven'],
        [400, 'g', 'Fleischwurst', 'Fleisch & Wurst'],
        [500, 'g', 'Mayonnaise', 'Kühlregal'],
        [500, 'g', 'Joghurt', 'Kühlregal'],
    ], 0, 'Gluten, Ei, Milch, Senf');
    $recipe('Belegte Brötchen (gemischt)', 'Belegte Brötchen', 10, "Brötchen aufschneiden, mit Butter bestreichen und belegen.\nMit Salat und Gurke garnieren.", [
        [10, 'Stück', 'Brötchen', 'Brot & Backwaren'],
        [125, 'g', 'Butter', 'Kühlregal'],
        [150, 'g', 'Käse in Scheiben', 'Käse'],
        [150, 'g', 'Schinken/Salami', 'Fleisch & Wurst'],
        [1, 'Kopf', 'Eisbergsalat', 'Obst & Gemüse'],
        [1, 'Stück', 'Salatgurke', 'Obst & Gemüse'],
    ], 0, 'Gluten, Milch');
    $recipe('Obstsalat', 'Dessert', 15, "Obst waschen, schälen und klein schneiden. Mit Zitronensaft beträufeln, damit nichts braun wird.", [
        [1, 'kg', 'Äpfel', 'Obst & Gemüse'],
        [1, 'kg', 'Bananen', 'Obst & Gemüse'],
        [500, 'g', 'Weintrauben', 'Obst & Gemüse'],
        [4, 'Stück', 'Orangen', 'Obst & Gemüse'],
        [100, 'ml', 'Zitronensaft', 'Konserven'],
    ], 1);
}
