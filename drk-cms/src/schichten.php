<?php
declare(strict_types=1);

/**
 * Schichtsystem Blutspende
 *
 *  Schicht    = Aufgabe mit Zeitfenster (von–bis) und Bedarf (gleichzeitig benötigte Personen)
 *               fest:      alle Eingeteilten arbeiten die ganze Schichtzeit
 *               flexibel:  jede Einteilung hat eigene Zeiten innerhalb des Zeitfensters
 *  Einteilung = Person in einer Schicht (eine Person kann mehrere Einteilungen haben,
 *               z. B. erst Anmeldung, dann Imbiss)
 *  Ist-Zeiten = nach dem Dienst erfasste Zeiten → Ehrenamtsstunden
 *
 * Alle Zeiten liegen im 15-Minuten-Raster.
 */

const SLOT_MIN = 15;

/** "HH:MM" → Minuten seit Mitternacht */
function t2m(?string $t): ?int
{
    if ($t === null || !preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return null;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

/** Minuten → "HH:MM" */
function m2t(int $m): string
{
    $m = max(0, min(24 * 60 - 1, $m));
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Auf das 15-Minuten-Raster runden */
function round15(?string $t): ?string
{
    $m = t2m($t);
    return $m === null ? null : m2t((int)(round($m / SLOT_MIN) * SLOT_MIN));
}

/** Auswahlliste im 15-Minuten-Raster zwischen $from und $to */
function time_options(?string $from, ?string $to, ?string $selected): string
{
    $a = t2m($from) ?? 6 * 60;
    $b = t2m($to) ?? 22 * 60;
    $html = '';
    for ($m = $a; $m <= $b; $m += SLOT_MIN) {
        $t = m2t($m);
        $html .= '<option value="' . $t . '"' . ($t === $selected ? ' selected' : '') . '>' . $t . '</option>';
    }
    return $html;
}

/** Zeiten einer Einteilung: feste Schicht → Schichtzeit, flexibel → eigene Zeit (Standard: Schichtzeit) */
function eff_times(array $e, array $s): array
{
    if (!(int)$s['flexibel']) {
        return [$s['von'], $s['bis']];
    }
    return [$e['von'] ?: $s['von'], $e['bis'] ?: $s['bis']];
}

/** Gewünschte Zeiten in das Zeitfenster der Schicht einpassen, Raster 15 Minuten. null = ungültig */
function clamp_to_shift(?string $von, ?string $bis, array $s): ?array
{
    $sv = t2m($s['von']);
    $sb = t2m($s['bis']);
    $v = t2m(round15($von)) ?? $sv;
    $b = t2m(round15($bis)) ?? $sb;
    if ($sv !== null && $sb !== null) {
        $v = max($sv, min($v ?? $sv, $sb));
        $b = max($sv, min($b ?? $sb, $sb));
    }
    if ($v === null || $b === null || $v >= $b) {
        return null;
    }
    return [m2t($v), m2t($b)];
}

/** Belegung je 15-Minuten-Abschnitt: [Minute => Anzahl zugesagter Personen] */
function shift_coverage(array $s, array $assignments): array
{
    $from = t2m($s['von']);
    $to = t2m($s['bis']);
    if ($from === null || $to === null || $from >= $to) {
        return [];
    }
    $cov = [];
    for ($m = $from; $m < $to; $m += SLOT_MIN) {
        $cov[$m] = 0;
    }
    foreach ($assignments as $e) {
        if ($e['status'] !== 'zugesagt') {
            continue;
        }
        [$v, $b] = eff_times($e, $s);
        $v = t2m($v);
        $b = t2m($b);
        foreach ($cov as $m => $n) {
            if ($v !== null && $b !== null && $m >= $v && $m < $b) {
                $cov[$m] = $n + 1;
            }
        }
    }
    return $cov;
}

/**
 * Auswertung einer Schicht.
 * besetzt = durchgehend besetzte Plätze (Minimum über alle Abschnitte)
 * luecken = Zeiträume, in denen Personen fehlen: [[von, bis, fehlen], …]
 */
function shift_summary(array $s, array $assignments): array
{
    $need = max(1, (int)$s['benoetigt']);
    $cov = shift_coverage($s, $assignments);
    if (!$cov) {
        // Schicht ohne Zeiten: einfache Kopfzahl
        $n = count(array_filter($assignments, fn($e) => $e['status'] === 'zugesagt'));
        return ['besetzt' => min($n, $need), 'bedarf' => $need, 'luecken' => [], 'cov' => []];
    }
    $gaps = [];
    $start = null;
    $missing = 0;
    foreach ($cov + [PHP_INT_MAX => $need] as $m => $n) {
        $miss = max(0, $need - $n);
        if ($start !== null && $miss !== $missing) {
            $gaps[] = [m2t($start), m2t($m === PHP_INT_MAX ? (int)t2m($s['bis']) : $m), $missing];
            $start = null;
        }
        if ($miss > 0 && $start === null) {
            $start = $m;
            $missing = $miss;
        }
    }
    return ['besetzt' => min($need, min($cov)), 'bedarf' => $need, 'luecken' => $gaps, 'cov' => $cov];
}

/** Alle Schichten eines Termins mit ihren Einteilungen (inkl. Personendaten) */
function termin_shifts(int $terminId): array
{
    $shifts = all('SELECT * FROM bs_schichten WHERE termin_id = ? ORDER BY von, aufgabe', [$terminId]);
    $byShift = [];
    foreach (all('SELECT e.*, m.vorname, m.nachname, m.mobil, m.telefon, m.qualifikationen, m.foto
        FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id JOIN mitglieder m ON m.id = e.mitglied_id
        WHERE s.termin_id = ? ORDER BY e.von, m.nachname', [$terminId]) as $e) {
        $byShift[$e['schicht_id']][] = $e;
    }
    foreach ($shifts as &$s) {
        $s['einteilungen'] = $byShift[$s['id']] ?? [];
        foreach ($s['einteilungen'] as &$e) {
            [$e['eff_von'], $e['eff_bis']] = eff_times($e, $s);
        }
        unset($e);
        usort($s['einteilungen'], fn($x, $y) => [$x['eff_von'], $x['nachname']] <=> [$y['eff_von'], $y['nachname']]);
        $s['summary'] = shift_summary($s, $s['einteilungen']);
    }
    return $shifts;
}

/** Besetzung eines Termins: [durchgehend besetzt, Bedarf] über alle Schichten */
function staffing(int $terminId): array
{
    $have = $need = 0;
    foreach (termin_shifts($terminId) as $s) {
        $have += $s['summary']['besetzt'];
        $need += $s['summary']['bedarf'];
    }
    return [$have, $need];
}

/** Überschneidungen: dieselbe Person zur selben Zeit in zwei Einteilungen. [einteilung_id => andere Aufgabe] */
function assignment_conflicts(array $shifts): array
{
    $list = [];
    foreach ($shifts as $s) {
        foreach ($s['einteilungen'] as $e) {
            if ($e['status'] !== 'abgesagt') {
                $list[] = $e + ['aufgabe' => $s['aufgabe']];
            }
        }
    }
    $conflicts = [];
    foreach ($list as $a) {
        foreach ($list as $b) {
            if ($a['id'] !== $b['id'] && $a['mitglied_id'] === $b['mitglied_id']
                && t2m($a['eff_von']) < t2m($b['eff_bis']) && t2m($b['eff_von']) < t2m($a['eff_bis'])) {
                $conflicts[$a['id']] = $b['aufgabe'];
            }
        }
    }
    return $conflicts;
}

/** Ablauf je Person: [mitglied_id => ['name' =>, 'mobil' =>, 'einsaetze' => [[von, bis, aufgabe, status], …], 'minuten' =>]] */
function person_schedule(array $shifts): array
{
    $people = [];
    foreach ($shifts as $s) {
        foreach ($s['einteilungen'] as $e) {
            if ($e['status'] === 'abgesagt') {
                continue;
            }
            $p = &$people[$e['mitglied_id']];
            $p['name'] ??= $e['nachname'] . ', ' . $e['vorname'];
            $p['mobil'] ??= $e['mobil'] ?: $e['telefon'];
            $p['einsaetze'][] = [$e['eff_von'], $e['eff_bis'], $s['aufgabe'], $e['status']];
            unset($p);
        }
    }
    foreach ($people as &$p) {
        usort($p['einsaetze'], fn($x, $y) => $x[0] <=> $y[0]);
        $p['minuten'] = union_minutes(array_map(fn($x) => [$x[0], $x[1]], $p['einsaetze']));
    }
    unset($p);
    uasort($people, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $people;
}

/** Summe der Minuten über Zeiträume, Überschneidungen werden nur einmal gezählt */
function union_minutes(array $intervals): int
{
    $iv = [];
    foreach ($intervals as [$v, $b]) {
        $v = t2m($v);
        $b = t2m($b);
        if ($v !== null && $b !== null && $b > $v) {
            $iv[] = [$v, $b];
        }
    }
    sort($iv);
    $sum = 0;
    $cur = null;
    foreach ($iv as [$v, $b]) {
        if ($cur && $v <= $cur[1]) {
            $cur[1] = max($cur[1], $b);
        } else {
            $sum += $cur ? $cur[1] - $cur[0] : 0;
            $cur = [$v, $b];
        }
    }
    return $sum + ($cur ? $cur[1] - $cur[0] : 0);
}

function hours_de(int $minutes): string
{
    return num_de($minutes / 60, 2) . ' Std.';
}

/**
 * Ehrenamtsstunden aus den erfassten Ist-Zeiten.
 * @return array<int, array{mitglied_id:int, name:string, termine:int, minuten:int, einsaetze:list<array>}>
 */
function volunteer_hours(string $from, string $to, ?int $memberId = null): array
{
    $sql = "SELECT e.mitglied_id, e.ist_von, e.ist_bis, s.aufgabe, t.id AS termin_id, t.datum, t.ort, m.vorname, m.nachname
        FROM bs_einteilung e
        JOIN bs_schichten s ON s.id = e.schicht_id
        JOIN bs_termine t ON t.id = s.termin_id
        JOIN mitglieder m ON m.id = e.mitglied_id
        WHERE e.ist_von IS NOT NULL AND e.ist_bis IS NOT NULL AND e.nicht_erschienen = 0 AND t.datum BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($memberId) {
        $sql .= ' AND e.mitglied_id = ?';
        $params[] = $memberId;
    }
    $out = [];
    foreach (all($sql . ' ORDER BY t.datum, e.ist_von', $params) as $r) {
        $mid = (int)$r['mitglied_id'];
        $out[$mid] ??= ['mitglied_id' => $mid, 'name' => $r['nachname'] . ', ' . $r['vorname'], 'tage' => []];
        $out[$mid]['tage'][$r['termin_id']]['datum'] = $r['datum'];
        $out[$mid]['tage'][$r['termin_id']]['ort'] = $r['ort'];
        $out[$mid]['tage'][$r['termin_id']]['zeiten'][] = [$r['ist_von'], $r['ist_bis']];
        $out[$mid]['tage'][$r['termin_id']]['aufgaben'][$r['aufgabe']] = true;
    }
    foreach ($out as &$p) {
        $p['minuten'] = 0;
        foreach ($p['tage'] as &$d) {
            $d['minuten'] = union_minutes($d['zeiten']);
            $d['aufgaben'] = array_keys($d['aufgaben']);
            $p['minuten'] += $d['minuten'];
        }
        unset($d);
        $p['termine'] = count($p['tage']);
    }
    unset($p);
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Zeitleiste (Gantt) aller Schichten eines Termins: Besetzung je 15 Minuten */
function render_timeline(array $shifts): string
{
    $starts = array_filter(array_map(fn($s) => t2m($s['von']), $shifts), fn($v) => $v !== null);
    $ends = array_filter(array_map(fn($s) => t2m($s['bis']), $shifts), fn($v) => $v !== null);
    if (!$starts || !$ends) {
        return '';
    }
    $from = (int)(floor(min($starts) / 60) * 60);
    $to = (int)(ceil(max($ends) / 60) * 60);
    $html = '<div class="timeline-wrap"><table class="timeline"><thead><tr><th></th>';
    for ($m = $from; $m < $to; $m += 60) {
        $html .= '<th colspan="4">' . m2t($m) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($shifts as $s) {
        $cov = $s['summary']['cov'];
        $need = $s['summary']['bedarf'];
        $html .= '<tr><th class="tl-label">' . e($s['aufgabe']) . ' <small>' . $need . '</small></th>';
        for ($m = $from; $m < $to; $m += SLOT_MIN) {
            if (!array_key_exists($m, $cov)) {
                $html .= '<td class="tl-off' . ($m % 60 === 0 ? ' tl-hour' : '') . '"></td>';
                continue;
            }
            $n = $cov[$m];
            $cls = $n >= $need ? 'tl-ok' : ($n > 0 ? 'tl-part' : 'tl-empty');
            $html .= '<td class="' . $cls . ($m % 60 === 0 ? ' tl-hour' : '') . '" title="' . e($s['aufgabe'] . ' ' . m2t($m) . '–' . m2t($m + SLOT_MIN) . ': ' . $n . ' von ' . $need) . '">'
                . ($n < $need ? $n : '') . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table><p class="tl-legend"><span class="tl-ok"></span> voll besetzt <span class="tl-part"></span> teilweise (Zahl = anwesend) '
        . '<span class="tl-empty"></span> niemand · je Kästchen 15 Minuten</p></div>';
}

/** SQL: tatsächliche Planzeiten einer Einteilung (Alias e = bs_einteilung, s = bs_schichten) */
const SQL_EFF_VON = "(CASE WHEN s.flexibel = 1 AND e.von IS NOT NULL THEN e.von ELSE s.von END)";
const SQL_EFF_BIS = "(CASE WHEN s.flexibel = 1 AND e.bis IS NOT NULL THEN e.bis ELSE s.bis END)";

/** Auswertung einer einzelnen Schicht anhand ihrer ID */
function shift_summary_by_id(int $sid): array
{
    $s = one('SELECT * FROM bs_schichten WHERE id = ?', [$sid]);
    return $s ? shift_summary($s, all('SELECT * FROM bs_einteilung WHERE schicht_id = ?', [$sid])) : ['besetzt' => 0, 'bedarf' => 0, 'luecken' => [], 'cov' => []];
}

/** Sehr kleiner Besetzungsbalken (für Listen) */
function coverage_bar_small(array $s): string
{
    $cov = $s['summary']['cov'];
    if (!$cov) {
        return '';
    }
    $need = $s['summary']['bedarf'];
    $html = '<div class="cov-bar small" aria-hidden="true">';
    foreach ($cov as $m => $n) {
        $html .= '<span class="' . ($n >= $need ? 'tl-ok' : ($n > 0 ? 'tl-part' : 'tl-empty')) . '" title="' . m2t($m) . ': ' . $n . '/' . $need . '"></span>';
    }
    return $html . '</div>';
}
