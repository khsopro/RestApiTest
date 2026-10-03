<?php
declare(strict_types=1);

/** Einheiten, die zusammengefasst/umgerechnet werden: [Basiseinheit, Faktor] */
function unit_base(string $unit): array
{
    $u = mb_strtolower(trim($unit));
    return match ($u) {
        'kg'           => ['g', 1000.0],
        'g', 'gr'      => ['g', 1.0],
        'l', 'liter'   => ['ml', 1000.0],
        'ml'           => ['ml', 1.0],
        'stk', 'stk.', 'stück' => ['Stück', 1.0],
        default        => [trim($unit), 1.0],
    };
}

/** Einheiten, die man nur ganz kaufen kann → aufrunden */
function unit_is_whole(string $unit): bool
{
    return in_array(mb_strtolower($unit), ['stück', 'pck', 'pck.', 'packung', 'packungen', 'dose', 'dosen', 'flasche', 'flaschen',
        'glas', 'gläser', 'bund', 'becher', 'kopf', 'netz', 'tüte', 'beutel', 'kiste', 'karton', 'laib', 'brot'], true);
}

function unit_display(float $amount, string $baseUnit): array
{
    if ($baseUnit === 'g' && $amount >= 1000) {
        return [$amount / 1000, 'kg'];
    }
    if ($baseUnit === 'ml' && $amount >= 1000) {
        return [$amount / 1000, 'l'];
    }
    if (unit_is_whole($baseUnit)) {
        return [ceil($amount - 0.0001), $baseUnit];
    }
    if ($baseUnit === 'g' || $baseUnit === 'ml') {
        return [ceil($amount), $baseUnit];
    }
    return [$amount, $baseUnit];
}

/**
 * Berechnet die Einkaufsliste für einen Blutspendetermin.
 * Mengen werden pro Rezept auf die geplanten Portionen hochgerechnet
 * und gleiche Zutaten (gleiche Einheit) zusammengefasst.
 *
 * @return array<string, list<array{name:string, menge:float, einheit:string, rezepte:list<string>}>>
 */
function shopping_list(int $terminId): array
{
    $rows = all(
        'SELECT z.name, z.menge, z.einheit, z.abteilung, r.portionen AS basis, m.portionen AS ziel, r.name AS rezept
         FROM bs_menue m
         JOIN rezepte r ON r.id = m.rezept_id
         JOIN rezept_zutaten z ON z.rezept_id = r.id
         WHERE m.termin_id = ?
         ORDER BY z.abteilung, z.name',
        [$terminId]
    );
    $items = [];
    foreach ($rows as $r) {
        [$base, $factor] = unit_base((string)$r['einheit']);
        $key = mb_strtolower(trim($r['name'])) . '|' . mb_strtolower($base);
        $amount = (float)$r['menge'] * $factor * ((int)$r['ziel'] / max(1, (int)$r['basis']));
        $section = trim((string)$r['abteilung']) ?: 'Sonstiges';
        if (!isset($items[$key])) {
            $items[$key] = ['name' => trim($r['name']), 'menge' => 0.0, 'einheit' => $base, 'abteilung' => $section, 'rezepte' => []];
        }
        $items[$key]['menge'] += $amount;
        if (!in_array($r['rezept'], $items[$key]['rezepte'], true)) {
            $items[$key]['rezepte'][] = $r['rezept'];
        }
    }
    $grouped = [];
    foreach ($items as $it) {
        [$it['menge'], $it['einheit']] = unit_display($it['menge'], $it['einheit']);
        $grouped[$it['abteilung']][] = $it;
    }
    ksort($grouped, SORT_LOCALE_STRING);
    foreach ($grouped as &$list) {
        usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    }
    return $grouped;
}

/** Standard-Schichten aus den Einstellungen: "Aufgabe | von | bis | Anzahl | Qualifikation" */
function default_shifts(?string $beginn, ?string $ende): array
{
    $time = function (string $v, ?string $fallback) use ($beginn, $ende): ?string {
        return match (mb_strtolower($v)) {
            'beginn' => $beginn,
            'ende'   => $ende,
            ''       => $fallback,
            default  => $v,
        };
    };
    $out = [];
    // Vorrang: Aufgaben, die in den Stellenbeschreibungen als Standard-Schicht markiert sind
    foreach (all('SELECT * FROM bs_stellen WHERE standard = 1 AND archiviert = 0 ORDER BY sortierung, titel') as $s) {
        $out[] = [
            'aufgabe'       => $s['titel'],
            'von'           => $time((string)$s['std_von'], $beginn),
            'bis'           => $time((string)$s['std_bis'], $ende),
            'benoetigt'     => max(1, (int)$s['std_anzahl']),
            'qualifikation' => (string)$s['qualifikation'],
            'flexibel'      => (int)$s['std_flexibel'],
        ];
    }
    if ($out) {
        return $out;
    }
    // Rückfall: Textvorlage aus den Einstellungen
    foreach (setting_lines('bs_standard_schichten') as $line) {
        $p = array_pad(array_map('trim', explode('|', $line)), 5, '');
        $out[] = [
            'aufgabe'       => $p[0],
            'von'           => $time($p[1], $beginn),
            'bis'           => $time($p[2], $ende),
            'benoetigt'     => max(1, (int)$p[3]),
            'qualifikation' => $p[4],
            'flexibel'      => 1,
        ];
    }
    return $out;
}



function qualification_options(): array
{
    return setting_lines('qualifikationen');
}

function area_options(): array
{
    return setting_lines('bereiche');
}

function member_name(array $m): string
{
    return trim($m['vorname'] . ' ' . $m['nachname']);
}

function staffing_badge(int $have, int $need): string
{
    if ($need === 0) {
        return '<span class="badge">keine Schichten</span>';
    }
    $cls = $have >= $need ? 'ok' : ($have >= $need / 2 ? 'warn' : 'bad');
    return '<span class="badge ' . $cls . '">' . $have . ' / ' . $need . ' besetzt</span>';
}

/** Profilbild eines Mitglieds oder Initialen als Ersatz */
function member_avatar(array $m, string $class = 'avatar'): string
{
    $url = media_url(!empty($m['foto']) ? (int)$m['foto'] : null);
    if ($url) {
        return '<img src="' . e($url) . '" alt="" class="' . e($class) . '">';
    }
    $initials = mb_strtoupper(mb_substr((string)$m['vorname'], 0, 1) . mb_substr((string)$m['nachname'], 0, 1));
    return '<span class="' . e($class) . '" aria-hidden="true">' . e($initials) . '</span>';
}

/** Einsatzstatistik je Mitglied: [mitglied_id => [einsaetze, letzter, naechster]] */
function helper_stats(): array
{
    $today = date('Y-m-d');
    $stats = [];
    foreach (all("SELECT e.mitglied_id,
            SUM(CASE WHEN t.datum < ? THEN 1 ELSE 0 END) AS einsaetze,
            MAX(CASE WHEN t.datum < ? THEN t.datum END) AS letzter,
            MIN(CASE WHEN t.datum >= ? THEN t.datum END) AS naechster
        FROM bs_einteilung e
        JOIN bs_schichten s ON s.id = e.schicht_id
        JOIN bs_termine t ON t.id = s.termin_id
        WHERE e.status = 'zugesagt'
        GROUP BY e.mitglied_id", [$today, $today, $today]) as $r) {
        $stats[(int)$r['mitglied_id']] = $r;
    }
    return $stats;
}

/** Qualifikationen als Schlagworte; $highlight wird hervorgehoben */
function qualification_tags(string $list, string $highlight = ''): string
{
    $items = array_filter(array_map('trim', explode(',', $list)));
    if (!$items) {
        return '';
    }
    return '<div class="tags">' . implode('', array_map(
        fn($q) => '<span class="tag' . ($q === $highlight ? ' match' : '') . '">' . e($q) . '</span>',
        $items
    )) . '</div>';
}

/* ---------- Stellenbeschreibungen ---------- */

/** Zuordnung Aufgabenname (klein geschrieben) → ID der Stellenbeschreibung */
function job_ids(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (all('SELECT id, titel FROM bs_stellen WHERE archiviert = 0') as $r) {
            $map[mb_strtolower(trim($r['titel']))] = (int)$r['id'];
        }
    }
    return $map;
}

/** Link „ⓘ“ zur Stellenbeschreibung einer Schicht-Aufgabe (leer, wenn keine existiert) */
function job_link(string $aufgabe, string $label = 'ⓘ'): string
{
    $id = job_ids()[mb_strtolower(trim($aufgabe))] ?? null;
    return $id ? ' <a class="job-link" href="' . e(url_admin('stellen', 'ansehen', ['id' => $id])) . '" title="Stellenbeschreibung „' . e($aufgabe) . '“">' . e($label) . '</a>' : '';
}

/** Vorlagen passend zu den Standard-Schichten */
function seed_job_descriptions(): int
{
    $jobs = [
        ['Aufbau', 'Räume für den Blutspendetermin vorbereiten.',
            "- Liegen, Tische und Stühle nach Raumplan aufstellen\n- Wegweiser und Plakate am Eingang anbringen\n- Material des Blutspendedienstes entgegennehmen und verteilen",
            "Schlüssel abholen\nRaumplan prüfen\nLiegen aufbauen\nAnmeldung und Wartebereich einrichten\nRuheraum herrichten\nWegweiser aufhängen",
            "Körperlich belastbar (Tragen von Tischen und Liegen).", '', 'ca. 2 Stunden vor Beginn', "Feste Schuhe tragen. Schwere Teile immer zu zweit tragen."],
        ['Anmeldung', 'Erste Anlaufstelle für Spenderinnen und Spender.',
            "- Spender/innen freundlich begrüßen\n- Personalausweis und Spendeausweis kontrollieren\n- Fragebogen ausgeben und bei Fragen helfen\n- Erstspender/innen besonders betreuen und den Ablauf erklären",
            "Ausweise prüfen\nFragebogen und Kugelschreiber bereitlegen\nWartenummern vergeben\nErstspender/innen kennzeichnen",
            "Freundliches Auftreten, Geduld, gute Deutschkenntnisse.", '', 'gesamte Spendezeit', "Datenschutz beachten: keine Fragebögen offen liegen lassen."],
        ['Arztzimmer/Labor-Unterstützung', 'Unterstützung des Teams vom Blutspendedienst.',
            "- Spender/innen zum Arzt und zur Laboruntersuchung begleiten\n- Material nachfüllen\n- Bei Kreislaufproblemen sofort Hilfe holen",
            "Material nach Absprache nachfüllen\nWartebereich im Blick behalten",
            "Sanitätsausbildung erforderlich.", 'Sanitätshelfer/in', 'gesamte Spendezeit', "Anweisungen des ärztlichen Personals haben Vorrang. Schweigepflicht beachten."],
        ['Ruheraum/Betreuung', 'Spender/innen nach der Entnahme betreuen.',
            "- Spender/innen nach der Spende in den Ruheraum begleiten\n- Getränke anbieten\n- Auf Anzeichen von Kreislaufproblemen achten (Blässe, Schwindel, Schweiß)\n- Erst nach ausreichender Ruhezeit zum Imbiss schicken",
            "Getränke bereitstellen\nDecken und Kissen bereitlegen\nNotfallausrüstung prüfen",
            "Erste-Hilfe-Kenntnisse, ruhige Art.", 'Erste-Hilfe-Kurs', 'gesamte Spendezeit', "Bei Problemen sofort das ärztliche Personal rufen. Spender/innen nie allein lassen."],
        ['Küche', 'Zubereitung des Imbisses für die Spender/innen.',
            "- Gerichte nach Menüplan und Rezepten zubereiten\n- Zutaten von der Einkaufsliste kontrollieren\n- Kühlkette einhalten\n- Küche sauber halten und Geschirr spülen",
            "Hände waschen und desinfizieren\nSchürze und Haarnetz anlegen\nKühlschranktemperatur prüfen (max. 7 °C)\nGerichte nach Rezept zubereiten\nReste korrekt entsorgen\nKüche reinigen",
            "Gültige Belehrung nach § 43 Infektionsschutzgesetz.", 'Hygienebelehrung (§ 43 IfSG)', 'ab ca. 2,5 Stunden vor Beginn bis Ende',
            "Wer Durchfall, Erbrechen, Fieber oder offene Wunden an den Händen hat, darf nicht in der Küche arbeiten. Schmuck an Händen und Unterarmen ablegen."],
        ['Imbiss-Ausgabe', 'Ausgabe von Essen und Getränken an die Spender/innen.',
            "- Speisen ansprechend anrichten und ausgeben\n- Auf Allergene hinweisen (Aushang)\n- Tische abräumen und sauber halten\n- Nachschub aus der Küche anfordern",
            "Allergen-Aushang aufhängen\nAusgabetheke einrichten\nGeschirr und Besteck bereitstellen",
            "Gültige Belehrung nach § 43 Infektionsschutzgesetz.", 'Hygienebelehrung (§ 43 IfSG)', 'gesamte Spendezeit',
            "Speisen nur mit Besteck oder Handschuhen anfassen. Warme Speisen über 65 °C, kalte unter 7 °C halten."],
        ['Abbau', 'Räume nach dem Termin wieder herrichten.',
            "- Liegen, Tische und Stühle abbauen und verstauen\n- Müll trennen und entsorgen\n- Räume besenrein übergeben",
            "Material des Blutspendedienstes übergeben\nMöbel zurückstellen\nMüll entsorgen\nFenster schließen, Licht aus, abschließen\nSchlüssel zurückgeben",
            "Körperlich belastbar.", '', 'ca. 1,5 Stunden nach Ende', "Feste Schuhe tragen."],
    ];
    // Standard-Schicht je Aufgabe: [von, bis, Anzahl]
    $std = [
        'Aufbau' => ['13:30', 'beginn', 4], 'Anmeldung' => ['beginn', 'ende', 2], 'Arztzimmer/Labor-Unterstützung' => ['beginn', 'ende', 1],
        'Ruheraum/Betreuung' => ['beginn', 'ende', 2], 'Küche' => ['13:00', 'ende', 3], 'Imbiss-Ausgabe' => ['beginn', 'ende', 2],
        'Abbau' => ['ende', '21:00', 4],
    ];
    $n = 0;
    foreach ($jobs as $i => [$titel, $kurz, $aufgaben, $ablauf, $anf, $quali, $zeit, $hinweise]) {
        if (val('SELECT id FROM bs_stellen WHERE titel = ?', [$titel])) {
            continue;
        }
        insert('bs_stellen', ['titel' => $titel, 'kurz' => $kurz, 'aufgaben' => $aufgaben, 'ablauf' => $ablauf, 'anforderungen' => $anf,
            'qualifikation' => $quali, 'zeitaufwand' => $zeit, 'hinweise' => $hinweise, 'ansprechpartner' => '', 'sortierung' => $i * 10,
            'standard' => 1, 'std_von' => $std[$titel][0], 'std_bis' => $std[$titel][1], 'std_anzahl' => $std[$titel][2],
            'std_flexibel' => in_array($titel, ['Aufbau', 'Abbau'], true) ? 0 : 1,
            'archiviert' => 0, 'aktualisiert' => now()]);
        $n++;
    }
    return $n;
}

/** Bereich in einer kommagetrennten Liste ergänzen oder entfernen */
function areas_with(?string $csv, string $area, bool $add = true): string
{
    $list = array_values(array_filter(array_map('trim', explode(',', (string)$csv))));
    $list = array_values(array_filter($list, fn($a) => $a !== $area));
    if ($add) {
        $list[] = $area;
    }
    return implode(', ', $list);
}
