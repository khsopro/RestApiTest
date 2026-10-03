<?php
/** Benutzerkonten und Rollen (nur Admin) */

$id = (int)get('id', 0);
$me = current_user();

if (is_post()) {
    if ($a === 'speichern') {
        $rollen = array_values(array_intersect(array_keys(roles()), (array)($_POST['rollen'] ?? [])));
        $data = [
            'benutzername' => (string)post('benutzername'),
            'name'         => (string)post('name'),
            'email'        => (string)post('email'),
            'rollen'       => implode(',', $rollen),
            'mitglied_id'  => (int)post('mitglied_id') ?: null,
            'aktiv'        => isset($_POST['aktiv']) ? 1 : 0,
        ];
        $pw = (string)($_POST['passwort'] ?? '');
        $back = url_admin('benutzer', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []);
        if ($data['benutzername'] === '' || val('SELECT id FROM benutzer WHERE benutzername = ? AND id <> ?', [$data['benutzername'], $id])) {
            flash('Benutzername fehlt oder ist bereits vergeben.', 'error');
            redirect($back);
        }
        if ((!$id || $pw !== '') && ($err = password_ok($pw))) {
            flash($err, 'error');
            redirect($back);
        }
        if ($id === (int)$me['id'] && (!in_array('admin', $rollen, true) || !$data['aktiv'])) {
            flash('Sie können sich nicht selbst die Administratorrechte entziehen oder sich deaktivieren.', 'error');
            redirect($back);
        }
        if ($pw !== '') {
            $data['passwort_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        }
        if ($id) {
            update('benutzer', $data, $id);
        } else {
            $data['erstellt'] = now();
            $id = insert('benutzer', $data);
        }
        audit('Benutzer gespeichert', $data['benutzername'] . ' [' . $data['rollen'] . ']');
        flash('Benutzer gespeichert.' . ($pw !== '' ? ' Bitte das Passwort auf sicherem Weg mitteilen.' : ''));
        redirect(url_admin('benutzer'));
    }
    if ($a === 'loeschen' && $id && $id !== (int)$me['id']) {
        $name = (string)val('SELECT benutzername FROM benutzer WHERE id = ?', [$id]);
        q('DELETE FROM benutzer WHERE id = ?', [$id]);
        audit('Benutzer gelöscht', $name);
        flash('Benutzer gelöscht.');
        redirect(url_admin('benutzer'));
    }
}

if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one('SELECT * FROM benutzer WHERE id = ?', [$id]) : ['aktiv' => 1, 'rollen' => 'helfer', 'mitglied_id' => (int)get('mitglied', 0) ?: null];
    if (!$row) {
        redirect(url_admin('benutzer'));
    }
    if (!$id && $row['mitglied_id'] && ($mem = one('SELECT * FROM mitglieder WHERE id = ?', [$row['mitglied_id']]))) {
        $row['name'] = member_name($mem);
        $row['email'] = $mem['email'];
        $row['benutzername'] = slugify($mem['vorname'] . '.' . $mem['nachname']);
    }
    $members = ['' => '– keine Verknüpfung –'];
    foreach (all("SELECT id, vorname, nachname FROM mitglieder WHERE status <> 'ausgetreten' ORDER BY nachname, vorname") as $mm) {
        $members[$mm['id']] = $mm['nachname'] . ', ' . $mm['vorname'];
    }
    $current = user_roles($row);
    $title = $id ? 'Benutzer ' . $row['benutzername'] : 'Neuer Benutzer';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('benutzer')) ?>">Benutzer</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <form method="post" action="<?= e(url_admin('benutzer', 'speichern', $id ? ['id' => $id] : [])) ?>" class="panel">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= form_fields([
                ['benutzername', 'Benutzername', 'text', ['required' => true]],
                ['name', 'Name', 'text'],
                ['email', 'E-Mail', 'email'],
                ['mitglied_id', 'Verknüpftes Mitgliederprofil', 'select', ['options' => $members, 'assoc' => true, 'help' => 'Nötig für „Mein Profil“ und Selbst-Eintragung in Schichten.']],
            ], $row) ?>
            <div class="field"><label>Passwort <?= $id ? '(leer lassen = unverändert)' : '' ?></label><input type="password" name="passwort" autocomplete="new-password" <?= $id ? '' : 'required' ?>></div>
            <fieldset class="field wide"><legend>Rollen</legend>
                <?php foreach (roles() as $k => $label): ?>
                    <label class="check"><input type="checkbox" name="rollen[]" value="<?= e($k) ?>"<?= in_array($k, $current, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </fieldset>
            <?= form_field(['aktiv', 'Konto aktiv', 'checkbox'], $row) ?>
        </div>
        <div class="actions"><button class="btn">Speichern</button> <a href="<?= e(url_admin('benutzer')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id && $id !== (int)$me['id']): ?>
        <form method="post" action="<?= e(url_admin('benutzer', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Benutzerkonto löschen?">
            <?= csrf_field() ?><button class="btn btn-danger">Benutzer löschen</button>
        </form>
    <?php endif;
    return;
}

$rows = all('SELECT b.*, m.vorname, m.nachname FROM benutzer b LEFT JOIN mitglieder m ON m.id = b.mitglied_id ORDER BY b.benutzername');
?>
<div class="head-row">
    <h1>Benutzer</h1>
    <a class="btn" href="<?= e(url_admin('benutzer', 'neu')) ?>">+ Neuer Benutzer</a>
</div>
<table class="list">
    <thead><tr><th>Benutzername</th><th>Name</th><th>Rollen</th><th>Mitglied</th><th>Letzte Anmeldung</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= e(url_admin('benutzer', 'bearbeiten', ['id' => $r['id']])) ?>"><strong><?= e($r['benutzername']) ?></strong></a></td>
            <td><?= e($r['name']) ?></td>
            <td><?php foreach (user_roles($r) as $role): ?><span class="badge"><?= e($role) ?></span> <?php endforeach; ?></td>
            <td><?= $r['vorname'] ? e($r['vorname'] . ' ' . $r['nachname']) : '<span class="muted">–</span>' ?></td>
            <td><?= e(datetime_de($r['letzter_login'])) ?></td>
            <td><?= (int)$r['aktiv'] ? '<span class="badge ok">aktiv</span>' : '<span class="badge bad">gesperrt</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
