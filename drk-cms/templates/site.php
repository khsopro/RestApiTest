<?php
/** @var array $page @var array $blocks @var array $top @var array $childrenOf @var ?array $parent @var array $sidebar */
$siteTitle = setting('seitentitel', 'DRK-Ortsverein');
$logoId = (int)setting('logo');
$heroUrl = media_url($page['hero_bild'] ? (int)$page['hero_bild'] : null);
$isHome = (int)$page['ist_startseite'] === 1;
$layout = $page['layout'] ?: 'standard';
$activeTop = $parent['id'] ?? $page['id'];
$footerPages = all('SELECT titel, slug FROM seiten WHERE veroeffentlicht = 1 AND im_menue = 0 AND ist_startseite = 0 AND parent_id IS NULL ORDER BY sortierung');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($isHome ? $siteTitle : $page['titel'] . ' – ' . $siteTitle) ?></title>
    <?php if ($page['beschreibung']): ?><meta name="description" content="<?= e($page['beschreibung']) ?>"><?php endif; ?>
    <link rel="stylesheet" href="assets/site.css?v=<?= CMS_VERSION ?>">
</head>
<body class="layout-<?= e($layout) ?> accent-<?= e($page['farbe'] ?: 'rot') ?>">
<a class="skip" href="#inhalt">Zum Inhalt springen</a>

<?php if ((int)$page['veroeffentlicht'] === 0): ?>
    <div class="preview-bar">Vorschau – diese Seite ist noch nicht veröffentlicht. <a href="<?= e(url_admin('seiten', 'bearbeiten', ['id' => $page['id']])) ?>">Bearbeiten</a></div>
<?php endif; ?>

<div class="topbar">
    <div class="wrap">
        <span><?= e(setting('notruf_hinweis')) ?></span>
        <a href="admin.php"><?= current_user() ? 'Verwaltung' : 'Mitglieder-Login' ?></a>
    </div>
</div>

<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="<?= e(url_page('')) ?>">
            <?php if ($logoId && media_url($logoId)): ?>
                <img src="<?= e(media_url($logoId)) ?>" alt="<?= e($siteTitle) ?>" class="logo-img">
            <?php else: ?>
                <svg class="logo" viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" fill="#fff"/><path d="M14 4h12v10h10v12H26v10H14V26H4V14h10z" fill="#E60005"/></svg>
                <span class="brand-text"><strong><?= e($siteTitle) ?></strong><small><?= e(setting('untertitel')) ?></small></span>
            <?php endif; ?>
        </a>
        <button class="nav-toggle" aria-expanded="false" aria-controls="hauptmenue">☰ <span>Menü</span></button>
        <nav id="hauptmenue" class="main-nav" aria-label="Hauptnavigation">
            <ul>
                <?php foreach ($top as $item): $kids = $childrenOf[$item['id']] ?? []; ?>
                    <li class="<?= $item['id'] == $activeTop ? 'active' : '' ?><?= $kids ? ' has-sub' : '' ?>">
                        <a href="<?= e(url_page($item['slug'])) ?>"><?= e($item['menue_titel'] ?: $item['titel']) ?></a>
                        <?php if ($kids): ?>
                            <ul class="sub">
                                <?php foreach ($kids as $k): ?>
                                    <li><a href="<?= e(url_page($k['slug'])) ?>"><?= e($k['menue_titel'] ?: $k['titel']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>

<?php if ($heroUrl || $isHome || $page['hero_text']): ?>
    <section class="hero<?= $heroUrl ? ' has-image' : '' ?>"<?= $heroUrl ? ' style="--hero:url(\'' . e($heroUrl) . '\')"' : '' ?>>
        <div class="wrap">
            <h1><?= e($isHome ? $siteTitle : $page['titel']) ?></h1>
            <?php if ($page['hero_text']): ?><p><?= e($page['hero_text']) ?></p><?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <div class="page-head">
        <div class="wrap">
            <?php if ($parent): ?>
                <nav class="crumbs" aria-label="Brotkrumen"><a href="<?= e(url_page('')) ?>">Start</a> › <a href="<?= e(url_page($parent['slug'])) ?>"><?= e($parent['titel']) ?></a> › <span><?= e($page['titel']) ?></span></nav>
            <?php endif; ?>
            <h1><?= e($page['titel']) ?></h1>
        </div>
    </div>
<?php endif; ?>

<main id="inhalt" class="wrap main">
    <?php if ($layout === 'seitenleiste' && $sidebar): ?>
        <aside class="sidebar">
            <h2><?= e($parent['titel'] ?? $page['titel']) ?></h2>
            <ul>
                <?php foreach ($sidebar as $s): ?>
                    <li class="<?= $s['slug'] === $page['slug'] ? 'active' : '' ?>"><a href="<?= e(url_page($s['slug'])) ?>"><?= e($s['menue_titel'] ?: $s['titel']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </aside>
    <?php endif; ?>
    <div class="content">
        <?php foreach ($blocks as $b): $html = render_block($b, $page); if ($html === '') continue; ?>
            <section class="block block-<?= e($b['typ']) ?>"><?= $html ?></section>
        <?php endforeach; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="wrap footer-grid">
        <div>
            <h2><?= e($siteTitle) ?></h2>
            <div class="prose"><?= md(setting('kontakt_text')) ?></div>
        </div>
        <div>
            <h2>Seiten</h2>
            <ul>
                <?php foreach ($top as $item): ?><li><a href="<?= e(url_page($item['slug'])) ?>"><?= e($item['menue_titel'] ?: $item['titel']) ?></a></li><?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h2>Rechtliches</h2>
            <ul>
                <?php foreach ($footerPages as $fp): ?><li><a href="<?= e(url_page($fp['slug'])) ?>"><?= e($fp['titel']) ?></a></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="wrap footer-bottom">© <?= date('Y') ?> <?= e(setting('fusszeile', $siteTitle)) ?></div>
</footer>
<script>
document.querySelector('.nav-toggle')?.addEventListener('click', function () {
    var open = document.body.classList.toggle('nav-open');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
});
</script>
</body>
</html>
