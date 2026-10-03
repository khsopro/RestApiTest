<?php
/** Unterstützer, Spender und Sponsoren */

$fields = [
    ['typ', 'Typ', 'select', ['options' => ['Privatperson', 'Firma', 'Stiftung', 'Verein/Institution', 'Kommune']]],
    ['name', 'Name / Firma', 'text', ['required' => true]],
    ['ansprechpartner', 'Ansprechpartner/in', 'text'],
    ['art', 'Art der Unterstützung', 'select', ['options' => ['Geldspende', 'Sachspende', 'Sponsoring', 'Fördermitgliedschaft', 'Dienstleistung', 'Räume', 'Sonstiges']]],
    ['betrag', 'Betrag pro Jahr (€, optional)', 'number', ['step' => '0.01']],
    ['seit', 'Unterstützt seit', 'date'],
    ['strasse', 'Straße', 'text'],
    ['plz', 'PLZ', 'text'],
    ['ort', 'Ort', 'text'],
    ['telefon', 'Telefon', 'tel'],
    ['email', 'E-Mail', 'email'],
    ['webseite', 'Webseite', 'text', ['help' => 'mit https://']],
    ['notizen', 'Notizen (z. B. Spendenquittung erstellt, Absprachen)', 'textarea', ['wide' => true]],
    ['oeffentlich', 'Auf der Website nennen (Baustein „Unterstützer“) – nur mit Einverständnis!', 'checkbox', ['wide' => true]],
];
$id = (int)get('id', 0);

if (is_post()) {
    if ($a === 'speichern') {
        $data = form_collect($fields);
        $data['logo'] = (int)post('logo') ?: null;
        if ($data['name'] === '') {
            flash('Bitte einen Namen angeben.', 'error');
            redirect(url_admin('unterstuetzer', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
        }
        $data['aktualisiert'] = now();
        if ($id) {
            update('unterstuetzer', $data, $id);
        } else {
            $data['erstellt'] = now();
            $id = insert('unterstuetzer', $data);
        }
        audit('Unterstützer gespeichert', $data['name'] . ' (#' . $id . ')');
        flash('Gespeichert.');
        redirect(url_admin('unterstuetzer'));
    }
    if ($a === 'loeschen' && $id) {
        $name = (string)val('SELECT name FROM unterstuetzer WHERE id = ?', [$id]);
        q('DELETE FROM unterstuetzer WHERE id = ?', [$id]);
        audit('Unterstützer gelöscht', $name . ' (#' . $id . ')');
        flash('Gelöscht.');
        redirect(url_admin('unterstuetzer'));
    }
}

if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one('SELECT * FROM unterstuetzer WHERE id = ?', [$id]) : ['seit' => date('Y-m-d')];
    if (!$row) {
        redirect(url_admin('unterstuetzer'));
    }
    $title = $id ? $row['name'] : 'Neuer Unterstützer';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('unterstuetzer')) ?>">Unterstützer</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <form method="post" action="<?= e(url_admin('unterstuetzer', 'speichern', $id ? ['id' => $id] : [])) ?>" class="panel">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= form_fields($fields, $row) ?>
            <div class="field wide"><label>Logo (für die Website)</label><?= image_picker('logo', (string)($row['logo'] ?? '')) ?></div>
        </div>
        <div class="actions"><button class="btn">Speichern</button> <a href="<?= e(url_admin('unterstuetzer')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url_admin('unterstuetzer', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Eintrag löschen?">
            <?= csrf_field() ?><button class="btn btn-danger">Löschen</button>
        </form>
    <?php endif;
    return;
}

$search = trim((string)get('q', ''));
$rows = $search === ''
    ? all('SELECT * FROM unterstuetzer ORDER BY name')
    : all('SELECT * FROM unterstuetzer WHERE name LIKE ? OR ansprechpartner LIKE ? OR ort LIKE ? ORDER BY name', array_fill(0, 3, '%' . $search . '%'));
$sum = array_sum(array_map(fn($r) => (float)$r['betrag'], $rows));
?>
<div class="head-row">
    <h1>Unterstützer <small class="muted">(<?= count($rows) ?>)</small></h1>
    <a class="btn" href="<?= e(url_admin('unterstuetzer', 'neu')) ?>">+ Neuer Eintrag</a>
</div>
<form class="filters" method="get">
    <input type="hidden" name="m" value="unterstuetzer">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, Ansprechpartner, Ort …">
    <button class="btn btn-outline">Suchen</button>
</form>
<table class="list">
    <thead><tr><th>Name</th><th>Typ</th><th>Art</th><th class="right">€/Jahr</th><th>Kontakt</th><th>Website</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= e(url_admin('unterstuetzer', 'bearbeiten', ['id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a>
                <?php if ($r['ansprechpartner']): ?><br><small class="muted"><?= e($r['ansprechpartner']) ?></small><?php endif; ?></td>
            <td><?= e($r['typ']) ?></td>
            <td><?= e($r['art']) ?></td>
            <td class="right"><?= $r['betrag'] !== null ? num_de((float)$r['betrag']) : '' ?></td>
            <td class="small"><?= e($r['telefon']) ?><?php if ($r['email']): ?><br><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?></td>
            <td><?= (int)$r['oeffentlich'] ? '<span class="badge ok">genannt</span>' : '<span class="badge">nein</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Noch keine Einträge.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($sum > 0): ?><tfoot><tr><td colspan="3">Summe</td><td class="right"><strong><?= num_de($sum) ?></strong></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
</table>
