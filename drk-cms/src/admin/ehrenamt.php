<?php
/** Ehrenamtsstunden: Auswertung, Export und Bescheinigung (Grundlage: nach dem Dienst erfasste Ist-Zeiten) */

$years = array_map('intval', array_column(all("SELECT DISTINCT SUBSTR(t.datum, 1, 4) AS j FROM bs_einteilung e
    JOIN bs_schichten s ON s.id = e.schicht_id JOIN bs_termine t ON t.id = s.termin_id WHERE e.ist_von IS NOT NULL ORDER BY j DESC"), 'j'));
$year = (int)get('jahr', $years[0] ?? (int)date('Y'));
if (!in_array((int)date('Y'), $years, true)) {
    array_unshift($years, (int)date('Y'));
}
$from = $year . '-01-01';
$to = $year . '-12-31';
$id = (int)get('id', 0);

/* ---------- Bescheinigung für eine Person ---------- */
if ($a === 'nachweis' && $id) {
    $m = one('SELECT * FROM mitglieder WHERE id = ?', [$id]);
    $p = volunteer_hours($from, $to, $id)[$id] ?? null;
    if (!$m) {
        redirect(url_admin('ehrenamt'));
    }
    $printView = true;
    $title = 'Bescheinigung ' . member_name($m);
    audit('Ehrenamtsbescheinigung erstellt', member_name($m) . ' ' . $year);
    ?>
    <div class="print-page certificate">
        <p class="cert-org"><strong><?= e(setting('seitentitel')) ?></strong><br><?= nl2br(e(trim((string)preg_replace(['/\[([^\]]+)\]\([^)]+\)/', '/[*_#]+/', "/\n{2,}/"], ['$1', '', "\n"], setting('kontakt_text'))))) ?></p>
        <h1>Bescheinigung über ehrenamtliche Tätigkeit</h1>
        <p>Hiermit bestätigen wir, dass</p>
        <p class="cert-name"><?= e(trim(($m['anrede'] ? $m['anrede'] . ' ' : '') . member_name($m))) ?><?= $m['geburtsdatum'] ? ', geb. ' . e(date_de($m['geburtsdatum'])) : '' ?></p>
        <p>im Zeitraum vom <?= e(date_de($from)) ?> bis <?= e(date_de($to)) ?> ehrenamtlich im Blutspendedienst unseres Ortsvereins tätig war.</p>
        <?php if ($p): ?>
            <table class="print-table">
                <thead><tr><th>Datum</th><th>Ort</th><th>Tätigkeit</th><th class="right">Stunden</th></tr></thead>
                <?php foreach ($p['tage'] as $d): ?>
                    <tr><td><?= e(date_de($d['datum'])) ?></td><td><?= e($d['ort']) ?></td><td><?= e(implode(', ', $d['aufgaben'])) ?></td><td class="right"><?= e(num_de($d['minuten'] / 60)) ?></td></tr>
                <?php endforeach; ?>
                <tfoot><tr><td colspan="3"><strong>Insgesamt (<?= (int)$p['termine'] ?> Einsätze)</strong></td><td class="right"><strong><?= e(num_de($p['minuten'] / 60)) ?></strong></td></tr></tfoot>
            </table>
        <?php else: ?>
            <p><em>Für diesen Zeitraum sind keine Stunden erfasst.</em></p>
        <?php endif; ?>
        <p>Die Tätigkeit wurde unentgeltlich ausgeübt. Wir danken für das Engagement.</p>
        <div class="signature">
            <div><span>Ort, Datum</span></div>
            <div><span>Unterschrift / Stempel</span></div>
        </div>
    </div>
    <?php
    return;
}

$rows = volunteer_hours($from, $to);
$totalMin = array_sum(array_column($rows, 'minuten'));
$openCount = (int)val("SELECT COUNT(*) FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id JOIN bs_termine t ON t.id = s.termin_id
    WHERE e.status = 'zugesagt' AND e.ist_von IS NULL AND e.nicht_erschienen = 0 AND t.datum BETWEEN ? AND ?", [$from, min($to, date('Y-m-d', strtotime('-1 day')))]);

/* ---------- CSV-Export ---------- */
if ($a === 'export') {
    audit('Ehrenamtsstunden exportiert', (string)$year);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ehrenamtsstunden-' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Datum', 'Ort', 'Tätigkeit', 'Stunden'], ';');
    foreach ($rows as $p) {
        foreach ($p['tage'] as $d) {
            fputcsv($out, [csv_safe($p['name']), date_de($d['datum']), csv_safe((string)$d['ort']), csv_safe(implode(', ', $d['aufgaben'])),
                number_format($d['minuten'] / 60, 2, ',', '')], ';');
        }
        fputcsv($out, [csv_safe($p['name']), '', '', 'Summe', number_format($p['minuten'] / 60, 2, ',', '')], ';');
    }
    exit;
}

/* ---------- Druckliste ---------- */
if ($a === 'druck') {
    $printView = true;
    $title = 'Ehrenamtsstunden ' . $year;
    echo '<div class="print-page"><h1>Ehrenamtsstunden Blutspende ' . $year . '</h1><p>' . e(setting('seitentitel')) . ' · Stand ' . e(date_de(date('Y-m-d'))) . '</p>'
        . '<table class="print-table"><thead><tr><th>Name</th><th class="right">Einsätze</th><th class="right">Stunden</th></tr></thead>';
    foreach ($rows as $p) {
        echo '<tr><td>' . e($p['name']) . '</td><td class="right">' . (int)$p['termine'] . '</td><td class="right">' . e(num_de($p['minuten'] / 60)) . '</td></tr>';
    }
    echo '<tfoot><tr><td>Gesamt</td><td></td><td class="right">' . e(num_de($totalMin / 60)) . '</td></tr></tfoot></table></div>';
    return;
}
?>
<p class="crumbs"><?php if (has_role('blutspende')): ?><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <?php endif; ?>Ehrenamtsstunden</p>
<div class="head-row">
    <h1>Ehrenamtsstunden <?= $year ?></h1>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= e(url_admin('ehrenamt', 'druck', ['jahr' => $year])) ?>" target="_blank">Liste drucken</a>
        <a class="btn btn-outline" href="<?= e(url_admin('ehrenamt', 'export', ['jahr' => $year])) ?>">CSV-Export</a>
    </div>
</div>
<nav class="tabs">
    <?php foreach ($years as $y): ?><a href="<?= e(url_admin('ehrenamt', '', ['jahr' => $y])) ?>" class="<?= $y === $year ? 'active' : '' ?>"><?= $y ?></a><?php endforeach; ?>
</nav>
<div class="cards">
    <div class="card stat"><strong><?= e(num_de($totalMin / 60, 1)) ?></strong>Stunden gesamt</div>
    <div class="card stat"><strong><?= count($rows) ?></strong>Helfer/innen</div>
    <div class="card stat"><strong><?= count(array_unique(array_merge([], ...array_map(fn($p) => array_keys($p['tage']), array_values($rows))))) ?></strong>Termine mit Stunden</div>
</div>
<?php if ($openCount): ?>
    <div class="notice-box"><?= $openCount ?> Einteilung(en) vergangener Termine haben noch keine Stunden. Bitte im jeweiligen Termin unter „Stunden“ nachtragen.</div>
<?php endif; ?>
<table class="list">
    <thead><tr><th>Name</th><th class="right">Einsätze</th><th class="right">Stunden</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
        <tr>
            <td><a href="<?= e(url_admin('helferprofile', 'profil', ['id' => $p['mitglied_id']])) ?>"><?= e($p['name']) ?></a></td>
            <td class="right"><?= (int)$p['termine'] ?></td>
            <td class="right nowrap"><strong><?= e(hours_de($p['minuten'])) ?></strong></td>
            <td class="right"><a href="<?= e(url_admin('ehrenamt', 'nachweis', ['id' => $p['mitglied_id'], 'jahr' => $year])) ?>" target="_blank">Bescheinigung</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Für <?= $year ?> sind noch keine Stunden erfasst. Stunden werden nach jedem Dienst im Termin unter „Stunden“ eingetragen.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr><td>Gesamt</td><td></td><td class="right nowrap"><?= e(hours_de($totalMin)) ?></td><td></td></tr></tfoot><?php endif; ?>
</table>
