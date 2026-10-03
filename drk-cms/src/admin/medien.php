<?php
/** Bilder & Dateien */

$dir = CMS_ROOT . '/uploads';
$id = (int)get('id', 0);

if (is_post()) {
    if ($a === 'hochladen') {
        $files = $_FILES['dateien'] ?? null;
        $ok = 0;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $orig) {
                $result = store_upload([
                    'name' => $orig, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i],
                ], (string)post('alt_text'));
                if (is_string($result)) {
                    flash($result, 'error');
                } else {
                    $ok++;
                }
            }
        }
        if ($ok) {
            flash($ok . ' Datei(en) hochgeladen.');
        }
        redirect(url_admin('medien'));
    }
    if ($a === 'speichern' && $id) {
        update('medien', ['alt_text' => (string)post('alt_text')], $id);
        flash('Beschreibung gespeichert.');
        redirect(url_admin('medien'));
    }
    if ($a === 'loeschen' && $id) {
        $f = one('SELECT * FROM medien WHERE id = ?', [$id]);
        if ($f) {
            q('DELETE FROM medien WHERE id = ?', [$id]);
            q('UPDATE seiten SET hero_bild = NULL WHERE hero_bild = ?', [$id]);
            q('UPDATE unterstuetzer SET logo = NULL WHERE logo = ?', [$id]);
            q('UPDATE mitglieder SET foto = NULL WHERE foto = ?', [$id]);
            $path = $dir . '/' . basename($f['dateiname']);
            if (is_file($path)) {
                unlink($path);
            }
            flash('Datei gelöscht.');
        }
        redirect(url_admin('medien'));
    }
}

$media = all('SELECT * FROM medien ORDER BY id DESC');
?>
<div class="head-row"><h1>Bilder &amp; Dateien</h1></div>

<form method="post" enctype="multipart/form-data" action="<?= e(url_admin('medien', 'hochladen')) ?>" class="panel upload">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field wide">
            <label>Dateien auswählen (JPG, PNG, GIF, WebP, PDF – max. <?= (int)cfg('upload_max_mb', 8) ?> MB)</label>
            <input type="file" name="dateien[]" multiple accept="image/*,application/pdf" required>
        </div>
        <div class="field wide">
            <label>Bildbeschreibung (Alternativtext für Barrierefreiheit, optional)</label>
            <input type="text" name="alt_text" placeholder="z. B. Helferinnen beim Blutspendetermin im Gemeindehaus">
        </div>
    </div>
    <p class="muted small">Bitte nur Fotos hochladen, für die eine Einwilligung der abgebildeten Personen vorliegt.</p>
    <button class="btn">Hochladen</button>
</form>

<div class="media-grid">
    <?php foreach ($media as $f): $url = 'uploads/' . rawurlencode($f['dateiname']); ?>
        <div class="media-item">
            <a href="<?= e($url) ?>" target="_blank" class="thumb">
                <?php if (str_starts_with((string)$f['mime'], 'image/')): ?>
                    <img src="<?= e($url) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="file">PDF</span>
                <?php endif; ?>
            </a>
            <div class="meta">
                <small title="<?= e($f['original']) ?>"><?= e(mb_strimwidth((string)$f['original'], 0, 32, '…')) ?> · <?= num_de(($f['groesse'] ?? 0) / 1024, 0) ?> KB</small>
                <form method="post" action="<?= e(url_admin('medien', 'speichern', ['id' => $f['id']])) ?>" class="alt-form">
                    <?= csrf_field() ?>
                    <input type="text" name="alt_text" value="<?= e($f['alt_text']) ?>" placeholder="Beschreibung">
                    <button class="btn btn-small btn-ghost" title="Speichern">✓</button>
                </form>
                <div class="row-between">
                    <input type="text" readonly value="<?= e($url) ?>" class="copy" title="Link zum Kopieren (z. B. für PDFs in Texten)">
                    <?= post_button(url_admin('medien', 'loeschen', ['id' => $f['id']]), 'Löschen', [], '', false, 'Datei endgültig löschen? Sie verschwindet auch von allen Seiten.', 'btn btn-small btn-danger') ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$media): ?><p class="muted">Noch keine Dateien vorhanden.</p><?php endif; ?>
</div>
