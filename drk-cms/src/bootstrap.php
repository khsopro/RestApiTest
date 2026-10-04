<?php
declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', '1.0.0');

$configFile = CMS_ROOT . '/config.php';
$GLOBALS['cms_config'] = file_exists($configFile)
    ? require $configFile
    : require CMS_ROOT . '/config.sample.php';

if (!empty($GLOBALS['cms_config']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    session_name('drkcms');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/markdown.php';
require __DIR__ . '/blocks.php';
require __DIR__ . '/blutspende.php';
require __DIR__ . '/schichten.php';
require __DIR__ . '/news.php';
require __DIR__ . '/social.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
