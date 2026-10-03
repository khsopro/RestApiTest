<?php
/** Seitenverwaltung mit Baustein-Editor */

$layouts = ['standard' => 'Standard (Lesebreite)', 'breit' => 'Breit (volle Breite, z. B. Startseite)', 'seitenleiste' => 'Mit Seitenleiste (Unterseiten-Navigation)'];
$farben = ['rot' => 'DRK-Rot', 'dunkelrot' => 'Dunkelrot', 'grau' => 'Grau', 'blau' => 'Blau', 'gruen' => 'Grün'];

$id = (int)get('id', 0);

/* ---------- Aktionen (POST) ---------- */
if (is_post()) {
    switch ($a) {
        case 'speichern':
            $data = [
                'titel'           => (string)post('titel'),
                'menue_titel'     => (string)post('menue_titel'),
                'slug'            => slugify((string)(post('slug') ?: post('titel'))),
                'parent_id'       => (int)post('parent_id') ?: null,
                'sortierung'      => (int)post('sortierung'),
                'im_menue'        => isset($_POST['im_menue']) ? 1 : 0,
                'veroeffentlicht' => isset($_POST['veroeffentlicht']) ? 1 : 0,
                'layout'          => array_key_exists(post('layout'), $layouts) ? post('layout') : 'standard',
                'farbe'           => array_key_exists(post('farbe'), $farben) ? post('farbe') : 'rot',
                'hero_bild'       => (int)post('hero_bild') ?: null,
                'hero_text'       => (string)post('hero_text'),
                'beschreibung'    => (string)post('beschreibung'),
                'aktualisiert'    => now(),
            ];
            if ($data['titel'] === '') {
                flash('Bitte einen Titel angeben.', 'error');
                redirect(url_admin('seiten', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
            }
            if ($data['parent_id'] === $id) {
                $data['parent_id'] = null;
            }
            // Kürzel eindeutig machen
            $base = $data['slug'];
            $n = 2;
            while (val('SELECT id FROM seiten WHERE slug = ? AND id <> ?', [$data['slug'], $id])) {
                $data['slug'] = $base . '-' . $n++;
            }
            if ($id) {
                update('seiten', $data, $id);
            } else {
                $data['erstellt'] = now();
                $id = insert('seiten', $data);
            }
            if (isset($_POST['ist_startseite'])) {
                q('UPDATE seiten SET ist_startseite = CASE WHEN id = ? THEN 1 ELSE 0 END', [$id]);
            }
            audit('Seite gespeichert', $data['titel']);
            flash('Seite gespeichert.');
            redirect(url_admin('seiten', 'bearbeiten', ['id' => $id]));

        case 'loeschen':
            $p = one('SELECT * FROM seiten WHERE id = ?', [$id]);
            if ($p && (int)$p['ist_startseite'] === 1) {
                flash('Die Startseite kann nicht gelöscht werden. Bitte zuerst eine andere Seite als Startseite festlegen.', 'error');
            } elseif ($p) {
                q('UPDATE seiten SET parent_id = NULL WHERE parent_id = ?', [$id]);
                q('DELETE FROM seiten_bloecke WHERE seite_id = ?', [$id]);
                q('DELETE FROM seiten WHERE id = ?', [$id]);
                audit('Seite gelöscht', $p['titel']);
                flash('Seite „' . $p['titel'] . '“ gelöscht.');
            }
            redirect(url_admin('seiten'));

        case 'block_neu':
            $typ = (string)post('typ');
            if (isset(block_types()[$typ]) && $id) {
                $sort = (int)val('SELECT COALESCE(MAX(sortierung), -1) + 1 FROM seiten_bloecke WHERE seite_id = ?', [$id]);
                $bid = insert('seiten_bloecke', ['seite_id' => $id, 'sortierung' => $sort, 'typ' => $typ, 'daten' => '{}']);
                redirect(url_admin('seiten', 'block', ['id' => $bid]));
            }
            redirect(url_admin('seiten', 'bearbeiten', ['id' => $id]));

        case 'block':
            $b = one('SELECT * FROM seiten_bloecke WHERE id = ?', [$id]);
            if ($b) {
                update('seiten_bloecke', ['daten' => json_encode(block_collect($b['typ']), JSON_UNESCAPED_UNICODE)], $id);
                update('seiten', ['aktualisiert' => now()], (int)$b['seite_id']);
                flash('Baustein gespeichert.');
                redirect(isset($_POST['weiter'])
                    ? url_admin('seiten', 'block', ['id' => $id])
                    : url_admin('seiten', 'bearbeiten', ['id' => $b['seite_id']]) . '#bausteine');
            }
            redirect(url_admin('seiten'));

        case 'block_verschieben':
        case 'block_loeschen':
        case 'block_kopieren':
            $b = one('SELECT * FROM seiten_bloecke WHERE id = ?', [$id]);
            if ($b) {
                $pid = (int)$b['seite_id'];
                if ($a === 'block_loeschen') {
                    q('DELETE FROM seiten_bloecke WHERE id = ?', [$id]);
                    flash('Baustein entfernt.');
                } elseif ($a === 'block_kopieren') {
                    q('UPDATE seiten_bloecke SET sortierung = sortierung + 1 WHERE seite_id = ? AND sortierung > ?', [$pid, $b['sortierung']]);
                    insert('seiten_bloecke', ['seite_id' => $pid, 'sortierung' => (int)$b['sortierung'] + 1, 'typ' => $b['typ'], 'daten' => $b['daten']]);
                    flash('Baustein dupliziert.');
                } else {
                    // Reihenfolge normalisieren und Nachbarn tauschen
                    $ids = array_column(all('SELECT id FROM seiten_bloecke WHERE seite_id = ? ORDER BY sortierung, id', [$pid]), 'id');
                    $pos = array_search($b['id'], $ids);
                    $swap = $pos + (post('richtung') === 'hoch' ? -1 : 1);
                    if ($pos !== false && isset($ids[$swap])) {
                        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    }
                    foreach ($ids as $i => $bid) {
                        q('UPDATE seiten_bloecke SET sortierung = ? WHERE id = ?', [$i, $bid]);
                    }
                }
                update('seiten', ['aktualisiert' => now()], $pid);
                redirect(url_admin('seiten', 'bearbeiten', ['id' => $pid]) . '#bausteine');
            }
            redirect(url_admin('seiten'));
    }
}

/* ---------- Baustein bearbeiten ---------- */
if ($a === 'block') {
    $b = one('SELECT b.*, s.titel AS seite, s.slug FROM seiten_bloecke b JOIN seiten s ON s.id = b.seite_id WHERE b.id = ?', [$id]);
    if (!$b) {
        redirect(url_admin('seiten'));
    }
    $def = block_types()[$b['typ']] ?? ['label' => $b['typ'], 'icon' => '?'];
    $title = 'Baustein bearbeiten';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('seiten')) ?>">Seiten</a> › <a href="<?= e(url_admin('seiten', 'bearbeiten', ['id' => $b['seite_id']])) ?>"><?= e($b['seite']) ?></a> › Baustein</p>
    <h1><span class="ico"><?= $def['icon'] ?></span> <?= e($def['label']) ?></h1>
    <form method="post" action="<?= e(url_admin('seiten', 'block', ['id' => $id])) ?>" class="form-grid panel">
        <?= csrf_field() ?>
        <?= block_form($b['typ'], block_data($b)) ?>
        <div class="actions wide">
            <button class="btn">Speichern &amp; zurück</button>
            <button class="btn btn-outline" name="weiter" value="1">Speichern</button>
            <a href="<?= e(url_page($b['slug'])) ?>" target="_blank">Seite ansehen ↗</a>
        </div>
    </form>
    <?php
    return;
}

/* ---------- Seite anlegen / bearbeiten ---------- */
if ($a === 'neu' || $a === 'bearbeiten') {
    $p = $id ? one('SELECT * FROM seiten WHERE id = ?', [$id]) : null;
    if ($a === 'bearbeiten' && !$p) {
        redirect(url_admin('seiten'));
    }
    $p ??= ['titel' => '', 'menue_titel' => '', 'slug' => '', 'parent_id' => (int)get('parent', 0) ?: null, 'sortierung' => 50, 'im_menue' => 1,
        'veroeffentlicht' => 0, 'ist_startseite' => 0, 'layout' => 'standard', 'farbe' => 'rot', 'hero_bild' => null, 'hero_text' => '', 'beschreibung' => ''];
    $parents = ['' => '– keine (Hauptseite) –'];
    foreach (all('SELECT id, titel FROM seiten WHERE parent_id IS NULL AND id <> ? ORDER BY sortierung, titel', [$id]) as $r) {
        $parents[$r['id']] = $r['titel'];
    }
    $title = $p['titel'] ?: 'Neue Seite';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('seiten')) ?>">Seiten</a> › <?= e($title) ?></p>
    <div class="head-row">
        <h1><?= e($title) ?></h1>
        <?php if ($id): ?><a class="btn btn-outline" href="<?= e(url_page($p['slug'])) ?>" target="_blank">Ansehen ↗</a><?php endif; ?>
    </div>

    <form method="post" action="<?= e(url_admin('seiten', 'speichern', $id ? ['id' => $id] : [])) ?>" class="panel">
        <?= csrf_field() ?>
        <details <?= $id ? '' : 'open' ?> class="settings">
            <summary>Seiteneigenschaften</summary>
            <div class="form-grid">
                <?= form_fields([
                    ['titel', 'Titel', 'text', ['required' => true]],
                    ['menue_titel', 'Kurzer Menütitel (optional)', 'text'],
                    ['slug', 'Kürzel in der Adresse', 'text', ['help' => 'Wird automatisch aus dem Titel erzeugt, z. B. „blutspende“.']],
                    ['parent_id', 'Übergeordnete Seite (Bereich)', 'select', ['options' => $parents, 'assoc' => true]],
                    ['layout', 'Layout', 'select', ['options' => $layouts]],
                    ['farbe', 'Akzentfarbe', 'select', ['options' => $farben]],
                    ['sortierung', 'Reihenfolge im Menü', 'number', ['help' => 'Kleinere Zahl = weiter vorne']],
                    ['beschreibung', 'Kurzbeschreibung (für Suchmaschinen und Kacheln)', 'text', ['wide' => true]],
                    ['hero_text', 'Text im Kopfbild', 'text', ['wide' => true, 'help' => 'Wenn Kopfbild oder Text gesetzt ist, erscheint ein großer Kopfbereich.']],
                ], $p) ?>
                <div class="field wide"><label>Kopfbild</label><?= image_picker('hero_bild', (string)($p['hero_bild'] ?? '')) ?></div>
                <div class="field wide inline-checks">
                    <?= form_field(['veroeffentlicht', 'Veröffentlicht (öffentlich sichtbar)', 'checkbox'], $p) ?>
                    <?= form_field(['im_menue', 'Im Hauptmenü anzeigen', 'checkbox'], $p) ?>
                    <?= form_field(['ist_startseite', 'Als Startseite verwenden', 'checkbox'], $p) ?>
                </div>
            </div>
        </details>
        <div class="actions"><button class="btn">Seite speichern</button></div>
    </form>

    <?php if ($id): $blocks = all('SELECT * FROM seiten_bloecke WHERE seite_id = ? ORDER BY sortierung, id', [$id]); ?>
        <h2 id="bausteine">Inhalt der Seite</h2>
        <?php if (!$blocks): ?><p class="muted">Diese Seite hat noch keinen Inhalt. Fügen Sie unten den ersten Baustein hinzu.</p><?php endif; ?>
        <ol class="block-list">
            <?php foreach ($blocks as $i => $b): $def = block_types()[$b['typ']] ?? ['label' => $b['typ'], 'icon' => '?']; ?>
                <li>
                    <span class="ico"><?= $def['icon'] ?></span>
                    <a class="block-title" href="<?= e(url_admin('seiten', 'block', ['id' => $b['id']])) ?>">
                        <strong><?= e($def['label']) ?></strong>
                        <span class="muted"><?= e(block_summary($b)) ?></span>
                    </a>
                    <span class="block-actions">
                        <?= post_button(url_admin('seiten', 'block_verschieben', ['id' => $b['id']]), '↑', ['richtung' => 'hoch'], 'Nach oben', $i === 0) ?>
                        <?= post_button(url_admin('seiten', 'block_verschieben', ['id' => $b['id']]), '↓', ['richtung' => 'runter'], 'Nach unten', $i === count($blocks) - 1) ?>
                        <?= post_button(url_admin('seiten', 'block_kopieren', ['id' => $b['id']]), '⧉', [], 'Duplizieren') ?>
                        <a class="btn btn-small" href="<?= e(url_admin('seiten', 'block', ['id' => $b['id']])) ?>">Bearbeiten</a>
                        <?= post_button(url_admin('seiten', 'block_loeschen', ['id' => $b['id']]), '✕', [], 'Entfernen', false, 'Diesen Baustein wirklich entfernen?') ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>

        <form method="post" action="<?= e(url_admin('seiten', 'block_neu', ['id' => $id])) ?>" class="add-block panel">
            <?= csrf_field() ?>
            <h3>Baustein hinzufügen</h3>
            <div class="type-grid">
                <?php foreach (block_types() as $key => $def): ?>
                    <button name="typ" value="<?= e($key) ?>" class="type-btn"><span class="ico"><?= $def['icon'] ?></span><?= e($def['label']) ?></button>
                <?php endforeach; ?>
            </div>
        </form>

        <form method="post" action="<?= e(url_admin('seiten', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Seite „<?= e($p['titel']) ?>“ mit allen Inhalten löschen?">
            <?= csrf_field() ?><button class="btn btn-danger">Seite löschen</button>
        </form>
    <?php endif;
    return;
}

/* ---------- Übersicht ---------- */
$pages = all('SELECT s.*, (SELECT COUNT(*) FROM seiten_bloecke b WHERE b.seite_id = s.id) AS bloecke FROM seiten s ORDER BY sortierung, titel');
$children = [];
foreach ($pages as $p) {
    $children[$p['parent_id'] ?? 0][] = $p;
}
?>
<div class="head-row">
    <h1>Seiten</h1>
    <a class="btn" href="<?= e(url_admin('seiten', 'neu')) ?>">+ Neue Seite</a>
</div>
<p class="muted">Hauptseiten bilden die Bereiche im Menü, Unterseiten erscheinen darunter im Aufklappmenü.</p>
<table class="list">
    <thead><tr><th>Titel</th><th>Adresse</th><th>Bausteine</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php
    $row = function (array $p, int $level) {
        ?>
        <tr class="<?= $level ? 'child' : '' ?>">
            <td><?= $level ? '<span class="indent">↳</span> ' : '' ?><a href="<?= e(url_admin('seiten', 'bearbeiten', ['id' => $p['id']])) ?>"><strong><?= e($p['titel']) ?></strong></a>
                <?= (int)$p['ist_startseite'] ? ' <span class="badge">Startseite</span>' : '' ?></td>
            <td class="muted"><?= e($p['slug']) ?></td>
            <td><?= (int)$p['bloecke'] ?></td>
            <td><?= (int)$p['veroeffentlicht'] ? '<span class="badge ok">online</span>' : '<span class="badge warn">Entwurf</span>' ?>
                <?= (int)$p['im_menue'] ? '' : ' <span class="badge">nicht im Menü</span>' ?></td>
            <td class="right">
                <?php if (!$level): ?><a href="<?= e(url_admin('seiten', 'neu', ['parent' => $p['id']])) ?>">+ Unterseite</a> · <?php endif; ?>
                <a href="<?= e(url_page($p['slug'])) ?>" target="_blank">ansehen</a>
            </td>
        </tr>
        <?php
    };
    foreach ($children[0] ?? [] as $p) {
        $row($p, 0);
        foreach ($children[$p['id']] ?? [] as $c) {
            $row($c, 1);
        }
    }
    // Unterseiten, deren Elternseite selbst Unterseite ist (zweite Ebene)
    $shown = array_merge(array_column($children[0] ?? [], 'id'));
    foreach ($pages as $p) {
        if ($p['parent_id'] && !in_array($p['parent_id'], $shown)) {
            $row($p, 1);
        }
    }
    ?>
    </tbody>
</table>
