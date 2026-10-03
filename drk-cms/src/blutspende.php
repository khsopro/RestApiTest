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
    foreach (setting_lines('bs_standard_schichten') as $line) {
        $p = array_pad(array_map('trim', explode('|', $line)), 5, '');
        $out[] = [
            'aufgabe'       => $p[0],
            'von'           => $time($p[1], $beginn),
            'bis'           => $time($p[2], $ende),
            'benoetigt'     => max(1, (int)$p[3]),
            'qualifikation' => $p[4],
        ];
    }
    return $out;
}

/** Besetzung eines Termins: [zugesagt, benötigt] */
function staffing(int $terminId): array
{
    $need = (int)val('SELECT COALESCE(SUM(benoetigt),0) FROM bs_schichten WHERE termin_id = ?', [$terminId]);
    $have = (int)val("SELECT COUNT(*) FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id WHERE s.termin_id = ? AND e.status = 'zugesagt'", [$terminId]);
    return [$have, $need];
}

function times_overlap(?string $a1, ?string $a2, ?string $b1, ?string $b2): bool
{
    if (!$a1 || !$a2 || !$b1 || !$b2) {
        return true; // ohne Zeitangabe vorsichtshalber als Überschneidung werten
    }
    return $a1 < $b2 && $b1 < $a2;
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
