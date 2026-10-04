<?php
declare(strict_types=1);

/** Social Media: Vorschau beim Teilen, Kanal-Links, Teilen-Knöpfe, Redaktionsplan-Vorlagen */

/** Basisadresse der Website mit https://… (Einstellung „website_url“ oder automatisch erkannt) */
function base_url(): string
{
    $set = rtrim(setting('website_url'), '/');
    if ($set !== '') {
        return $set . '/';
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/** Relative Adresse (z. B. „index.php?seite=blutspende“) → absolute Adresse */
function abs_url(string $relative): string
{
    if (preg_match('~^https?://~i', $relative)) {
        return $relative;
    }
    return base_url() . ltrim($relative === 'index.php' || $relative === './' ? '' : $relative, '/');
}

/** Konfigurierte Kanäle: ['facebook' => url, 'instagram' => url] */
function social_channels(): array
{
    return array_filter([
        'facebook'  => setting('social_facebook'),
        'instagram' => setting('social_instagram'),
    ], fn($u) => preg_match('~^https://~i', (string)$u));
}

function social_icon(string $name): string
{
    $paths = [
        'facebook'  => '<path d="M13.5 21v-7.5h2.5l.4-3h-2.9V8.6c0-.9.3-1.5 1.5-1.5h1.5V4.4c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2.4H8v3h2.6V21z"/>',
        'instagram' => '<path d="M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4zm0 7.7a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm4.9-7.9a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0zM12 4.6c2.4 0 2.7 0 3.6.1 2.4.1 3.5 1.2 3.6 3.6.1.9.1 1.2.1 3.6s0 2.7-.1 3.6c-.1 2.4-1.2 3.5-3.6 3.6-.9.1-1.2.1-3.6.1s-2.7 0-3.6-.1c-2.4-.1-3.5-1.2-3.6-3.6-.1-.9-.1-1.2-.1-3.6s0-2.7.1-3.6c.1-2.4 1.2-3.5 3.6-3.6.9-.1 1.2-.1 3.6-.1zM12 3c-2.4 0-2.7 0-3.7.1-3.3.1-5.1 2-5.2 5.2C3 9.3 3 9.6 3 12s0 2.7.1 3.7c.1 3.3 2 5.1 5.2 5.2 1 .1 1.3.1 3.7.1s2.7 0 3.7-.1c3.3-.1 5.1-2 5.2-5.2.1-1 .1-1.3.1-3.7s0-2.7-.1-3.7c-.1-3.3-2-5.1-5.2-5.2C14.7 3 14.4 3 12 3z"/>',
        'whatsapp'  => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3zm0 16.4a7.4 7.4 0 0 1-3.8-1l-.3-.2-2.7.7.7-2.6-.2-.3A7.4 7.4 0 1 1 12 19.4zm4.1-5.5c-.2-.1-1.3-.7-1.5-.7-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1a6 6 0 0 1-3-2.6c-.2-.4.2-.4.6-1.2.1-.1 0-.3 0-.4l-.7-1.6c-.2-.4-.4-.4-.5-.4h-.4a.8.8 0 0 0-.6.3 2.5 2.5 0 0 0-.8 1.9 4.4 4.4 0 0 0 .9 2.3 10 10 0 0 0 3.8 3.4c1.4.6 2 .7 2.7.6.4-.1 1.3-.5 1.5-1.1.2-.5.2-1 .1-1.1l-.4-.2z"/>',
        'mail'      => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h15A1.5 1.5 0 0 1 21 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5zm1.8.2L12 12l7.2-5.3zM19.5 8 12 13.6 4.5 8v9.5h15z"/>',
        'link'      => '<path d="M10.6 13.4a1 1 0 0 1 0-1.4l3.5-3.5a1 1 0 1 1 1.4 1.4L12 13.4a1 1 0 0 1-1.4 0zM8.5 19a3.5 3.5 0 0 1-2.5-6l2.1-2.1a1 1 0 1 1 1.4 1.4l-2.1 2.1a1.5 1.5 0 0 0 2.1 2.1l2.1-2.1a1 1 0 1 1 1.4 1.4L11 18a3.5 3.5 0 0 1-2.5 1zm7-6.4a1 1 0 0 1-.7-1.7l2.1-2.1a1.5 1.5 0 0 0-2.1-2.1l-2.1 2.1a1 1 0 1 1-1.4-1.4L13.4 5a3.5 3.5 0 0 1 5 5l-2.1 2.1a1 1 0 0 1-.8.5z"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="currentColor">' . ($paths[$name] ?? '') . '</svg>';
}

/** Links zu den eigenen Kanälen (Kopf- und Fußzeile) */
function social_links_html(string $class = 'social-links'): string
{
    $labels = ['facebook' => 'Facebook', 'instagram' => 'Instagram'];
    $html = '';
    foreach (social_channels() as $k => $url) {
        $html .= '<a href="' . e($url) . '" target="_blank" rel="noopener" title="' . $labels[$k] . '" aria-label="Wir auf ' . $labels[$k] . '">' . social_icon($k) . '</a>';
    }
    return $html ? '<span class="' . e($class) . '">' . $html . '</span>' : '';
}

/**
 * Teilen-Knöpfe als einfache Links: Es wird nichts von Facebook geladen und
 * keine Daten übertragen, solange niemand klickt.
 */
function share_buttons(string $url, string $text): string
{
    $u = rawurlencode($url);
    $t = rawurlencode($text);
    return '<div class="share" data-share-url="' . e($url) . '" data-share-text="' . e($text) . '"><span class="share-label">Teilen:</span>'
        . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '" target="_blank" rel="noopener nofollow" title="Auf Facebook teilen">' . social_icon('facebook') . '</a>'
        . '<a href="https://wa.me/?text=' . rawurlencode($text . ' ' . $url) . '" target="_blank" rel="noopener nofollow" title="Per WhatsApp teilen">' . social_icon('whatsapp') . '</a>'
        . '<a href="mailto:?subject=' . $t . '&amp;body=' . rawurlencode($text . "\n\n" . $url) . '" title="Per E-Mail teilen">' . social_icon('mail') . '</a>'
        . '<button type="button" class="share-copy" title="Link kopieren (z. B. für Instagram)">' . social_icon('link') . '<span>Link kopieren</span></button>'
        . '</div>';
}

/* ---------- Redaktionsplan ---------- */

function sm_channels(): array
{
    return ['beide' => 'Facebook + Instagram', 'facebook' => 'Facebook', 'instagram' => 'Instagram'];
}

function sm_status(): array
{
    return ['idee' => 'Idee', 'entwurf' => 'Entwurf', 'geplant' => 'Geplant', 'veroeffentlicht' => 'Veröffentlicht'];
}

/** Platzhalter für Vorlagen ersetzen */
function sm_fill(string $text, array $values): string
{
    return strtr($text, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($values)), array_values($values)));
}

/** Vorlagen „Blutspendetermin bewerben“: [Tage vorher, Titel, Text] – Texte unter Einstellungen änderbar */
function sm_termin_templates(): array
{
    $defaults = [
        [14, 'Ankündigung', setting('sm_vorlage_ankuendigung',
            "🩸 Save the Date: Blutspende in {ort}!\n\nAm {wochentag}, {datum} von {zeit} Uhr könnt ihr bei uns Leben retten. Jede Spende hilft bis zu drei kranken oder verletzten Menschen.\n\n📍 {ort}{adresse}\n\nWir freuen uns auf euch – nach der Spende gibt es wie immer einen leckeren Imbiss!")],
        [3, 'Erinnerung', setting('sm_vorlage_erinnerung',
            "⏰ Nur noch 3 Tage: Am {wochentag} ist Blutspende!\n\n🗓 {datum}, {zeit} Uhr\n📍 {ort}{adresse}\n\nBitte Personalausweis mitbringen. Erstspenderinnen und Erstspender sind herzlich willkommen!")],
        [1, 'Morgen', setting('sm_vorlage_morgen',
            "Morgen ist es so weit! ❤️\n\nBlutspende am {wochentag}, {datum} von {zeit} Uhr in {ort}. Bringt gern Freunde und Familie mit – jede Spende zählt.")],
    ];
    return $defaults;
}

/** Platzhalterwerte für einen Blutspendetermin */
function sm_termin_values(array $t): array
{
    $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $ts = strtotime($t['datum']);
    $slug = val("SELECT s.slug FROM seiten s JOIN seiten_bloecke b ON b.seite_id = s.id WHERE b.typ = 'blutspendetermine' AND s.veroeffentlicht = 1 ORDER BY s.ist_startseite, s.id LIMIT 1");
    return [
        'datum'     => date('d.m.Y', $ts),
        'wochentag' => $days[(int)date('w', $ts)],
        'zeit'      => trim(($t['beginn'] ?? '') . '–' . ($t['ende'] ?? ''), '–'),
        'ort'       => (string)$t['ort'],
        'adresse'   => $t['adresse'] ? ', ' . $t['adresse'] : '',
        'verein'    => setting('seitentitel'),
        'link'      => abs_url(url_page($slug ? (string)$slug : '')),
    ];
}

/** Fertiger Text zum Kopieren. Instagram: Links sind dort nicht klickbar → Hinweis „Link in der Bio“ */
function sm_compose(array $post, string $channel): string
{
    $text = trim((string)$post['text']);
    $link = trim((string)$post['link']);
    $tags = trim((string)$post['hashtags']);
    if ($channel === 'instagram') {
        $out = $text . ($link ? "\n\n👉 Infos über den Link in unserer Bio" : '');
    } else {
        $out = $text . ($link ? "\n\n👉 " . $link : '');
    }
    return $out . ($tags !== '' ? "\n\n" . $tags : '');
}
