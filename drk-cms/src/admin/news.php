<?php
/** Aktuelles: Meldungen schreiben, planen und veröffentlichen */

$fields = [
    ['titel', 'Überschrift', 'text', ['required' => true, 'wide' => true]],
    ['datum', 'Datum (ab diesem Tag sichtbar)', 'date', ['required' => true]],
    ['kategorie', 'Bereich', 'select', ['options' => array_merge([''], area_options())]],
    ['autor', 'Verfasst von', 'text'],
    ['slug', 'Kürzel in der Adresse', 'text', ['help' => 'Wird automatisch aus der Überschrift erzeugt.']],
    ['teaser', 'Kurzfassung für die Übersicht (optional)', 'textarea', ['rows' => 2, 'wide' => true, 'help' => 'Leer lassen = Anfang des Textes wird verwendet.']],
    ['inhalt', 'Text', 'textarea', ['rows' => 14, 'wide' => true,
        'help' => 'Formatierung: ## Zwischenüberschrift, **fett**, *kursiv*, - Liste, [Linktext](https://…), Leerzeile = neuer Absatz']],
];
$id = (int)get('id', 0);

if (is_post()) {
    if ($a === 'speichern') {
        $data = form_collect($fields);
        $data['bild'] = (int)post('bild') ?: null;
        $data['angeheftet'] = isset($_POST['angeheftet']) ? 1 : 0;
        $data['veroeffentlicht'] = isset($_POST['veroeffentlicht']) ? 1 : 0;
        $data['inhalt'] = str_replace("\r\n", "\n", (string)$data['inhalt']);
        if ($data['titel'] === '' || !$data['datum']) {
            flash('Überschrift und Datum sind Pflichtfelder.', 'error');
            redirect(url_admin('news', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
        }
        $data['slug'] = slugify($data['slug'] ?: $data['titel']);
        $base = $data['slug'];
        $n = 2;
        while (val('SELECT id FROM news WHERE slug = ? AND id <> ?', [$data['slug'], $id])) {
            $data['slug'] = $base . '-' . $n++;
        }
        $data['aktualisiert'] = now();
        if ($id) {
            update('news', $data, $id);
        } else {
            $data['erstellt'] = now();
            $id = insert('news', $data);
        }
        audit('Meldung gespeichert', $data['titel']);
        flash('Meldung gespeichert.');
        redirect(url_admin('news', 'bearbeiten', ['id' => $id]));
    }
    if ($a === 'loeschen' && $id) {
        $titel = (string)val('SELECT titel FROM news WHERE id = ?', [$id]);
        q('DELETE FROM news WHERE id = ?', [$id]);
        audit('Meldung gelöscht', $titel);
        flash('Meldung gelöscht.');
        redirect(url_admin('news'));
    }
}

if ($a === 'neu' || $a === 'bearbeiten') {
    $u = current_user();
    $row = $id ? one('SELECT * FROM news WHERE id = ?', [$id])
        : ['datum' => date('Y-m-d'), 'veroeffentlicht' => 1, 'autor' => $u['name'] ?: ''];
    if (!$row) {
        redirect(url_admin('news'));
    }
    $title = $id ? $row['titel'] : 'Neue Meldung';
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('news')) ?>">Aktuelles</a> › <?= e($title) ?></p>
    <div class="head-row">
        <h1><?= e($title) ?></h1>
        <?php if ($id): ?><a class="btn btn-outline" href="<?= e(url_news($row['slug'])) ?>" target="_blank">Ansehen ↗</a><?php endif; ?>
    </div>
    <form method="post" action="<?= e(url_admin('news', 'speichern', $id ? ['id' => $id] : [])) ?>">
        <?= csrf_field() ?>
        <section class="panel">
            <div class="form-grid">
                <?= form_fields($fields, $row) ?>
                <div class="field wide"><label>Bild</label><?= image_picker('bild', (string)($row['bild'] ?? '')) ?></div>
                <div class="field wide inline-checks">
                    <?= form_field(['veroeffentlicht', 'Veröffentlicht', 'checkbox'], $row) ?>
                    <?= form_field(['angeheftet', 'Oben anheften (wichtige Meldung)', 'checkbox'], $row) ?>
                </div>
            </div>
        </section>
        <div class="actions sticky-actions"><button class="btn">Speichern</button> <a href="<?= e(url_admin('news')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url_admin('news', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Meldung „<?= e($row['titel']) ?>“ löschen?">
            <?= csrf_field() ?><button class="btn btn-danger">Meldung löschen</button>
        </form>
    <?php endif;
    return;
}

$search = trim((string)get('q', ''));
$rows = $search === ''
    ? all('SELECT * FROM news ORDER BY datum DESC, id DESC')
    : all('SELECT * FROM news WHERE titel LIKE ? OR inhalt LIKE ? ORDER BY datum DESC, id DESC', ['%' . $search . '%', '%' . $search . '%']);
$today = date('Y-m-d');
?>
<div class="head-row">
    <h1>Aktuelles <small class="muted">(<?= count($rows) ?>)</small></h1>
    <a class="btn" href="<?= e(url_admin('news', 'neu')) ?>">+ Neue Meldung</a>
</div>
<?php if (!news_archive_slug()): ?>
    <div class="notice-box">Tipp: Legen Sie eine Seite „Aktuelles“ an und fügen Sie den Baustein <strong>Aktuelles / News</strong> mit „Blättern erlauben: Ja“ hinzu.
        Dann verlinken die Übersichten auf der Startseite automatisch auf alle Meldungen.</div>
<?php endif; ?>
<form class="filters" method="get">
    <input type="hidden" name="m" value="news">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Überschrift oder Text …">
    <button class="btn btn-outline">Suchen</button>
</form>
<table class="list">
    <thead><tr><th>Datum</th><th>Überschrift</th><th>Bereich</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="nowrap"><?= e(date_de($r['datum'])) ?></td>
            <td><a href="<?= e(url_admin('news', 'bearbeiten', ['id' => $r['id']])) ?>"><strong><?= e($r['titel']) ?></strong></a>
                <?= (int)$r['angeheftet'] ? ' <span class="badge">angeheftet</span>' : '' ?>
                <br><small class="muted"><?= e(mb_strimwidth(news_teaser($r), 0, 110, '…')) ?></small></td>
            <td><?= e($r['kategorie']) ?></td>
            <td><?php if (!(int)$r['veroeffentlicht']): ?><span class="badge warn">Entwurf</span>
                <?php elseif ($r['datum'] > $today): ?><span class="badge">geplant</span>
                <?php else: ?><span class="badge ok">online</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Noch keine Meldungen.</td></tr><?php endif; ?>
    </tbody>
</table>
