<?php
/**
 * Beispielkonfiguration. Kopieren nach config.php und anpassen.
 * Ohne config.php werden diese Standardwerte verwendet (SQLite im Ordner data/).
 */
return [
    // SQLite (Standard, keine Einrichtung nötig):
    'db_dsn'  => 'sqlite:' . __DIR__ . '/data/cms.sqlite',
    'db_user' => null,
    'db_pass' => null,
    // MySQL/MariaDB (z. B. beim Webhoster):
    // 'db_dsn'  => 'mysql:host=localhost;dbname=drk_cms;charset=utf8mb4',
    // 'db_user' => 'drk_cms',
    // 'db_pass' => 'geheim',

    // Schöne URLs (/blutspende statt index.php?seite=blutspende).
    // Nur aktivieren, wenn der Server .htaccess / mod_rewrite unterstützt.
    'pretty_urls' => false,

    // Maximale Größe für hochgeladene Dateien in MB
    'upload_max_mb' => 8,

    // Fehlerausgabe (nur zur Entwicklung auf true setzen)
    'debug' => false,
];
