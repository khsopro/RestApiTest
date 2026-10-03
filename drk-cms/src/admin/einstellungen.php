<?php
/** Allgemeine Einstellungen (nur Admin) */

$fields = [
    ['seitentitel', 'Name des Ortsvereins (Seitentitel)', 'text', ['required' => true]],
    ['untertitel', 'Untertitel / Leitspruch', 'text'],
    ['notruf_hinweis', 'Hinweis in der obersten Leiste', 'text'],
    ['fusszeile', 'Text in der Fußzeile', 'text'],
    ['kontakt_text', 'Kontaktblock in der Fußzeile', 'textarea', ['rows' => 6, 'wide' => true, 'help' => 'Formatierung wie in Textbausteinen möglich.']],
    ['bereiche', 'Bereiche / Gemeinschaften (eine pro Zeile)', 'textarea', ['rows' => 7]],
    ['qualifikationen', 'Qualifikationen (eine pro Zeile)', 'textarea', ['rows' => 7]],
    ['rezept_kategorien', 'Rezept-Kategorien (eine pro Zeile)', 'textarea', ['rows' => 6]],
    ['einkauf_abteilungen', 'Abteilungen der Einkaufsliste (eine pro Zeile)', 'textarea', ['rows' => 6]],
    ['bs_standard_schichten', 'Standard-Schichten Blutspende', 'textarea', ['rows' => 8, 'wide' => true,
        'help' => 'Nur noch Rückfall: Sobald eine Stellenbeschreibung als Standard-Schicht markiert ist (Blutspende › Stellenbeschreibungen), werden diese verwendet. Format: Aufgabe | von | bis | Anzahl | Qualifikation.']],
];

if (is_post()) {
    foreach (form_collect($fields) as $k => $v) {
        set_setting($k, str_replace("\r\n", "\n", (string)$v));
    }
    set_setting('logo', (string)((int)post('logo') ?: ''));
    audit('Einstellungen geändert');
    flash('Einstellungen gespeichert.');
    redirect(url_admin('einstellungen'));
}

$values = [];
foreach ($fields as $f) {
    $values[$f[0]] = setting($f[0]);
}
?>
<h1>Einstellungen</h1>
<form method="post" class="panel">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= form_fields($fields, $values) ?>
        <div class="field wide"><label>Logo (ersetzt Kreuz und Vereinsnamen im Kopf der Website)</label><?= image_picker('logo', setting('logo')) ?>
            <small>Bitte nur das offizielle DRK-Logo Ihres Ortsvereins verwenden (Vorlagen über den Kreis-/Landesverband bzw. das DRK-Markenportal).</small></div>
    </div>
    <div class="actions"><button class="btn">Speichern</button></div>
</form>

<section class="panel">
    <h2>System</h2>
    <dl class="dl">
        <dt>Version</dt><dd><?= CMS_VERSION ?></dd>
        <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
        <dt>Datenbank</dt><dd><?= e(db_driver()) ?></dd>
        <dt>Uploads beschreibbar</dt><dd><?= is_writable(CMS_ROOT . '/uploads') ? 'ja' : '<strong class="warn-text">nein – Schreibrechte setzen!</strong>' ?></dd>
        <dt>config.php</dt><dd><?= file_exists(CMS_ROOT . '/config.php') ? 'vorhanden' : 'nicht vorhanden (Standardwerte aktiv)' ?></dd>
        <dt>HTTPS</dt><dd><?= !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'aktiv' : '<strong class="warn-text">nicht aktiv – für den Login unbedingt HTTPS einrichten!</strong>' ?></dd>
    </dl>
</section>
