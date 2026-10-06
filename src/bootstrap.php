<?php
declare(strict_types=1);

error_reporting(E_ALL);
$maintenanceLock=dirname(__DIR__).'/storage/maintenance.lock';
if(PHP_SAPI!=='cli' && is_file($maintenanceLock)){
    http_response_code(503);
    header('Retry-After: 60');
    exit('Store maintenance in progress.');
}
if ((getenv('APP_ENV') ?: 'development') === 'production') {
    ini_set('display_errors','0');
    ini_set('log_errors','1');
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fudge_donuts_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

spl_autoload_register(static function (string $class): void {
    $prefix = 'FudgeDonuts\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

\FudgeDonuts\SecurityService::applyHeaders();

function env(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    if (!$loaded) {
        $file = dirname(__DIR__) . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                if (getenv(trim($k)) === false) putenv(trim($k) . '=' . trim($v));
            }
        }
        $loaded = true;
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
}

if((env('OBSERVABILITY_ENABLED','1')??'1')!=='0'){
    \FudgeDonuts\ObservabilityService::installRuntimeHandlers(dirname(__DIR__));
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function verify_csrf(?string $token): void
{
    if (!$token || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

function admin_has_role(array $roles): bool
{
    return !empty($_SESSION['admin']) && isset($_SESSION['admin_role']) && in_array((string)$_SESSION['admin_role'],$roles,true);
}

function require_admin_roles(array $roles): void
{
    if(empty($_SESSION['admin']) || empty($_SESSION['admin_id'])){
        header('Location: /admin.php');
        exit;
    }
    try {
        $admin=(new \FudgeDonuts\AdminAuthService(\FudgeDonuts\Database::connection()))->admin((int)$_SESSION['admin_id']);
    } catch (Throwable) {
        $admin=null;
    }
    if(!$admin || !(int)$admin['active']){
        unset($_SESSION['admin'],$_SESSION['admin_id'],$_SESSION['admin_role']);
        session_regenerate_id(true);
        header('Location: /admin.php');
        exit;
    }
    $_SESSION['admin_role']=(string)$admin['role'];
    if(!in_array((string)$admin['role'],$roles,true)){
        http_response_code(403);
        exit('Forbidden');
    }
}
