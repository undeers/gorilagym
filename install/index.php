<?php
/**
 * GorilaGym · instalador.
 * Se bloquea solo después de instalar (private/storage/install.lock).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/app/core.php';
require PRIVATE_DIR . '/app/push.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (is_file(PRIVATE_DIR . '/storage/install.lock')) {
    http_response_code(404);
    exit('GorilaGym ya está instalado. Para reinstalar, elimina private/storage/install.lock y private/config.php (se perderá la configuración, no la base de datos).');
}

date_default_timezone_set('America/Santiago');
session_name('gg_install');
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$https = is_https();
$scheme = $https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/');
$baseGuess = $scheme . '://' . $host . $path;
$domain = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $host));

// ------------------------------------------------------------------ Requisitos
$req = [];
$req[] = ['PHP 8.1 o superior', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION, true];
foreach (['pdo_sqlite' => 'PDO SQLite', 'openssl' => 'OpenSSL', 'mbstring' => 'mbstring', 'fileinfo' => 'fileinfo', 'json' => 'JSON'] as $ext => $n) $req[] = [$n, extension_loaded($ext), extension_loaded($ext) ? 'ok' : 'falta', true];
foreach (['curl' => 'cURL (push más confiable)', 'zip' => 'Zip (leer .xlsx)', 'gd' => 'GD'] as $ext => $n) $req[] = [$n, extension_loaded($ext), extension_loaded($ext) ? 'ok' : 'opcional', false];
$sqliteOk = false;
if (extension_loaded('pdo_sqlite')) {
    $v = (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();
    $sqliteOk = version_compare((string) $v, '3.27.0', '>=');
    $req[] = ['SQLite 3.27+', $sqliteOk, (string) $v, true];
}
$dirs = ['private', 'private/storage', 'private/storage/db', 'private/storage/backups', 'private/storage/imports', 'private/storage/logs', 'private/storage/cache', 'media', 'media/ejercicios', 'media/maquinas', 'media/branding'];
foreach ($dirs as $d) {
    $full = ROOT_DIR . '/' . $d;
    if (!is_dir($full)) @mkdir($full, 0755, true);
    $req[] = ['Escritura en ' . $d . '/', is_dir($full) && is_writable($full), is_writable($full) ? 'ok' : 'sin permiso', true];
}
$okReq = !in_array(false, array_map(fn($r) => !$r[3] || $r[1], $req), true);

// Protección de /private por HTTP
function probe(string $url): int
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $c;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    @file_get_contents($url, false, $ctx);
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) return (int) $m[1];
    return 0;
}
$probeCode = probe($baseGuess . '/private/migrations/001_init.sql');
$probeOk = $probeCode !== 0 && $probeCode !== 200;

$errors = [];
$done = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['_csrf'] ?? ''))) $errors[] = 'Sesión expirada, recarga.';
    $f = fn($k) => trim((string) ($_POST[$k] ?? ''));
    $base = rtrim($f('base_url'), '/');
    if (!filter_var($base, FILTER_VALIDATE_URL)) $errors[] = 'URL base inválida.';
    if (!valid_email($f('mail_from'))) $errors[] = 'Correo remitente inválido.';
    if ($f('nombre') === '') $errors[] = 'Nombre del super admin obligatorio.';
    if (!rut_valid($f('rut'))) $errors[] = 'RUT del super admin inválido.';
    if (!valid_email($f('email'))) $errors[] = 'Correo del super admin inválido.';
    if (!$okReq) $errors[] = 'Hay requisitos sin cumplir.';
    if (!$probeOk && empty($_POST['acepto_riesgo'])) $errors[] = 'La carpeta private/ es accesible desde internet. Corrige la configuración del servidor o confirma que lo resolverás.';
    if (!$errors) {
        try {
            $vapid = vapid_generate();
            $cfg = [
                'base_url' => $base,
                'db_path' => PRIVATE_DIR . '/storage/db/gorila.sqlite',
                'timezone' => 'America/Santiago',
                'mail_from' => $f('mail_from'),
                'app_key' => bin2hex(random_bytes(32)),
                'cron_token' => bin2hex(random_bytes(16)),
                'vapid_public' => $vapid['public'],
                'vapid_private_pem' => $vapid['private_pem'],
                'vapid_subject' => 'mailto:' . $f('mail_from'),
                'debug' => false,
                'mail_log_only' => false,
                'installed_at' => date('c'),
            ];
            $php = "<?php\n// GorilaGym · configuración generada por el instalador. NO publicar este archivo.\n// Para mover a otro dominio: cambia base_url (y mail_from). Las rutas son relativas a la carpeta private/.\n"
                . "return [\n"
                . "    'base_url' => " . var_export($cfg['base_url'], true) . ",\n"
                . "    'db_path' => PRIVATE_DIR . '/storage/db/gorila.sqlite',\n"
                . "    'timezone' => 'America/Santiago',\n"
                . "    'mail_from' => " . var_export($cfg['mail_from'], true) . ",\n"
                . "    'app_key' => " . var_export($cfg['app_key'], true) . ",\n"
                . "    'cron_token' => " . var_export($cfg['cron_token'], true) . ",\n"
                . "    'vapid_public' => " . var_export($cfg['vapid_public'], true) . ",\n"
                . "    'vapid_private_pem' => " . var_export($cfg['vapid_private_pem'], true) . ",\n"
                . "    'vapid_subject' => " . var_export($cfg['vapid_subject'], true) . ",\n"
                . "    'debug' => false,\n"
                . "    'mail_log_only' => false, // true = no envía correos, los escribe en storage/logs/mail.log (pruebas)\n"
                . "];\n";
            if (file_put_contents(CONFIG_FILE, $php) === false) throw new RuntimeException('No se pudo escribir private/config.php');
            @chmod(CONFIG_FILE, 0640);

            require_once PRIVATE_DIR . '/app/seed.php';
            run_migrations();
            setting_set('migraciones_n', (string) count(glob(PRIVATE_DIR . '/migrations/*.sql')));
            seed_defaults();
            seed_permissions();
            setting_set('brand_nombre', $f('gym') ?: 'Gorila Gym');
            $doc = rut_normalize($f('rut'));
            $ex = q1("SELECT id FROM users WHERE doc_tipo = 'rut' AND doc_num = ?", [$doc]);
            if ($ex) update('users', ['rol' => 'superadmin', 'activo' => 1, 'email' => mb_strtolower($f('email'))], 'id = ?', [$ex['id']]);
            else insert('users', ['rol' => 'superadmin', 'doc_tipo' => 'rut', 'doc_num' => $doc, 'nombre' => $f('nombre'), 'apellido' => $f('apellido'), 'email' => mb_strtolower($f('email')), 'activo' => 1, 'sede_id' => 1]);
            file_put_contents(PRIVATE_DIR . '/storage/install.lock', date('c'));
            $done = ['base' => $base, 'cron' => PRIVATE_DIR . '/cron/cron.php', 'cron_url' => $base . '/?r=api/cron&token=' . $cfg['cron_token']];
        } catch (Throwable $e) {
            @unlink(CONFIG_FILE);
            $errors[] = 'Error durante la instalación: ' . $e->getMessage();
        }
    }
}
$v = fn($k, $d = '') => e($_POST[$k] ?? $d);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalar GorilaGym</title><link rel="stylesheet" href="../assets/css/app.css"><link rel="icon" href="../assets/img/icons/favicon.png"></head>
<body class="bg-light text-dark" data-base="<?= e($path) ?>">
<main class="mx-auto max-w-2xl px-4 py-10">
  <img src="../assets/img/brand/logo-horizontal.svg" class="h-12" alt="">
  <h1 class="mt-8 text-2xl font-semibold">Instalación</h1>

<?php if ($done): ?>
  <div class="mt-6 card p-6 space-y-4">
    <div class="flex items-center gap-2 text-primary font-semibold text-lg">✓ Instalado</div>
    <p>El instalador quedó bloqueado. Siguientes pasos:</p>
    <ol class="space-y-3 text-[15px]">
      <li class="flex gap-3"><span class="step">1</span><span>Configura el <b>cron cada minuto</b> en el hosting:<br><code class="block mt-1 text-xs bg-dark/5 rounded p-2 break-all">* * * * * php <?= e($done['cron']) ?> &gt;/dev/null 2&gt;&amp;1</code>
        <span class="text-sm text-dark/60">Si el hosting solo permite cron por URL: <code class="text-xs break-all"><?= e($done['cron_url']) ?></code></span></span></li>
      <li class="flex gap-3"><span class="step">2</span><span>Revisa <b>SPF, DKIM y DMARC</b> del dominio para que los códigos de acceso no lleguen a spam.</span></li>
      <li class="flex gap-3"><span class="step">3</span><span>Entra con tu RUT: te llegará un código al correo. Luego carga máquinas, ejercicios y plantillas, e importa el Excel de alumnos.</span></li>
    </ol>
    <a href="<?= e($done['base']) ?>/" class="btn-primary">Ir a GorilaGym</a>
  </div>
<?php else: ?>
  <?php if ($errors): ?><div class="mt-6 rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

  <section class="mt-6 card p-5">
    <h2 class="font-semibold">Requisitos del servidor</h2>
    <ul class="mt-3 text-sm divide-y divide-dark/5">
      <?php foreach ($req as [$n, $ok, $det, $oblig]): ?>
        <li class="flex justify-between py-1.5"><span><?= e($n) ?></span><span class="<?= $ok ? 'text-primary' : ($oblig ? 'text-danger' : 'text-dark/50') ?>"><?= $ok ? '✓' : '✗' ?> <?= e($det) ?></span></li>
      <?php endforeach; ?>
      <li class="flex justify-between py-1.5"><span>Carpeta private/ bloqueada desde internet</span><span class="<?= $probeOk ? 'text-primary' : 'text-danger' ?>"><?= $probeOk ? '✓ HTTP ' . $probeCode : ($probeCode ? '✗ accesible (HTTP 200)' : '? no se pudo verificar') ?></span></li>
    </ul>
    <?php if (!$probeOk): ?>
      <p class="mt-3 text-sm text-dark/70">En Apache el archivo <code>.htaccess</code> incluido la protege. En Nginx agrega al server block: <code class="text-xs">location ~ ^<?= e($path) ?>/(private|install)/ { deny all; }</code> (y quita <code>install</code> después de instalar).</p>
    <?php endif; ?>
  </section>

  <form method="post" class="mt-6 card p-5 grid sm:grid-cols-2 gap-4">
    <input type="hidden" name="_csrf" value="<?= e($_SESSION['csrf']) ?>">
    <div class="sm:col-span-2"><h2 class="font-semibold">Gimnasio</h2></div>
    <div><label class="label">Nombre</label><input name="gym" class="input" value="<?= $v('gym', 'Gorila Gym') ?>"></div>
    <div><label class="label">Correo remitente</label><input name="mail_from" class="input" value="<?= $v('mail_from', 'no-reply@' . $domain) ?>"><p class="text-xs text-dark/50 mt-1">Debe ser del mismo dominio del hosting.</p></div>
    <div class="sm:col-span-2"><label class="label">URL base</label><input name="base_url" class="input font-mono text-sm" value="<?= $v('base_url', $baseGuess) ?>"><p class="text-xs text-dark/50 mt-1">Detectada automáticamente. Si cambias de dominio después, edita base_url en private/config.php.</p></div>
    <div class="sm:col-span-2 pt-2"><h2 class="font-semibold">Super admin</h2></div>
    <div><label class="label">Nombre</label><input name="nombre" class="input" value="<?= $v('nombre') ?>" required></div>
    <div><label class="label">Apellido</label><input name="apellido" class="input" value="<?= $v('apellido') ?>"></div>
    <div><label class="label">RUT</label><input name="rut" class="input" value="<?= $v('rut') ?>" required data-rut><p class="hidden mt-1.5 text-sm text-danger" data-rut-error>RUT inválido</p></div>
    <div><label class="label">Correo (para recibir el código)</label><input type="email" name="email" class="input" value="<?= $v('email') ?>" required></div>
    <?php if (!$probeOk): ?><label class="sm:col-span-2 toggle"><input type="checkbox" name="acepto_riesgo" value="1"><span class="text-sm text-danger">Entiendo que debo bloquear private/ en el servidor antes de usar el sistema con datos reales.</span></label><?php endif; ?>
    <div class="sm:col-span-2"><button class="btn-primary" <?= $okReq ? '' : 'disabled' ?>>Instalar</button></div>
  </form>
<?php endif; ?>
</main>
<script src="../assets/js/app.js"></script>
</body></html>
