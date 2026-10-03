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
                update('mitglieder', form_collect($ownFields) + ['aktualisiert' => now()], $mid);
                audit('Eigenes Profil geändert', member_name($member));
                flash('Profil gespeichert.');
            }
            redirect(url_admin('profil'));

        case 'eintragen':
        case 'austragen':
            $sid = (int)post('sid');
            $shift = one('SELECT s.*, t.datum FROM bs_schichten s JOIN bs_termine t ON t.id = s.termin_id WHERE s.id = ?', [$sid]);
            if ($member && $shift && $shift['datum'] >= date('Y-m-d')) {
                $taken = (int)val("SELECT COUNT(*) FROM bs_einteilung WHERE schicht_id = ? AND status = 'zugesagt'", [$sid]);
                $hasQuali = !$shift['qualifikation'] || str_contains((string)$member['qualifikationen'], $shift['qualifikation']);
                if ($a === 'eintragen' && ($taken >= (int)$shift['benoetigt'] || !$hasQuali)) {
                    flash('Diese Schicht ist bereits voll oder erfordert eine andere Qualifikation.', 'error');
                } elseif ($a === 'eintragen') {
                    $existing = val('SELECT id FROM bs_einteilung WHERE schicht_id = ? AND mitglied_id = ?', [$sid, $mid]);
                    if ($existing) {
                        q("UPDATE bs_einteilung SET status = 'zugesagt' WHERE id = ?", [$existing]);
                    } else {
                        insert('bs_einteilung', ['schicht_id' => $sid, 'mitglied_id' => $mid, 'status' => 'zugesagt']);
                    }
                    flash('Danke! Du bist für „' . $shift['aufgabe'] . '“ eingetragen.');
                } elseif ($a === 'austragen') {
                    q("UPDATE bs_einteilung SET status = 'abgesagt' WHERE schicht_id = ? AND mitglied_id = ?", [$sid, $mid]);
                    flash('Du hast dich ausgetragen. Das Blutspende-Team sieht die Absage.');
                }
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
    <h2>Blutspende – Helfer/innen gesucht</h2>
    <?php
    $termine = all('SELECT * FROM bs_termine WHERE datum >= ? ORDER BY datum LIMIT 6', [date('Y-m-d')]);
    if (!$termine): ?><p class="muted">Aktuell sind keine Termine geplant.</p><?php endif;
    foreach ($termine as $t):
        $shifts = all("SELECT s.*,
                (SELECT COUNT(*) FROM bs_einteilung e WHERE e.schicht_id = s.id AND e.status = 'zugesagt') AS belegt,
                (SELECT status FROM bs_einteilung e WHERE e.schicht_id = s.id AND e.mitglied_id = ?) AS mein_status
            FROM bs_schichten s WHERE s.termin_id = ? ORDER BY s.von, s.aufgabe", [$mid, $t['id']]);
        if (!$shifts) continue; ?>
        <h3><?= e(date_de($t['datum'], true)) ?> · <?= e($t['ort']) ?> <small class="muted"><?= e($t['beginn']) ?>–<?= e($t['ende']) ?> Uhr</small></h3>
        <table class="list">
            <?php foreach ($shifts as $s):
                $free = (int)$s['benoetigt'] - (int)$s['belegt'];
                $hasQuali = !$s['qualifikation'] || str_contains((string)$member['qualifikationen'], $s['qualifikation']); ?>
                <tr>
                    <td><strong><?= e($s['aufgabe']) ?></strong><?php if ($s['qualifikation']): ?><br><small class="muted">benötigt: <?= e($s['qualifikation']) ?></small><?php endif; ?></td>
                    <td><?= e($s['von']) ?>–<?= e($s['bis']) ?></td>
                    <td><?= $free > 0 ? '<span class="badge warn">' . $free . ' frei</span>' : '<span class="badge ok">voll</span>' ?></td>
                    <td class="right">
                        <?php if ($s['mein_status'] === 'zugesagt' || $s['mein_status'] === 'angefragt'): ?>
                            <span class="badge ok">Du bist dabei</span>
                            <?= post_button(url_admin('profil', 'austragen'), 'Absagen', ['sid' => $s['id']], '', false, 'Wirklich absagen?') ?>
                        <?php elseif ($free > 0 && $hasQuali): ?>
                            <?= post_button(url_admin('profil', 'eintragen'), 'Ich helfe mit', ['sid' => $s['id']], '', false, '', 'btn btn-small') ?>
                        <?php elseif (!$hasQuali): ?>
                            <small class="muted">Qualifikation fehlt</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>
</section>

<section class="panel">
    <h2>Meine Daten &amp; Qualifikationen</h2>
    <p class="muted small">Diese Angaben sieht die Vereinsverwaltung. Name, Geburtsdatum und Mitgliedsstatus ändert die Verwaltung.</p>
    <form method="post" action="<?= e(url_admin('profil', 'mitglied')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <?= form_fields($ownFields, $member) ?>
        <div class="actions wide"><button class="btn">Profil speichern</button></div>
    </form>
</section>
