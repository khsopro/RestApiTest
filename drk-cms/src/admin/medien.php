<?php
/** Bilder & Dateien */

$allowed = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
$dir = CMS_ROOT . '/uploads';
$id = (int)get('id', 0);

if (is_post()) {
    if ($a === 'hochladen') {
        $files = $_FILES['dateien'] ?? null;
        $ok = 0;
        $max = (int)cfg('upload_max_mb', 8) * 1024 * 1024;
        if ($files && is_array($files['name'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($files['name'] as $i => $orig) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    flash('Fehler beim Hochladen von „' . $orig . '“.', 'error');
                    continue;
                }
                $mime = $finfo->file($files['tmp_name'][$i]) ?: '';
                if (!isset($allowed[$mime])) {
                    flash('„' . $orig . '“: Dateityp nicht erlaubt (erlaubt: JPG, PNG, GIF, WebP, PDF).', 'error');
                    continue;
                }
                if ($files['size'][$i] > $max) {
                    flash('„' . $orig . '“ ist zu groß (max. ' . cfg('upload_max_mb', 8) . ' MB).', 'error');
                    continue;
                }
                $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
                    flash('„' . $orig . '“ konnte nicht gespeichert werden. Schreibrechte für den Ordner uploads/ prüfen.', 'error');
                    continue;
                }
                shrink_image($dir . '/' . $name, $mime);
                $alt = pathinfo((string)$orig, PATHINFO_FILENAME);
                insert('medien', ['dateiname' => $name, 'original' => mb_substr((string)$orig, 0, 250), 'mime' => $mime,
                    'groesse' => filesize($dir . '/' . $name), 'alt_text' => post('alt_text') ?: $alt, 'erstellt' => now()]);
                $ok++;
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
            $path = $dir . '/' . basename($f['dateiname']);
            if (is_file($path)) {
                unlink($path);
            }
            flash('Datei gelöscht.');
        }
        redirect(url_admin('medien'));
    }
}

/** Sehr große Fotos (z. B. vom Handy) auf max. 2000 px verkleinern, falls GD vorhanden ist. */
function shrink_image(string $path, string $mime, int $maxSide = 2000): void
{
    if (!function_exists('imagecreatetruecolor') || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return;
    }
    $size = @getimagesize($path);
    if (!$size || max($size[0], $size[1]) <= $maxSide) {
        return;
    }
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
    };
    if (!$src) {
        return;
    }
    $ratio = $maxSide / max($size[0], $size[1]);
    $w = (int)round($size[0] * $ratio);
    $h = (int)round($size[1] * $ratio);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $size[0], $size[1]);
    match ($mime) {
        'image/jpeg' => imagejpeg($dst, $path, 85),
        'image/png'  => imagepng($dst, $path, 8),
        'image/webp' => imagewebp($dst, $path, 85),
    };
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
