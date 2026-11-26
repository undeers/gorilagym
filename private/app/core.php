<?php
/**
 * GorilaGym · núcleo: configuración, BD, sesión, seguridad, helpers.
 */
declare(strict_types=1);

define('APP_VERSION', '1.6.0');
define('PRIVATE_DIR', dirname(__DIR__));
define('ROOT_DIR', dirname(PRIVATE_DIR));
// Ruta de config.php. En Docker se apunta al volumen persistente con GG_CONFIG_FILE.
define('CONFIG_FILE', getenv('GG_CONFIG_FILE') ?: PRIVATE_DIR . '/config.php');

// ---------------------------------------------------------------- Config
function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = CONFIG_FILE;
        $cfg = is_file($file) ? (require $file) : [];
    }
    if ($key === null) return $cfg;
    return $cfg[$key] ?? $default;
}

function is_installed(): bool
{
    return is_file(CONFIG_FILE) && is_file(PRIVATE_DIR . '/storage/install.lock');
}

// ---------------------------------------------------------------- Errores
function boot_errors(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', config('debug') ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', PRIVATE_DIR . '/storage/logs/php-error.log');
    date_default_timezone_set(config('timezone', 'America/Santiago'));
}

// ---------------------------------------------------------------- BD
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = config('db_path', PRIVATE_DIR . '/storage/db/gorila.sqlite');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q1(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function qv(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}
function qa(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}
function update(string $table, array $data, string $where, array $wparams = []): int
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    return q("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $wparams))->rowCount();
}
function tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Aplica migraciones nuevas al subir una versión nueva (chequeo barato por cantidad de archivos). */
function auto_migrate(): void
{
    $n = count(glob(PRIVATE_DIR . '/migrations/*.sql') ?: []);
    if ((int) setting('migraciones_n', '0') === $n) return;
    $lock = fopen(PRIVATE_DIR . '/storage/cache/migrate.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return;
    try {
        $hechas = run_migrations();
        setting_set('migraciones_n', (string) $n);
        if ($hechas) error_log('Migraciones aplicadas: ' . implode(', ', $hechas));
    } finally {
        flock($lock, LOCK_UN);
    }
}

function run_migrations(): array
{
    db()->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TEXT)');
    $done = array_column(qa('SELECT version FROM migrations'), 'version');
    $files = glob(PRIVATE_DIR . '/migrations/*.sql');
    sort($files);
    $applied = [];
    foreach ($files as $f) {
        $v = basename($f, '.sql');
        if (in_array($v, $done, true)) continue;
        db()->exec(file_get_contents($f));
        insert('migrations', ['version' => $v, 'applied_at' => date('Y-m-d H:i:s')]);
        $applied[] = $v;
    }
    return $applied;
}

// ---------------------------------------------------------------- Settings
function &settings_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (qa('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
        } catch (Throwable $e) {
            $cache = null;
            $empty = [];
            return $empty;
        }
    }
    return $cache;
}
function setting(string $key, $default = null)
{
    $c = &settings_cache();
    return $c[$key] ?? $default;
}
function setting_set(string $key, $value): void
{
    q('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, (string) $value]);
    $c = &settings_cache();
    $c[$key] = (string) $value;
}

// ---------------------------------------------------------------- URLs
function base_url(): string
{
    return rtrim((string) config('base_url', ''), '/');
}
function base_path(): string
{
    $p = parse_url(base_url(), PHP_URL_PATH) ?: '';
    return rtrim($p, '/');
}
function url(string $route = '', array $params = []): string
{
    $qs = $route !== '' ? ['r' => $route] + $params : $params;
    return base_path() . '/' . ($qs ? '?' . http_build_query($qs) : '');
}
function abs_url(string $route = '', array $params = []): string
{
    $qs = $route !== '' ? ['r' => $route] + $params : $params;
    return base_url() . '/' . ($qs ? '?' . http_build_query($qs) : '');
}
function asset(string $path): string
{
    $f = ROOT_DIR . '/' . ltrim($path, '/');
    $v = is_file($f) ? substr(md5((string) filemtime($f)), 0, 8) : APP_VERSION;
    return base_path() . '/' . ltrim($path, '/') . '?v=' . $v;
}
function media_url(?string $rel): ?string
{
    if (!$rel) return null;
    return base_path() . '/media/' . ltrim($rel, '/');
}

// ---------------------------------------------------------------- Seguridad HTTP
function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://i.scdn.co https://*.mzstatic.com; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-src https://open.spotify.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// ---------------------------------------------------------------- Sesión
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('gg_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (base_path() ?: '') . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    $dir = PRIVATE_DIR . '/storage/cache/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        if (is_api()) json_out(['ok' => false, 'error' => 'Sesión expirada. Recarga la página.'], 419);
        http_response_code(419);
        exit('Sesión expirada. Vuelve atrás y recarga la página.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
function is_api(): bool
{
    return str_starts_with((string) ($_GET['r'] ?? ''), 'api/');
}

// ---------------------------------------------------------------- Auth
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) $id = remember_login();
    if ($id) {
        $row = q1('SELECT * FROM users WHERE id = ? AND activo = 1', [$id]);
        if ($row && sesion_unica_aplica($row) && !hash_equals((string) ($row['session_token'] ?? ''), (string) ($_SESSION['stok'] ?? ''))) {
            // Se inició sesión en otro dispositivo: cerrar esta
            logout_user(false);
            flash('warning', 'Tu cuenta se abrió en otro dispositivo, así que cerramos la sesión aquí. Solo puedes tener una sesión activa.');
            $row = null;
        }
        if ($row && ($bloqueo = alumno_app_bloqueo($row)) !== null) {
            // Su plan ya no incluye la app
            logout_user(false);
            flash('error', $bloqueo);
            $row = null;
        }
        if ($row) $u = $row;
        else unset($_SESSION['uid']);
    }
    return $u;
}
/** Tipo del plan del alumno ("coach" | "libre"), mirando la membresía vigente o, si no hay, la última. null = sin plan. */
function alumno_plan_tipo(int $uid): ?string
{
    static $cache = [];
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $hoy = date('Y-m-d');
    $sql = 'SELECT p.tipo FROM membresias m JOIN planes p ON p.id = m.plan_id WHERE m.user_id = ? ';
    $tipo = qv($sql . 'AND m.fecha_inicio <= ? ORDER BY (m.fecha_fin >= ?) DESC, m.fecha_fin DESC LIMIT 1', [$uid, $hoy, $hoy])
        ?: qv($sql . 'ORDER BY m.fecha_inicio DESC LIMIT 1', [$uid]);
    return $cache[$uid] = $tipo ?: null;
}
const MSG_SOLO_SEMIPERSONALIZADOS = 'La app de Gorila es para los planes semipersonalizados (con coach). Tu plan no la incluye: si quieres cambiarte, pregunta en recepción.';
/** Motivo por el que un alumno no puede usar la app, o null si puede. */
function alumno_app_bloqueo(array $u): ?string
{
    if ($u['rol'] !== 'alumno') return null;
    $tipo = alumno_plan_tipo((int) $u['id']);
    if ($tipo === 'coach') return null;
    if ($tipo === null) return 'No encontramos un plan a tu nombre. Pregunta en recepción.';
    return setting('app_plan_libre', 'limitado') === 'bloqueado' ? MSG_SOLO_SEMIPERSONALIZADOS : null;
}
function alumno_app_permitido(array $u): bool
{
    return alumno_app_bloqueo($u) === null;
}
/** Plan sin coach: solo entrenos predeterminados del gimnasio y muro. Ningún coach lo entrena. */
function alumno_solo_plantillas(array $u): bool
{
    return $u['rol'] === 'alumno' && alumno_plan_tipo((int) $u['id']) === 'libre';
}
function sesion_unica_aplica(array $u): bool
{
    return $u['rol'] === 'alumno' ? setting('sesion_unica_alumnos', '1') === '1' : setting('sesion_unica_staff', '0') === '1';
}
function uid(): int
{
    return (int) (current_user()['id'] ?? 0);
}
function login_user(array $user, bool $remember = true): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['perm_v'] = null;
    unset($_SESSION['csrf']);
    $stok = bin2hex(random_bytes(16));
    $_SESSION['stok'] = $stok;
    update('users', ['last_login' => date('Y-m-d H:i:s'), 'session_token' => $stok], 'id = ?', [$user['id']]);
    if (sesion_unica_aplica($user)) {
        // Un solo dispositivo: invalidar "recordarme" y suscripciones push de los demás dispositivos
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$user['id']]);
        q('DELETE FROM push_subs WHERE user_id = ?', [$user['id']]);
    }
    if ($remember) remember_issue((int) $user['id']);
    audit('login', 'users', (int) $user['id']);
}
function logout_user(bool $all = false): void
{
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id && $all) {
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$id]);
        update('users', ['session_token' => bin2hex(random_bytes(16))], 'id = ?', [$id]);
    }
    if (!empty($_COOKIE['gg_rem'])) {
        [$sel] = explode(':', (string) $_COOKIE['gg_rem']) + [''];
        q('DELETE FROM remember_tokens WHERE selector = ?', [$sel]);
        setcookie('gg_rem', '', ['expires' => time() - 3600, 'path' => (base_path() ?: '') . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    $_SESSION = [];
    session_regenerate_id(true);
}
function remember_issue(int $userId): void
{
    $sel = bin2hex(random_bytes(9));
    $val = bin2hex(random_bytes(32));
    $days = (int) setting('remember_days', '90');
    insert('remember_tokens', [
        'user_id' => $userId, 'selector' => $sel, 'validator_hash' => hash('sha256', $val),
        'expires_at' => time() + $days * 86400, 'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), 'created_at' => time(),
    ]);
    setcookie('gg_rem', $sel . ':' . $val, [
        'expires' => time() + $days * 86400, 'path' => (base_path() ?: '') . '/',
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}
function remember_login(): ?int
{
    $c = $_COOKIE['gg_rem'] ?? '';
    if (!is_string($c) || !str_contains($c, ':')) return null;
    [$sel, $val] = explode(':', $c, 2);
    $row = q1('SELECT * FROM remember_tokens WHERE selector = ?', [$sel]);
    if (!$row || $row['expires_at'] < time() || !hash_equals($row['validator_hash'], hash('sha256', $val))) return null;
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['user_id'];
    $_SESSION['stok'] = (string) qv('SELECT session_token FROM users WHERE id = ?', [$row['user_id']]);
    return (int) $row['user_id'];
}

// ---------------------------------------------------------------- Permisos
function perms(): array
{
    $u = current_user();
    if (!$u) return [];
    $ver = (string) setting('permissions_version', '1');
    if (($_SESSION['perm_v'] ?? null) !== $ver || ($_SESSION['perm_rol'] ?? null) !== $u['rol']) {
        if ($u['rol'] === 'superadmin') {
            $_SESSION['perms'] = array_column(qa('SELECT key FROM permissions'), 'key');
        } else {
            $_SESSION['perms'] = array_column(qa('SELECT perm_key FROM role_permissions WHERE rol = ?', [$u['rol']]), 'perm_key');
        }
        $_SESSION['perm_v'] = $ver;
        $_SESSION['perm_rol'] = $u['rol'];
    }
    return $_SESSION['perms'] ?? [];
}
function can(string $perm): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['rol'] === 'superadmin') return true;
    return in_array($perm, perms(), true);
}
function can_any(array $list): bool
{
    foreach ($list as $p) if (can($p)) return true;
    return false;
}
function admin_perms(): array
{
    return ['sistema.config', 'sistema.usuarios_staff', 'sistema.respaldos', 'sistema.auditoria', 'planes.gestionar', 'planes.importar', 'planes.pausas', 'planes.acreditar',
        'catalogo.gestionar', 'plantillas.gestionar', 'reportes.gimnasio', 'reportes.adopcion', 'calif.detalle', 'calif.promedios', 'muro.moderar', 'avisos.publicar', 'alumnos.eliminacion', 'alumnos.editar'];
}
function can_admin(): bool
{
    return can_any(admin_perms());
}
function is_staff(): bool
{
    $u = current_user();
    return $u && $u['rol'] !== 'alumno';
}
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        if (is_api()) json_out(['ok' => false, 'error' => 'No autenticado'], 401);
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect(url('login'));
    }
    return $u;
}
function require_perm(string $perm): array
{
    $u = require_login();
    if (!can($perm)) deny();
    return $u;
}
function require_staff(): array
{
    $u = require_login();
    if ($u['rol'] === 'alumno') {
        if (!is_api() && !is_post()) redirect(url('inicio'));
        deny();
    }
    return $u;
}
function deny(string $msg = 'No tienes permiso para esto.'): void
{
    if (is_api()) json_out(['ok' => false, 'error' => $msg], 403);
    http_response_code(403);
    view('shared/error', ['title' => 'Sin acceso', 'msg' => $msg]);
    exit;
}
function not_found(): void
{
    if (is_api()) json_out(['ok' => false, 'error' => 'No encontrado'], 404);
    http_response_code(404);
    view('shared/error', ['title' => 'No encontrado', 'msg' => 'Lo que buscas no existe o fue movido.']);
    exit;
}

// ---------------------------------------------------------------- Rate limit
function rate_limit(string $key, int $max, int $window): bool
{
    $now = time();
    $row = q1('SELECT * FROM rate_limits WHERE key = ?', [$key]);
    if (!$row || $row['window_start'] + $window < $now) {
        q('INSERT INTO rate_limits(key,hits,window_start) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET hits=1, window_start=excluded.window_start', [$key, $now]);
        return true;
    }
    if ($row['hits'] >= $max) return false;
    q('UPDATE rate_limits SET hits = hits + 1 WHERE key = ?', [$key]);
    return true;
}

// ---------------------------------------------------------------- Auditoría
function audit(string $accion, ?string $entidad = null, ?int $id = null, $detalle = null): void
{
    try {
        insert('audit_log', [
            'user_id' => uid() ?: null, 'accion' => $accion, 'entidad' => $entidad, 'entidad_id' => $id,
            'detalle' => $detalle === null ? null : (is_string($detalle) ? $detalle : json_encode($detalle, JSON_UNESCAPED_UNICODE)),
            'ip' => client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('audit: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------- Respuestas
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}
function back(string $fallback = ''): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = parse_url(base_url(), PHP_URL_HOST);
    if ($ref && parse_url($ref, PHP_URL_HOST) === $host) redirect($ref);
    redirect($fallback ?: url());
}
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function old(string $k, $default = '')
{
    return $_POST[$k] ?? $default;
}
function input(string $k, $default = null)
{
    $v = $_POST[$k] ?? $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}
function input_int(string $k, ?int $default = null): ?int
{
    $v = input($k);
    return ($v === null || $v === '') ? $default : (int) $v;
}
function json_body(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input') ?: '';
        $b = json_decode($raw, true);
        if (!is_array($b)) $b = $_POST;
    }
    return $b;
}

// ---------------------------------------------------------------- Vistas
function view(string $__tpl, array $__vars = [], ?string $__layout = 'layout/app'): void
{
    extract($__vars, EXTR_SKIP);
    ob_start();
    require PRIVATE_DIR . '/views/' . $__tpl . '.php';
    $content = ob_get_clean();
    if ($__layout) {
        require PRIVATE_DIR . '/views/' . $__layout . '.php';
    } else {
        echo $content;
    }
}
function partial(string $__tpl, array $__vars = []): void
{
    extract($__vars, EXTR_SKIP);
    require PRIVATE_DIR . '/views/' . $__tpl . '.php';
}
function icon(string $name, string $cls = 'w-5 h-5'): string
{
    static $icons = null;
    if ($icons === null) $icons = require __DIR__ . '/icons.php';
    $inner = $icons[$name] ?? $icons['info'];
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="' . e($cls) . '" aria-hidden="true">' . $inner . '</svg>';
}

function avatar(array $u, string $cls = 'w-9 h-9'): string
{
    if (!empty($u['foto'])) return '<img src="' . e(media_url($u['foto'])) . '" alt="" class="' . e($cls) . ' rounded-full object-cover bg-dark/5 shrink-0" loading="lazy">';
    $ini = mb_strtoupper(mb_substr((string) ($u['nombre'] ?? '?'), 0, 1));
    return '<span class="' . e($cls) . ' rounded-full bg-primary/10 text-primary inline-flex items-center justify-center font-medium text-sm shrink-0">' . e($ini) . '</span>';
}

// ---------------------------------------------------------------- Formatos
function rut_clean(string $rut): string
{
    return strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
}
function rut_dv(string $num): string
{
    $s = 1;
    for ($m = 0; $num != 0; $num = (int) ($num / 10)) $s = ($s + $num % 10 * (9 - $m++ % 6)) % 11;
    return $s ? (string) ($s - 1) : 'K';
}
function rut_valid(string $rut): bool
{
    $c = rut_clean($rut);
    if (strlen($c) < 2) return false;
    $num = substr($c, 0, -1);
    $dv = substr($c, -1);
    if (!ctype_digit($num) || (int) $num < 100000) return false;
    return rut_dv($num) === $dv;
}
function rut_normalize(string $rut): string
{
    $c = rut_clean($rut);
    return ltrim(substr($c, 0, -1), '0') . '-' . substr($c, -1);
}
function rut_format(string $rut): string
{
    $c = rut_clean($rut);
    if (strlen($c) < 2) return $rut;
    $num = ltrim(substr($c, 0, -1), '0');
    return number_format((int) $num, 0, '', '.') . '-' . substr($c, -1);
}
function doc_normalize(string $tipo, string $num): string
{
    return $tipo === 'rut' ? rut_normalize($num) : strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $num));
}
function doc_display(array $u): string
{
    return $u['doc_tipo'] === 'rut' ? rut_format($u['doc_num']) : strtoupper($u['doc_tipo']) . ' ' . $u['doc_num'];
}
function money($n): string
{
    return $n === null || $n === '' ? '—' : '$' . number_format((float) $n, 0, ',', '.');
}
function kg($n): string
{
    if ($n === null || $n === '') return '—';
    $n = (float) $n;
    return (fmod($n, 1.0) == 0.0 ? number_format($n, 0, ',', '.') : number_format($n, 1, ',', '.')) . ' kg';
}
function fecha(?string $d, string $fmt = 'd/m/Y'): string
{
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date($fmt, $t) : '—';
}
function fecha_larga(?string $d): string
{
    if (!$d) return '—';
    $t = strtotime($d);
    $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return date('j', $t) . ' de ' . $m[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}
function mes_nombre(int $m): string
{
    return ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][$m - 1];
}
function dia_nombre(int $d): string
{
    return ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$d];
}
function hace(?string $d): string
{
    if (!$d) return '';
    $s = time() - strtotime($d);
    if ($s < 60) return 'recién';
    if ($s < 3600) return 'hace ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'hace ' . floor($s / 3600) . ' h';
    if ($s < 172800) return 'ayer';
    return 'hace ' . floor($s / 86400) . ' días';
}
function nombre_corto(array $u): string
{
    return trim($u['nombre'] . ' ' . mb_substr((string) ($u['apellido'] ?? ''), 0, 1) . (($u['apellido'] ?? '') ? '.' : ''));
}
function nombre_completo(array $u): string
{
    return trim($u['nombre'] . ' ' . ($u['apellido'] ?? ''));
}
function valid_email(?string $e): bool
{
    return $e !== null && (bool) filter_var($e, FILTER_VALIDATE_EMAIL);
}
function rol_label(string $r): string
{
    return ['superadmin' => 'Super admin', 'jefe' => 'Jefe de turno', 'coach' => 'Coach', 'alumno' => 'Alumno'][$r] ?? $r;
}
function hex_rgb(string $hex): string
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) return '0 0 0';
    return hexdec(substr($h, 0, 2)) . ' ' . hexdec(substr($h, 2, 2)) . ' ' . hexdec(substr($h, 4, 2));
}
function token(int $bytes = 12): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

// ---------------------------------------------------------------- Correo
function send_mail(string $to, string $subject, string $html): bool
{
    if (!valid_email($to)) return false;
    $from = (string) config('mail_from', 'no-reply@localhost');
    $name = (string) setting('brand_nombre', 'Gorila Gym');
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: GorilaGym',
    ];
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body = mail_layout($subject, $html);
    if (config('mail_log_only')) {
        file_put_contents(PRIVATE_DIR . '/storage/logs/mail.log', date('c') . " TO:$to SUBJ:$subject\n" . strip_tags($html) . "\n\n", FILE_APPEND);
        return true;
    }
    $ok = @mail($to, $subj, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) error_log("mail() falló para $to");
    return $ok;
}
function mail_layout(string $title, string $inner): string
{
    $c = setting('brand_primary', '#283614');
    $l = setting('brand_light', '#F5F9E5');
    $d = setting('brand_dark', '#111D13');
    $n = e(setting('brand_nombre', 'Gorila Gym'));
    return '<!doctype html><html><body style="margin:0;background:' . $l . ';font-family:Arial,Helvetica,sans-serif;color:' . $d . '">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px">'
        . '<table width="100%" style="max-width:520px;background:#ffffff;border-radius:8px" cellpadding="0" cellspacing="0">'
        . '<tr><td style="background:' . $c . ';padding:18px 24px;border-radius:8px 8px 0 0;color:' . $l . ';font-size:16px;font-weight:bold">' . $n . '</td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.55">' . $inner . '</td></tr>'
        . '</table><p style="font-size:12px;color:#6b7266;margin-top:16px">' . $n . ' · Correo automático, no respondas este mensaje.</p>'
        . '</td></tr></table></body></html>';
}

// ---------------------------------------------------------------- Uploads
function handle_upload(string $field, string $dir, array $allowed, int $maxBytes): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Error al subir el archivo (código ' . $f['error'] . ').');
    if ($f['size'] > $maxBytes) throw new RuntimeException('El archivo supera el máximo de ' . round($maxBytes / 1048576) . ' MB.');
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Tipo de archivo no permitido (' . $mime . ').');
    $ext = $allowed[$mime];
    $target = ROOT_DIR . '/media/' . $dir;
    if (!is_dir($target)) mkdir($target, 0755, true);
    $name = date('Ymd') . '-' . token(9) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $target . '/' . $name)) throw new RuntimeException('No se pudo guardar el archivo.');
    @chmod($target . '/' . $name, 0644);
    return $dir . '/' . $name;
}
function delete_media(?string $rel): void
{
    if (!$rel || str_contains($rel, '..')) return;
    $p = ROOT_DIR . '/media/' . $rel;
    if (is_file($p)) @unlink($p);
}
const IMG_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
