<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if ((int)val('SELECT COUNT(*) FROM benutzer') === 0) {
    redirect('admin.php'); // Ersteinrichtung
}

$canPreview = current_user() && has_role('redaktion');
$article = null;
$parent = null;
$notFound = function (): array {
    http_response_code(404);
    return ['id' => 0, 'titel' => 'Seite nicht gefunden', 'slug' => '', 'parent_id' => null, 'layout' => 'standard',
        'farbe' => 'rot', 'hero_bild' => null, 'hero_text' => '', 'beschreibung' => '', 'veroeffentlicht' => 1, 'ist_startseite' => 0];
};
$notFoundBlocks = [['typ' => 'text', 'daten' => json_encode(['inhalt' => "Die angeforderte Seite gibt es leider nicht (mehr).\n\n[Zur Startseite](" . url_page('') . ")"])]];

if (get('news') !== null) {
    /* ---------- Einzelne Meldung ---------- */
    [$where, $params] = news_public_where();
    $article = $canPreview
        ? one('SELECT * FROM news WHERE slug = ?', [(string)get('news')])
        : one("SELECT * FROM news WHERE slug = ? AND $where", array_merge([(string)get('news')], $params));
    $blocks = [];
    if ($article) {
        $isPublic = (int)$article['veroeffentlicht'] === 1 && $article['datum'] <= date('Y-m-d');
        $archiveSlug = news_archive_slug();
        $parent = $archiveSlug ? one('SELECT * FROM seiten WHERE slug = ?', [$archiveSlug]) : null;
        $page = ['id' => 0, 'titel' => $article['titel'], 'slug' => '', 'parent_id' => $parent['id'] ?? null, 'layout' => 'standard',
            'farbe' => 'rot', 'hero_bild' => null, 'hero_text' => '', 'beschreibung' => news_teaser($article),
            'veroeffentlicht' => $isPublic ? 1 : 0, 'ist_startseite' => 0];
        $editUrl = url_admin('news', 'bearbeiten', ['id' => $article['id']]);
    } else {
        $page = $notFound();
        $blocks = $notFoundBlocks;
    }
} else {
    /* ---------- Normale Seite ---------- */
    $slug = (string)get('seite', home_slug());
    $page = one('SELECT * FROM seiten WHERE slug = ?' . ($canPreview ? '' : ' AND veroeffentlicht = 1'), [$slug]);
    if (!$page) {
        $page = $notFound();
        $blocks = $notFoundBlocks;
    } else {
        $blocks = all('SELECT * FROM seiten_bloecke WHERE seite_id = ? ORDER BY sortierung, id', [$page['id']]);
        $editUrl = url_admin('seiten', 'bearbeiten', ['id' => $page['id']]);
        $parent = $page['parent_id'] ? one('SELECT * FROM seiten WHERE id = ?', [$page['parent_id']]) : null;
    }
}

// Navigation: Hauptseiten mit einer Ebene Unterseiten
$menu = all('SELECT id, titel, menue_titel, slug, parent_id FROM seiten WHERE veroeffentlicht = 1 AND im_menue = 1 ORDER BY sortierung, titel');
$top = array_values(array_filter($menu, fn($p) => $p['parent_id'] === null));
$childrenOf = [];
foreach ($menu as $p) {
    if ($p['parent_id'] !== null) {
        $childrenOf[$p['parent_id']][] = $p;
    }
}

$sectionId = $parent['id'] ?? $page['id'];
$sidebar = $article ? [] : all('SELECT titel, menue_titel, slug FROM seiten WHERE parent_id = ? AND veroeffentlicht = 1 ORDER BY sortierung, titel', [$sectionId]);

require __DIR__ . '/templates/site.php';
