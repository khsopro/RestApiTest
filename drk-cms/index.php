<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if ((int)val('SELECT COUNT(*) FROM benutzer') === 0) {
    redirect('admin.php'); // Ersteinrichtung
}

$slug = (string)get('seite', home_slug());
$canPreview = current_user() && has_role('redaktion');
$page = one('SELECT * FROM seiten WHERE slug = ?' . ($canPreview ? '' : ' AND veroeffentlicht = 1'), [$slug]);

if (!$page) {
    http_response_code(404);
    $page = ['id' => 0, 'titel' => 'Seite nicht gefunden', 'slug' => '', 'parent_id' => null, 'layout' => 'standard',
        'farbe' => 'rot', 'hero_bild' => null, 'hero_text' => '', 'beschreibung' => '', 'veroeffentlicht' => 1, 'ist_startseite' => 0];
    $blocks = [['typ' => 'text', 'daten' => json_encode(['inhalt' => "Die angeforderte Seite gibt es leider nicht (mehr).\n\n[Zur Startseite](" . url_page('') . ")"])]];
} else {
    $blocks = all('SELECT * FROM seiten_bloecke WHERE seite_id = ? ORDER BY sortierung, id', [$page['id']]);
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

$parent = $page['parent_id'] ? one('SELECT * FROM seiten WHERE id = ?', [$page['parent_id']]) : null;
$sectionId = $parent['id'] ?? $page['id'];
$sidebar = all('SELECT titel, menue_titel, slug FROM seiten WHERE parent_id = ? AND veroeffentlicht = 1 ORDER BY sortierung, titel', [$sectionId]);

require __DIR__ . '/templates/site.php';
