<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (is_post()) {
    csrf_check();
}

$m = (string)get('m', 'dashboard');
$a = (string)get('a', '');
$title = '';
$printView = false;

/* ---------- Ersteinrichtung ---------- */
if ((int)val('SELECT COUNT(*) FROM benutzer') === 0) {
    require __DIR__ . '/src/setup.php';
    $error = null;
    if (is_post()) {
        $pw = (string)($_POST['passwort'] ?? '');
        $error = password_ok($pw);
        if (!$error && $pw !== ($_POST['passwort2'] ?? '')) {
            $error = 'Die Passwörter stimmen nicht überein.';
        }
        if (!$error && (post('benutzername') === '' || post('verein') === '')) {
            $error = 'Bitte alle Felder ausfüllen.';
        }
        if (!$error) {
            db()->beginTransaction();
            seed_defaults((string)post('verein'));
            $uid = insert('benutzer', [
                'benutzername' => post('benutzername'), 'name' => post('name'), 'email' => post('email'),
                'passwort_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'rollen' => 'admin', 'aktiv' => 1, 'erstellt' => now(),
            ]);
            db()->commit();
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            flash('Willkommen! Die Grundeinrichtung ist abgeschlossen. Beispielseiten und -rezepte wurden angelegt.');
            redirect(url_admin());
        }
    }
    $title = 'Ersteinrichtung';
    ob_start(); ?>
    <div class="login-box wide">
        <h1>DRK-CMS einrichten</h1>
        <p>Legen Sie den Namen Ihres Ortsvereins und das erste Administratorkonto an. Beispielseiten werden automatisch erstellt.</p>
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <?= form_fields([
                ['verein', 'Name des Ortsvereins', 'text', ['required' => true, 'wide' => true, 'default' => 'DRK-Ortsverein Musterstadt']],
                ['name', 'Ihr Name', 'text'],
                ['email', 'Ihre E-Mail', 'email'],
                ['benutzername', 'Benutzername', 'text', ['required' => true]],
                ['passwort', 'Passwort (mind. 10 Zeichen)', 'password', ['required' => true]],
                ['passwort2', 'Passwort wiederholen', 'password', ['required' => true]],
            ], array_diff_key($_POST, ['passwort' => 1, 'passwort2' => 1])) ?>
            <div class="actions wide"><button class="btn">Einrichten</button></div>
        </form>
    </div>
    <?php $content = ob_get_clean();
    $bare = true;
    require __DIR__ . '/templates/admin.php';
    exit;
}

/* ---------- Anmeldung ---------- */
if ($m === 'login') {
    $error = null;
    if (is_post()) {
        if (attempt_login((string)post('benutzername'), (string)($_POST['passwort'] ?? ''))) {
            redirect(url_admin());
        }
        $error = 'Benutzername oder Passwort falsch.';
    }
    $title = 'Anmelden';
    ob_start(); ?>
    <div class="login-box">
        <svg class="logo" viewBox="0 0 40 40" aria-hidden="true"><path d="M14 4h12v10h10v12H26v10H14V26H4V14h10z" fill="#E60005"/></svg>
        <h1><?= e(setting('seitentitel')) ?></h1>
        <p>Anmeldung zum internen Bereich</p>
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field"><label for="u">Benutzername</label><input id="u" name="benutzername" autocomplete="username" required autofocus></div>
            <div class="field"><label for="p">Passwort</label><input id="p" type="password" name="passwort" autocomplete="current-password" required></div>
            <button class="btn btn-block">Anmelden</button>
        </form>
        <p><a href="index.php">← Zur Website</a></p>
    </div>
    <?php $content = ob_get_clean();
    $bare = true;
    require __DIR__ . '/templates/admin.php';
    exit;
}

if ($m === 'logout' && is_post()) {
    logout();
    redirect('index.php');
}

/* ---------- Module ---------- */
$modules = [
    'dashboard'     => ['',           'Übersicht',     '⌂'],
    'seiten'        => ['redaktion',  'Seiten',        '▤'],
    'news'          => ['redaktion',  'Aktuelles',     '✎'],
    'medien'        => ['redaktion|oeffentlichkeit', 'Bilder & Dateien', '▣'],
    'social'        => ['redaktion|oeffentlichkeit', 'Social Media', '✆'],
    'mitglieder'    => ['verwaltung', 'Mitglieder',    '☺'],
    'unterstuetzer' => ['verwaltung', 'Unterstützer',  '★'],
    'blutspende'    => ['blutspende', 'Blutspende',    '♥'],
    'rezepte'       => ['blutspende', 'Rezepte',       '☕', true],
    'helferprofile' => ['blutspende', 'Helfer-Profile', '☺', true],
    'stellen'       => ['blutspende|helfer', 'Stellenbeschreibungen', '☷', true],
    'ehrenamt'      => ['blutspende|verwaltung', 'Ehrenamtsstunden', '⌛', true],
    'profil'        => ['',           'Mein Profil',   '⚙'],
    'benutzer'      => ['admin',      'Benutzer',      '⚿'],
    'einstellungen' => ['admin',      'Einstellungen', '⚒'],
    'protokoll'     => ['admin',      'Protokoll',     '☰'],
];

if (!current_user()) {
    redirect(url_admin('login'));
}
if (!isset($modules[$m])) {
    $m = 'dashboard';
}
if ($modules[$m][0] !== '') {
    require_role($modules[$m][0]);
}

$title = $modules[$m][1];
ob_start();
require __DIR__ . '/src/admin/' . $m . '.php';
$content = ob_get_clean();
require __DIR__ . '/templates/admin.php';
