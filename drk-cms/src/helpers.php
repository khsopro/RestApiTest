<?php
declare(strict_types=1);

/** HTML-Ausgabe sicher maskieren */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['cms_config'][$key] ?? $default;
}

/* ---------- Datenbank-Kurzformen ---------- */

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Tabellen- und Spaltennamen stammen immer aus dem Code, niemals aus Benutzereingaben. */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')';
    q($sql, $data);
    return (int)db()->lastInsertId();
}

function update(string $table, array $data, int $id): void
{
    $sets = implode(', ', array_map(fn($c) => $c . ' = :' . $c, array_keys($data)));
    $data['__id'] = $id;
    q('UPDATE ' . $table . ' SET ' . $sets . ' WHERE id = :__id', $data);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/* ---------- Einstellungen ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = [];
        foreach (all('SELECT schluessel, wert FROM einstellungen') as $r) {
            $cache[$r['schluessel']] = (string)$r['wert'];
        }
        if ($key === '__reset') {
            return '';
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    q('DELETE FROM einstellungen WHERE schluessel = ?', [$key]);
    insert('einstellungen', ['schluessel' => $key, 'wert' => $value]);
    setting('__reset');
}

function setting_lines(string $key): array
{
    return array_values(array_filter(array_map('trim', explode("\n", setting($key)))));
}

/* ---------- Request / Response ---------- */

function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function post(string $key, mixed $default = ''): mixed
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $message, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF-Schutz ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden und erneut versuchen.');
    }
}

/* ---------- URLs ---------- */

function url_page(string $slug): string
{
    if ($slug === '' || $slug === home_slug()) {
        return cfg('pretty_urls') ? './' : 'index.php';
    }
    return cfg('pretty_urls') ? rawurlencode($slug) : 'index.php?seite=' . rawurlencode($slug);
}

function url_admin(string $module = 'dashboard', string $action = '', array $params = []): string
{
    $p = ['m' => $module];
    if ($action !== '') {
        $p['a'] = $action;
    }
    return 'admin.php?' . http_build_query($p + $params);
}

function home_slug(): string
{
    static $slug = null;
    if ($slug === null) {
        $slug = (string)(val('SELECT slug FROM seiten WHERE ist_startseite = 1 ORDER BY id LIMIT 1') ?? 'start');
    }
    return $slug;
}

function media_url(?int $id): string
{
    if (!$id) {
        return '';
    }
    $f = val('SELECT dateiname FROM medien WHERE id = ?', [$id]);
    return $f ? 'uploads/' . rawurlencode((string)$f) : '';
}

function media_alt(?int $id): string
{
    return $id ? (string)(val('SELECT alt_text FROM medien WHERE id = ?', [$id]) ?? '') : '';
}

/* ---------- Formatierung ---------- */

function date_de(?string $iso, bool $withWeekday = false): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return e($iso);
    }
    $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    return ($withWeekday ? $days[(int)date('w', $ts)] . ', ' : '') . date('d.m.Y', $ts);
}

function datetime_de(?string $iso): string
{
    return $iso ? date('d.m.Y H:i', strtotime($iso)) : '';
}

function num_de(float $n, int $maxDecimals = 2): string
{
    $s = number_format($n, $maxDecimals, ',', '.');
    if ($maxDecimals > 0) {
        $s = rtrim(rtrim($s, '0'), ',');
    }
    return $s;
}

function parse_num(string $s): float
{
    $s = str_replace(' ', '', $s);
    if (str_contains($s, ',')) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

function slugify(string $text): string
{
    $text = mb_strtolower($text);
    $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'seite';
}

function age(?string $birth): ?int
{
    if (!$birth || !strtotime($birth)) {
        return null;
    }
    return (new DateTime($birth))->diff(new DateTime('today'))->y;
}

/** Protokoll für Nachvollziehbarkeit (DSGVO Rechenschaftspflicht) */
function audit(string $action, string $object = ''): void
{
    $u = current_user();
    insert('protokoll', [
        'zeit'     => now(),
        'benutzer' => $u['benutzername'] ?? '-',
        'aktion'   => mb_substr($action, 0, 200),
        'objekt'   => mb_substr($object, 0, 200),
    ]);
}

/* ---------- Generische Formularfelder ---------- */

/**
 * Feldspezifikation: [name, label, typ, optionen]
 * Typen: text, email, tel, date, time, number, textarea, select, checkbox, checklist
 */
function form_field(array $f, array $row): string
{
    [$name, $label, $type] = $f;
    $opts = $f[3] ?? [];
    $value = $row[$name] ?? ($opts['default'] ?? '');
    $req = !empty($opts['required']) ? ' required' : '';
    $cls = 'field' . (!empty($opts['wide']) ? ' wide' : '');
    $id = 'f_' . $name;
    $help = !empty($opts['help']) ? '<small>' . e($opts['help']) . '</small>' : '';
    $html = '<div class="' . $cls . '">';

    switch ($type) {
        case 'textarea':
            $html .= '<label for="' . $id . '">' . e($label) . '</label><textarea id="' . $id . '" name="' . e($name)
                . '" rows="' . ($opts['rows'] ?? 4) . '"' . $req . '>' . e($value) . '</textarea>';
            break;
        case 'select':
            $html .= '<label for="' . $id . '">' . e($label) . '</label><select id="' . $id . '" name="' . e($name) . '"' . $req . '>';
            foreach ($opts['options'] as $k => $v) {
                $key = is_int($k) && !($opts['assoc'] ?? false) ? $v : $k;
                $html .= '<option value="' . e($key) . '"' . ((string)$key === (string)$value ? ' selected' : '') . '>' . e($v) . '</option>';
            }
            $html .= '</select>';
            break;
        case 'checkbox':
            $html .= '<label class="check"><input type="checkbox" name="' . e($name) . '" value="1"'
                . ((int)$value === 1 ? ' checked' : '') . '> ' . e($label) . '</label>';
            break;
        case 'checklist':
            $selected = array_map('trim', explode(',', (string)$value));
            $html .= '<fieldset><legend>' . e($label) . '</legend><div class="checklist">';
            foreach ($opts['options'] as $opt) {
                $html .= '<label class="check"><input type="checkbox" name="' . e($name) . '[]" value="' . e($opt) . '"'
                    . (in_array($opt, $selected, true) ? ' checked' : '') . '> ' . e($opt) . '</label>';
            }
            $html .= '</div></fieldset>';
            break;
        default:
            $step = $type === 'number' ? ' step="' . e($opts['step'] ?? '1') . '"' : '';
            $html .= '<label for="' . $id . '">' . e($label) . '</label><input id="' . $id . '" type="' . e($type)
                . '" name="' . e($name) . '" value="' . e($value) . '"' . $step . $req . '>';
    }
    return $html . $help . '</div>';
}

function form_fields(array $fields, array $row): string
{
    return implode('', array_map(fn($f) => form_field($f, $row), $fields));
}

/** Liest die Felder aus $_POST. Nur die im Code definierten Felder werden übernommen. */
function form_collect(array $fields): array
{
    $data = [];
    foreach ($fields as $f) {
        [$name, , $type] = $f;
        $data[$name] = match ($type) {
            'checkbox'  => isset($_POST[$name]) ? 1 : 0,
            'checklist' => implode(', ', array_filter(array_map('strval', (array)($_POST[$name] ?? [])), 'strlen')),
            'number'    => post($name) === '' ? null : parse_num((string)post($name)),
            'date', 'time' => post($name) === '' ? null : post($name),
            default     => (string)post($name),
        };
    }
    return $data;
}

/** Kleine Schaltfläche, die eine POST-Aktion (mit CSRF-Token) auslöst */
function post_button(string $action, string $label, array $fields = [], string $title = '', bool $disabled = false, string $confirm = '', string $class = 'btn btn-small btn-ghost'): string
{
    $html = '<form method="post" action="' . e($action) . '" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $html . '<button class="' . e($class) . '" title="' . e($title) . '"' . ($disabled ? ' disabled' : '') . '>' . e($label) . '</button></form>';
}
