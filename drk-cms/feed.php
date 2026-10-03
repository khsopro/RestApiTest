<?php
declare(strict_types=1);

/** RSS-Feed „Aktuelles“ – z. B. für Feed-Reader oder die Einbindung beim Kreisverband */
require __DIR__ . '/src/bootstrap.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
[$items] = news_list(20);
$x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<rss version="2.0">
<channel>
    <title><?= $x(setting('seitentitel') . ' – Aktuelles') ?></title>
    <link><?= $x($base) ?></link>
    <description><?= $x(setting('untertitel')) ?></description>
    <language>de-de</language>
<?php foreach ($items as $n): ?>
    <item>
        <title><?= $x($n['titel']) ?></title>
        <link><?= $x($base . url_news($n['slug'])) ?></link>
        <guid isPermaLink="true"><?= $x($base . url_news($n['slug'])) ?></guid>
        <pubDate><?= $x(date(DATE_RSS, (int)strtotime($n['datum'] . ' 08:00'))) ?></pubDate>
<?php if ($n['kategorie']): ?>        <category><?= $x($n['kategorie']) ?></category>
<?php endif; ?>
        <description><?= $x(news_teaser($n, 400)) ?></description>
    </item>
<?php endforeach; ?>
</channel>
</rss>
