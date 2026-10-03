<?php
declare(strict_types=1);

/** Aktuelles / Neuigkeiten */

function url_news(string $slug): string
{
    return cfg('pretty_urls') ? 'news/' . rawurlencode($slug) : 'index.php?news=' . rawurlencode($slug);
}

/** Hängt Parameter an eine URL an (mit ? oder &) */
function url_with(string $url, array $params): string
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $params ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params) : $url;
}

/** Bedingung für öffentlich sichtbare Meldungen (veröffentlicht und Datum erreicht) */
function news_public_where(): array
{
    return ['veroeffentlicht = 1 AND datum <= ?', [date('Y-m-d')]];
}

/**
 * @return array{0: list<array>, 1: int} Meldungen und Gesamtanzahl
 */
function news_list(int $limit, int $offset = 0, string $kategorie = ''): array
{
    [$where, $params] = news_public_where();
    if ($kategorie !== '') {
        $where .= ' AND kategorie = ?';
        $params[] = $kategorie;
    }
    $total = (int)val("SELECT COUNT(*) FROM news WHERE $where", $params);
    $rows = all("SELECT * FROM news WHERE $where ORDER BY angeheftet DESC, datum DESC, id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    return [$rows, $total];
}

function news_teaser(array $n, int $len = 180): string
{
    if (trim((string)$n['teaser']) !== '') {
        return (string)$n['teaser'];
    }
    // Markdown-Zeichen entfernen (Links, Überschriften, Listenpunkte, Zitate, Fett/Kursiv)
    $plain = preg_replace(
        ['/\[([^\]]+)\]\([^)]+\)/', '/^\s*(#{1,4}|[-*]|\d+[.)]|>)\s+/m', '/\*+/', '/\s+/'],
        ['$1', '', '', ' '],
        (string)$n['inhalt']
    ) ?? '';
    return mb_strimwidth(trim($plain), 0, $len, '…');
}

function render_news_items(array $items, string $style): string
{
    if (!$items) {
        return '<p class="muted-text">Zurzeit gibt es keine Meldungen.</p>';
    }
    $html = '<div class="news-' . ($style === 'liste' ? 'list' : 'grid') . '">';
    foreach ($items as $n) {
        $img = render_image($n['bild'] ? (int)$n['bild'] : null);
        $html .= '<article class="news-card">'
            . ($img ? '<a class="news-img" href="' . e(url_news($n['slug'])) . '" tabindex="-1">' . $img . '</a>' : '')
            . '<div class="news-body"><p class="news-meta">' . ((int)$n['angeheftet'] ? '<span class="pin">Wichtig</span> ' : '')
            . '<time datetime="' . e($n['datum']) . '">' . e(date_de($n['datum'])) . '</time>'
            . ($n['kategorie'] ? ' · ' . e($n['kategorie']) : '') . '</p>'
            . '<h3><a href="' . e(url_news($n['slug'])) . '">' . e($n['titel']) . '</a></h3>'
            . '<p>' . e(news_teaser($n)) . '</p>'
            . '<a class="more" href="' . e(url_news($n['slug'])) . '">Weiterlesen →</a></div></article>';
    }
    return $html . '</div>';
}

/** Slug der Seite mit dem Archiv (erste Seite mit einem News-Baustein im Archivmodus) */
function news_archive_slug(): ?string
{
    $slug = val("SELECT s.slug FROM seiten s JOIN seiten_bloecke b ON b.seite_id = s.id
        WHERE b.typ = 'news' AND b.daten LIKE ? AND s.veroeffentlicht = 1 ORDER BY s.id LIMIT 1", ['%"archiv":"ja"%']);
    return $slug ? (string)$slug : null;
}
