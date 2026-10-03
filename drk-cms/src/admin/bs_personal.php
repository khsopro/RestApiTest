<?php
/**
 * Blutspende: Personaleinteilung (feste und flexible Schichten), Erfassung der Ehrenamtsstunden, Dienstplan.
 * Wird von blutspende.php eingebunden; $id ist die Termin-ID, $back() erzeugt Links auf die Reiter.
 */

$assignStatus = ['zugesagt' => 'zugesagt', 'angefragt' => 'angefragt', 'abgesagt' => 'abgesagt'];

/* ---------- Aktionen ---------- */
if (is_post()) {
    $shiftOf = fn(int $sid) => one('SELECT * FROM bs_schichten WHERE id = ? AND termin_id = ?', [$sid, $id]);
    $assignmentOf = fn(int $eid) => one('SELECT e.* FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id WHERE e.id = ? AND s.termin_id = ?', [$eid, $id]);
    $shiftData = function (): array {
        $von = round15((string)post('von')) ?: null;
        $bis = round15((string)post('bis')) ?: null;
        return ['aufgabe' => (string)post('aufgabe'), 'von' => $von, 'bis' => $bis, 'benoetigt' => max(1, (int)post('benoetigt')),
            'qualifikation' => (string)post('qualifikation'), 'flexibel' => isset($_POST['flexibel']) ? 1 : 0];
    };

    switch ($a) {
        case 'schicht_add':
            $data = $shiftData();
            if ($data['aufgabe'] !== '') {
                insert('bs_schichten', $data + ['termin_id' => $id]);
            }
            redirect($back('personal'));

        case 'schicht_update':
            $s = $shiftOf((int)post('sid'));
            if ($s) {
                $data = $shiftData();
                update('bs_schichten', $data, (int)$s['id']);
                // Individuelle Zeiten in das neue Zeitfenster einpassen
                $s = $data + $s;
                $moved = 0;
                foreach (all('SELECT * FROM bs_einteilung WHERE schicht_id = ? AND von IS NOT NULL', [$s['id']]) as $e) {
                    $t = (int)$s['flexibel'] ? clamp_to_shift($e['von'], $e['bis'], $s) : null;
                    if ($t !== [$e['von'], $e['bis']]) {
                        q('UPDATE bs_einteilung SET von = ?, bis = ? WHERE id = ?', [$t[0] ?? null, $t[1] ?? null, $e['id']]);
                        $moved++;
                    }
                }
                flash('Schicht gespeichert.' . ($moved ? " Bei $moved Einteilung(en) wurden die Zeiten an das neue Zeitfenster angepasst." : ''));
            }
            redirect($back('personal') . '#s' . (int)post('sid'));

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
            $s = $shiftOf((int)post('sid'));
            $mid = (int)post('mitglied_id');
            if (!$s || !$mid || !val('SELECT id FROM mitglieder WHERE id = ?', [$mid])) {
                redirect($back('personal'));
            }
            $times = [null, null];
            if ((int)$s['flexibel']) {
                $times = clamp_to_shift((string)post('von'), (string)post('bis'), $s);
                if (!$times) {
                    flash('Bitte gültige Zeiten innerhalb der Schicht wählen („von“ vor „bis“).', 'error');
                    redirect($back('personal') . '#s' . $s['id']);
                }
            }
            // Dieselbe Person darf in einer Schicht mehrmals stehen, aber nicht zeitgleich
            foreach (all("SELECT * FROM bs_einteilung WHERE schicht_id = ? AND mitglied_id = ? AND status <> 'abgesagt'", [$s['id'], $mid]) as $e) {
                [$ev, $eb] = eff_times($e, $s);
                [$nv, $nb] = (int)$s['flexibel'] ? $times : [$s['von'], $s['bis']];
                if (!(int)$s['flexibel'] || (t2m($nv) < t2m($eb) && t2m($ev) < t2m($nb))) {
                    flash('Diese Person ist in dieser Schicht bereits eingeteilt (' . $ev . '–' . $eb . ').', 'error');
                    redirect($back('personal') . '#s' . $s['id']);
                }
            }
            $st = array_key_exists(post('status'), $assignStatus) ? post('status') : 'zugesagt';
            insert('bs_einteilung', ['schicht_id' => $s['id'], 'mitglied_id' => $mid, 'status' => $st, 'von' => $times[0], 'bis' => $times[1]]);
            redirect($back('personal') . '#s' . $s['id']);

        case 'einteilung_update':
            $e = $assignmentOf((int)post('eid'));
            if ($e) {
                $st = (string)post('status', $e['status']);
                if ($st === 'entfernen') {
                    q('DELETE FROM bs_einteilung WHERE id = ?', [$e['id']]);
                } else {
                    $s = $shiftOf((int)$e['schicht_id']);
                    $data = ['status' => array_key_exists($st, $assignStatus) ? $st : $e['status']];
                    if ((int)$s['flexibel'] && post('von') !== '') {
                        $times = clamp_to_shift((string)post('von'), (string)post('bis'), $s);
                        if ($times) {
                            [$data['von'], $data['bis']] = $times;
                        } else {
                            flash('Ungültige Zeiten – „von“ muss vor „bis“ liegen.', 'error');
                        }
                    }
                    update('bs_einteilung', $data, (int)$e['id']);
                }
                redirect($back('personal') . '#s' . $e['schicht_id']);
            }
            redirect($back('personal'));

        case 'stunden_speichern':
            $u = current_user();
            $n = 0;
            foreach ((array)($_POST['ist_von'] ?? []) as $eid => $von) {
                $e = $assignmentOf((int)$eid);
                if (!$e) {
                    continue;
                }
                $absent = isset($_POST['fehlt'][$eid]);
                $v = round15((string)$von);
                $b = round15((string)($_POST['ist_bis'][$eid] ?? ''));
                if ($absent) {
                    $data = ['nicht_erschienen' => 1, 'ist_von' => null, 'ist_bis' => null];
                } elseif ($v && $b && t2m($v) < t2m($b)) {
                    $data = ['nicht_erschienen' => 0, 'ist_von' => $v, 'ist_bis' => $b];
                } elseif (!$v && !$b) {
                    $data = ['nicht_erschienen' => 0, 'ist_von' => null, 'ist_bis' => null];
                } else {
                    flash('Bei einer Zeile war „von“ nicht vor „bis“ – diese Zeile wurde nicht gespeichert.', 'error');
                    continue;
                }
                if ([$data['ist_von'], $data['ist_bis'], $data['nicht_erschienen']] !== [$e['ist_von'], $e['ist_bis'], (int)$e['nicht_erschienen']]) {
                    update('bs_einteilung', $data + ['erfasst_am' => now(), 'erfasst_von' => $u['benutzername']], (int)$e['id']);
                    $n++;
                }
            }
            audit('Ehrenamtsstunden erfasst', 'Termin #' . $id . ', ' . $n . ' Einträge');
            flash($n ? "Stunden gespeichert ($n Änderungen)." : 'Keine Änderungen.');
            redirect($back('stunden'));

        case 'stunden_uebernehmen':
            $u = current_user();
            $n = 0;
            foreach (termin_shifts($id) as $s) {
                foreach ($s['einteilungen'] as $e) {
                    if ($e['status'] === 'zugesagt' && !$e['ist_von'] && !(int)$e['nicht_erschienen'] && $e['eff_von'] && $e['eff_bis']) {
                        update('bs_einteilung', ['ist_von' => $e['eff_von'], 'ist_bis' => $e['eff_bis'], 'erfasst_am' => now(), 'erfasst_von' => $u['benutzername']], (int)$e['id']);
                        $n++;
                    }
                }
            }
            audit('Ehrenamtsstunden wie geplant übernommen', 'Termin #' . $id . ', ' . $n . ' Einträge');
            flash($n ? "$n Einteilung(en) wie geplant übernommen. Bitte Abweichungen anpassen." : 'Es gab keine offenen Einteilungen.');
            redirect($back('stunden'));

        case 'stunden_nachtragen':
            $s = $shiftOf((int)post('sid'));
            $mid = (int)post('mitglied_id');
            $v = round15((string)post('von'));
            $b = round15((string)post('bis'));
            if (!$s || !$mid || !$v || !$b || t2m($v) >= t2m($b)) {
                flash('Bitte Person, Aufgabe und gültige Zeiten angeben.', 'error');
                redirect($back('stunden'));
            }
            $plan = (int)$s['flexibel'] ? (clamp_to_shift($v, $b, $s) ?? [null, null]) : [null, null];
            insert('bs_einteilung', ['schicht_id' => $s['id'], 'mitglied_id' => $mid, 'status' => 'zugesagt', 'von' => $plan[0], 'bis' => $plan[1],
                'ist_von' => $v, 'ist_bis' => $b, 'erfasst_am' => now(), 'erfasst_von' => current_user()['benutzername']]);
            audit('Ehrenamtsstunden nachgetragen', 'Termin #' . $id);
            flash('Einsatz nachgetragen.');
            redirect($back('stunden'));
    }
}

/* ---------- Darstellung ---------- */

/** Kleiner Balken mit der Besetzung je 15 Minuten */
function coverage_bar(array $s): string
{
    $cov = $s['summary']['cov'];
    if (!$cov) {
        return '';
    }
    $need = $s['summary']['bedarf'];
    $html = '<div class="cov-bar" aria-hidden="true">';
    foreach ($cov as $m => $n) {
        $html .= '<span class="' . ($n >= $need ? 'tl-ok' : ($n > 0 ? 'tl-part' : 'tl-empty')) . '" title="' . m2t($m) . ': ' . $n . '/' . $need . '"></span>';
    }
    return $html . '</div><div class="cov-scale"><span>' . e($s['von']) . '</span><span>' . e($s['bis']) . '</span></div>';
}

function gaps_text(array $s): string
{
    if (!$s['summary']['luecken']) {
        return $s['summary']['cov'] ? '<p class="small ok-text">Durchgehend besetzt.</p>' : '';
    }
    $parts = array_map(fn($g) => $g[0] . '–' . $g[1] . ' (' . $g[2] . ' fehlt' . ($g[2] > 1 ? 'en' : '') . ')', $s['summary']['luecken']);
    return '<p class="small warn-text">Noch offen: ' . e(implode(', ', $parts)) . '</p>';
}

function render_personal_tab(array $t, int $id, callable $back, array $assignStatus): void
{
    $shifts = termin_shifts($id);
    $conflicts = assignment_conflicts($shifts);
    $members = all("SELECT id, vorname, nachname, qualifikationen FROM mitglieder
        WHERE status NOT IN ('ausgetreten', 'foerdernd') AND bs_archiviert = 0 ORDER BY nachname, vorname");
    $qualiOptions = array_merge([''], qualification_options());
    ?>
    <div class="head-row">
        <p class="muted">Feste Schichten: alle arbeiten die ganze Schichtzeit. Flexible Schichten: jede/r hat eigene Zeiten (15-Minuten-Raster).
            ⚠ = zeitliche Überschneidung, ✓ = passende Qualifikation.</p>
        <a class="btn btn-outline" href="<?= e(url_admin('blutspende', 'dienstplan_druck', ['id' => $id])) ?>" target="_blank">Dienstplan drucken</a>
    </div>
    <?php if (!$shifts): ?>
        <div class="panel">
            <p>Noch keine Schichten angelegt.</p>
            <?= post_button(url_admin('blutspende', 'standard_schichten', ['id' => $id]), 'Standard-Schichten anlegen', [], '', false, '', 'btn') ?>
        </div>
    <?php else: ?>
        <section class="panel">
            <h2>Zeitplan</h2>
            <?= render_timeline($shifts) ?>
        </section>
    <?php endif; ?>

    <div class="shifts">
        <?php foreach ($shifts as $s): $flex = (int)$s['flexibel'] === 1; ?>
            <section class="panel shift" id="s<?= (int)$s['id'] ?>">
                <div class="shift-head">
                    <h3><?= e($s['aufgabe']) ?><?= job_link($s['aufgabe']) ?>
                        <small class="muted"><?= e($s['von']) ?>–<?= e($s['bis']) ?></small></h3>
                    <?= staffing_badge($s['summary']['besetzt'], $s['summary']['bedarf']) ?>
                </div>
                <p class="small muted"><span class="badge <?= $flex ? '' : 'fixed' ?>"><?= $flex ? 'flexibel' : 'feste Zeit' ?></span>
                    <?= (int)$s['benoetigt'] ?> Person(en) gleichzeitig<?= $s['qualifikation'] ? ' · benötigt: ' . e($s['qualifikation']) : '' ?></p>
                <?= coverage_bar($s) ?>
                <?= gaps_text($s) ?>
                <ul class="people">
                    <?php foreach ($s['einteilungen'] as $e):
                        $qualified = $s['qualifikation'] && str_contains((string)$e['qualifikationen'], $s['qualifikation']); ?>
                        <li class="st-<?= e($e['status']) ?>">
                            <span class="person"><a href="<?= e(url_admin('helferprofile', 'profil', ['id' => $e['mitglied_id']])) ?>"><?= e(member_name($e)) ?></a><?= $qualified ? ' <span title="Qualifikation vorhanden">✓</span>' : '' ?>
                                <?= isset($conflicts[$e['id']]) ? ' <span class="warn-text" title="Überschneidung mit: ' . e($conflicts[$e['id']]) . '">⚠</span>' : '' ?>
                                <?php if (!$flex): ?><small class="muted"><?= e($e['mobil'] ?: $e['telefon']) ?></small><?php endif; ?></span>
                            <form method="post" action="<?= e(url_admin('blutspende', 'einteilung_update', ['id' => $id])) ?>" class="assign-row">
                                <?= csrf_field() ?><input type="hidden" name="eid" value="<?= (int)$e['id'] ?>">
                                <?php if ($flex): ?>
                                    <select name="von" class="small-select" aria-label="von"><?= time_options($s['von'], $s['bis'], $e['eff_von']) ?></select>–<select name="bis" class="small-select" aria-label="bis"><?= time_options($s['von'], $s['bis'], $e['eff_bis']) ?></select>
                                <?php endif; ?>
                                <select name="status" class="small-select" aria-label="Status"<?= $flex ? '' : ' onchange="this.form.submit()"' ?>>
                                    <?php foreach ($assignStatus + ['entfernen' => '– entfernen –'] as $k => $v): ?>
                                        <option value="<?= e($k) ?>"<?= $k === $e['status'] ? ' selected' : '' ?>><?= e($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-small btn-ghost" title="Speichern">✓</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?= e(url_admin('blutspende', 'einteilen', ['id' => $id])) ?>" class="assign">
                    <?= csrf_field() ?><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                    <select name="mitglied_id" required>
                        <option value="">Person hinzufügen …</option>
                        <?php
                        $inShift = $flex ? [] : array_column($s['einteilungen'], 'mitglied_id');
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
                    <?php if ($flex): ?>
                        <select name="von" class="small-select" aria-label="von"><?= time_options($s['von'], $s['bis'], $s['von']) ?></select>–<select name="bis" class="small-select" aria-label="bis"><?= time_options($s['von'], $s['bis'], $s['bis']) ?></select>
                    <?php endif; ?>
                    <select name="status" class="small-select" aria-label="Status"><?php foreach ($assignStatus as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-small">+</button>
                </form>
                <details class="shift-edit">
                    <summary>Schicht bearbeiten</summary>
                    <form method="post" action="<?= e(url_admin('blutspende', 'schicht_update', ['id' => $id])) ?>" class="form-grid compact">
                        <?= csrf_field() ?><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                        <?= shift_form_fields($s, $qualiOptions) ?>
                        <div class="actions wide"><button class="btn btn-small">Speichern</button></div>
                    </form>
                    <?= post_button(url_admin('blutspende', 'schicht_loeschen', ['id' => $id]), 'Schicht löschen', ['sid' => $s['id']], '', false, 'Schicht inkl. Einteilungen löschen?', 'btn btn-small btn-danger') ?>
                </details>
            </section>
        <?php endforeach; ?>
    </div>

    <?php $people = person_schedule($shifts); if ($people): ?>
        <section class="panel">
            <h2>Ablauf pro Person</h2>
            <table class="list">
                <thead><tr><th>Person</th><th>Einsätze</th><th class="right">geplant</th></tr></thead>
                <?php foreach ($people as $mid => $p): ?>
                    <tr>
                        <td><a href="<?= e(url_admin('helferprofile', 'profil', ['id' => $mid])) ?>"><?= e($p['name']) ?></a><br><small class="muted"><?= e($p['mobil']) ?></small></td>
                        <td><?php foreach ($p['einsaetze'] as [$v, $b, $task, $st]): ?>
                                <span class="slot-chip<?= $st === 'angefragt' ? ' pending' : '' ?>"><?= e($v) ?>–<?= e($b) ?> <?= e($task) ?><?= $st === 'angefragt' ? ' (angefragt)' : '' ?></span>
                            <?php endforeach; ?></td>
                        <td class="right nowrap"><?= e(hours_de($p['minuten'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </section>
    <?php endif; ?>

    <form method="post" action="<?= e(url_admin('blutspende', 'schicht_add', ['id' => $id])) ?>" class="panel">
        <?= csrf_field() ?>
        <datalist id="stellen"><?php foreach (all('SELECT titel FROM bs_stellen WHERE archiviert = 0 ORDER BY sortierung, titel') as $st): ?><option value="<?= e($st['titel']) ?>"><?php endforeach; ?></datalist>
        <h3>Weitere Schicht / Aufgabe</h3>
        <div class="form-grid"><?= shift_form_fields(['von' => $t['beginn'], 'bis' => $t['ende'], 'benoetigt' => 1, 'flexibel' => 1], $qualiOptions, true) ?></div>
        <button class="btn">Schicht hinzufügen</button>
    </form>
    <?php
}

function shift_form_fields(array $s, array $qualiOptions, bool $new = false): string
{
    return form_fields([
        ['aufgabe', 'Aufgabe', 'text', ['required' => true, 'list' => 'stellen'] + ($new ? ['help' => 'Vorschläge aus den Stellenbeschreibungen'] : [])],
        ['benoetigt', 'Personen gleichzeitig', 'number'],
        ['von', 'von', 'time', ['step' => '900']],
        ['bis', 'bis', 'time', ['step' => '900']],
        ['qualifikation', 'Benötigte Qualifikation', 'select', ['options' => $qualiOptions]],
        ['flexibel', 'Flexible Zeiten pro Helfer/in (aus = alle arbeiten die ganze Schichtzeit)', 'checkbox', ['wide' => true]],
    ], $s);
}

function render_hours_tab(array $t, int $id): void
{
    $shifts = termin_shifts($id);
    $rows = [];
    foreach ($shifts as $s) {
        foreach ($s['einteilungen'] as $e) {
            if ($e['status'] !== 'abgesagt') {
                $rows[$e['mitglied_id']]['name'] = $e['nachname'] . ', ' . $e['vorname'];
                $rows[$e['mitglied_id']]['items'][] = $e + ['aufgabe' => $s['aufgabe']];
            }
        }
    }
    uasort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    $total = 0;
    $open = 0;
    ?>
    <div class="head-row">
        <p class="muted">Nach dem Dienst die tatsächlichen Zeiten eintragen. Daraus werden die Ehrenamtsstunden berechnet
            (Überschneidungen zählen nur einmal).</p>
        <?= post_button(url_admin('blutspende', 'stunden_uebernehmen', ['id' => $id]), 'Alle offenen wie geplant übernehmen', [], 'Ist-Zeiten = Planzeiten für alle zugesagten Einteilungen ohne Eintrag', false, '', 'btn btn-outline') ?>
    </div>
    <?php if ($t['datum'] > date('Y-m-d')): ?><div class="notice-box">Der Termin liegt in der Zukunft. Stunden werden normalerweise nach dem Dienst eingetragen.</div><?php endif; ?>
    <?php if (!$rows): ?><p class="muted">Noch niemand eingeteilt.</p><?php else: ?>
        <form method="post" action="<?= e(url_admin('blutspende', 'stunden_speichern', ['id' => $id])) ?>">
            <?= csrf_field() ?>
            <table class="list hours">
                <thead><tr><th>Person</th><th>Aufgabe</th><th>Plan</th><th>gekommen</th><th>gegangen</th><th>nicht da</th><th class="right">Stunden</th></tr></thead>
                <?php foreach ($rows as $p):
                    $intervals = [];
                    foreach ($p['items'] as $i => $e):
                        if ($e['ist_von'] && !(int)$e['nicht_erschienen']) {
                            $intervals[] = [$e['ist_von'], $e['ist_bis']];
                        } elseif (!(int)$e['nicht_erschienen'] && $e['status'] === 'zugesagt') {
                            $open++;
                        } ?>
                        <tr class="<?= $i === 0 ? 'first' : 'cont' ?><?= (int)$e['nicht_erschienen'] ? ' absent' : '' ?>">
                            <td><?= $i === 0 ? '<strong>' . e($p['name']) . '</strong>' : '' ?></td>
                            <td><?= e($e['aufgabe']) ?><?= $e['status'] === 'angefragt' ? ' <small class="muted">(angefragt)</small>' : '' ?></td>
                            <td class="nowrap muted"><?= e($e['eff_von']) ?>–<?= e($e['eff_bis']) ?></td>
                            <td><input type="time" step="900" name="ist_von[<?= (int)$e['id'] ?>]" value="<?= e($e['ist_von']) ?>" placeholder="<?= e($e['eff_von']) ?>"></td>
                            <td><input type="time" step="900" name="ist_bis[<?= (int)$e['id'] ?>]" value="<?= e($e['ist_bis']) ?>" placeholder="<?= e($e['eff_bis']) ?>"></td>
                            <td class="center"><input type="checkbox" name="fehlt[<?= (int)$e['id'] ?>]" value="1"<?= (int)$e['nicht_erschienen'] ? ' checked' : '' ?> aria-label="nicht erschienen"></td>
                            <td class="right nowrap"><?= $e['ist_von'] ? e(hours_de(union_minutes([[$e['ist_von'], $e['ist_bis']]]))) : '' ?></td>
                        </tr>
                    <?php endforeach;
                    $min = union_minutes($intervals);
                    $total += $min; ?>
                    <tr class="subtotal"><td colspan="6" class="right">Summe <?= e($p['name']) ?></td><td class="right nowrap"><strong><?= e(hours_de($min)) ?></strong></td></tr>
                <?php endforeach; ?>
                <tfoot><tr><td colspan="6">Ehrenamtsstunden gesamt<?= $open ? ' <span class="badge warn">' . $open . ' noch offen</span>' : '' ?></td><td class="right nowrap"><?= e(hours_de($total)) ?></td></tr></tfoot>
            </table>
            <div class="actions"><button class="btn">Stunden speichern</button></div>
        </form>
    <?php endif; ?>

    <?php if ($shifts): ?>
        <form method="post" action="<?= e(url_admin('blutspende', 'stunden_nachtragen', ['id' => $id])) ?>" class="panel">
            <?= csrf_field() ?>
            <h3>Spontane Hilfe nachtragen</h3>
            <p class="muted small">Für Personen, die ohne Einteilung geholfen haben.</p>
            <div class="filters">
                <select name="mitglied_id" required><option value="">Person …</option>
                    <?php foreach (all("SELECT id, vorname, nachname FROM mitglieder WHERE status <> 'ausgetreten' ORDER BY nachname, vorname") as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= e($m['nachname'] . ', ' . $m['vorname']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sid" required><?php foreach ($shifts as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['aufgabe']) ?></option><?php endforeach; ?></select>
                <input type="time" step="900" name="von" required aria-label="von" value="<?= e($t['beginn']) ?>">
                <input type="time" step="900" name="bis" required aria-label="bis" value="<?= e($t['ende']) ?>">
                <button class="btn btn-outline">Nachtragen</button>
            </div>
        </form>
    <?php endif;
}

function render_dienstplan_print(array $t, int $id): void
{
    $shifts = termin_shifts($id);
    $heading = date_de($t['datum'], true) . ' · ' . $t['ort'] . ($t['beginn'] ? ' · ' . $t['beginn'] . '–' . $t['ende'] . ' Uhr' : '');
    echo '<div class="print-page"><h1>Dienstplan Blutspende</h1><p>' . e($heading) . '</p>';
    echo render_timeline($shifts);
    foreach ($shifts as $s) {
        $people = array_filter($s['einteilungen'], fn($e) => $e['status'] !== 'abgesagt');
        echo '<h2>' . e($s['aufgabe']) . ' <small>' . e($s['von']) . '–' . e($s['bis']) . ' Uhr · ' . ((int)$s['flexibel'] ? 'flexibel' : 'feste Zeit')
            . ' · ' . $s['summary']['besetzt'] . '/' . $s['summary']['bedarf'] . '</small></h2>' . gaps_text($s) . '<table class="print-table">';
        foreach ($people as $p) {
            echo '<tr><td>' . e($p['eff_von']) . '–' . e($p['eff_bis']) . '</td><td>' . e(member_name($p)) . '</td><td>' . e($p['mobil'] ?: $p['telefon']) . '</td><td>'
                . ($p['status'] === 'angefragt' ? 'angefragt' : '') . '</td><td class="sign"></td></tr>';
        }
        if (!$people) {
            echo '<tr class="open"><td colspan="5">noch niemand eingeteilt</td></tr>';
        }
        echo '</table>';
    }
    $persons = person_schedule($shifts);
    if ($persons) {
        echo '<h2 class="page-break">Ablauf pro Person</h2><table class="print-table"><tr><th>Person</th><th>Einsätze</th><th>Std.</th></tr>';
        foreach ($persons as $p) {
            echo '<tr><td>' . e($p['name']) . '</td><td>' . e(implode(' · ', array_map(fn($x) => $x[0] . '–' . $x[1] . ' ' . $x[2], $p['einsaetze']))) . '</td><td>'
                . e(num_de($p['minuten'] / 60)) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';
}
