<?php
/** Eigenes Profil: Kontaktdaten, Qualifikationen, Passwort, Selbst-Eintragung in Blutspende-Schichten */

$u = current_user();
$mid = (int)($u['mitglied_id'] ?? 0);
$member = $mid ? one('SELECT * FROM mitglieder WHERE id = ?', [$mid]) : null;
$ownFields = [
    ['telefon', 'Telefon', 'tel'],
    ['mobil', 'Mobil', 'tel'],
    ['email', 'E-Mail', 'email'],
    ['strasse', 'Straße & Hausnummer', 'text'],
    ['plz', 'PLZ', 'text'],
    ['ort', 'Ort', 'text'],
    ['qualifikationen', 'Meine Qualifikationen', 'checklist', ['options' => qualification_options(), 'wide' => true]],
    ['verfuegbarkeit', 'Wann kann ich helfen?', 'textarea', ['rows' => 2, 'wide' => true]],
    ['profil_text', 'Über mich / Interessen', 'textarea', ['rows' => 3, 'wide' => true]],
];

if (is_post()) {
    switch ($a) {
        case 'passwort':
            $old = (string)($_POST['alt'] ?? '');
            $new = (string)($_POST['neu'] ?? '');
            if (!password_verify($old, $u['passwort_hash'])) {
                flash('Das bisherige Passwort ist falsch.', 'error');
            } elseif ($err = password_ok($new)) {
                flash($err, 'error');
            } elseif ($new !== ($_POST['neu2'] ?? '')) {
                flash('Die neuen Passwörter stimmen nicht überein.', 'error');
            } else {
                update('benutzer', ['passwort_hash' => password_hash($new, PASSWORD_DEFAULT)], (int)$u['id']);
                session_regenerate_id(true);
                audit('Passwort geändert');
                flash('Passwort geändert.');
            }
            redirect(url_admin('profil'));

        case 'konto':
            update('benutzer', ['name' => (string)post('name'), 'email' => (string)post('email')], (int)$u['id']);
            flash('Kontodaten gespeichert.');
            redirect(url_admin('profil'));

        case 'mitglied':
            if ($member) {
                $foto = $member['foto'];
                if (!empty($_FILES['foto_datei']['name'])) {
                    $result = store_upload($_FILES['foto_datei'], 'Profilfoto ' . member_name($member), true);
                    if (is_string($result)) {
                        flash($result, 'error');
                    } else {
                        $foto = $result;
                    }
                } elseif (isset($_POST['foto_entfernen'])) {
                    $foto = null;
                }
                update('mitglieder', form_collect($ownFields) + ['foto' => $foto, 'aktualisiert' => now()], $mid);
                audit('Eigenes Profil geändert', member_name($member));
                flash('Profil gespeichert.');
            }
            redirect(url_admin('profil'));

        case 'eintragen':
            $shift = one('SELECT s.*, t.datum FROM bs_schichten s JOIN bs_termine t ON t.id = s.termin_id WHERE s.id = ?', [(int)post('sid')]);
            if (!$member || !$shift || $shift['datum'] < date('Y-m-d')) {
                redirect(url_admin('profil') . '#einsaetze');
            }
            $hasQuali = !$shift['qualifikation'] || str_contains((string)$member['qualifikationen'], $shift['qualifikation']);
            $times = (int)$shift['flexibel'] ? clamp_to_shift((string)post('von'), (string)post('bis'), $shift) : [$shift['von'], $shift['bis']];
            $assignments = all('SELECT * FROM bs_einteilung WHERE schicht_id = ?', [$shift['id']]);
            $cov = shift_coverage($shift, $assignments);
            $useful = !$cov;
            foreach ($cov as $m => $n) {
                if ($times && $m >= t2m($times[0]) && $m < t2m($times[1]) && $n < (int)$shift['benoetigt']) {
                    $useful = true;
                }
            }
            if (!$cov && count(array_filter($assignments, fn($e) => $e['status'] === 'zugesagt')) >= (int)$shift['benoetigt']) {
                $useful = false;
            }
            // eigene Überschneidungen am selben Termin
            $clash = null;
            foreach (all("SELECT e.*, s.aufgabe, s.von AS s_von, s.bis AS s_bis, s.flexibel FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id
                WHERE s.termin_id = ? AND e.mitglied_id = ? AND e.status <> 'abgesagt'", [$shift['termin_id'], $mid]) as $own) {
                [$ov, $ob] = eff_times($own, ['von' => $own['s_von'], 'bis' => $own['s_bis'], 'flexibel' => $own['flexibel']]);
                if ($times && t2m($times[0]) < t2m($ob) && t2m($ov) < t2m($times[1])) {
                    $clash = $own['aufgabe'] . ' ' . $ov . '–' . $ob;
                }
            }
            if (!$hasQuali) {
                flash('Für diese Aufgabe fehlt dir die Qualifikation „' . $shift['qualifikation'] . '“.', 'error');
            } elseif (!$times) {
                flash('Bitte gültige Zeiten wählen („von“ vor „bis“).', 'error');
            } elseif ($clash) {
                flash('Zu dieser Zeit bist du schon eingeteilt: ' . $clash . '.', 'error');
            } elseif (!$useful) {
                flash('In diesem Zeitraum ist „' . $shift['aufgabe'] . '“ schon voll besetzt.', 'error');
            } else {
                insert('bs_einteilung', ['schicht_id' => $shift['id'], 'mitglied_id' => $mid, 'status' => 'zugesagt',
                    'von' => (int)$shift['flexibel'] ? $times[0] : null, 'bis' => (int)$shift['flexibel'] ? $times[1] : null]);
                flash('Danke! Du bist für „' . $shift['aufgabe'] . '“ von ' . $times[0] . ' bis ' . $times[1] . ' Uhr eingetragen.');
            }
            redirect(url_admin('profil') . '#einsaetze');

        case 'austragen':
            $own = one('SELECT e.*, t.datum FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id JOIN bs_termine t ON t.id = s.termin_id
                WHERE e.id = ? AND e.mitglied_id = ?', [(int)post('eid'), $mid]);
            if ($own && $own['datum'] >= date('Y-m-d')) {
                update('bs_einteilung', ['status' => 'abgesagt'], (int)$own['id']);
                flash('Du hast dich ausgetragen. Das Blutspende-Team sieht die Absage.');
            }
            redirect(url_admin('profil') . '#einsaetze');
    }
}
?>
<h1>Mein Profil</h1>

<div class="grid-2">
    <section class="panel">
        <h2>Benutzerkonto</h2>
        <p class="muted small">Benutzername: <strong><?= e($u['benutzername']) ?></strong> · Rollen: <?= e(implode(', ', array_map(fn($r) => roles()[$r] ?? $r, user_roles()))) ?></p>
        <form method="post" action="<?= e(url_admin('profil', 'konto')) ?>" class="form-grid">
            <?= csrf_field() ?>
            <?= form_fields([['name', 'Name', 'text'], ['email', 'E-Mail', 'email']], $u) ?>
            <div class="actions wide"><button class="btn btn-outline">Speichern</button></div>
        </form>
    </section>
    <section class="panel">
        <h2>Passwort ändern</h2>
        <form method="post" action="<?= e(url_admin('profil', 'passwort')) ?>" class="form-grid">
            <?= csrf_field() ?>
            <div class="field wide"><label>Bisheriges Passwort</label><input type="password" name="alt" required autocomplete="current-password"></div>
            <div class="field"><label>Neues Passwort (mind. 10 Zeichen)</label><input type="password" name="neu" required autocomplete="new-password"></div>
            <div class="field"><label>Wiederholen</label><input type="password" name="neu2" required autocomplete="new-password"></div>
            <div class="actions wide"><button class="btn btn-outline">Passwort ändern</button></div>
        </form>
    </section>
</div>

<?php if (!$member): ?>
    <div class="notice-box">Ihr Benutzerkonto ist mit keinem Mitgliederprofil verknüpft. Bitte wenden Sie sich an die Administration, wenn Sie sich selbst für Einsätze eintragen möchten.</div>
    <?php return; endif; ?>

<section class="panel" id="einsaetze">
    <div class="head-row">
        <h2>Blutspende – Helfer/innen gesucht</h2>
        <a href="<?= e(url_admin('stellen')) ?>">Was ist bei welcher Aufgabe zu tun? →</a>
    </div>
    <p class="muted small">Bei flexiblen Aufgaben kannst du selbst wählen, von wann bis wann du hilfst (15-Minuten-Schritte). Du kannst dich auch für mehrere Aufgaben nacheinander eintragen.</p>
    <?php
    $termine = all('SELECT * FROM bs_termine WHERE datum >= ? ORDER BY datum LIMIT 6', [date('Y-m-d')]);
    if (!$termine): ?><p class="muted">Aktuell sind keine Termine geplant.</p><?php endif;
    foreach ($termine as $t):
        $shifts = termin_shifts((int)$t['id']);
        if (!$shifts) continue; ?>
        <h3><?= e(date_de($t['datum'], true)) ?> · <?= e($t['ort']) ?> <small class="muted"><?= e($t['beginn']) ?>–<?= e($t['ende']) ?> Uhr</small></h3>
        <table class="list signup">
            <?php foreach ($shifts as $s):
                $flex = (int)$s['flexibel'] === 1;
                $mine = array_filter($s['einteilungen'], fn($e) => (int)$e['mitglied_id'] === $mid && $e['status'] !== 'abgesagt');
                $hasQuali = !$s['qualifikation'] || str_contains((string)$member['qualifikationen'], $s['qualifikation']);
                $gaps = $s['summary']['luecken'];
                $open = $s['summary']['cov'] ? (bool)$gaps : $s['summary']['besetzt'] < $s['summary']['bedarf'];
                $suggest = $gaps ? [$gaps[0][0], $gaps[0][1]] : [$s['von'], $s['bis']]; ?>
                <tr>
                    <td><strong><?= e($s['aufgabe']) ?></strong><?= job_link($s['aufgabe']) ?>
                        <br><small class="muted"><?= e($s['von']) ?>–<?= e($s['bis']) ?> · <?= $flex ? 'flexible Zeiten' : 'feste Zeit' ?><?= $s['qualifikation'] ? ' · benötigt: ' . e($s['qualifikation']) : '' ?></small>
                        <?= coverage_bar_small($s) ?></td>
                    <td class="small"><?php if ($gaps): ?><span class="warn-text">gesucht: <?= e(implode(', ', array_map(fn($g) => $g[0] . '–' . $g[1] . ' (' . $g[2] . ')', $gaps))) ?></span>
                        <?php elseif ($open): ?><span class="warn-text"><?= $s['summary']['bedarf'] - $s['summary']['besetzt'] ?> frei</span>
                        <?php else: ?><span class="badge ok">voll</span><?php endif; ?></td>
                    <td class="right">
                        <?php foreach ($mine as $e): ?>
                            <div class="mine"><span class="badge ok">Du: <?= e($e['eff_von']) ?>–<?= e($e['eff_bis']) ?></span>
                                <?= post_button(url_admin('profil', 'austragen'), 'Absagen', ['eid' => $e['id']], '', false, 'Wirklich absagen?') ?></div>
                        <?php endforeach; ?>
                        <?php if (!$hasQuali): ?>
                            <small class="muted">Qualifikation fehlt</small>
                        <?php elseif ($open && ($flex || !$mine)): ?>
                            <form method="post" action="<?= e(url_admin('profil', 'eintragen')) ?>" class="signup-form">
                                <?= csrf_field() ?><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                                <?php if ($flex): ?>
                                    <select name="von" class="small-select" aria-label="von"><?= time_options($s['von'], $s['bis'], $suggest[0]) ?></select>–<select name="bis" class="small-select" aria-label="bis"><?= time_options($s['von'], $s['bis'], $suggest[1]) ?></select>
                                <?php endif; ?>
                                <button class="btn btn-small">Ich helfe mit</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>
</section>

<?php $year = (int)date('Y'); $own = volunteer_hours($year . '-01-01', $year . '-12-31', $mid)[$mid] ?? null; ?>
<section class="panel">
    <h2>Meine Ehrenamtsstunden <?= $year ?></h2>
    <?php if (!$own): ?><p class="muted">Für dieses Jahr sind noch keine Stunden erfasst. Das Blutspende-Team trägt sie nach jedem Dienst ein.</p><?php else: ?>
        <table class="list">
            <?php foreach ($own['tage'] as $d): ?>
                <tr><td class="nowrap"><?= e(date_de($d['datum'])) ?></td><td><?= e($d['ort']) ?><br><small class="muted"><?= e(implode(', ', $d['aufgaben'])) ?></small></td>
                    <td class="right nowrap"><?= e(hours_de($d['minuten'])) ?></td></tr>
            <?php endforeach; ?>
            <tfoot><tr><td colspan="2">Summe (<?= (int)$own['termine'] ?> Einsätze)</td><td class="right nowrap"><?= e(hours_de($own['minuten'])) ?></td></tr></tfoot>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Meine Daten &amp; Qualifikationen</h2>
    <p class="muted small">Diese Angaben sieht die Vereinsverwaltung. Name, Geburtsdatum und Mitgliedsstatus ändert die Verwaltung.</p>
    <form method="post" action="<?= e(url_admin('profil', 'mitglied')) ?>" class="form-grid" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="field wide profile-head">
            <?= member_avatar($member, 'avatar big') ?>
            <div class="field">
                <label for="foto_datei">Profilfoto (JPG, PNG oder WebP)</label>
                <input id="foto_datei" type="file" name="foto_datei" accept="image/jpeg,image/png,image/webp">
                <?php if ($member['foto']): ?><label class="check"><input type="checkbox" name="foto_entfernen" value="1"> Foto entfernen</label><?php endif; ?>
                <small>Das Foto sehen nur das Blutspende-Team und die Vereinsverwaltung.</small>
            </div>
        </div>
        <?= form_fields($ownFields, $member) ?>
        <div class="actions wide"><button class="btn">Profil speichern</button></div>
    </form>
</section>
