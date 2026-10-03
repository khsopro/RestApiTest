<?php
/** @var string $title @var string $content @var array $modules @var string $m */
$bare ??= false;
$printView ??= false;
$user = current_user();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> – Verwaltung</title>
    <link rel="stylesheet" href="assets/admin.css?v=<?= CMS_VERSION ?>">
</head>
<body class="<?= $bare ? 'bare' : '' ?><?= $printView ? ' print-view' : '' ?>">
<?php if ($bare || $printView): ?>
    <?php if ($printView): ?>
        <div class="print-actions no-print"><button onclick="window.print()" class="btn">Drucken</button> <a href="javascript:history.back()">Zurück</a></div>
    <?php endif; ?>
    <?= $content ?>
<?php else: ?>
<div class="admin">
    <aside class="side">
        <a class="side-brand" href="<?= e(url_admin()) ?>">
            <svg viewBox="0 0 40 40" aria-hidden="true"><path d="M14 4h12v10h10v12H26v10H14V26H4V14h10z" fill="#E60005"/></svg>
            <span><?= e(setting('seitentitel')) ?></span>
        </a>
        <nav>
            <?php foreach ($modules as $key => $mod): [$role, $label, $icon] = $mod; $isSub = $mod[3] ?? false;
                if ($role !== '' && !has_role($role)) continue; ?>
                <a href="<?= e(url_admin($key)) ?>" class="<?= $key === $m ? 'active' : '' ?><?= $isSub ? ' sub' : '' ?>"><span class="ico"><?= $icon ?></span><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <a href="index.php" target="_blank">Website ansehen ↗</a>
            <form method="post" action="<?= e(url_admin('logout')) ?>"><?= csrf_field() ?><button class="link">Abmelden (<?= e($user['benutzername']) ?>)</button></form>
        </div>
    </aside>
    <main class="body">
        <?php foreach (take_flashes() as $f): ?>
            <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
<?php endif; ?>
<script src="assets/admin.js?v=<?= CMS_VERSION ?>"></script>
</body>
</html>
