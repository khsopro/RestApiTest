<?php
/** Öffentlichkeitsarbeit: Redaktionsplan für Facebook/Instagram, Werbekampagnen, Auswertung */

$id = (int)get('id', 0);
$tab = (string)get('tab', 'plan');
$today = date('Y-m-d');
$channels = sm_channels();
$states = sm_status();
$back = fn(string $t = 'plan', array $p = []) => url_admin('social', '', ['tab' => $t] + $p);

$postFields = [
    ['titel', 'Interner Titel', 'text', ['required' => true, 'wide' => true, 'help' => 'Nur für euch, z. B. „Blutspende Nov – Erinnerung“']],
    ['datum', 'Geplant am', 'date'],
    ['uhrzeit', 'Uhrzeit', 'time', ['step' => '900']],
    ['kanal', 'Kanal', 'select', ['options' => $channels, 'assoc' => true]],
    ['status', 'Status', 'select', ['options' => $states, 'assoc' => true]],
    ['text', 'Text', 'textarea', ['rows' => 9, 'wide' => true]],
    ['hashtags', 'Hashtags', 'text', ['wide' => true]],
    ['link', 'Link (z. B. zur Terminseite)', 'text', ['wide' => true, 'help' => 'Auf Facebook klickbar. Bei Instagram wird automatisch „Link in der Bio“ ergänzt.']],
    ['notizen', 'Interne Notizen', 'textarea', ['rows' => 2, 'wide' => true]],
];
$campaignFields = [
    ['name', 'Name der Kampagne', 'text', ['required' => true, 'wide' => true]],
    ['kanal', 'Kanal', 'select', ['options' => $channels, 'assoc' => true]],
    ['ziel', 'Ziel', 'text', ['help' => 'z. B. „mehr Erstspender/innen“, „Helfer/innen gewinnen“']],
    ['start', 'Start', 'date'],
    ['ende', 'Ende', 'date'],
    ['budget', 'Budget (€)', 'number', ['step' => '0.01']],
    ['kosten', 'Tatsächliche Kosten (€)', 'number', ['step' => '0.01', 'help' => 'nach Ende aus dem Werbeanzeigenmanager übernehmen']],
    ['zielgruppe', 'Zielgruppe / Einstellungen', 'textarea', ['rows' => 3, 'wide' => true, 'help' => 'z. B. Umkreis 15 km, 18–65 Jahre, Interessen …']],
    ['reichweite', 'Reichweite', 'number'],
    ['impressionen', 'Impressionen', 'number'],
    ['klicks', 'Link-Klicks', 'number'],
    ['notizen', 'Notizen / Erkenntnisse', 'textarea', ['rows' => 3, 'wide' => true]],
];

/* ---------- Aktionen ---------- */
if (is_post()) {
    $u = current_user();
    switch ($a) {
        case 'speichern':
            $data = form_collect($postFields);
            if ($data['titel'] === '') {
                flash('Bitte einen Titel angeben.', 'error');
                redirect(url_admin('social', $id ? 'bearbeiten' : 'neu', $id ? ['id' => $id] : []));
            }
            $data['kanal'] = isset($channels[$data['kanal']]) ? $data['kanal'] : 'beide';
            $data['status'] = isset($states[$data['status']]) ? $data['status'] : 'entwurf';
            $data['text'] = str_replace("\r\n", "\n", (string)$data['text']);
            $data['link'] = preg_match('~^https?://~i', (string)$data['link']) ? $data['link'] : '';
            foreach (['bild', 'termin_id', 'kampagne_id'] as $k) {
                $data[$k] = (int)post($k) ?: null;
            }
            $data['aktualisiert'] = now();
            if ($data['status'] === 'veroeffentlicht') {
                $data['veroeffentlicht_am'] = val('SELECT veroeffentlicht_am FROM sm_beitraege WHERE id = ?', [$id]) ?: now();
            }
            if ($id) {
                update('sm_beitraege', $data, $id);
            } else {
                $data += ['erstellt' => now(), 'erstellt_von' => $u['benutzername']];
                $id = insert('sm_beitraege', $data);
            }
            flash('Beitrag gespeichert.');
            redirect(url_admin('social', 'bearbeiten', ['id' => $id]));

        case 'veroeffentlicht':
            update('sm_beitraege', ['status' => 'veroeffentlicht', 'veroeffentlicht_am' => now(), 'aktualisiert' => now()], $id);
            flash('Als veröffentlicht markiert.');
            redirect(post('zurueck') === 'liste' ? $back() : url_admin('social', 'bearbeiten', ['id' => $id]));

        case 'kopieren':
            $row = one('SELECT * FROM sm_beitraege WHERE id = ?', [$id]);
            if ($row) {
                unset($row['id']);
                $row = ['titel' => $row['titel'] . ' (Kopie)', 'status' => 'entwurf', 'veroeffentlicht_am' => null, 'datum' => null,
                    'erstellt' => now(), 'aktualisiert' => now(), 'erstellt_von' => $u['benutzername']] + $row;
                $id = insert('sm_beitraege', $row);
                flash('Kopie angelegt.');
            }
            redirect(url_admin('social', 'bearbeiten', ['id' => $id]));

        case 'loeschen':
            q('DELETE FROM sm_beitraege WHERE id = ?', [$id]);
            flash('Beitrag gelöscht.');
            redirect($back());

        case 'vorlage_termin':
            $t = one('SELECT * FROM bs_termine WHERE id = ?', [(int)post('termin_id')]);
            if (!$t) {
                redirect($back());
            }
            $values = sm_termin_values($t);
            $n = 0;
            foreach (sm_termin_templates() as [$daysBefore, $label, $text]) {
                $date = date('Y-m-d', strtotime($t['datum'] . " -$daysBefore days"));
                if ($date < $today) {
                    continue; // Zeitpunkt schon vorbei
                }
                insert('sm_beitraege', [
                    'titel' => 'Blutspende ' . date_de($t['datum']) . ' – ' . $label, 'datum' => $date, 'uhrzeit' => '18:00',
                    'kanal' => 'beide', 'status' => 'entwurf', 'text' => sm_fill($text, $values),
                    'hashtags' => setting('sm_hashtags', '#Blutspende #DRK #Lebensretter'), 'link' => $values['link'],
                    'bild' => (int)setting('og_bild') ?: null, 'termin_id' => $t['id'],
                    'erstellt' => now(), 'aktualisiert' => now(), 'erstellt_von' => $u['benutzername'],
                ]);
                $n++;
            }
            flash($n ? "$n Beitragsentwürfe für den Termin am " . date_de($t['datum']) . ' angelegt. Bitte Texte prüfen und Bild auswählen.'
                : 'Alle Vorlagen-Zeitpunkte für diesen Termin liegen schon in der Vergangenheit.', $n ? 'ok' : 'error');
            redirect($back());

        case 'vorlage_news':
            $n = one('SELECT * FROM news WHERE id = ?', [(int)post('news_id')]);
            if ($n) {
                $id = insert('sm_beitraege', [
                    'titel' => 'Meldung: ' . mb_strimwidth($n['titel'], 0, 150, '…'), 'datum' => max($today, $n['datum']), 'uhrzeit' => '18:00',
                    'kanal' => 'beide', 'status' => 'entwurf', 'text' => $n['titel'] . "\n\n" . news_teaser($n, 400),
                    'hashtags' => setting('sm_hashtags', '#DRK'), 'link' => abs_url(url_news($n['slug'])), 'bild' => $n['bild'] ?: null,
                    'news_id' => $n['id'], 'erstellt' => now(), 'aktualisiert' => now(), 'erstellt_von' => $u['benutzername'],
                ]);
                flash('Beitragsentwurf aus der Meldung angelegt.');
                redirect(url_admin('social', 'bearbeiten', ['id' => $id]));
            }
            redirect($back());

        case 'kampagne_speichern':
            $data = form_collect($campaignFields);
            if ($data['name'] === '') {
                flash('Bitte einen Namen angeben.', 'error');
                redirect(url_admin('social', 'kampagne', $id ? ['id' => $id] : []));
            }
            $data['kanal'] = isset($channels[$data['kanal']]) ? $data['kanal'] : 'beide';
            $data['termin_id'] = (int)post('termin_id') ?: null;
            foreach (['reichweite', 'impressionen', 'klicks'] as $k) {
                $data[$k] = $data[$k] === null ? null : (int)$data[$k];
            }
            $data['aktualisiert'] = now();
            if ($id) {
                update('sm_kampagnen', $data, $id);
            } else {
                $id = insert('sm_kampagnen', $data + ['erstellt' => now()]);
            }
            audit('Kampagne gespeichert', $data['name']);
            flash('Kampagne gespeichert.');
            redirect($back('kampagnen'));

        case 'kampagne_loeschen':
            q('UPDATE sm_beitraege SET kampagne_id = NULL WHERE kampagne_id = ?', [$id]);
            q('DELETE FROM sm_kampagnen WHERE id = ?', [$id]);
            flash('Kampagne gelöscht.');
            redirect($back('kampagnen'));
    }
}

$termOptions = function (bool $futureOnly = false) use ($today): array {
    $opts = ['' => '– kein Termin –'];
    foreach (all('SELECT id, datum, ort FROM bs_termine' . ($futureOnly ? ' WHERE datum >= ?' : ' WHERE datum >= ?') . ' ORDER BY datum', [$futureOnly ? $today : date('Y-m-d', strtotime('-1 year'))]) as $t) {
        $opts[$t['id']] = date_de($t['datum']) . ' – ' . $t['ort'];
    }
    return $opts;
};

/* ---------- Beitrag anlegen / bearbeiten ---------- */
if ($a === 'neu' || $a === 'bearbeiten') {
    $row = $id ? one('SELECT * FROM sm_beitraege WHERE id = ?', [$id])
        : ['kanal' => 'beide', 'status' => 'entwurf', 'datum' => get('datum', $today), 'uhrzeit' => '18:00', 'hashtags' => setting('sm_hashtags'),
            'titel' => '', 'text' => '', 'link' => '', 'bild' => null, 'veroeffentlicht_am' => null];
    if (!$row) {
        redirect($back());
    }
    $campaigns = ['' => '– keine –'] + array_column(all('SELECT id, name FROM sm_kampagnen ORDER BY start DESC, id DESC'), 'name', 'id');
    $title = $id ? $row['titel'] : 'Neuer Beitrag';
    $imgUrl = media_url($row['bild'] ? (int)$row['bild'] : null);
    ?>
    <p class="crumbs"><a href="<?= e($back()) ?>">Social Media</a> › <?= e($title) ?></p>
    <div class="head-row">
        <h1><?= e($title) ?> <?php if ($id): ?><span class="badge st-<?= e($row['status']) ?>"><?= e($states[$row['status']] ?? $row['status']) ?></span><?php endif; ?></h1>
        <?php if ($id): ?>
            <div class="btn-group">
                <?php if ($row['status'] !== 'veroeffentlicht'): ?><?= post_button(url_admin('social', 'veroeffentlicht', ['id' => $id]), 'Als veröffentlicht markieren', [], '', false, '', 'btn') ?><?php endif; ?>
                <?= post_button(url_admin('social', 'kopieren', ['id' => $id]), 'Kopieren', [], 'Als Vorlage für einen neuen Beitrag', false, '', 'btn btn-outline') ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="grid-2 sm-layout">
        <form method="post" action="<?= e(url_admin('social', 'speichern', $id ? ['id' => $id] : [])) ?>" class="panel">
            <?= csrf_field() ?>
            <div class="form-grid">
                <?= form_fields($postFields, $row) ?>
                <?= form_field(['termin_id', 'Gehört zu Blutspendetermin', 'select', ['options' => $termOptions(), 'assoc' => true]], $row) ?>
                <?= form_field(['kampagne_id', 'Gehört zu Werbekampagne', 'select', ['options' => $campaigns, 'assoc' => true]], $row) ?>
                <div class="field wide"><label>Bild</label><?= image_picker('bild', (string)($row['bild'] ?? '')) ?></div>
            </div>
            <div class="actions"><button class="btn">Speichern</button> <a href="<?= e($back()) ?>">Zurück zum Plan</a></div>
        </form>
        <section class="panel sm-preview">
            <h2>Zum Veröffentlichen</h2>
            <p class="muted small">Text kopieren und in Facebook, Instagram oder der Meta Business Suite einfügen. Dort kann man den Beitrag auch zeitgesteuert planen.</p>
            <?php foreach (($row['kanal'] === 'beide' ? ['facebook', 'instagram'] : [$row['kanal']]) as $ch): $txt = sm_compose($row, $ch); ?>
                <div class="copy-box">
                    <div class="copy-head"><strong><?= social_icon($ch) ?> <?= $ch === 'facebook' ? 'Facebook' : 'Instagram' ?></strong>
                        <button type="button" class="btn btn-small" data-copy="#copy-<?= $ch ?>">Text kopieren</button></div>
                    <textarea id="copy-<?= $ch ?>" rows="9" readonly><?= e($txt) ?></textarea>
                    <small class="muted"><?= mb_strlen($txt) ?> Zeichen<?php if ($ch === 'instagram'): $tags = preg_match_all('/#\w+/u', $txt); ?> · <?= $tags ?> Hashtags<?= mb_strlen($txt) > 2200 ? ' · <span class="warn-text">zu lang (max. 2.200)</span>' : '' ?><?= $tags > 30 ? ' · <span class="warn-text">max. 30 Hashtags</span>' : '' ?><?php endif; ?></small>
                </div>
            <?php endforeach; ?>
            <?php if ($imgUrl): ?>
                <div class="sm-image"><img src="<?= e($imgUrl) ?>" alt=""><a class="btn btn-small btn-outline" href="<?= e($imgUrl) ?>" download>Bild herunterladen</a></div>
            <?php else: ?>
                <p class="muted small">Kein Bild ausgewählt. Beiträge mit Bild erreichen deutlich mehr Menschen – für Instagram ist ein Bild Pflicht.</p>
            <?php endif; ?>
            <?php if ($row['veroeffentlicht_am']): ?><p class="small ok-text">Veröffentlicht am <?= e(datetime_de($row['veroeffentlicht_am'])) ?></p><?php endif; ?>
        </section>
    </div>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url_admin('social', 'loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Beitrag löschen?">
            <?= csrf_field() ?><button class="btn btn-danger">Beitrag löschen</button>
        </form>
    <?php endif;
    return;
}

/* ---------- Kampagne anlegen / bearbeiten ---------- */
if ($a === 'kampagne') {
    $row = $id ? one('SELECT * FROM sm_kampagnen WHERE id = ?', [$id]) : ['kanal' => 'beide', 'start' => $today];
    if (!$row) {
        redirect($back('kampagnen'));
    }
    $title = $id ? $row['name'] : 'Neue Werbekampagne';
    $posts = $id ? all('SELECT id, titel, datum, status FROM sm_beitraege WHERE kampagne_id = ? ORDER BY datum', [$id]) : [];
    ?>
    <p class="crumbs"><a href="<?= e($back('kampagnen')) ?>">Kampagnen</a> › <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <form method="post" action="<?= e(url_admin('social', 'kampagne_speichern', $id ? ['id' => $id] : [])) ?>" class="panel">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= form_fields($campaignFields, $row) ?>
            <?= form_field(['termin_id', 'Beworbener Blutspendetermin', 'select', ['options' => $termOptions(), 'assoc' => true, 'help' => 'Für die Auswertung „Spender/innen mit und ohne Werbung“']], $row) ?>
        </div>
        <div class="actions"><button class="btn">Speichern</button> <a href="<?= e($back('kampagnen')) ?>">Abbrechen</a></div>
    </form>
    <?php if ($posts): ?>
        <section class="panel"><h2>Zugehörige Beiträge</h2><ul class="plain">
            <?php foreach ($posts as $p): ?><li><a href="<?= e(url_admin('social', 'bearbeiten', ['id' => $p['id']])) ?>"><?= e($p['titel']) ?></a>
                <span class="muted"><?= e(date_de($p['datum'])) ?></span> <span class="badge st-<?= e($p['status']) ?>"><?= e($states[$p['status']] ?? '') ?></span></li><?php endforeach; ?>
        </ul></section>
    <?php endif; ?>
    <?php if ($id): ?>
        <form method="post" action="<?= e(url_admin('social', 'kampagne_loeschen', ['id' => $id])) ?>" class="danger-zone" data-confirm="Kampagne löschen? Zugehörige Beiträge bleiben erhalten.">
            <?= csrf_field() ?><button class="btn btn-danger">Kampagne löschen</button>
        </form>
    <?php endif;
    return;
}

/* ---------- Übersicht ---------- */
$tabs = ['plan' => 'Redaktionsplan', 'kalender' => 'Kalender', 'kampagnen' => 'Werbekampagnen', 'auswertung' => 'Auswertung'];
$channelIcons = function (string $k): string {
    return $k === 'beide' ? social_icon('facebook') . social_icon('instagram') : social_icon($k);
};
?>
<div class="head-row">
    <h1>Social Media</h1>
    <div class="btn-group">
        <?php if ($tab === 'kampagnen'): ?><a class="btn" href="<?= e(url_admin('social', 'kampagne')) ?>">+ Neue Kampagne</a>
        <?php else: ?><a class="btn" href="<?= e(url_admin('social', 'neu')) ?>">+ Neuer Beitrag</a><?php endif; ?>
    </div>
</div>
<?php if (!social_channels()): ?>
    <div class="notice-box">Tipp: Tragt unter <a href="<?= e(url_admin('einstellungen')) ?>">Einstellungen</a> die Adressen eurer Facebook-Seite und eures Instagram-Profils ein – dann erscheinen sie auf der Website.</div>
<?php endif; ?>
<nav class="tabs">
    <?php foreach ($tabs as $k => $label): ?><a href="<?= e($back($k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'plan'):
    $past = (bool)get('vergangen');
    $rows = $past
        ? all("SELECT b.*, t.datum AS t_datum FROM sm_beitraege b LEFT JOIN bs_termine t ON t.id = b.termin_id WHERE b.status = 'veroeffentlicht' OR b.datum < ? ORDER BY b.datum DESC, b.uhrzeit DESC LIMIT 100", [$today])
        : all("SELECT b.*, t.datum AS t_datum FROM sm_beitraege b LEFT JOIN bs_termine t ON t.id = b.termin_id WHERE b.status <> 'veroeffentlicht' AND (b.datum IS NULL OR b.datum >= ?) ORDER BY b.datum IS NULL, b.datum, b.uhrzeit", [$today]);
    $overdue = (int)val("SELECT COUNT(*) FROM sm_beitraege WHERE status <> 'veroeffentlicht' AND datum < ?", [$today]);
    $upcomingTermine = all('SELECT id, datum, ort FROM bs_termine WHERE datum >= ? ORDER BY datum LIMIT 10', [$today]);
    $recentNews = all('SELECT id, titel, datum FROM news WHERE veroeffentlicht = 1 ORDER BY datum DESC LIMIT 15');
    ?>
    <div class="grid-2">
        <?php if ($upcomingTermine): ?>
            <form method="post" action="<?= e(url_admin('social', 'vorlage_termin')) ?>" class="panel">
                <?= csrf_field() ?>
                <h3>Blutspendetermin bewerben</h3>
                <p class="muted small">Legt 3 fertige Entwürfe an: Ankündigung (14 Tage vorher), Erinnerung (3 Tage) und „Morgen ist es so weit“. Die Texte lassen sich unter Einstellungen anpassen.</p>
                <div class="filters"><select name="termin_id"><?php foreach ($upcomingTermine as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e(date_de($t['datum'], true)) ?> – <?= e($t['ort']) ?></option><?php endforeach; ?></select>
                    <button class="btn">Entwürfe anlegen</button></div>
            </form>
        <?php endif; ?>
        <?php if ($recentNews): ?>
            <form method="post" action="<?= e(url_admin('social', 'vorlage_news')) ?>" class="panel">
                <?= csrf_field() ?>
                <h3>Meldung teilen</h3>
                <p class="muted small">Macht aus einer Meldung von der Website einen Beitragsentwurf mit Text, Bild und Link.</p>
                <div class="filters"><select name="news_id"><?php foreach ($recentNews as $n): ?><option value="<?= (int)$n['id'] ?>"><?= e(date_de($n['datum'])) ?> – <?= e(mb_strimwidth($n['titel'], 0, 50, '…')) ?></option><?php endforeach; ?></select>
                    <button class="btn">Entwurf anlegen</button></div>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($overdue && !$past): ?><div class="notice-box"><?= $overdue ?> geplante(r) Beitrag/Beiträge mit vergangenem Datum sind noch nicht als veröffentlicht markiert. <a href="<?= e($back('plan', ['vergangen' => 1])) ?>">Ansehen</a></div><?php endif; ?>
    <nav class="tabs sub-tabs">
        <a href="<?= e($back()) ?>" class="<?= $past ? '' : 'active' ?>">Anstehend</a>
        <a href="<?= e($back('plan', ['vergangen' => 1])) ?>" class="<?= $past ? 'active' : '' ?>">Vergangen &amp; veröffentlicht</a>
    </nav>
    <table class="list">
        <thead><tr><th>Wann</th><th>Kanal</th><th>Beitrag</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['datum'] === $today ? 'today' : '' ?>">
                <td class="nowrap"><?= $r['datum'] ? e(date_de($r['datum'], true)) . ($r['uhrzeit'] ? '<br><small class="muted">' . e($r['uhrzeit']) . ' Uhr</small>' : '') : '<span class="muted">ohne Datum</span>' ?></td>
                <td class="sm-icons" title="<?= e($channels[$r['kanal']] ?? '') ?>"><?= $channelIcons($r['kanal']) ?></td>
                <td><a href="<?= e(url_admin('social', 'bearbeiten', ['id' => $r['id']])) ?>"><strong><?= e($r['titel']) ?></strong></a>
                    <br><small class="muted"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['text']) ?? '', 0, 110, '…')) ?></small>
                    <?= $r['t_datum'] ? '<br><small>♥ Blutspende ' . e(date_de($r['t_datum'])) . '</small>' : '' ?></td>
                <td><span class="badge st-<?= e($r['status']) ?>"><?= e($states[$r['status']] ?? $r['status']) ?></span></td>
                <td class="right"><?php if ($r['status'] !== 'veroeffentlicht'): ?><?= post_button(url_admin('social', 'veroeffentlicht', ['id' => $r['id']]), '✓ veröffentlicht', ['zurueck' => 'liste'], 'Als veröffentlicht markieren') ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted"><?= $past ? 'Noch nichts veröffentlicht.' : 'Nichts geplant. Legt einen Beitrag an oder nutzt eine Vorlage oben.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'kalender'):
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)get('monat')) ? (string)get('monat') : date('Y-m');
    $first = strtotime($ym . '-01');
    $start = strtotime('-' . ((int)date('N', $first) - 1) . ' days', $first);
    $end = strtotime('+' . (7 - (int)date('N', strtotime(date('Y-m-t', $first)))) . ' days', strtotime(date('Y-m-t', $first)));
    $posts = [];
    foreach (all('SELECT * FROM sm_beitraege WHERE datum BETWEEN ? AND ? ORDER BY uhrzeit', [date('Y-m-d', $start), date('Y-m-d', $end)]) as $p) {
        $posts[$p['datum']][] = $p;
    }
    $events = [];
    foreach (all('SELECT * FROM bs_termine WHERE datum BETWEEN ? AND ?', [date('Y-m-d', $start), date('Y-m-d', $end)]) as $t) {
        $events[$t['datum']][] = $t;
    }
    $months = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    ?>
    <div class="head-row cal-nav">
        <a class="btn btn-small btn-outline" href="<?= e($back('kalender', ['monat' => date('Y-m', strtotime('-1 month', $first))])) ?>">← zurück</a>
        <h2><?= $months[(int)date('n', $first)] ?> <?= date('Y', $first) ?></h2>
        <a class="btn btn-small btn-outline" href="<?= e($back('kalender', ['monat' => date('Y-m', strtotime('+1 month', $first))])) ?>">weiter →</a>
    </div>
    <table class="calendar">
        <thead><tr><?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)): $iso = date('Y-m-d', $d); ?>
            <?= date('N', $d) === '1' ? '<tr>' : '' ?>
            <td class="<?= date('m', $d) !== date('m', $first) ? 'other' : '' ?><?= $iso === $today ? ' today' : '' ?>">
                <div class="cal-day"><span><?= date('j', $d) ?></span><a href="<?= e(url_admin('social', 'neu', ['datum' => $iso])) ?>" class="cal-add" title="Beitrag an diesem Tag planen">+</a></div>
                <?php foreach ($events[$iso] ?? [] as $t): ?><div class="cal-event" title="<?= e($t['ort']) ?>">♥ Blutspende</div><?php endforeach; ?>
                <?php foreach ($posts[$iso] ?? [] as $p): ?>
                    <a class="cal-post st-<?= e($p['status']) ?>" href="<?= e(url_admin('social', 'bearbeiten', ['id' => $p['id']])) ?>" title="<?= e($states[$p['status']] ?? '') ?>">
                        <?= e($p['uhrzeit']) ?> <?= e(mb_strimwidth($p['titel'], 0, 34, '…')) ?></a>
                <?php endforeach; ?>
            </td>
            <?= date('N', $d) === '7' ? '</tr>' : '' ?>
        <?php endfor; ?>
        </tbody>
    </table>
    <p class="cal-legend">Status: <span class="badge st-idee">Idee</span> <span class="badge st-entwurf">Entwurf</span> <span class="badge st-geplant">Geplant</span> <span class="badge st-veroeffentlicht">Veröffentlicht</span></p>

<?php elseif ($tab === 'kampagnen'):
    $rows = all('SELECT k.*, t.datum AS t_datum, t.ort AS t_ort FROM sm_kampagnen k LEFT JOIN bs_termine t ON t.id = k.termin_id ORDER BY k.start DESC, k.id DESC');
    ?>
    <table class="list">
        <thead><tr><th>Kampagne</th><th>Zeitraum</th><th>Kanal</th><th class="right">Budget</th><th class="right">Kosten</th><th class="right">Reichweite</th><th class="right">Klicks</th><th class="right">€/Klick</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $k):
            $state = $k['ende'] && $k['ende'] < $today ? 'beendet' : ($k['start'] && $k['start'] > $today ? 'geplant' : 'läuft'); ?>
            <tr>
                <td><a href="<?= e(url_admin('social', 'kampagne', ['id' => $k['id']])) ?>"><strong><?= e($k['name']) ?></strong></a> <span class="badge <?= $state === 'läuft' ? 'ok' : '' ?>"><?= $state ?></span>
                    <?= $k['t_datum'] ? '<br><small>♥ Blutspende ' . e(date_de($k['t_datum'])) . ', ' . e($k['t_ort']) . '</small>' : '' ?></td>
                <td class="nowrap small"><?= e(date_de($k['start'])) ?> – <?= e(date_de($k['ende'])) ?></td>
                <td class="sm-icons"><?= $channelIcons($k['kanal']) ?></td>
                <td class="right"><?= $k['budget'] !== null ? e(num_de((float)$k['budget'])) . ' €' : '' ?></td>
                <td class="right"><?= $k['kosten'] !== null ? e(num_de((float)$k['kosten'])) . ' €' : '' ?></td>
                <td class="right"><?= $k['reichweite'] !== null ? e(num_de((float)$k['reichweite'], 0)) : '' ?></td>
                <td class="right"><?= $k['klicks'] !== null ? e(num_de((float)$k['klicks'], 0)) : '' ?></td>
                <td class="right"><?= $k['kosten'] && $k['klicks'] ? e(num_de((float)$k['kosten'] / (int)$k['klicks'])) . ' €' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted">Noch keine Kampagnen. Erfasst hier bezahlte Anzeigen aus dem Meta-Werbeanzeigenmanager – mit Budget und nachträglich den Ergebnissen.</td></tr><?php endif; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'auswertung'):
    $year = (int)get('jahr', date('Y'));
    $from = "$year-01-01";
    $to = "$year-12-31";
    $cost = one('SELECT COALESCE(SUM(kosten), 0) AS kosten, COALESCE(SUM(CASE WHEN kosten IS NULL THEN budget END), 0) AS offen, COUNT(*) AS n,
        COALESCE(SUM(reichweite), 0) AS reichweite, COALESCE(SUM(klicks), 0) AS klicks FROM sm_kampagnen WHERE start BETWEEN ? AND ?', [$from, $to]);
    $published = (int)val("SELECT COUNT(*) FROM sm_beitraege WHERE status = 'veroeffentlicht' AND COALESCE(SUBSTR(veroeffentlicht_am, 1, 10), datum) BETWEEN ? AND ?", [$from, $to]);
    $termine = all("SELECT t.*,
            (SELECT COALESCE(SUM(kosten), 0) FROM sm_kampagnen k WHERE k.termin_id = t.id) AS werbekosten,
            (SELECT COUNT(*) FROM sm_kampagnen k WHERE k.termin_id = t.id) AS kampagnen,
            (SELECT COUNT(*) FROM sm_beitraege b WHERE b.termin_id = t.id AND b.status = 'veroeffentlicht') AS beitraege
        FROM bs_termine t WHERE t.datum BETWEEN ? AND ? ORDER BY t.datum", [$from, $to]);
    $groups = ['mit' => [], 'ohne' => []];
    foreach ($termine as $t) {
        if ($t['tatsaechliche_spender'] !== null) {
            $groups[(int)$t['kampagnen'] > 0 ? 'mit' : 'ohne'][] = $t;
        }
    }
    $avg = fn(array $l, string $k) => $l ? array_sum(array_map(fn($t) => (int)$t[$k], $l)) / count($l) : null;
    ?>
    <nav class="tabs sub-tabs">
        <?php foreach (range((int)date('Y'), (int)date('Y') - 3) as $y): ?><a href="<?= e($back('auswertung', ['jahr' => $y])) ?>" class="<?= $y === $year ? 'active' : '' ?>"><?= $y ?></a><?php endforeach; ?>
    </nav>
    <div class="cards">
        <div class="card stat"><strong><?= e(num_de((float)$cost['kosten'])) ?> €</strong>Werbekosten <?= $year ?></div>
        <div class="card stat"><strong><?= (int)$cost['n'] ?></strong>Kampagnen<?= (float)$cost['offen'] > 0 ? ' (+' . e(num_de((float)$cost['offen'])) . ' € Budget ohne Abrechnung)' : '' ?></div>
        <div class="card stat"><strong><?= e(num_de((float)$cost['reichweite'], 0)) ?></strong>Reichweite (Anzeigen)</div>
        <div class="card stat"><strong><?= $published ?></strong>veröffentlichte Beiträge</div>
    </div>
    <section class="panel">
        <h2>Blutspende: mit und ohne Werbekampagne</h2>
        <?php if (!$groups['mit'] && !$groups['ohne']): ?>
            <p class="muted">Sobald bei Terminen die tatsächlichen Spenderzahlen eingetragen sind, erscheint hier der Vergleich.</p>
        <?php else: ?>
            <table class="list">
                <thead><tr><th></th><th class="right">Termine</th><th class="right">Ø Spender/innen</th><th class="right">Ø Erstspender/innen</th></tr></thead>
                <?php foreach (['mit' => 'mit Werbekampagne', 'ohne' => 'ohne Werbekampagne'] as $k => $label): ?>
                    <tr><td><?= $label ?></td><td class="right"><?= count($groups[$k]) ?></td>
                        <td class="right"><?= $groups[$k] ? e(num_de($avg($groups[$k], 'tatsaechliche_spender'), 1)) : '–' ?></td>
                        <td class="right"><?= $groups[$k] ? e(num_de($avg($groups[$k], 'erstspender'), 1)) : '–' ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="muted small">Vorsicht bei wenigen Terminen: Wetter, Ferien und Ort beeinflussen die Zahlen oft stärker als die Werbung.</p>
        <?php endif; ?>
    </section>
    <table class="list">
        <thead><tr><th>Termin</th><th class="right">Beiträge</th><th class="right">Werbekosten</th><th class="right">Spender/innen</th><th class="right">davon Erst-</th><th class="right">Kosten je Spender/in</th></tr></thead>
        <tbody>
        <?php foreach ($termine as $t): ?>
            <tr>
                <td><?= e(date_de($t['datum'], true)) ?><br><small class="muted"><?= e($t['ort']) ?></small></td>
                <td class="right"><?= (int)$t['beitraege'] ?></td>
                <td class="right"><?= (float)$t['werbekosten'] > 0 ? e(num_de((float)$t['werbekosten'])) . ' €' : '–' ?></td>
                <td class="right"><?= $t['tatsaechliche_spender'] !== null ? (int)$t['tatsaechliche_spender'] : '<span class="muted">offen</span>' ?></td>
                <td class="right"><?= $t['erstspender'] !== null ? (int)$t['erstspender'] : '' ?></td>
                <td class="right"><?= (float)$t['werbekosten'] > 0 && (int)$t['tatsaechliche_spender'] > 0 ? e(num_de((float)$t['werbekosten'] / (int)$t['tatsaechliche_spender'])) . ' €' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$termine): ?><tr><td colspan="6" class="muted">Keine Blutspendetermine in <?= $year ?>.</td></tr><?php endif; ?>
        </tbody>
    </table>
<?php endif; ?>
