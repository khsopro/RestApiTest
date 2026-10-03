<?php
declare(strict_types=1);

/**
 * Inhaltsbausteine einer Seite.
 * Neue Bausteine: Eintrag hier ergänzen + Fall in render_block().
 * Feldtypen: text, markdown, image, select, number, lines
 */
function block_types(): array
{
    return [
        'text' => ['label' => 'Text', 'icon' => '¶', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['inhalt', 'Inhalt', 'markdown'],
        ]],
        'bild_text' => ['label' => 'Bild & Text', 'icon' => '▧', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['inhalt', 'Inhalt', 'markdown'],
            ['bild', 'Bild', 'image'],
            ['position', 'Bildposition', 'select', ['links' => 'Bild links', 'rechts' => 'Bild rechts']],
        ]],
        'bild' => ['label' => 'Bild', 'icon' => '▣', 'fields' => [
            ['bild', 'Bild', 'image'],
            ['unterschrift', 'Bildunterschrift', 'text'],
            ['breite', 'Breite', 'select', ['normal' => 'Normal', 'voll' => 'Volle Breite']],
        ]],
        'zwei_spalten' => ['label' => 'Zwei Spalten', 'icon' => '◫', 'fields' => [
            ['titel', 'Überschrift (optional)', 'text'],
            ['links', 'Linke Spalte', 'markdown'],
            ['rechts', 'Rechte Spalte', 'markdown'],
        ]],
        'kacheln' => ['label' => 'Kacheln / Bereiche', 'icon' => '▦', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['eintraege', 'Kacheln', 'lines', 'Eine Kachel pro Zeile:  Titel | kurzer Text | Link (Seiten-Kürzel oder https://…)'],
        ]],
        'unterseiten' => ['label' => 'Unterseiten als Kacheln', 'icon' => '⊞', 'fields' => [
            ['titel', 'Überschrift', 'text'],
        ]],
        'hinweis' => ['label' => 'Hinweisbox', 'icon' => '!', 'fields' => [
            ['stil', 'Art', 'select', ['info' => 'Information (grau)', 'wichtig' => 'Wichtig (rot)', 'erfolg' => 'Positiv (grün)']],
            ['inhalt', 'Text', 'markdown'],
        ]],
        'button' => ['label' => 'Schaltfläche', 'icon' => '➔', 'fields' => [
            ['text', 'Beschriftung', 'text'],
            ['link', 'Ziel (Seiten-Kürzel oder https://…)', 'text'],
            ['stil', 'Stil', 'select', ['primaer' => 'Rot (Hauptaktion)', 'sekundaer' => 'Umrandet']],
        ]],
        'akkordeon' => ['label' => 'Fragen & Antworten', 'icon' => '≡', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['eintraege', 'Einträge', 'lines', 'Ein Eintrag pro Zeile:  Frage | Antwort'],
        ]],
        'kontakt' => ['label' => 'Ansprechpartner/in', 'icon' => '☺', 'fields' => [
            ['name', 'Name', 'text'],
            ['funktion', 'Funktion', 'text'],
            ['telefon', 'Telefon', 'text'],
            ['email', 'E-Mail', 'text'],
            ['bild', 'Foto', 'image'],
            ['text', 'Zusatztext', 'markdown'],
        ]],
        'zahlen' => ['label' => 'Zahlen & Fakten', 'icon' => '#', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['eintraege', 'Fakten', 'lines', 'Ein Fakt pro Zeile:  Zahl | Beschriftung   (z. B. 120 | aktive Mitglieder)'],
        ]],
        'blutspendetermine' => ['label' => 'Blutspendetermine (automatisch)', 'icon' => '♥', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['anzahl', 'Anzahl Termine', 'number'],
            ['text', 'Text unter der Liste', 'markdown'],
        ]],
        'unterstuetzer' => ['label' => 'Unterstützer/Sponsoren (automatisch)', 'icon' => '★', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['text', 'Einleitung', 'markdown'],
        ]],
        'karte' => ['label' => 'Adresse / Anfahrt', 'icon' => '⌖', 'fields' => [
            ['titel', 'Überschrift', 'text'],
            ['adresse', 'Adresse', 'markdown'],
            ['link', 'Link zum Kartendienst (optional)', 'text'],
        ]],
    ];
}

function block_data(array $block): array
{
    $d = json_decode((string)$block['daten'], true);
    return is_array($d) ? $d : [];
}

/** Link-Ziel: Seitenkürzel oder externe URL */
function resolve_link(string $target): string
{
    $target = trim($target);
    if ($target === '') {
        return '';
    }
    if (preg_match('~^(https?://|mailto:|tel:)~i', $target)) {
        return $target;
    }
    if (str_contains($target, ':')) {
        return ''; // unsichere Schemata verwerfen
    }
    if (str_starts_with($target, '#') || str_contains($target, '.') || str_contains($target, '/') || str_contains($target, '?')) {
        return $target;
    }
    return url_page($target);
}

function split_lines(string $text, int $parts): array
{
    $rows = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = array_pad(array_map('trim', explode('|', $line, $parts)), $parts, '');
    }
    return $rows;
}

function render_image(?int $id, string $class = ''): string
{
    $url = media_url($id);
    return $url ? '<img src="' . e($url) . '" alt="' . e(media_alt($id)) . '" class="' . e($class) . '" loading="lazy">' : '';
}

function render_block(array $block, array $page): string
{
    $d = block_data($block);
    $t = fn(string $k) => trim((string)($d[$k] ?? ''));
    $h2 = fn(string $k) => $t($k) !== '' ? '<h2>' . e($t($k)) . '</h2>' : '';
    $type = $block['typ'];

    switch ($type) {
        case 'text':
            return $h2('titel') . '<div class="prose">' . md($t('inhalt')) . '</div>';

        case 'bild_text':
            $img = render_image((int)($d['bild'] ?? 0));
            return '<div class="media-text ' . ($t('position') === 'rechts' ? 'img-right' : 'img-left') . '">'
                . ($img ? '<div class="media">' . $img . '</div>' : '')
                . '<div class="prose">' . $h2('titel') . md($t('inhalt')) . '</div></div>';

        case 'bild':
            $img = render_image((int)($d['bild'] ?? 0));
            return $img ? '<figure class="figure ' . ($t('breite') === 'voll' ? 'full' : '') . '">' . $img
                . ($t('unterschrift') ? '<figcaption>' . e($t('unterschrift')) . '</figcaption>' : '') . '</figure>' : '';

        case 'zwei_spalten':
            return $h2('titel') . '<div class="cols"><div class="prose">' . md($t('links')) . '</div><div class="prose">' . md($t('rechts')) . '</div></div>';

        case 'kacheln':
            $html = '';
            foreach (split_lines($t('eintraege'), 3) as [$title, $text, $link]) {
                $href = resolve_link($link);
                $inner = '<h3>' . e($title) . '</h3>' . ($text ? '<p>' . e($text) . '</p>' : '') . ($href ? '<span class="more">Mehr erfahren →</span>' : '');
                $html .= $href ? '<a class="tile" href="' . e($href) . '">' . $inner . '</a>' : '<div class="tile">' . $inner . '</div>';
            }
            return $h2('titel') . '<div class="tiles">' . $html . '</div>';

        case 'unterseiten':
            $children = all('SELECT * FROM seiten WHERE parent_id = ? AND veroeffentlicht = 1 ORDER BY sortierung, titel', [$page['id']]);
            $html = '';
            foreach ($children as $c) {
                $img = $c['hero_bild'] ? '<div class="tile-img">' . render_image((int)$c['hero_bild']) . '</div>' : '';
                $html .= '<a class="tile" href="' . e(url_page($c['slug'])) . '">' . $img . '<h3>' . e($c['titel']) . '</h3>'
                    . ($c['beschreibung'] ? '<p>' . e($c['beschreibung']) . '</p>' : '') . '<span class="more">Mehr erfahren →</span></a>';
            }
            return $html ? $h2('titel') . '<div class="tiles">' . $html . '</div>' : '';

        case 'hinweis':
            return '<div class="notice notice-' . e($t('stil') ?: 'info') . '">' . md($t('inhalt')) . '</div>';

        case 'button':
            $href = resolve_link($t('link'));
            return $href ? '<p class="btn-row"><a class="btn ' . ($t('stil') === 'sekundaer' ? 'btn-outline' : '') . '" href="' . e($href) . '">' . e($t('text') ?: 'Mehr') . '</a></p>' : '';

        case 'akkordeon':
            $html = '';
            foreach (split_lines($t('eintraege'), 2) as [$q, $a]) {
                $html .= '<details><summary>' . e($q) . '</summary><div class="prose">' . md(str_replace('\n', "\n", $a)) . '</div></details>';
            }
            return $h2('titel') . '<div class="faq">' . $html . '</div>';

        case 'kontakt':
            $img = render_image((int)($d['bild'] ?? 0));
            $tel = $t('telefon');
            $mail = $t('email');
            return '<div class="contact-card">' . ($img ?: '<div class="avatar">' . e(mb_substr($t('name'), 0, 1)) . '</div>')
                . '<div><h3>' . e($t('name')) . '</h3>' . ($t('funktion') ? '<p class="role">' . e($t('funktion')) . '</p>' : '')
                . ($tel ? '<p>☎ <a href="tel:' . e(preg_replace('/[^0-9+]/', '', $tel)) . '">' . e($tel) . '</a></p>' : '')
                . ($mail ? '<p>✉ <a href="mailto:' . e($mail) . '">' . e($mail) . '</a></p>' : '')
                . '<div class="prose">' . md($t('text')) . '</div></div></div>';

        case 'zahlen':
            $html = '';
            foreach (split_lines($t('eintraege'), 2) as [$num, $label]) {
                $html .= '<div class="stat"><strong>' . e($num) . '</strong><span>' . e($label) . '</span></div>';
            }
            return $h2('titel') . '<div class="stats">' . $html . '</div>';

        case 'blutspendetermine':
            $limit = max(1, min(50, (int)($d['anzahl'] ?? 5)));
            $rows = all('SELECT * FROM bs_termine WHERE oeffentlich = 1 AND datum >= ? ORDER BY datum, beginn LIMIT ' . $limit, [date('Y-m-d')]);
            $html = '';
            foreach ($rows as $r) {
                $ts = strtotime($r['datum']);
                $html .= '<li class="event"><div class="event-date"><span>' . date('d', $ts) . '</span>' . e(month_short((int)date('n', $ts))) . '</div><div>'
                    . '<strong>' . e(date_de($r['datum'], true)) . ($r['beginn'] ? ', ' . e($r['beginn']) . '–' . e($r['ende']) . ' Uhr' : '') . '</strong><br>'
                    . e($r['ort']) . ($r['adresse'] ? ', ' . e($r['adresse']) : '')
                    . ($r['hinweis'] ? '<br><small>' . e($r['hinweis']) . '</small>' : '') . '</div></li>';
            }
            if (!$html) {
                $html = '<li class="empty">Zurzeit sind keine Termine eingetragen.</li>';
            }
            return $h2('titel') . '<ul class="events">' . $html . '</ul><div class="prose">' . md($t('text')) . '</div>';

        case 'unterstuetzer':
            $rows = all('SELECT * FROM unterstuetzer WHERE oeffentlich = 1 ORDER BY name');
            $html = '';
            foreach ($rows as $r) {
                $logo = render_image((int)$r['logo']);
                $inner = $logo ?: '<span>' . e($r['name']) . '</span>';
                $url = resolve_link((string)$r['webseite']);
                $html .= $url && preg_match('~^https?://~', $url)
                    ? '<a class="sponsor" href="' . e($url) . '" target="_blank" rel="noopener" title="' . e($r['name']) . '">' . $inner . '</a>'
                    : '<div class="sponsor" title="' . e($r['name']) . '">' . $inner . '</div>';
            }
            return $html ? $h2('titel') . '<div class="prose">' . md($t('text')) . '</div><div class="sponsors">' . $html . '</div>' : '';

        case 'karte':
            $link = resolve_link($t('link'));
            return '<div class="address-box">' . $h2('titel') . '<div class="prose">' . md($t('adresse')) . '</div>'
                . ($link ? '<p><a class="btn btn-outline" href="' . e($link) . '" target="_blank" rel="noopener">Route planen</a></p>' : '') . '</div>';
    }
    return '';
}

function month_short(int $m): string
{
    return ['', 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'][$m] ?? '';
}

/* ---------- Admin: Formular für einen Baustein ---------- */

function image_picker(string $name, string $value): string
{
    static $media = null;
    $media ??= all('SELECT id, original, dateiname FROM medien WHERE mime LIKE ? ORDER BY id DESC', ['image/%']);
    $html = '<div class="image-picker"><label class="pick"><input type="radio" name="' . e($name) . '" value=""' . ($value === '' || $value === '0' ? ' checked' : '') . '><span class="none">kein Bild</span></label>';
    foreach ($media as $m) {
        $html .= '<label class="pick"><input type="radio" name="' . e($name) . '" value="' . (int)$m['id'] . '"' . ((string)$m['id'] === $value ? ' checked' : '')
            . '><img src="uploads/' . e(rawurlencode($m['dateiname'])) . '" alt="" title="' . e($m['original']) . '" loading="lazy"></label>';
    }
    return $html . '</div><small><a href="' . e(url_admin('medien')) . '" target="_blank">Neues Bild hochladen</a> (danach diese Seite neu laden)</small>';
}

function block_form(string $type, array $data): string
{
    $def = block_types()[$type] ?? null;
    if (!$def) {
        return '';
    }
    $html = '';
    foreach ($def['fields'] as $f) {
        [$name, $label, $ftype] = $f;
        $value = (string)($data[$name] ?? '');
        $html .= '<div class="field wide"><label>' . e($label) . '</label>';
        switch ($ftype) {
            case 'markdown':
                $html .= '<textarea name="d[' . e($name) . ']" rows="8" class="md">' . e($value) . '</textarea>'
                    . '<small>Formatierung: <code>## Überschrift</code>, <code>**fett**</code>, <code>*kursiv*</code>, <code>- Liste</code>, <code>[Linktext](https://…)</code>, Leerzeile = neuer Absatz</small>';
                break;
            case 'lines':
                $html .= '<textarea name="d[' . e($name) . ']" rows="7">' . e($value) . '</textarea><small>' . e($f[3] ?? '') . '</small>';
                break;
            case 'select':
                $html .= '<select name="d[' . e($name) . ']">';
                foreach ($f[3] as $k => $v) {
                    $html .= '<option value="' . e($k) . '"' . ($k === $value ? ' selected' : '') . '>' . e($v) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'number':
                $html .= '<input type="number" name="d[' . e($name) . ']" value="' . e($value) . '" min="0">';
                break;
            case 'image':
                $html .= image_picker('d[' . $name . ']', $value);
                break;
            default:
                $html .= '<input type="text" name="d[' . e($name) . ']" value="' . e($value) . '">';
        }
        $html .= '</div>';
    }
    return $html;
}

function block_collect(string $type): array
{
    $def = block_types()[$type] ?? ['fields' => []];
    $in = (array)($_POST['d'] ?? []);
    $data = [];
    foreach ($def['fields'] as $f) {
        $v = $in[$f[0]] ?? '';
        $data[$f[0]] = is_string($v) ? str_replace("\r\n", "\n", trim($v)) : '';
    }
    return $data;
}

function block_summary(array $block): string
{
    $d = block_data($block);
    foreach (['titel', 'text', 'name', 'inhalt', 'unterschrift', 'links', 'eintraege'] as $k) {
        if (!empty($d[$k])) {
            return mb_strimwidth(preg_replace('/\s+/', ' ', strip_tags((string)$d[$k])) ?? '', 0, 90, '…');
        }
    }
    return '';
}
