<?php
/** Blutspendedienst: Termine, Menü, Einkaufsliste, Personaleinteilung */

$fields = [
    ['datum', 'Datum', 'date', ['required' => true]],
    ['beginn', 'Beginn', 'time'],
    ['ende', 'Ende', 'time'],
    ['ort', 'Ort / Gebäude', 'text', ['required' => true, 'help' => 'z. B. Gemeindehaus St. Martin']],
    ['adresse', 'Adresse', 'text'],
    ['erwartete_spender', 'Erwartete Spender/innen', 'number'],
    ['hinweis', 'Öffentlicher Hinweis (z. B. „Bitte Termin online reservieren“)', 'text', ['wide' => true]],
    ['notizen', 'Interne Notizen (Schlüssel, Absprachen mit dem Blutspendedienst …)', 'textarea', ['wide' => true]],
    ['oeffentlich', 'Auf der Website anzeigen', 'checkbox'],
];
$fieldsResult = [
    ['tatsaechliche_spender', 'Tatsächliche Spender/innen', 'number'],
    ['erstspender', 'davon Erstspender/innen', 'number'],
];
$assignStatus = ['zugesagt' => 'zugesagt', 'angefragt' => 'angefragt', 'abgesagt' => 'abgesagt'];
$id = (int)get('id', 0);
$tab = (string)get('tab', 'uebersicht');

$back = fn(string $t) => url_admin('blutspende', 'termin', ['id' => $id, 'tab' => $t]);

/* ---------- Aktionen ---------- */
if (is_post()) {
    switch ($a) {
        case 'speichern':
            $data = form_collect(array_merge($fields, $id ? $fieldsResult : []));
            if (!$data['datum'] || $data['ort'] === '') {
                flash('Datum und Ort sind Pflichtfelder.', 'error');
                redirect(url_admin('blutspende', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
            }
            if ($id) {
                update('bs_termine', $data, $id);
            } else {
                $data['erstellt'] = now();
                $id = insert('bs_termine', $data);
                if (isset($_POST['standard_schichten'])) {
                    foreach (default_shifts($data['beginn'], $data['ende']) as $s) {
                        insert('bs_schichten', $s + ['termin_id' => $id]);
                    }
                }
            }
            audit('Blutspendetermin gespeichert', $data['datum'] . ' ' . $data['ort']);
            flash('Termin gespeichert.');
            redirect(url_admin('blutspende', 'termin', ['id' => $id]));

        case 'loeschen':
            q('DELETE FROM bs_termine WHERE id = ?', [$id]);
            audit('Blutspendetermin gelöscht', '#' . $id);
            flash('Termin gelöscht.');
            redirect(url_admin('blutspende'));

        case 'menue_add':
            $rid = (int)post('rezept_id');
            if ($rid && val('SELECT id FROM rezepte WHERE id = ?', [$rid])) {
                insert('bs_menue', ['termin_id' => $id, 'rezept_id' => $rid, 'portionen' => max(1, (int)post('portionen'))]);
            }
            redirect($back('menue'));

        case 'menue_update':
            foreach ((array)($_POST['portionen'] ?? []) as $mid => $p) {
                q('UPDATE bs_menue SET portionen = ? WHERE id = ? AND termin_id = ?', [max(1, (int)$p), (int)$mid, $id]);
            }
            flash('Portionen aktualisiert.');
            redirect($back('menue'));

        case 'menue_entfernen':
            q('DELETE FROM bs_menue WHERE id = ? AND termin_id = ?', [(int)post('mid'), $id]);
            redirect($back('menue'));

        case 'menue_kopieren':
            $from = (int)post('von_termin');
            foreach (all('SELECT rezept_id, portionen FROM bs_menue WHERE termin_id = ?', [$from]) as $r) {
                insert('bs_menue', ['termin_id' => $id, 'rezept_id' => $r['rezept_id'], 'portionen' => $r['portionen']]);
            }
            flash('Menü übernommen.');
            redirect($back('menue'));

        case 'schicht_add':
            if (post('aufgabe') !== '') {
                insert('bs_schichten', ['termin_id' => $id, 'aufgabe' => post('aufgabe'), 'von' => post('von') ?: null, 'bis' => post('bis') ?: null,
                    'benoetigt' => max(1, (int)post('benoetigt')), 'qualifikation' => post('qualifikation')]);
            }
            redirect($back('personal'));

        case 'schicht_update':
            $sid = (int)val('SELECT id FROM bs_schichten WHERE id = ? AND termin_id = ?', [(int)post('sid'), $id]);
            update('bs_schichten', ['aufgabe' => post('aufgabe'), 'von' => post('von') ?: null, 'bis' => post('bis') ?: null,
                'benoetigt' => max(1, (int)post('benoetigt')), 'qualifikation' => post('qualifikation')], $sid);
            redirect($back('personal') . '#s' . $sid);

        case 'schicht_loeschen':
            q('DELETE FROM bs_schichten WHERE id = ? AND termin_id = ?', [(int)post('sid'), $id]);
            redirect($back('personal'));

        case 'standard_schichten':
            $t = one('SELECT * FROM bs_termine WHERE id = ?', [$id]);
            foreach (default_shifts($t['beginn'] ?? null, $t['ende'] ?? null) as $s) {
                insert('bs_schichten', $s + ['termin_id' => $id]);
            }
            flash('Standard-Schichten angelegt.');
            redirect($back('personal'));

        case 'einteilen':
            $sid = (int)post('sid');
            $mid = (int)post('mitglied_id');
            if ($mid && !val('SELECT id FROM bs_einteilung WHERE schicht_id = ? AND mitglied_id = ?', [$sid, $mid])) {
                $st = array_key_exists(post('status'), $assignStatus) ? post('status') : 'zugesagt';
                insert('bs_einteilung', ['schicht_id' => $sid, 'mitglied_id' => $mid, 'status' => $st]);
            }
            redirect($back('personal') . '#s' . $sid);

        case 'einteilung_status':
            $eid = (int)post('eid');
            $st = (string)post('status');
            if ($st === 'entfernen') {
                q('DELETE FROM bs_einteilung WHERE id = ?', [$eid]);
            } elseif (array_key_exists($st, $assignStatus)) {
                q('UPDATE bs_einteilung SET status = ? WHERE id = ?', [$st, $eid]);
            }
            redirect($back('personal') . '#s' . (int)post('sid'));
    }
}

/* ---------- Termin anlegen / bearbeiten ---------- */
if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one('SELECT * FROM bs_termine WHERE id = ?', [$id]) : ['oeffentlich' => 1, 'beginn' => '15:30', 'ende' => '19:30'];
    if (!$row) {
        redirect(url_admin('blutspende'));
    }
    $title = $id ? 'Termin bearbeiten' : 'Neuer Blutspendetermin';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <form method="post" action="<?= e(url_admin('blutspende', 'speichern', $id ? ['id' => $id] : [])) ?>" class="panel">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= form_fields($fields, $row) ?>
            <?php if ($id): ?>
                <div class="field wide"><h3>Nach dem Termin</h3></div>
                <?= form_fields($fieldsResult, $row) ?>
            <?php else: ?>
                <div class="field wide"><label class="check"><input type="checkbox" name="standard_schichten" value="1" checked> Standard-Schichten für die Personaleinteilung anlegen</label>
                    <small>Die Vorlage kann unter Einstellungen angepasst werden.</small></div>
            <?php endif; ?>
        </div>
        <div class="actions"><button class="btn">Speichern</button> <a href="<?= e($id ? $back('uebersicht') : url_admin('blutspende')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url_admin('blutspende', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Termin mit Menü und Personaleinteilung löschen?">
            <?= csrf_field() ?><button class="btn btn-danger">Termin löschen</button>
        </form>
    <?php endif;
    return;
}

/* ---------- Druckansichten ---------- */
if ($a === 'einkauf_druck' || $a === 'dienstplan_druck') {
    $t = one('SELECT * FROM bs_termine WHERE id = ?', [$id]);
    if (!$t) {
        redirect(url_admin('blutspende'));
    }
    $printView = true;
    $heading = date_de($t['datum'], true) . ' · ' . $t['ort'] . ($t['beginn'] ? ' · ' . $t['beginn'] . '–' . $t['ende'] . ' Uhr' : '');
    if ($a === 'einkauf_druck') {
        $title = 'Einkaufsliste';
        echo '<div class="print-page"><h1>Einkaufsliste Blutspende</h1><p>' . e($heading) . '</p>';
        echo '<p><strong>Menü:</strong> ' . e(implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['portionen'] . ' Port.)',
                all('SELECT r.name, m.portionen FROM bs_menue m JOIN rezepte r ON r.id = m.rezept_id WHERE m.termin_id = ? ORDER BY r.name', [$id])))) . '</p>';
        echo render_shopping_list(shopping_list($id), true) . '</div>';
    } else {
        $title = 'Dienstplan';
        echo '<div class="print-page"><h1>Dienstplan Blutspende</h1><p>' . e($heading) . '</p>';
        foreach (all('SELECT * FROM bs_schichten WHERE termin_id = ? ORDER BY von, aufgabe', [$id]) as $s) {
            $people = all("SELECT m.vorname, m.nachname, m.mobil, m.telefon, e.status FROM bs_einteilung e JOIN mitglieder m ON m.id = e.mitglied_id
                WHERE e.schicht_id = ? AND e.status <> 'abgesagt' ORDER BY m.nachname", [$s['id']]);
            echo '<h2>' . e($s['aufgabe']) . ' <small>' . e($s['von']) . '–' . e($s['bis']) . ' Uhr · ' . count($people) . '/' . (int)$s['benoetigt'] . '</small></h2><table class="print-table">';
            foreach ($people as $p) {
                echo '<tr><td>' . e(member_name($p)) . '</td><td>' . e($p['mobil'] ?: $p['telefon']) . '</td><td>' . ($p['status'] === 'angefragt' ? 'angefragt' : '') . '</td><td class="sign"></td></tr>';
            }
            for ($i = count($people); $i < (int)$s['benoetigt']; $i++) {
                echo '<tr class="open"><td>offen</td><td></td><td></td><td class="sign"></td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }
    return;
}

function render_shopping_list(array $groups, bool $print = false): string
{
    if (!$groups) {
        return '<p class="muted">Noch keine Rezepte im Menü – die Einkaufsliste wird automatisch aus dem Menü berechnet.</p>';
    }
    $html = '<div class="shopping' . ($print ? ' print' : '') . '">';
    foreach ($groups as $section => $items) {
        $html .= '<div class="shop-group"><h3>' . e($section) . '</h3><ul>';
        foreach ($items as $it) {
            $html .= '<li><label><input type="checkbox"> <strong>' . e(num_de((float)$it['menge'])) . ' ' . e($it['einheit']) . '</strong> ' . e($it['name'])
                . ' <small class="muted">(' . e(implode(', ', $it['rezepte'])) . ')</small></label></li>';
        }
        $html .= '</ul></div>';
    }
    return $html . '</div>';
}

/* ---------- Termin-Detail ---------- */
if ($a === 'termin') {
    $t = one('SELECT * FROM bs_termine WHERE id = ?', [$id]);
    if (!$t) {
        redirect(url_admin('blutspende'));
    }
    [$have, $need] = staffing($id);
    $title = 'Blutspende ' . date_de($t['datum']);
    $tabs = ['uebersicht' => 'Übersicht', 'menue' => 'Menü', 'einkauf' => 'Einkaufsliste', 'personal' => 'Personaleinteilung'];
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <?= e(date_de($t['datum'])) ?></p>
    <div class="head-row">
        <h1><?= e(date_de($t['datum'], true)) ?> <small class="muted"><?= e($t['ort']) ?></small></h1>
        <a class="btn btn-outline" href="<?= e(url_admin('blutspende', 'bearbeiten', ['id' => $id])) ?>">Termin bearbeiten</a>
    </div>
    <nav class="tabs">
        <?php foreach ($tabs as $k => $label): ?>
            <a href="<?= e($back($k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?><?= $k === 'personal' ? ' ' . staffing_badge($have, $need) : '' ?></a>
        <?php endforeach; ?>
    </nav>
    <?php

    if ($tab === 'uebersicht'): ?>
        <div class="grid-2">
            <section class="panel">
                <h2>Termin</h2>
                <dl class="dl">
                    <dt>Datum</dt><dd><?= e(date_de($t['datum'], true)) ?></dd>
                    <dt>Zeit</dt><dd><?= e($t['beginn']) ?>–<?= e($t['ende']) ?> Uhr</dd>
                    <dt>Ort</dt><dd><?= e($t['ort']) ?><br><?= e($t['adresse']) ?></dd>
                    <dt>Erwartet</dt><dd><?= (int)$t['erwartete_spender'] ?> Spender/innen</dd>
                    <?php if ($t['tatsaechliche_spender'] !== null): ?>
                        <dt>Gespendet</dt><dd><?= (int)$t['tatsaechliche_spender'] ?> (davon <?= (int)$t['erstspender'] ?> Erstspender/innen)</dd>
                    <?php endif; ?>
                    <dt>Website</dt><dd><?= (int)$t['oeffentlich'] ? 'wird angezeigt' : 'nicht öffentlich' ?></dd>
                </dl>
                <?php if ($t['notizen']): ?><h3>Notizen</h3><p class="pre"><?= e($t['notizen']) ?></p><?php endif; ?>
            </section>
            <section class="panel">
                <h2>Stand der Planung</h2>
                <ul class="checklist-status">
                    <?php $menuCount = (int)val('SELECT COUNT(*) FROM bs_menue WHERE termin_id = ?', [$id]); ?>
                    <li class="<?= $menuCount ? 'done' : '' ?>"><a href="<?= e($back('menue')) ?>">Menü geplant</a> (<?= $menuCount ?> Gerichte)</li>
                    <li class="<?= $menuCount ? 'done' : '' ?>"><a href="<?= e($back('einkauf')) ?>">Einkaufsliste</a></li>
                    <li class="<?= $need && $have >= $need ? 'done' : '' ?>"><a href="<?= e($back('personal')) ?>">Personal eingeteilt</a> <?= staffing_badge($have, $need) ?></li>
                </ul>
                <p>
                    <a class="btn btn-outline" href="<?= e(url_admin('blutspende', 'einkauf_druck', ['id' => $id])) ?>" target="_blank">Einkaufsliste drucken</a>
                    <a class="btn btn-outline" href="<?= e(url_admin('blutspende', 'dienstplan_druck', ['id' => $id])) ?>" target="_blank">Dienstplan drucken</a>
                </p>
            </section>
        </div>
    <?php elseif ($tab === 'menue'):
        $menu = all('SELECT m.id, m.portionen, r.id AS rid, r.name, r.kategorie, r.portionen AS basis, r.vegetarisch, r.allergene
            FROM bs_menue m JOIN rezepte r ON r.id = m.rezept_id WHERE m.termin_id = ? ORDER BY r.kategorie, r.name', [$id]);
        $recipes = all('SELECT id, name, kategorie FROM rezepte ORDER BY kategorie, name');
        $others = all('SELECT t.id, t.datum, t.ort FROM bs_termine t WHERE t.id <> ? AND EXISTS (SELECT 1 FROM bs_menue m WHERE m.termin_id = t.id) ORDER BY t.datum DESC LIMIT 10', [$id]);
        ?>
        <section class="panel">
            <h2>Menü für den Imbiss</h2>
            <?php if ($menu): ?>
                <form method="post" action="<?= e(url_admin('blutspende', 'menue_update', ['id' => $id])) ?>" id="menueform">
                    <?= csrf_field() ?>
                </form>
                <table class="list">
                    <thead><tr><th>Gericht</th><th>Kategorie</th><th>Allergene</th><th>Portionen</th><th></th></tr></thead>
                    <?php foreach ($menu as $r): ?>
                        <tr>
                            <td><a href="<?= e(url_admin('rezepte', 'bearbeiten', ['id' => $r['rid']])) ?>"><?= e($r['name']) ?></a><?= (int)$r['vegetarisch'] ? ' <span class="badge ok">veg.</span>' : '' ?>
                                <br><small class="muted">Rezept für <?= (int)$r['basis'] ?> Portionen → Faktor <?= num_de($r['portionen'] / max(1, $r['basis'])) ?></small></td>
                            <td><?= e($r['kategorie']) ?></td>
                            <td class="small"><?= e($r['allergene']) ?></td>
                            <td><input type="number" min="1" name="portionen[<?= (int)$r['id'] ?>]" value="<?= (int)$r['portionen'] ?>" class="num" form="menueform"></td>
                            <td class="right"><?= post_button(url_admin('blutspende', 'menue_entfernen', ['id' => $id]), '✕', ['mid' => $r['id']], 'Entfernen') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <p><button class="btn btn-outline" form="menueform">Portionen speichern</button></p>
            <?php else: ?>
                <p class="muted">Noch keine Gerichte geplant.</p>
            <?php endif; ?>
        </section>
        <div class="grid-2">
            <form method="post" action="<?= e(url_admin('blutspende', 'menue_add', ['id' => $id])) ?>" class="panel">
                <?= csrf_field() ?>
                <h3>Gericht hinzufügen</h3>
                <?php if (!$recipes): ?><p class="muted">Noch keine Rezepte vorhanden. <a href="<?= e(url_admin('rezepte', 'neu')) ?>">Rezept anlegen</a></p><?php else: ?>
                    <div class="form-grid">
                        <div class="field"><label>Rezept</label><select name="rezept_id">
                                <?php foreach ($recipes as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?><?= $r['kategorie'] ? ' (' . e($r['kategorie']) . ')' : '' ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="field"><label>Portionen</label><input type="number" name="portionen" min="1" value="<?= (int)($t['erwartete_spender'] ?: 50) ?>"></div>
                    </div>
                    <button class="btn">Hinzufügen</button>
                <?php endif; ?>
            </form>
            <?php if ($others): ?>
                <form method="post" action="<?= e(url_admin('blutspende', 'menue_kopieren', ['id' => $id])) ?>" class="panel">
                    <?= csrf_field() ?>
                    <h3>Menü eines früheren Termins übernehmen</h3>
                    <div class="field"><select name="von_termin">
                            <?php foreach ($others as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e(date_de($o['datum'])) ?> – <?= e($o['ort']) ?></option><?php endforeach; ?>
                        </select></div>
                    <button class="btn btn-outline">Übernehmen</button>
                </form>
            <?php endif; ?>
        </div>
    <?php elseif ($tab === 'einkauf'): ?>
        <section class="panel">
            <div class="head-row">
                <h2>Einkaufsliste</h2>
                <a class="btn btn-outline" href="<?= e(url_admin('blutspende', 'einkauf_druck', ['id' => $id])) ?>" target="_blank">Drucken</a>
            </div>
            <p class="muted small">Automatisch berechnet aus dem Menü: Zutatenmengen × (geplante Portionen ÷ Rezept-Portionen). Gleiche Zutaten werden zusammengefasst, Stückzahlen aufgerundet.</p>
            <?= render_shopping_list(shopping_list($id)) ?>
        </section>
    <?php elseif ($tab === 'personal'):
        $shifts = all('SELECT * FROM bs_schichten WHERE termin_id = ? ORDER BY von, aufgabe', [$id]);
        $members = all("SELECT id, vorname, nachname, qualifikationen, bereiche FROM mitglieder WHERE status NOT IN ('ausgetreten', 'foerdernd') AND bs_archiviert = 0 ORDER BY nachname, vorname");
        $assigned = [];
        foreach (all('SELECT e.*, s.von, s.bis, s.aufgabe, m.vorname, m.nachname, m.mobil, m.telefon, m.qualifikationen FROM bs_einteilung e
            JOIN bs_schichten s ON s.id = e.schicht_id JOIN mitglieder m ON m.id = e.mitglied_id WHERE s.termin_id = ? ORDER BY m.nachname', [$id]) as $e) {
            $assigned[$e['schicht_id']][] = $e;
        }
        $allAssigned = array_merge([], ...array_values($assigned));
        ?>
        <div class="head-row">
            <p class="muted">Tragen Sie Helfer/innen in die Schichten ein. ⚠ markiert zeitliche Überschneidungen, ✓ passende Qualifikation.</p>
            <a class="btn btn-outline" href="<?= e(url_admin('blutspende', 'dienstplan_druck', ['id' => $id])) ?>" target="_blank">Dienstplan drucken</a>
        </div>
        <?php if (!$shifts): ?>
            <div class="panel">
                <p>Noch keine Schichten angelegt.</p>
                <?= post_button(url_admin('blutspende', 'standard_schichten', ['id' => $id]), 'Standard-Schichten anlegen', [], '', false, '', 'btn') ?>
            </div>
        <?php endif; ?>
        <div class="shifts">
            <?php foreach ($shifts as $s):
                $list = $assigned[$s['id']] ?? [];
                $ok = count(array_filter($list, fn($e) => $e['status'] === 'zugesagt')); ?>
                <section class="panel shift" id="s<?= (int)$s['id'] ?>">
                    <div class="shift-head">
                        <h3><?= e($s['aufgabe']) ?><?= job_link($s['aufgabe']) ?> <small class="muted"><?= e($s['von']) ?>–<?= e($s['bis']) ?></small></h3>
                        <?= staffing_badge($ok, (int)$s['benoetigt']) ?>
                    </div>
                    <?php if ($s['qualifikation']): ?><p class="small muted">Benötigt: <?= e($s['qualifikation']) ?></p><?php endif; ?>
                    <ul class="people">
                        <?php foreach ($list as $e):
                            $conflict = false;
                            foreach ($allAssigned as $o) {
                                if ($o['mitglied_id'] === $e['mitglied_id'] && $o['schicht_id'] !== $e['schicht_id'] && $o['status'] !== 'abgesagt' && $e['status'] !== 'abgesagt'
                                    && times_overlap($e['von'], $e['bis'], $o['von'], $o['bis'])) {
                                    $conflict = $o['aufgabe'];
                                }
                            }
                            $qualified = $s['qualifikation'] && str_contains((string)$e['qualifikationen'], $s['qualifikation']); ?>
                            <li class="st-<?= e($e['status']) ?>">
                                <span><a href="<?= e(url_admin('helferprofile', 'profil', ['id' => $e['mitglied_id']])) ?>"><?= e(member_name($e)) ?></a><?= $qualified ? ' <span title="Qualifikation vorhanden">✓</span>' : '' ?>
                                    <?= $conflict ? ' <span class="warn-text" title="Überschneidung mit: ' . e($conflict) . '">⚠</span>' : '' ?>
                                    <small class="muted"><?= e($e['mobil'] ?: $e['telefon']) ?></small></span>
                                <form method="post" action="<?= e(url_admin('blutspende', 'einteilung_status', ['id' => $id])) ?>" class="inline">
                                    <?= csrf_field() ?><input type="hidden" name="eid" value="<?= (int)$e['id'] ?>"><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                                    <select name="status" onchange="this.form.submit()" class="small-select">
                                        <?php foreach ($assignStatus + ['entfernen' => '– entfernen –'] as $k => $v): ?>
                                            <option value="<?= e($k) ?>"<?= $k === $e['status'] ? ' selected' : '' ?>><?= e($v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <noscript><button class="btn btn-small">OK</button></noscript>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="<?= e(url_admin('blutspende', 'einteilen', ['id' => $id])) ?>" class="assign">
                        <?= csrf_field() ?><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                        <select name="mitglied_id" required>
                            <option value="">Person hinzufügen …</option>
                            <?php
                            $inShift = array_column($list, 'mitglied_id');
                            $sorted = $members;
                            if ($s['qualifikation']) {
                                usort($sorted, fn($x, $y) => (int)str_contains((string)$y['qualifikationen'], $s['qualifikation']) <=> (int)str_contains((string)$x['qualifikationen'], $s['qualifikation']));
                            }
                            foreach ($sorted as $mem):
                                if (in_array($mem['id'], $inShift)) continue;
                                $q = $s['qualifikation'] && str_contains((string)$mem['qualifikationen'], $s['qualifikation']); ?>
                                <option value="<?= (int)$mem['id'] ?>"><?= $q ? '✓ ' : '' ?><?= e($mem['nachname'] . ', ' . $mem['vorname']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status" class="small-select"><?php foreach ($assignStatus as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-small">+</button>
                    </form>
                    <details class="shift-edit">
                        <summary>Schicht bearbeiten</summary>
                        <form method="post" action="<?= e(url_admin('blutspende', 'schicht_update', ['id' => $id])) ?>" class="form-grid compact">
                            <?= csrf_field() ?><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                            <?= form_fields([
                                ['aufgabe', 'Aufgabe', 'text'], ['benoetigt', 'Anzahl', 'number'],
                                ['von', 'von', 'time'], ['bis', 'bis', 'time'],
                                ['qualifikation', 'Qualifikation', 'select', ['options' => array_merge([''], qualification_options())]],
                            ], $s) ?>
                            <div class="actions wide"><button class="btn btn-small">Speichern</button></div>
                        </form>
                        <?= post_button(url_admin('blutspende', 'schicht_loeschen', ['id' => $id]), 'Schicht löschen', ['sid' => $s['id']], '', false, 'Schicht inkl. Einteilungen löschen?', 'btn btn-small btn-danger') ?>
                    </details>
                </section>
            <?php endforeach; ?>
        </div>
        <datalist id="stellen"><?php foreach (all('SELECT titel FROM bs_stellen WHERE archiviert = 0 ORDER BY sortierung, titel') as $st): ?><option value="<?= e($st['titel']) ?>"><?php endforeach; ?></datalist>
        <form method="post" action="<?= e(url_admin('blutspende', 'schicht_add', ['id' => $id])) ?>" class="panel">
            <?= csrf_field() ?>
            <h3>Weitere Schicht / Aufgabe</h3>
            <div class="form-grid">
                <?= form_fields([
                    ['aufgabe', 'Aufgabe', 'text', ['required' => true, 'list' => 'stellen', 'help' => 'Vorschläge aus den Stellenbeschreibungen']], ['benoetigt', 'Anzahl Personen', 'number', ['default' => 1]],
                    ['von', 'von', 'time', ['default' => $t['beginn']]], ['bis', 'bis', 'time', ['default' => $t['ende']]],
                    ['qualifikation', 'Benötigte Qualifikation', 'select', ['options' => array_merge([''], qualification_options())]],
                ], []) ?>
            </div>
            <button class="btn">Schicht hinzufügen</button>
        </form>
    <?php endif;
    return;
}

/* ---------- Terminliste ---------- */
$showPast = (bool)get('vergangen');
$today = date('Y-m-d');
$rows = $showPast
    ? all('SELECT * FROM bs_termine WHERE datum < ? ORDER BY datum DESC', [$today])
    : all('SELECT * FROM bs_termine WHERE datum >= ? ORDER BY datum', [$today]);
$stats = one('SELECT COUNT(*) AS n, SUM(tatsaechliche_spender) AS spender, SUM(erstspender) AS erst FROM bs_termine WHERE datum LIKE ?', [date('Y') . '%']);
?>
<div class="head-row">
    <h1>Blutspendetermine</h1>
    <a class="btn" href="<?= e(url_admin('blutspende', 'neu')) ?>">+ Neuer Termin</a>
</div>
<div class="cards">
    <div class="card stat"><strong><?= (int)$stats['n'] ?></strong>Termine <?= date('Y') ?></div>
    <div class="card stat"><strong><?= (int)$stats['spender'] ?></strong>Spender/innen <?= date('Y') ?></div>
    <div class="card stat"><strong><?= (int)$stats['erst'] ?></strong>Erstspender/innen <?= date('Y') ?></div>
</div>
<nav class="tabs">
    <a href="<?= e(url_admin('blutspende')) ?>" class="<?= $showPast ? '' : 'active' ?>">Kommende</a>
    <a href="<?= e(url_admin('blutspende', '', ['vergangen' => 1])) ?>" class="<?= $showPast ? 'active' : '' ?>">Vergangene</a>
</nav>
<table class="list">
    <thead><tr><th>Datum</th><th>Zeit</th><th>Ort</th><th>Erwartet</th><th>Menü</th><th>Personal</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$have, $need] = staffing((int)$r['id']); ?>
        <tr>
            <td><a href="<?= e(url_admin('blutspende', 'termin', ['id' => $r['id']])) ?>"><strong><?= e(date_de($r['datum'], true)) ?></strong></a></td>
            <td><?= e($r['beginn']) ?>–<?= e($r['ende']) ?></td>
            <td><?= e($r['ort']) ?></td>
            <td><?= $r['tatsaechliche_spender'] !== null ? (int)$r['tatsaechliche_spender'] . ' / ' : '' ?><?= (int)$r['erwartete_spender'] ?></td>
            <td><?= (int)val('SELECT COUNT(*) FROM bs_menue WHERE termin_id = ?', [$r['id']]) ?> Gerichte</td>
            <td><?= staffing_badge($have, $need) ?></td>
            <td class="right"><?= (int)$r['oeffentlich'] ? '' : '<span class="badge">intern</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Keine Termine.</td></tr><?php endif; ?>
    </tbody>
</table>
