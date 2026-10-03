<?php
/** Mitgliederverwaltung inkl. Helfer-Profil (Qualifikationen, Verfügbarkeit) */

$statusOptions = ['aktiv' => 'Aktives Mitglied', 'foerdernd' => 'Fördermitglied', 'passiv' => 'Passiv', 'jugend' => 'Jugendrotkreuz', 'ausgetreten' => 'Ausgetreten'];

$fieldsPerson = [
    ['mitgliedsnummer', 'Mitgliedsnummer', 'text'],
    ['anrede', 'Anrede', 'select', ['options' => ['', 'Frau', 'Herr', 'Divers']]],
    ['vorname', 'Vorname', 'text', ['required' => true]],
    ['nachname', 'Nachname', 'text', ['required' => true]],
    ['geburtsdatum', 'Geburtsdatum', 'date'],
    ['strasse', 'Straße & Hausnummer', 'text'],
    ['plz', 'PLZ', 'text'],
    ['ort', 'Ort', 'text'],
    ['telefon', 'Telefon', 'tel'],
    ['mobil', 'Mobil', 'tel'],
    ['email', 'E-Mail', 'email'],
];
$fieldsMembership = [
    ['status', 'Status', 'select', ['options' => $statusOptions, 'assoc' => true]],
    ['eintritt', 'Eintritt', 'date'],
    ['austritt', 'Austritt', 'date'],
    ['funktion', 'Funktion / Amt', 'text', ['help' => 'z. B. Bereitschaftsleiterin, Kassenwart']],
    ['datenschutz_einwilligung', 'Datenschutz-Einwilligung am', 'date'],
    ['bereiche', 'Bereiche / Gemeinschaften', 'checklist', ['options' => area_options(), 'wide' => true]],
];
$fieldsProfile = [
    ['qualifikationen', 'Qualifikationen', 'checklist', ['options' => qualification_options(), 'wide' => true]],
    ['verfuegbarkeit', 'Verfügbarkeit', 'textarea', ['rows' => 2, 'wide' => true, 'help' => 'z. B. „werktags ab 17 Uhr, nicht in den Schulferien“']],
    ['profil_text', 'Über mich / Interessen', 'textarea', ['rows' => 3, 'wide' => true]],
    ['notizen', 'Interne Notizen', 'textarea', ['rows' => 3, 'wide' => true]],
];
$allFields = array_merge($fieldsPerson, $fieldsMembership, $fieldsProfile);
$id = (int)get('id', 0);

/* ---------- Speichern / Löschen ---------- */
if (is_post()) {
    if ($a === 'speichern') {
        $data = form_collect($allFields);
        if ($data['vorname'] === '' || $data['nachname'] === '') {
            flash('Vor- und Nachname sind Pflichtfelder.', 'error');
            redirect(url_admin('mitglieder', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
        }
        if (!array_key_exists($data['status'], $statusOptions)) {
            $data['status'] = 'aktiv';
        }
        $data['aktualisiert'] = now();
        if ($id) {
            update('mitglieder', $data, $id);
            audit('Mitglied geändert', member_name($data) . ' (#' . $id . ')');
        } else {
            $data['erstellt'] = now();
            $id = insert('mitglieder', $data);
            audit('Mitglied angelegt', member_name($data) . ' (#' . $id . ')');
        }
        flash('Gespeichert.');
        redirect(url_admin('mitglieder', 'bearbeiten', ['id' => $id]));
    }
    if ($a === 'loeschen' && $id) {
        $row = one('SELECT * FROM mitglieder WHERE id = ?', [$id]);
        if ($row) {
            q('UPDATE benutzer SET mitglied_id = NULL WHERE mitglied_id = ?', [$id]);
            q('DELETE FROM mitglieder WHERE id = ?', [$id]);
            audit('Mitglied gelöscht', member_name($row) . ' (#' . $id . ')');
            flash('Datensatz gelöscht.');
        }
        redirect(url_admin('mitglieder'));
    }
}

/* ---------- Filter ---------- */
$search = trim((string)get('q', ''));
$status = (string)get('status', '');
$bereich = (string)get('bereich', '');
$quali = (string)get('quali', '');
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(vorname LIKE ? OR nachname LIKE ? OR ort LIKE ? OR email LIKE ? OR mitgliedsnummer LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $search . '%'));
}
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
} else {
    $where[] = "status <> 'ausgetreten'";
}
if ($bereich !== '') {
    $where[] = 'bereiche LIKE ?';
    $params[] = '%' . $bereich . '%';
}
if ($quali !== '') {
    $where[] = 'qualifikationen LIKE ?';
    $params[] = '%' . $quali . '%';
}
$sqlWhere = implode(' AND ', $where);

/* ---------- CSV-Export ---------- */
if ($a === 'export') {
    $rows = all("SELECT * FROM mitglieder WHERE $sqlWhere ORDER BY nachname, vorname", $params);
    audit('Mitgliederliste exportiert', count($rows) . ' Datensätze');
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mitglieder-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM für Excel
    $cols = array_map(fn($f) => $f[0], $allFields);
    fputcsv($out, array_map(fn($f) => $f[1], $allFields), ';');
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($c) => csv_safe((string)($r[$c] ?? '')), $cols), ';');
    }
    exit;
}

/** Verhindert Formel-Injection beim Öffnen in Excel */
function csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/* ---------- Formular ---------- */
if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one('SELECT * FROM mitglieder WHERE id = ?', [$id]) : ['status' => 'aktiv', 'eintritt' => date('Y-m-d')];
    if (!$row) {
        redirect(url_admin('mitglieder'));
    }
    $title = $id ? member_name($row) : 'Neues Mitglied';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('mitglieder')) ?>">Mitglieder</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?>
        <?php if ($id && age($row['geburtsdatum'])): ?><small class="muted"><?= age($row['geburtsdatum']) ?> Jahre</small><?php endif; ?>
    </h1>
    <form method="post" action="<?= e(url_admin('mitglieder', 'speichern', $id ? ['id' => $id] : [])) ?>">
        <?= csrf_field() ?>
        <section class="panel"><h2>Person &amp; Kontakt</h2><div class="form-grid"><?= form_fields($fieldsPerson, $row) ?></div></section>
        <section class="panel"><h2>Mitgliedschaft</h2><div class="form-grid"><?= form_fields($fieldsMembership, $row) ?></div></section>
        <section class="panel"><h2>Helfer-Profil</h2><div class="form-grid"><?= form_fields($fieldsProfile, $row) ?></div></section>
        <div class="actions sticky-actions"><button class="btn">Speichern</button> <a href="<?= e(url_admin('mitglieder')) ?>">Abbrechen</a></div>
    </form>

    <?php if ($id):
        $einsaetze = all('SELECT t.id, t.datum, t.ort, s.aufgabe, e.status FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id
            JOIN bs_termine t ON t.id = s.termin_id WHERE e.mitglied_id = ? ORDER BY t.datum DESC LIMIT 30', [$id]);
        $konto = one('SELECT benutzername FROM benutzer WHERE mitglied_id = ?', [$id]); ?>
        <section class="panel">
            <h2>Blutspende-Einsätze</h2>
            <?php if (!$einsaetze): ?><p class="muted">Noch keine Einsätze.</p><?php endif; ?>
            <table class="list">
                <?php foreach ($einsaetze as $r): ?>
                    <tr><td><a href="<?= e(url_admin('blutspende', 'termin', ['id' => $r['id']])) ?>"><?= e(date_de($r['datum'])) ?></a></td><td><?= e($r['ort']) ?></td><td><?= e($r['aufgabe']) ?></td><td><?= e($r['status']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="muted small">Zugang zum internen Bereich: <?= $konto ? 'Benutzerkonto „' . e($konto['benutzername']) . '“' : 'kein Benutzerkonto verknüpft' ?>
                <?php if (has_role('admin') && !$konto): ?> – <a href="<?= e(url_admin('benutzer', 'neu', ['mitglied' => $id])) ?>">Konto anlegen</a><?php endif; ?></p>
        </section>
        <form method="post" action="<?= e(url_admin('mitglieder', 'loeschen', ['id' => $id])) ?>" class="danger-zone"
              data-confirm="Datensatz von <?= e($title) ?> endgültig löschen? (Tipp: Bei Austritt besser Status „Ausgetreten“ setzen und erst nach Ablauf der Aufbewahrungsfrist löschen.)">
            <?= csrf_field() ?><button class="btn btn-danger">Datensatz löschen</button>
        </form>
    <?php endif;
    return;
}

/* ---------- Liste ---------- */
$rows = all("SELECT * FROM mitglieder WHERE $sqlWhere ORDER BY nachname, vorname", $params);
$query = array_filter(['q' => $search, 'status' => $status, 'bereich' => $bereich, 'quali' => $quali]);
?>
<div class="head-row">
    <h1>Mitglieder <small class="muted">(<?= count($rows) ?>)</small></h1>
    <div>
        <a class="btn btn-outline" href="<?= e(url_admin('mitglieder', 'export', $query)) ?>">CSV-Export</a>
        <a class="btn" href="<?= e(url_admin('mitglieder', 'neu')) ?>">+ Neues Mitglied</a>
    </div>
</div>
<form class="filters" method="get">
    <input type="hidden" name="m" value="mitglieder">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, Ort, E-Mail, Nr. …">
    <select name="status"><option value="">Alle (ohne Ausgetretene)</option>
        <?php foreach ($statusOptions as $k => $v): ?><option value="<?= e($k) ?>"<?= $k === $status ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
    <select name="bereich"><option value="">Alle Bereiche</option>
        <?php foreach (area_options() as $o): ?><option<?= $o === $bereich ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
    </select>
    <select name="quali"><option value="">Alle Qualifikationen</option>
        <?php foreach (qualification_options() as $o): ?><option<?= $o === $quali ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline">Filtern</button>
    <?php if ($query): ?><a href="<?= e(url_admin('mitglieder')) ?>">zurücksetzen</a><?php endif; ?>
</form>
<table class="list">
    <thead><tr><th>Name</th><th>Status</th><th>Bereiche</th><th>Kontakt</th><th>Qualifikationen</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= e(url_admin('mitglieder', 'bearbeiten', ['id' => $r['id']])) ?>"><strong><?= e($r['nachname']) ?>, <?= e($r['vorname']) ?></strong></a>
                <?php if ($r['funktion']): ?><br><small class="muted"><?= e($r['funktion']) ?></small><?php endif; ?></td>
            <td><span class="badge <?= $r['status'] === 'aktiv' ? 'ok' : '' ?>"><?= e($statusOptions[$r['status']] ?? $r['status']) ?></span></td>
            <td class="small"><?= e($r['bereiche']) ?></td>
            <td class="small"><?= e($r['mobil'] ?: $r['telefon']) ?><?php if ($r['email']): ?><br><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?></td>
            <td class="small"><?= e($r['qualifikationen']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Keine Einträge gefunden.</td></tr><?php endif; ?>
    </tbody>
</table>
