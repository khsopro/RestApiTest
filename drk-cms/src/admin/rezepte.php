<?php
/** Rezepte für den Blutspende-Imbiss */

$fields = [
    ['name', 'Name des Gerichts', 'text', ['required' => true]],
    ['kategorie', 'Kategorie', 'select', ['options' => array_merge([''], setting_lines('rezept_kategorien'))]],
    ['portionen', 'Rezept ergibt Portionen', 'number', ['required' => true, 'help' => 'Die Mengen unten beziehen sich auf diese Anzahl.']],
    ['allergene', 'Allergene / Kennzeichnung', 'text', ['help' => 'z. B. Gluten, Ei, Milch, Senf']],
    ['vegetarisch', 'Vegetarisch', 'checkbox'],
    ['zubereitung', 'Zubereitung', 'textarea', ['rows' => 6, 'wide' => true]],
    ['notizen', 'Tipps & Erfahrungen (z. B. „reicht knapp“, „sehr beliebt“)', 'textarea', ['rows' => 2, 'wide' => true]],
];
$id = (int)get('id', 0);
$sections = setting_lines('einkauf_abteilungen');

if (is_post()) {
    if ($a === 'speichern') {
        $data = form_collect($fields);
        $data['portionen'] = max(1, (int)$data['portionen']);
        if ($data['name'] === '') {
            flash('Bitte einen Namen angeben.', 'error');
            redirect(url_admin('rezepte', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
        }
        $data['aktualisiert'] = now();
        db()->beginTransaction();
        if ($id) {
            update('rezepte', $data, $id);
            q('DELETE FROM rezept_zutaten WHERE rezept_id = ?', [$id]);
        } else {
            $data['erstellt'] = now();
            $id = insert('rezepte', $data);
        }
        $z = $_POST['z'] ?? [];
        foreach ((array)($z['name'] ?? []) as $i => $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            insert('rezept_zutaten', [
                'rezept_id' => $id, 'sortierung' => (int)$i, 'name' => $name,
                'menge' => parse_num((string)($z['menge'][$i] ?? '0')),
                'einheit' => trim((string)($z['einheit'][$i] ?? '')),
                'abteilung' => trim((string)($z['abteilung'][$i] ?? '')),
            ]);
        }
        db()->commit();
        flash('Rezept gespeichert.');
        redirect(url_admin('rezepte', 'bearbeiten', ['id' => $id]));
    }
    if ($a === 'kopieren' && $id) {
        $r = one('SELECT * FROM rezepte WHERE id = ?', [$id]);
        if ($r) {
            unset($r['id']);
            $r['name'] .= ' (Kopie)';
            $r['erstellt'] = $r['aktualisiert'] = now();
            $new = insert('rezepte', $r);
            foreach (all('SELECT * FROM rezept_zutaten WHERE rezept_id = ?', [$id]) as $z) {
                unset($z['id']);
                $z['rezept_id'] = $new;
                insert('rezept_zutaten', $z);
            }
            flash('Rezept kopiert.');
            redirect(url_admin('rezepte', 'bearbeiten', ['id' => $new]));
        }
    }
    if ($a === 'loeschen' && $id) {
        q('DELETE FROM rezepte WHERE id = ?', [$id]);
        flash('Rezept gelöscht.');
        redirect(url_admin('rezepte'));
    }
}

if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one('SELECT * FROM rezepte WHERE id = ?', [$id]) : ['portionen' => 10];
    if (!$row) {
        redirect(url_admin('rezepte'));
    }
    $ingredients = $id ? all('SELECT * FROM rezept_zutaten WHERE rezept_id = ? ORDER BY sortierung, id', [$id]) : [];
    $ingredients = array_merge($ingredients, array_fill(0, 3, ['menge' => '', 'einheit' => '', 'name' => '', 'abteilung' => '']));
    $units = ['g', 'kg', 'ml', 'l', 'Stück', 'Pck', 'Dose', 'Glas', 'Flasche', 'Bund', 'Kopf', 'Becher', 'EL', 'TL', 'Prise'];
    $title = $id ? $row['name'] : 'Neues Rezept';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('rezepte')) ?>">Rezepte</a> › <?= e($title) ?></p>
    <div class="head-row">
        <h1><?= e($title) ?></h1>
        <?php if ($id): ?><?= post_button(url_admin('rezepte', 'kopieren', ['id' => $id]), 'Als Vorlage kopieren', [], '', false, '', 'btn btn-outline') ?><?php endif; ?>
    </div>
    <form method="post" action="<?= e(url_admin('rezepte', 'speichern', $id ? ['id' => $id] : [])) ?>">
        <?= csrf_field() ?>
        <section class="panel"><div class="form-grid"><?= form_fields($fields, $row) ?></div></section>
        <section class="panel">
            <h2>Zutaten <small class="muted">für <?= (int)$row['portionen'] ?> Portionen</small></h2>
            <datalist id="units"><?php foreach ($units as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
            <datalist id="sections"><?php foreach ($sections as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
            <table class="list ingredients" id="ingredients">
                <thead><tr><th>Menge</th><th>Einheit</th><th>Zutat</th><th>Einkaufsabteilung</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($ingredients as $z): ?>
                    <tr>
                        <td><input name="z[menge][]" value="<?= $z['menge'] === '' ? '' : e(rtrim(rtrim(number_format((float)$z['menge'], 3, ',', ''), '0'), ',')) ?>" class="num" inputmode="decimal"></td>
                        <td><input name="z[einheit][]" value="<?= e($z['einheit']) ?>" list="units" class="unit"></td>
                        <td><input name="z[name][]" value="<?= e($z['name']) ?>"></td>
                        <td><input name="z[abteilung][]" value="<?= e($z['abteilung']) ?>" list="sections"></td>
                        <td><button type="button" class="btn btn-small btn-ghost" data-remove-row title="Zeile entfernen">✕</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-small btn-outline" data-add-row="ingredients">+ Zeile</button>
            <p class="muted small">Tipp: Gleiche Zutaten bitte immer gleich schreiben (z. B. „Butter“), dann werden sie in der Einkaufsliste zusammengefasst. g/kg und ml/l werden automatisch umgerechnet.</p>
        </section>
        <div class="actions sticky-actions"><button class="btn">Speichern</button> <a href="<?= e(url_admin('rezepte')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url_admin('rezepte', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Rezept löschen? Es wird auch aus allen Menüs entfernt.">
            <?= csrf_field() ?><button class="btn btn-danger">Rezept löschen</button>
        </form>
    <?php endif;
    return;
}

$kat = (string)get('kategorie', '');
$rows = all('SELECT r.*, (SELECT COUNT(*) FROM rezept_zutaten z WHERE z.rezept_id = r.id) AS zutaten,
        (SELECT COUNT(*) FROM bs_menue m WHERE m.rezept_id = r.id) AS verwendet
    FROM rezepte r' . ($kat !== '' ? ' WHERE kategorie = ?' : '') . ' ORDER BY kategorie, name', $kat !== '' ? [$kat] : []);
?>
<div class="head-row">
    <h1>Rezepte <small class="muted">(<?= count($rows) ?>)</small></h1>
    <a class="btn" href="<?= e(url_admin('rezepte', 'neu')) ?>">+ Neues Rezept</a>
</div>
<nav class="tabs">
    <a href="<?= e(url_admin('rezepte')) ?>" class="<?= $kat === '' ? 'active' : '' ?>">Alle</a>
    <?php foreach (setting_lines('rezept_kategorien') as $k): ?>
        <a href="<?= e(url_admin('rezepte', '', ['kategorie' => $k])) ?>" class="<?= $kat === $k ? 'active' : '' ?>"><?= e($k) ?></a>
    <?php endforeach; ?>
</nav>
<table class="list">
    <thead><tr><th>Gericht</th><th>Kategorie</th><th>Portionen</th><th>Zutaten</th><th>Allergene</th><th>Verwendet</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= e(url_admin('rezepte', 'bearbeiten', ['id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a><?= (int)$r['vegetarisch'] ? ' <span class="badge ok">veg.</span>' : '' ?></td>
            <td><?= e($r['kategorie']) ?></td>
            <td><?= (int)$r['portionen'] ?></td>
            <td><?= (int)$r['zutaten'] ?></td>
            <td class="small"><?= e($r['allergene']) ?></td>
            <td><?= (int)$r['verwendet'] ?>×</td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Keine Rezepte.</td></tr><?php endif; ?>
    </tbody>
</table>
