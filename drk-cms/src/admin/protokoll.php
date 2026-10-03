<?php
/** Änderungsprotokoll (Nachweis, wer wann personenbezogene Daten geändert hat) */
$rows = all('SELECT * FROM protokoll ORDER BY id DESC LIMIT 300');
?>
<h1>Protokoll</h1>
<p class="muted">Die letzten 300 Änderungen. Hilft bei der Rechenschaftspflicht nach DSGVO (wer hat wann Daten geändert oder exportiert).</p>
<table class="list">
    <thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Objekt</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td class="nowrap"><?= e(datetime_de($r['zeit'])) ?></td><td><?= e($r['benutzer']) ?></td><td><?= e($r['aktion']) ?></td><td><?= e($r['objekt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
