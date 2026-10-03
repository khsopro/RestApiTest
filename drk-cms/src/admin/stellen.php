<?php
/** Blutspendedienst: Stellenbeschreibungen der einzelnen Aufgaben (Helfer/innen dürfen lesen) */

$canEdit = has_role('blutspende');
$id = (int)get('id', 0);
$fields = [
    ['titel', 'Bezeichnung der Aufgabe', 'text', ['required' => true, 'help' => 'Gleicher Name wie in der Personaleinteilung, dann wird die Beschreibung dort automatisch verlinkt.']],
    ['zeitaufwand', 'Zeitaufwand', 'text', ['help' => 'z. B. „gesamte Spendezeit“ oder „ca. 2 Stunden vor Beginn“']],
    ['kurz', 'Kurzbeschreibung (ein Satz)', 'text', ['wide' => true]],
    ['aufgaben', 'Aufgaben', 'textarea', ['rows' => 7, 'wide' => true, 'help' => 'Formatierung: - Aufzählung, **fett**, Leerzeile = neuer Absatz']],
    ['ablauf', 'Checkliste / Ablauf (ein Schritt pro Zeile)', 'textarea', ['rows' => 7, 'wide' => true]],
    ['anforderungen', 'Anforderungen', 'textarea', ['rows' => 3, 'wide' => true]],
    ['qualifikation', 'Erforderliche Qualifikation', 'select', ['options' => array_merge([''], qualification_options())]],
    ['ansprechpartner', 'Ansprechpartner/in', 'text'],
    ['hinweise', 'Wichtige Hinweise (Sicherheit, Hygiene, Kleidung)', 'textarea', ['rows' => 4, 'wide' => true]],
    ['sortierung', 'Reihenfolge', 'number', ['help' => 'Kleinere Zahl = weiter vorne (auch in der Einweisungsmappe)']],
];
$fieldsStd = [
    ['standard', 'Bei neuen Blutspendeterminen automatisch als Schicht anlegen', 'checkbox', ['wide' => true]],
    ['std_von', 'von', 'text', ['help' => 'Uhrzeit (z. B. 13:30) oder „beginn“ / „ende“ des Termins']],
    ['std_bis', 'bis', 'text', ['help' => 'Uhrzeit oder „beginn“ / „ende“']],
    ['std_anzahl', 'Anzahl Personen', 'number'],
];
$tab = get('archiv') ? 'archiv' : 'aktiv';
$backToList = fn(bool $archiv = false) => url_admin('stellen', '', $archiv ? ['archiv' => 1] : []);

/* ---------- Aktionen (nur Blutspende-Team) ---------- */
if (is_post()) {
    if (!$canEdit) {
        http_response_code(403);
        exit('Keine Berechtigung.');
    }
    if ($a === 'speichern') {
        $data = form_collect(array_merge($fields, $fieldsStd));
        $data['sortierung'] = (int)$data['sortierung'];
        $data['std_anzahl'] = max(1, (int)$data['std_anzahl']);
        foreach (['std_von', 'std_bis'] as $k) {
            $v = mb_strtolower(trim((string)$data[$k]));
            $data[$k] = in_array($v, ['beginn', 'ende'], true) || preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '';
        }
        if ($data['titel'] === '') {
            flash('Bitte eine Bezeichnung angeben.', 'error');
            redirect(url_admin('stellen', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
        }
        $sameName = job_ids()[mb_strtolower(trim($data['titel']))] ?? null;
        if ($sameName && $sameName !== $id) {
            flash('Eine Stellenbeschreibung mit dieser Bezeichnung gibt es bereits.', 'error');
            redirect(url_admin('stellen', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
        }
        $data['aktualisiert'] = now();
        if ($id) {
            update('bs_stellen', $data, $id);
        } else {
            $id = insert('bs_stellen', $data);
        }
        audit('Stellenbeschreibung gespeichert', $data['titel']);
        flash('Stellenbeschreibung gespeichert.');
        redirect(url_admin('stellen', 'ansehen', ['id' => $id]));
    }
    if ($a === 'loeschen' && $id) {
        $titel = (string)val('SELECT titel FROM bs_stellen WHERE id = ?', [$id]);
        q('DELETE FROM bs_stellen WHERE id = ?', [$id]);
        audit('Stellenbeschreibung gelöscht', $titel);
        flash('Stellenbeschreibung „' . $titel . '“ gelöscht.');
        redirect($backToList((bool)post('archiv')));
    }
    if (in_array($a, ['archivieren', 'wiederherstellen'], true) && $id) {
        $titel = (string)val('SELECT titel FROM bs_stellen WHERE id = ?', [$id]);
        update('bs_stellen', ['archiviert' => $a === 'archivieren' ? 1 : 0, 'aktualisiert' => now()], $id);
        audit('Stellenbeschreibung ' . ($a === 'archivieren' ? 'archiviert' : 'wiederhergestellt'), $titel);
        flash('„' . $titel . '“ ' . ($a === 'archivieren'
            ? 'archiviert. Sie wird nicht mehr verlinkt und nicht mehr als Standard-Schicht angelegt.'
            : 'wiederhergestellt.'));
        redirect($backToList($a === 'wiederherstellen'));
    }
    if ($a === 'kopieren' && $id) {
        $row = one('SELECT * FROM bs_stellen WHERE id = ?', [$id]);
        if ($row) {
            unset($row['id']);
            $base = $row['titel'] . ' (Kopie)';
            $row['titel'] = $base;
            for ($n = 2; isset(job_ids()[mb_strtolower($row['titel'])]) || val('SELECT id FROM bs_stellen WHERE titel = ?', [$row['titel']]); $n++) {
                $row['titel'] = $base . ' ' . $n;
            }
            $row['standard'] = 0;
            $row['archiviert'] = 0;
            $row['aktualisiert'] = now();
            $new = insert('bs_stellen', $row);
            flash('Kopie angelegt – bitte Bezeichnung anpassen.');
            redirect(url_admin('stellen', 'bearbeiten', ['id' => $new]));
        }
        redirect($backToList());
    }
    if ($a === 'vorlagen') {
        $n = seed_job_descriptions();
        flash($n ? $n . ' Vorlagen angelegt. Bitte an die Gegebenheiten vor Ort anpassen.' : 'Alle Vorlagen sind bereits vorhanden.');
        redirect(url_admin('stellen'));
    }
}

/** Eine Stellenbeschreibung als HTML (für Ansicht und Druck) */
function render_job(array $s): string
{
    $html = '';
    if ($s['kurz']) {
        $html .= '<p class="lead">' . e($s['kurz']) . '</p>';
    }
    $facts = array_filter([
        'Zeitaufwand' => $s['zeitaufwand'],
        'Erforderliche Qualifikation' => $s['qualifikation'],
        'Ansprechpartner/in' => $s['ansprechpartner'],
    ]);
    if ($facts) {
        $html .= '<dl class="dl">';
        foreach ($facts as $k => $v) {
            $html .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
        }
        $html .= '</dl>';
    }
    if (trim((string)$s['aufgaben']) !== '') {
        $html .= '<h3>Aufgaben</h3><div class="prose">' . md($s['aufgaben']) . '</div>';
    }
    $steps = array_filter(array_map('trim', preg_split('/\R/', (string)$s['ablauf']) ?: []));
    if ($steps) {
        $html .= '<h3>Checkliste</h3><ul class="job-checklist">';
        foreach ($steps as $step) {
            $html .= '<li><label><input type="checkbox"> ' . e(ltrim($step, '-* ')) . '</label></li>';
        }
        $html .= '</ul>';
    }
    if (trim((string)$s['anforderungen']) !== '') {
        $html .= '<h3>Anforderungen</h3><div class="prose">' . md($s['anforderungen']) . '</div>';
    }
    if (trim((string)$s['hinweise']) !== '') {
        $html .= '<div class="job-notice"><strong>Wichtig:</strong><div class="prose">' . md($s['hinweise']) . '</div></div>';
    }
    return $html;
}

/* ---------- Druck (einzeln oder alle als Einweisungsmappe) ---------- */
if ($a === 'druck') {
    $rows = $id ? all('SELECT * FROM bs_stellen WHERE id = ?', [$id]) : all('SELECT * FROM bs_stellen WHERE archiviert = 0 ORDER BY sortierung, titel');
    $printView = true;
    $title = $id && $rows ? 'Stellenbeschreibung ' . $rows[0]['titel'] : 'Einweisungsmappe Blutspende';
    echo '<div class="print-page">';
    if (!$id) {
        echo '<h1>Einweisungsmappe Blutspende</h1><p>' . e(setting('seitentitel')) . ' · Stand ' . e(date_de(date('Y-m-d'))) . '</p>';
    }
    foreach ($rows as $s) {
        echo '<section class="job-print"><h' . ($id ? 1 : 2) . '>' . e($s['titel']) . '</h' . ($id ? 1 : 2) . '>' . render_job($s) . '</section>';
    }
    echo '</div>';
    return;
}

/* ---------- Ansehen ---------- */
if ($a === 'ansehen' && $id) {
    $s = one('SELECT * FROM bs_stellen WHERE id = ?', [$id]);
    if (!$s || ((int)$s['archiviert'] === 1 && !$canEdit)) {
        redirect(url_admin('stellen'));
    }
    $title = $s['titel'];
    $upcoming = array_slice(array_values(array_filter(all("SELECT t.id, t.datum, t.ort, s.aufgabe, s.von, s.bis, s.benoetigt,
            (SELECT COUNT(*) FROM bs_einteilung e WHERE e.schicht_id = s.id AND e.status = 'zugesagt') AS belegt
        FROM bs_schichten s JOIN bs_termine t ON t.id = s.termin_id
        WHERE t.datum >= ? ORDER BY t.datum, s.von", [date('Y-m-d')]),
        fn($r) => mb_strtolower(trim($r['aufgabe'])) === mb_strtolower(trim($s['titel'])))), 0, 10);
    ?>
    <p class="crumbs"><a href="<?= e(url_admin(has_role('blutspende') ? 'blutspende' : 'profil')) ?>"><?= has_role('blutspende') ? 'Blutspende' : 'Mein Profil' ?></a> ›
        <a href="<?= e(url_admin('stellen')) ?>">Stellenbeschreibungen</a> › <?= e($s['titel']) ?></p>
    <div class="head-row">
        <h1><?= e($s['titel']) ?><?= (int)$s['archiviert'] ? ' <span class="badge">archiviert</span>' : '' ?></h1>
        <div class="btn-group">
            <a class="btn btn-outline" href="<?= e(url_admin('stellen', 'druck', ['id' => $id])) ?>" target="_blank">Drucken</a>
            <?php if ($canEdit): ?><?= job_actions($s) ?><?php endif; ?>
        </div>
    </div>
    <?php if ((int)$s['standard'] && !(int)$s['archiviert']): ?>
        <p class="muted">Wird bei neuen Terminen automatisch als Schicht angelegt: <?= e(std_time_label((string)$s['std_von'])) ?>–<?= e(std_time_label((string)$s['std_bis'])) ?>, <?= (int)$s['std_anzahl'] ?> Person(en).</p>
    <?php endif; ?>
    <div class="grid-2 job-layout">
        <section class="panel job"><?= render_job($s) ?></section>
        <section class="panel">
            <h2>Nächste Einsätze in dieser Aufgabe</h2>
            <?php if (!$upcoming): ?><p class="muted">Derzeit nicht eingeplant.</p><?php endif; ?>
            <table class="list">
                <?php foreach ($upcoming as $u): ?>
                    <tr>
                        <td class="nowrap"><?= has_role('blutspende')
                                ? '<a href="' . e(url_admin('blutspende', 'termin', ['id' => $u['id'], 'tab' => 'personal'])) . '">' . e(date_de($u['datum'], true)) . '</a>'
                                : e(date_de($u['datum'], true)) ?></td>
                        <td><?= e($u['ort']) ?><br><small class="muted"><?= e($u['von']) ?>–<?= e($u['bis']) ?> Uhr</small></td>
                        <td><?= staffing_badge((int)$u['belegt'], (int)$u['benoetigt']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if (!has_role('blutspende')): ?><p><a href="<?= e(url_admin('profil')) ?>#einsaetze">Zur Selbst-Eintragung →</a></p><?php endif; ?>
        </section>
    </div>
    <?php
    return;
}

/* ---------- Anlegen / Bearbeiten ---------- */
if (($a === 'neu' || $a === 'bearbeiten') && $canEdit) {
    $row = $id ? one('SELECT * FROM bs_stellen WHERE id = ?', [$id]) : ['sortierung' => 100, 'std_von' => 'beginn', 'std_bis' => 'ende', 'std_anzahl' => 1];
    if (!$row) {
        redirect(url_admin('stellen'));
    }
    $title = $id ? $row['titel'] . ' bearbeiten' : 'Neue Stellenbeschreibung';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <a href="<?= e(url_admin('stellen')) ?>">Stellenbeschreibungen</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <form method="post" action="<?= e(url_admin('stellen', 'speichern', $id ? ['id' => $id] : [])) ?>">
        <?= csrf_field() ?>
        <section class="panel"><div class="form-grid"><?= form_fields($fields, $row) ?></div></section>
        <section class="panel">
            <h2>Standard-Schicht</h2>
            <p class="muted small">Ist das Häkchen gesetzt, wird diese Aufgabe beim Anlegen eines neuen Blutspendetermins automatisch als Schicht eingeplant.</p>
            <div class="form-grid"><?= form_fields($fieldsStd, $row) ?></div>
        </section>
        <div class="actions sticky-actions"><button class="btn">Speichern</button>
            <a href="<?= e($id ? url_admin('stellen', 'ansehen', ['id' => $id]) : url_admin('stellen')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id): ?>
        <div class="danger-zone btn-group"><?= job_actions($row, false) ?></div>
    <?php endif;
    return;
}

/* ---------- Übersicht ---------- */
$archivCount = (int)val('SELECT COUNT(*) FROM bs_stellen WHERE archiviert = 1');
if ($tab === 'archiv' && !$canEdit) {
    $tab = 'aktiv';
}
$rows = all('SELECT * FROM bs_stellen WHERE archiviert = ? ORDER BY sortierung, titel', [$tab === 'archiv' ? 1 : 0]);
$planned = [];
foreach (all('SELECT s.aufgabe FROM bs_schichten s JOIN bs_termine t ON t.id = s.termin_id WHERE t.datum >= ?', [date('Y-m-d')]) as $r) {
    $key = mb_strtolower(trim($r['aufgabe']));
    $planned[$key] = ($planned[$key] ?? 0) + 1;
}
$title = 'Stellenbeschreibungen';
?>
<p class="crumbs"><a href="<?= e(url_admin(has_role('blutspende') ? 'blutspende' : 'profil')) ?>"><?= has_role('blutspende') ? 'Blutspende' : 'Mein Profil' ?></a> › Stellenbeschreibungen</p>
<div class="head-row">
    <h1>Stellenbeschreibungen</h1>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= e(url_admin('stellen', 'druck')) ?>" target="_blank">Einweisungsmappe drucken</a>
        <?php if ($canEdit): ?><a class="btn" href="<?= e(url_admin('stellen', 'neu')) ?>">+ Neue Aufgabe</a><?php endif; ?>
    </div>
</div>
<p class="muted">Was ist bei welcher Aufgabe zu tun? Die Beschreibungen sind in der Personaleinteilung und bei der Selbst-Eintragung mit ⓘ verlinkt.</p>
<?php if ($canEdit): ?>
    <nav class="tabs">
        <a href="<?= e($backToList()) ?>" class="<?= $tab === 'aktiv' ? 'active' : '' ?>">Aktiv</a>
        <a href="<?= e($backToList(true)) ?>" class="<?= $tab === 'archiv' ? 'active' : '' ?>">Archiv (<?= $archivCount ?>)</a>
    </nav>
<?php endif; ?>
<?php if (!$rows && $tab === 'aktiv'): ?>
    <div class="panel">
        <p>Noch keine aktiven Stellenbeschreibungen.</p>
        <?php if ($canEdit): ?><?= post_button(url_admin('stellen', 'vorlagen'), 'Vorlagen für die Standard-Aufgaben anlegen', [], '', false, '', 'btn') ?><?php endif; ?>
    </div>
<?php elseif (!$rows): ?>
    <p class="muted">Das Archiv ist leer. Archivierte Aufgaben bleiben erhalten, werden aber nicht mehr verlinkt und nicht mehr automatisch eingeplant.</p>
<?php endif; ?>
<div class="profile-grid">
    <?php foreach ($rows as $s): $geplant = $planned[mb_strtolower(trim($s['titel']))] ?? 0; ?>
        <article class="profile-card job-card<?= (int)$s['archiviert'] ? ' archived' : '' ?>">
            <h3><a href="<?= e(url_admin('stellen', 'ansehen', ['id' => $s['id']])) ?>"><?= e($s['titel']) ?></a></h3>
            <?php if ($s['kurz']): ?><p class="muted"><?= e($s['kurz']) ?></p><?php endif; ?>
            <div class="tags">
                <?= $s['qualifikation'] ? '<span class="tag">' . e($s['qualifikation']) . '</span>' : '' ?>
                <?= (int)$s['standard'] ? '<span class="tag tag-grey" title="wird bei neuen Terminen automatisch eingeplant">Standard-Schicht · ' . (int)$s['std_anzahl'] . ' Pers.</span>' : '' ?>
            </div>
            <div class="profile-stats">
                <?php if ($s['zeitaufwand']): ?><span><?= e($s['zeitaufwand']) ?></span><?php endif; ?>
                <span><strong><?= $geplant ?></strong> geplante Schichten</span>
            </div>
            <?php if ($canEdit): ?><div class="card-actions"><?= job_actions($s) ?></div><?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
<?php
/** Bearbeiten / Kopieren / Archivieren bzw. Wiederherstellen / Löschen */
function job_actions(array $s, bool $withEdit = true): string
{
    $id = (int)$s['id'];
    $archived = (int)$s['archiviert'] === 1;
    $html = $withEdit && !$archived ? '<a class="btn btn-small" href="' . e(url_admin('stellen', 'bearbeiten', ['id' => $id])) . '">Bearbeiten</a>' : '';
    if (!$archived) {
        $html .= post_button(url_admin('stellen', 'kopieren', ['id' => $id]), 'Kopieren', [], 'Als Vorlage für eine neue Aufgabe kopieren');
        $html .= post_button(url_admin('stellen', 'archivieren', ['id' => $id]), 'Archivieren', [], 'Ausblenden, aber aufbewahren', false,
            'Aufgabe „' . $s['titel'] . '“ archivieren? Sie wird nicht mehr verlinkt und nicht mehr automatisch eingeplant.');
    } else {
        $html .= post_button(url_admin('stellen', 'wiederherstellen', ['id' => $id]), 'Wiederherstellen', [], '', false, '', 'btn btn-small');
    }
    return $html . post_button(url_admin('stellen', 'loeschen', ['id' => $id]), 'Löschen', ['archiv' => $archived ? 1 : ''], 'Endgültig löschen', false,
        'Aufgabe „' . $s['titel'] . '“ endgültig löschen? Bereits geplante Schichten bleiben erhalten.', 'btn btn-small btn-danger');
}

/** „beginn“/„ende“ lesbar machen */
function std_time_label(string $v): string
{
    return match ($v) {
        'beginn' => 'Terminbeginn',
        'ende'   => 'Terminende',
        default  => $v,
    };
}
