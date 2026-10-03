<?php
/** Blutspendedienst: Profile der Helferinnen und Helfer */

$id = (int)get('id', 0);
$today = date('Y-m-d');
$canDelete = has_role('verwaltung'); // Löschen entfernt den ganzen Mitgliedsdatensatz → nur Verwaltung
$profileFields = [
    ['vorname', 'Vorname', 'text', ['required' => true]],
    ['nachname', 'Nachname', 'text', ['required' => true]],
    ['mobil', 'Mobil', 'tel'],
    ['telefon', 'Telefon', 'tel'],
    ['email', 'E-Mail', 'email', ['wide' => true]],
    ['qualifikationen', 'Qualifikationen', 'checklist', ['options' => qualification_options(), 'wide' => true]],
    ['verfuegbarkeit', 'Verfügbarkeit', 'textarea', ['rows' => 2, 'wide' => true, 'help' => 'z. B. „werktags ab 16 Uhr“, „nur samstags“']],
    ['profil_text', 'Über mich / Interessen / bevorzugte Aufgaben', 'textarea', ['rows' => 3, 'wide' => true]],
];
$back = fn(string $tab = '') => url_admin('helferprofile', '', $tab !== '' ? [$tab => 1] : []);

/* ---------- Aktionen ---------- */
if (is_post()) {
    $m = $id ? one('SELECT * FROM mitglieder WHERE id = ?', [$id]) : null;
    switch ($a) {
        case 'speichern':
            $data = form_collect($profileFields);
            if ($data['vorname'] === '' || $data['nachname'] === '') {
                flash('Vor- und Nachname sind Pflichtfelder.', 'error');
                redirect(url_admin('helferprofile', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
            }
            if (!empty($_FILES['foto_datei']['name'])) {
                $result = store_upload($_FILES['foto_datei'], 'Profilfoto ' . member_name($data), true);
                if (is_string($result)) {
                    flash($result, 'error');
                } else {
                    $data['foto'] = $result;
                }
            } elseif (isset($_POST['foto_entfernen'])) {
                $data['foto'] = null;
            }
            $data['aktualisiert'] = now();
            if ($m) {
                update('mitglieder', $data, $id);
                audit('Helfer-Profil geändert', member_name($data) . ' (#' . $id . ')');
            } else {
                $data += ['status' => 'aktiv', 'bereiche' => 'Blutspende', 'bs_archiviert' => 0, 'erstellt' => now()];
                $id = insert('mitglieder', $data);
                audit('Helfer-Profil angelegt', member_name($data) . ' (#' . $id . ')');
            }
            flash('Profil gespeichert.');
            redirect(url_admin('helferprofile', 'profil', ['id' => $id]));

        case 'aufnehmen':
            $m = one("SELECT * FROM mitglieder WHERE id = ? AND status <> 'ausgetreten'", [(int)post('mitglied_id')]);
            if ($m) {
                update('mitglieder', ['bereiche' => areas_with($m['bereiche'], 'Blutspende'), 'bs_archiviert' => 0, 'aktualisiert' => now()], (int)$m['id']);
                audit('Ins Blutspende-Team aufgenommen', member_name($m));
                flash(member_name($m) . ' gehört jetzt zum Blutspende-Team.');
                redirect(url_admin('helferprofile', 'profil', ['id' => $m['id']]));
            }
            redirect($back());

        case 'archivieren':
        case 'wiederherstellen':
            if ($m) {
                $restore = $a === 'wiederherstellen';
                update('mitglieder', ['bs_archiviert' => $restore ? 0 : 1, 'aktualisiert' => now(),
                    'bereiche' => $restore ? areas_with($m['bereiche'], 'Blutspende') : $m['bereiche']], $id);
                audit('Helfer-Profil ' . ($restore ? 'wiederhergestellt' : 'archiviert'), member_name($m));
                flash(member_name($m) . ($restore ? ' ist wieder im Blutspende-Team.'
                    : ' wurde archiviert und erscheint nicht mehr in der Personaleinteilung. Die Mitgliedsdaten bleiben erhalten.'));
            }
            redirect($back($a === 'wiederherstellen' ? 'archiv' : ''));

        case 'loeschen':
            if (!$canDelete) {
                http_response_code(403);
                exit('Löschen dürfen nur Personen mit der Rolle „Verwaltung“. Bitte stattdessen archivieren.');
            }
            if ($m) {
                q('UPDATE benutzer SET mitglied_id = NULL WHERE mitglied_id = ?', [$id]);
                q('DELETE FROM mitglieder WHERE id = ?', [$id]);
                audit('Helfer-Profil gelöscht', member_name($m) . ' (#' . $id . ')');
                flash('Profil von ' . member_name($m) . ' gelöscht.');
            }
            redirect($back((bool)post('archiv') ? 'archiv' : ''));
    }
}

/* ---------- Profil anlegen / bearbeiten ---------- */
if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one("SELECT * FROM mitglieder WHERE id = ? AND status <> 'ausgetreten'", [$id]) : [];
    if ($a === 'bearbeiten' && !$row) {
        redirect($back());
    }
    $title = $id ? member_name($row) . ' bearbeiten' : 'Neues Helfer-Profil';
    $candidates = $id ? [] : all("SELECT id, vorname, nachname FROM mitglieder WHERE status NOT IN ('ausgetreten', 'foerdernd')
        AND (bereiche IS NULL OR bereiche NOT LIKE ?) ORDER BY nachname, vorname", ['%Blutspende%']);
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <a href="<?= e($back()) ?>">Helfer-Profile</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <?php if ($candidates): ?>
        <form method="post" action="<?= e(url_admin('helferprofile', 'aufnehmen')) ?>" class="panel inline-form">
            <?= csrf_field() ?>
            <h2>Schon Mitglied im Verein?</h2>
            <p class="muted small">Dann einfach ins Blutspende-Team aufnehmen – so entsteht kein doppelter Datensatz.</p>
            <div class="filters">
                <select name="mitglied_id" required><option value="">Mitglied auswählen …</option>
                    <?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nachname'] . ', ' . $c['vorname']) ?></option><?php endforeach; ?>
                </select>
                <button class="btn">Ins Team aufnehmen</button>
            </div>
        </form>
        <h2>Oder neue Person anlegen</h2>
    <?php endif; ?>
    <form method="post" action="<?= e(url_admin('helferprofile', 'speichern', $id ? ['id' => $id] : [])) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <section class="panel">
            <div class="form-grid">
                <div class="field wide profile-head">
                    <?= $id ? member_avatar($row, 'avatar big') : '' ?>
                    <div class="field">
                        <label for="foto_datei">Profilfoto (JPG, PNG oder WebP – nur mit Einwilligung)</label>
                        <input id="foto_datei" type="file" name="foto_datei" accept="image/jpeg,image/png,image/webp">
                        <?php if (!empty($row['foto'])): ?><label class="check"><input type="checkbox" name="foto_entfernen" value="1"> Foto entfernen</label><?php endif; ?>
                    </div>
                </div>
                <?= form_fields($profileFields, $row) ?>
            </div>
            <?php if (!$id): ?><p class="muted small">Die Person wird als aktives Mitglied mit dem Bereich „Blutspende“ angelegt. Adresse und Mitgliedsdaten ergänzt die Vereinsverwaltung.</p><?php endif; ?>
        </section>
        <div class="actions sticky-actions"><button class="btn">Speichern</button>
            <a href="<?= e($id ? url_admin('helferprofile', 'profil', ['id' => $id]) : $back()) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id): ?><div class="danger-zone btn-group"><?= profile_actions($row, $canDelete, false) ?></div><?php endif;
    return;
}

/* ---------- Einzelnes Profil ---------- */
if ($a === 'profil' && $id) {
    $m = one("SELECT * FROM mitglieder WHERE id = ? AND status <> 'ausgetreten'", [$id]);
    if (!$m) {
        redirect(url_admin('helferprofile'));
    }
    $stats = helper_stats()[$id] ?? ['einsaetze' => 0, 'letzter' => null, 'naechster' => null];
    $year = (int)date('Y');
    $hours = volunteer_hours($year . '-01-01', $year . '-12-31', $id)[$id]['minuten'] ?? 0;
    $einsaetze = all('SELECT t.id, t.datum, t.ort, s.aufgabe, ' . SQL_EFF_VON . ' AS von, ' . SQL_EFF_BIS . ' AS bis, e.status, e.ist_von, e.ist_bis, e.nicht_erschienen FROM bs_einteilung e
        JOIN bs_schichten s ON s.id = e.schicht_id JOIN bs_termine t ON t.id = s.termin_id
        WHERE e.mitglied_id = ? ORDER BY t.datum DESC, von LIMIT 50', [$id]);
    $aufgaben = all("SELECT s.aufgabe, COUNT(*) AS n FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id
        WHERE e.mitglied_id = ? AND e.status = 'zugesagt' GROUP BY s.aufgabe ORDER BY n DESC LIMIT 5", [$id]);
    $title = member_name($m);
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <a href="<?= e(url_admin('helferprofile')) ?>">Helfer-Profile</a> › <?= e($title) ?></p>
    <?php if ((int)$m['bs_archiviert']): ?><div class="notice-box">Dieses Profil ist archiviert und erscheint nicht in der Personaleinteilung.</div><?php endif; ?>
    <div class="head-row">
        <h1><?= e($title) ?></h1>
        <div class="btn-group"><?= profile_actions($m, $canDelete) ?></div>
    </div>
    <section class="panel profile-detail">
        <?= member_avatar($m, 'avatar big') ?>
        <div>
            <?php if ($m['funktion']): ?><p class="muted"><?= e($m['funktion']) ?></p><?php endif; ?>
            <ul class="contact-list">
                <?php if ($m['mobil']): ?><li>Mobil: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['mobil'])) ?>"><?= e($m['mobil']) ?></a></li><?php endif; ?>
                <?php if ($m['telefon']): ?><li>Telefon: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['telefon'])) ?>"><?= e($m['telefon']) ?></a></li><?php endif; ?>
                <?php if ($m['email']): ?><li>E-Mail: <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></li><?php endif; ?>
            </ul>
            <h3>Qualifikationen</h3>
            <?= qualification_tags((string)$m['qualifikationen']) ?: '<p class="muted">keine angegeben</p>' ?>
            <?php if ($m['verfuegbarkeit']): ?><h3>Verfügbarkeit</h3><p class="pre"><?= e($m['verfuegbarkeit']) ?></p><?php endif; ?>
            <?php if ($m['profil_text']): ?><h3>Über mich</h3><p class="pre"><?= e($m['profil_text']) ?></p><?php endif; ?>
            <?php if ($m['bereiche']): ?><h3>Bereiche</h3><p><?= e($m['bereiche']) ?></p><?php endif; ?>
        </div>
    </section>
    <div class="cards">
        <div class="card stat"><strong><?= (int)$stats['einsaetze'] ?></strong>Einsätze bisher</div>
        <div class="card stat"><strong><?= $stats['letzter'] ? e(date_de($stats['letzter'])) : '–' ?></strong>letzter Einsatz</div>
        <div class="card stat"><strong><?= $stats['naechster'] ? e(date_de($stats['naechster'])) : '–' ?></strong>nächster Einsatz</div>
        <a class="card stat" href="<?= e(url_admin('ehrenamt', 'nachweis', ['id' => $id, 'jahr' => $year])) ?>" target="_blank" title="Bescheinigung drucken"><strong><?= e(num_de($hours / 60, 1)) ?></strong>Ehrenamtsstunden <?= $year ?></a>
    </div>
    <div class="grid-2">
        <section class="panel">
            <h2>Einsätze</h2>
            <?php if (!$einsaetze): ?><p class="muted">Noch keine Einsätze.</p><?php else: ?>
                <table class="list">
                    <?php foreach ($einsaetze as $r): ?>
                        <tr class="<?= $r['datum'] >= $today ? '' : 'past' ?>">
                            <td class="nowrap"><a href="<?= e(url_admin('blutspende', 'termin', ['id' => $r['id'], 'tab' => 'personal'])) ?>"><?= e(date_de($r['datum'])) ?></a></td>
                            <td><?= e($r['aufgabe']) ?><br><small class="muted"><?= e($r['ort']) ?>, geplant <?= e($r['von']) ?>–<?= e($r['bis']) ?><?php if ((int)$r['nicht_erschienen']): ?> · <span class="warn-text">nicht erschienen</span><?php elseif ($r['ist_von']): ?> · tatsächlich <?= e($r['ist_von']) ?>–<?= e($r['ist_bis']) ?><?php endif; ?></small></td>
                            <td><span class="badge <?= $r['status'] === 'zugesagt' ? 'ok' : ($r['status'] === 'abgesagt' ? 'bad' : 'warn') ?>"><?= e($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2>Häufigste Aufgaben</h2>
            <?php if (!$aufgaben): ?><p class="muted">Noch keine Daten.</p><?php endif; ?>
            <ul class="plain">
                <?php foreach ($aufgaben as $r): ?><li><?= e($r['aufgabe']) ?> <span class="muted">(<?= (int)$r['n'] ?>×)</span></li><?php endforeach; ?>
            </ul>
        </section>
    </div>
    <?php
    return;
}

/* ---------- Filter ---------- */
$search = trim((string)get('q', ''));
$quali = (string)get('quali', '');
$alle = (bool)get('alle');
$archiv = (bool)get('archiv');
$where = ["m.status NOT IN ('ausgetreten', 'foerdernd')", 'm.bs_archiviert = ' . ($archiv ? 1 : 0)];
$params = [];
if ($archiv) {
    $alle = false;
} elseif (!$alle) {
    // Blutspende-Team: Bereich „Blutspende“ oder schon einmal eingeteilt
    $where[] = '(m.bereiche LIKE ? OR EXISTS (SELECT 1 FROM bs_einteilung e WHERE e.mitglied_id = m.id))';
    $params[] = '%Blutspende%';
}
if ($search !== '') {
    $where[] = '(m.vorname LIKE ? OR m.nachname LIKE ? OR m.verfuegbarkeit LIKE ? OR m.profil_text LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
if ($quali !== '') {
    $where[] = 'm.qualifikationen LIKE ?';
    $params[] = '%' . $quali . '%';
}
$rows = all('SELECT m.* FROM mitglieder m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.nachname, m.vorname', $params);
$stats = helper_stats();

/* ---------- Druck: Telefonliste ---------- */
if ($a === 'telefonliste') {
    $printView = true;
    $title = 'Telefonliste Blutspende-Team';
    echo '<div class="print-page"><h1>Telefonliste Blutspende-Team</h1><p>Stand: ' . e(date_de($today)) . ' · vertraulich, nur für den internen Gebrauch</p>'
        . '<table class="print-table list"><thead><tr><th>Name</th><th>Mobil</th><th>Telefon</th><th>Qualifikationen</th></tr></thead><tbody>';
    foreach ($rows as $m) {
        echo '<tr><td>' . e($m['nachname'] . ', ' . $m['vorname']) . '</td><td>' . e($m['mobil']) . '</td><td>' . e($m['telefon']) . '</td><td class="small">' . e($m['qualifikationen']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    audit('Telefonliste Blutspende gedruckt', count($rows) . ' Personen');
    return;
}

$query = array_filter(['q' => $search, 'quali' => $quali, 'alle' => $alle ? 1 : null, 'archiv' => $archiv ? 1 : null]);
$filterOnly = array_filter(['q' => $search, 'quali' => $quali]);
$archivCount = (int)val("SELECT COUNT(*) FROM mitglieder WHERE bs_archiviert = 1 AND status <> 'ausgetreten'");
?>
<p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › Helfer-Profile</p>
<div class="head-row">
    <h1>Helfer-Profile <small class="muted">(<?= count($rows) ?>)</small></h1>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= e(url_admin('helferprofile', 'telefonliste', $query)) ?>" target="_blank">Telefonliste drucken</a>
        <a class="btn" href="<?= e(url_admin('helferprofile', 'neu')) ?>">+ Neues Profil</a>
    </div>
</div>
<nav class="tabs">
    <a href="<?= e(url_admin('helferprofile', '', $filterOnly)) ?>" class="<?= !$alle && !$archiv ? 'active' : '' ?>">Blutspende-Team</a>
    <a href="<?= e(url_admin('helferprofile', '', $filterOnly + ['alle' => 1])) ?>" class="<?= $alle ? 'active' : '' ?>">Alle aktiven Mitglieder</a>
    <a href="<?= e(url_admin('helferprofile', '', $filterOnly + ['archiv' => 1])) ?>" class="<?= $archiv ? 'active' : '' ?>">Archiv (<?= $archivCount ?>)</a>
</nav>
<form class="filters" method="get">
    <input type="hidden" name="m" value="helferprofile">
    <?php if ($alle): ?><input type="hidden" name="alle" value="1"><?php endif; ?>
    <?php if ($archiv): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, Verfügbarkeit, Interessen …">
    <select name="quali"><option value="">Alle Qualifikationen</option>
        <?php foreach (qualification_options() as $o): ?><option<?= $o === $quali ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline">Filtern</button>
    <?php if ($search !== '' || $quali !== ''): ?><a href="<?= e(url_admin('helferprofile', '', $alle ? ['alle' => 1] : [])) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php if (!$rows && $archiv): ?>
    <p class="muted">Das Archiv ist leer. Archivierte Helfer/innen bleiben als Mitglieder erhalten, erscheinen aber nicht mehr in der Personaleinteilung.</p>
<?php elseif (!$rows): ?>
    <p class="muted">Keine Profile gefunden. Helfer/innen erscheinen hier, wenn bei ihnen der Bereich „Blutspende“ eingetragen ist oder sie schon einmal eingeteilt wurden.</p>
<?php endif; ?>
<div class="profile-grid">
    <?php foreach ($rows as $m): $s = $stats[(int)$m['id']] ?? null; ?>
        <article class="profile-card<?= (int)$m['bs_archiviert'] ? ' archived' : '' ?>">
            <div class="profile-head">
                <?= member_avatar($m) ?>
                <div>
                    <h3><a href="<?= e(url_admin('helferprofile', 'profil', ['id' => $m['id']])) ?>"><?= e(member_name($m)) ?></a></h3>
                    <small class="muted"><?= e($m['mobil'] ?: $m['telefon']) ?></small>
                </div>
            </div>
            <?= qualification_tags((string)$m['qualifikationen'], $quali) ?>
            <?php if ($m['verfuegbarkeit']): ?><p class="small"><strong>Verfügbar:</strong> <?= e(mb_strimwidth((string)$m['verfuegbarkeit'], 0, 120, '…')) ?></p><?php endif; ?>
            <div class="profile-stats">
                <span><strong><?= (int)($s['einsaetze'] ?? 0) ?></strong> Einsätze</span>
                <span>nächster: <strong><?= !empty($s['naechster']) ? e(date_de($s['naechster'])) : '–' ?></strong></span>
            </div>
            <div class="card-actions"><?= profile_actions($m, $canDelete) ?></div>
        </article>
    <?php endforeach; ?>
</div>
<?php
/** Bearbeiten / Archivieren bzw. Wiederherstellen / Löschen für ein Profil */
function profile_actions(array $m, bool $canDelete, bool $withEdit = true): string
{
    $id = (int)$m['id'];
    $archived = (int)$m['bs_archiviert'] === 1;
    $name = member_name($m);
    $html = $withEdit ? '<a class="btn btn-small" href="' . e(url_admin('helferprofile', 'bearbeiten', ['id' => $id])) . '">Bearbeiten</a>' : '';
    $html .= $archived
        ? post_button(url_admin('helferprofile', 'wiederherstellen', ['id' => $id]), 'Wiederherstellen', [], 'Wieder ins Blutspende-Team aufnehmen', false, '', 'btn btn-small')
        : post_button(url_admin('helferprofile', 'archivieren', ['id' => $id]), 'Archivieren', [], 'Aus dem aktiven Team nehmen, Daten bleiben erhalten', false,
            $name . ' archivieren? Das Profil bleibt erhalten, erscheint aber nicht mehr in der Personaleinteilung.');
    if ($canDelete) {
        $html .= post_button(url_admin('helferprofile', 'loeschen', ['id' => $id]), 'Löschen', ['archiv' => $archived ? 1 : ''], 'Endgültig löschen', false,
            $name . ' endgültig löschen? Dabei werden auch die Mitgliedsdaten und alle Einteilungen gelöscht. Tipp: Archivieren behält die Daten.', 'btn btn-small btn-danger');
    }
    return $html;
}
