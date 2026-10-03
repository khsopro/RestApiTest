<?php
/** Blutspendedienst: Profile der Helferinnen und Helfer */

$id = (int)get('id', 0);
$today = date('Y-m-d');
$canEdit = has_role('verwaltung');

/* ---------- Einzelnes Profil ---------- */
if ($a === 'profil' && $id) {
    $m = one("SELECT * FROM mitglieder WHERE id = ? AND status <> 'ausgetreten'", [$id]);
    if (!$m) {
        redirect(url_admin('helferprofile'));
    }
    $stats = helper_stats()[$id] ?? ['einsaetze' => 0, 'letzter' => null, 'naechster' => null];
    $einsaetze = all('SELECT t.id, t.datum, t.ort, s.aufgabe, s.von, s.bis, e.status FROM bs_einteilung e
        JOIN bs_schichten s ON s.id = e.schicht_id JOIN bs_termine t ON t.id = s.termin_id
        WHERE e.mitglied_id = ? ORDER BY t.datum DESC, s.von LIMIT 50', [$id]);
    $aufgaben = all("SELECT s.aufgabe, COUNT(*) AS n FROM bs_einteilung e JOIN bs_schichten s ON s.id = e.schicht_id
        WHERE e.mitglied_id = ? AND e.status = 'zugesagt' GROUP BY s.aufgabe ORDER BY n DESC LIMIT 5", [$id]);
    $title = member_name($m);
    ?>
    <p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › <a href="<?= e(url_admin('helferprofile')) ?>">Helfer-Profile</a> › <?= e($title) ?></p>
    <div class="head-row">
        <h1><?= e($title) ?></h1>
        <?php if ($canEdit): ?><a class="btn btn-outline" href="<?= e(url_admin('mitglieder', 'bearbeiten', ['id' => $id])) ?>">Profil bearbeiten</a><?php endif; ?>
    </div>
    <section class="panel profile-detail">
        <?= member_avatar($m, 'avatar big') ?>
        <div>
            <?php if ($m['funktion']): ?><p class="muted"><?= e($m['funktion']) ?></p><?php endif; ?>
            <ul class="contact-list">
                <?php if ($m['mobil']): ?><li>Mobil: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['mobil'])) ?>"><?= e($m['mobil']) ?></a></li><?php endif; ?>
                <?php if ($m['telefon']): ?><li>Telefon: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['telefon'])) ?>"><?= e($m['telefon']) ?></a></li><?php endif; ?>
                <?php if ($m['email']): ?><li>E-Mail: <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></li><?php endif; ?>
            </ul>
            <h3>Qualifikationen</h3>
            <?= qualification_tags((string)$m['qualifikationen']) ?: '<p class="muted">keine angegeben</p>' ?>
            <?php if ($m['verfuegbarkeit']): ?><h3>Verfügbarkeit</h3><p class="pre"><?= e($m['verfuegbarkeit']) ?></p><?php endif; ?>
            <?php if ($m['profil_text']): ?><h3>Über mich</h3><p class="pre"><?= e($m['profil_text']) ?></p><?php endif; ?>
            <?php if ($m['bereiche']): ?><h3>Bereiche</h3><p><?= e($m['bereiche']) ?></p><?php endif; ?>
        </div>
    </section>
    <div class="cards">
        <div class="card stat"><strong><?= (int)$stats['einsaetze'] ?></strong>Einsätze bisher</div>
        <div class="card stat"><strong><?= $stats['letzter'] ? e(date_de($stats['letzter'])) : '–' ?></strong>letzter Einsatz</div>
        <div class="card stat"><strong><?= $stats['naechster'] ? e(date_de($stats['naechster'])) : '–' ?></strong>nächster Einsatz</div>
    </div>
    <div class="grid-2">
        <section class="panel">
            <h2>Einsätze</h2>
            <?php if (!$einsaetze): ?><p class="muted">Noch keine Einsätze.</p><?php else: ?>
                <table class="list">
                    <?php foreach ($einsaetze as $r): ?>
                        <tr class="<?= $r['datum'] >= $today ? '' : 'past' ?>">
                            <td class="nowrap"><a href="<?= e(url_admin('blutspende', 'termin', ['id' => $r['id'], 'tab' => 'personal'])) ?>"><?= e(date_de($r['datum'])) ?></a></td>
                            <td><?= e($r['aufgabe']) ?><br><small class="muted"><?= e($r['ort']) ?>, <?= e($r['von']) ?>–<?= e($r['bis']) ?></small></td>
                            <td><span class="badge <?= $r['status'] === 'zugesagt' ? 'ok' : ($r['status'] === 'abgesagt' ? 'bad' : 'warn') ?>"><?= e($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2>Häufigste Aufgaben</h2>
            <?php if (!$aufgaben): ?><p class="muted">Noch keine Daten.</p><?php endif; ?>
            <ul class="plain">
                <?php foreach ($aufgaben as $r): ?><li><?= e($r['aufgabe']) ?> <span class="muted">(<?= (int)$r['n'] ?>×)</span></li><?php endforeach; ?>
            </ul>
        </section>
    </div>
    <?php
    return;
}

/* ---------- Filter ---------- */
$search = trim((string)get('q', ''));
$quali = (string)get('quali', '');
$alle = (bool)get('alle');
$where = ["m.status NOT IN ('ausgetreten', 'foerdernd')"];
$params = [];
if (!$alle) {
    // Blutspende-Team: Bereich „Blutspende“ oder schon einmal eingeteilt
    $where[] = '(m.bereiche LIKE ? OR EXISTS (SELECT 1 FROM bs_einteilung e WHERE e.mitglied_id = m.id))';
    $params[] = '%Blutspende%';
}
if ($search !== '') {
    $where[] = '(m.vorname LIKE ? OR m.nachname LIKE ? OR m.verfuegbarkeit LIKE ? OR m.profil_text LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
if ($quali !== '') {
    $where[] = 'm.qualifikationen LIKE ?';
    $params[] = '%' . $quali . '%';
}
$rows = all('SELECT m.* FROM mitglieder m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.nachname, m.vorname', $params);
$stats = helper_stats();

/* ---------- Druck: Telefonliste ---------- */
if ($a === 'telefonliste') {
    $printView = true;
    $title = 'Telefonliste Blutspende-Team';
    echo '<div class="print-page"><h1>Telefonliste Blutspende-Team</h1><p>Stand: ' . e(date_de($today)) . ' · vertraulich, nur für den internen Gebrauch</p>'
        . '<table class="print-table list"><thead><tr><th>Name</th><th>Mobil</th><th>Telefon</th><th>Qualifikationen</th></tr></thead><tbody>';
    foreach ($rows as $m) {
        echo '<tr><td>' . e($m['nachname'] . ', ' . $m['vorname']) . '</td><td>' . e($m['mobil']) . '</td><td>' . e($m['telefon']) . '</td><td class="small">' . e($m['qualifikationen']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    audit('Telefonliste Blutspende gedruckt', count($rows) . ' Personen');
    return;
}

$query = array_filter(['q' => $search, 'quali' => $quali, 'alle' => $alle ? 1 : null]);
?>
<p class="crumbs"><a href="<?= e(url_admin('blutspende')) ?>">Blutspende</a> › Helfer-Profile</p>
<div class="head-row">
    <h1>Helfer-Profile <small class="muted">(<?= count($rows) ?>)</small></h1>
    <a class="btn btn-outline" href="<?= e(url_admin('helferprofile', 'telefonliste', $query)) ?>" target="_blank">Telefonliste drucken</a>
</div>
<nav class="tabs">
    <a href="<?= e(url_admin('helferprofile', '', array_diff_key($query, ['alle' => 1]))) ?>" class="<?= $alle ? '' : 'active' ?>">Blutspende-Team</a>
    <a href="<?= e(url_admin('helferprofile', '', $query + ['alle' => 1])) ?>" class="<?= $alle ? 'active' : '' ?>">Alle aktiven Mitglieder</a>
</nav>
<form class="filters" method="get">
    <input type="hidden" name="m" value="helferprofile">
    <?php if ($alle): ?><input type="hidden" name="alle" value="1"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, Verfügbarkeit, Interessen …">
    <select name="quali"><option value="">Alle Qualifikationen</option>
        <?php foreach (qualification_options() as $o): ?><option<?= $o === $quali ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline">Filtern</button>
    <?php if ($search !== '' || $quali !== ''): ?><a href="<?= e(url_admin('helferprofile', '', $alle ? ['alle' => 1] : [])) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php if (!$rows): ?>
    <p class="muted">Keine Profile gefunden. Helfer/innen erscheinen hier, wenn bei ihnen der Bereich „Blutspende“ eingetragen ist oder sie schon einmal eingeteilt wurden.</p>
<?php endif; ?>
<div class="profile-grid">
    <?php foreach ($rows as $m): $s = $stats[(int)$m['id']] ?? null; ?>
        <article class="profile-card">
            <div class="profile-head">
                <?= member_avatar($m) ?>
                <div>
                    <h3><a href="<?= e(url_admin('helferprofile', 'profil', ['id' => $m['id']])) ?>"><?= e(member_name($m)) ?></a></h3>
                    <small class="muted"><?= e($m['mobil'] ?: $m['telefon']) ?></small>
                </div>
            </div>
            <?= qualification_tags((string)$m['qualifikationen'], $quali) ?>
            <?php if ($m['verfuegbarkeit']): ?><p class="small"><strong>Verfügbar:</strong> <?= e(mb_strimwidth((string)$m['verfuegbarkeit'], 0, 120, '…')) ?></p><?php endif; ?>
            <div class="profile-stats">
                <span><strong><?= (int)($s['einsaetze'] ?? 0) ?></strong> Einsätze</span>
                <span>nächster: <strong><?= !empty($s['naechster']) ? e(date_de($s['naechster'])) : '–' ?></strong></span>
            </div>
        </article>
    <?php endforeach; ?>
</div>
