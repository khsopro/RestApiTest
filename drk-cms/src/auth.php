<?php
declare(strict_types=1);

/** Rollen und was sie dürfen. "admin" darf alles. */
function roles(): array
{
    return [
        'admin'      => 'Administrator/in (alles)',
        'redaktion'  => 'Redaktion (Seiten & Medien)',
        'verwaltung' => 'Mitglieder- & Unterstützerverwaltung',
        'blutspende' => 'Blutspende-Team (Termine, Rezepte, Einteilung)',
        'oeffentlichkeit' => 'Öffentlichkeitsarbeit (Social Media, Kampagnen)',
        'helfer'     => 'Helfer/in (nur eigenes Profil & eigene Einsätze)',
    ];
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = $_SESSION['uid'] ?? null;
        $user = $id ? one('SELECT * FROM benutzer WHERE id = ? AND aktiv = 1', [$id]) : null;
    }
    return $user;
}

function user_roles(?array $u = null): array
{
    $u ??= current_user();
    return $u ? array_filter(array_map('trim', explode(',', $u['rollen']))) : [];
}

/** $role darf mehrere Rollen mit | enthalten („blutspende|helfer“ = eine davon genügt) */
function has_role(string $role): bool
{
    if (str_contains($role, '|')) {
        foreach (explode('|', $role) as $one) {
            if (has_role($one)) {
                return true;
            }
        }
        return false;
    }
    $r = user_roles();
    return in_array('admin', $r, true) || in_array($role, $r, true);
}

function attempt_login(string $username, string $password): bool
{
    $_SESSION['login_fails'] ??= 0;
    if ($_SESSION['login_fails'] >= 5) {
        sleep(2); // Brute-Force bremsen
    }
    $u = one('SELECT * FROM benutzer WHERE benutzername = ? AND aktiv = 1', [$username]);
    if (!$u || !password_verify($password, $u['passwort_hash'])) {
        $_SESSION['login_fails']++;
        return false;
    }
    if (password_needs_rehash($u['passwort_hash'], PASSWORD_DEFAULT)) {
        update('benutzer', ['passwort_hash' => password_hash($password, PASSWORD_DEFAULT)], (int)$u['id']);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['login_fails'] = 0;
    update('benutzer', ['letzter_login' => now()], (int)$u['id']);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function require_role(string $role): void
{
    if (!current_user()) {
        redirect(url_admin('login'));
    }
    if (!has_role($role)) {
        http_response_code(403);
        exit('Keine Berechtigung für diesen Bereich.');
    }
}

function password_ok(string $pw): ?string
{
    return mb_strlen($pw) < 10 ? 'Das Passwort muss mindestens 10 Zeichen lang sein.' : null;
}
