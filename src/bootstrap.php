<?php
declare(strict_types=1);

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

error_reporting(E_ALL);
$environment=(string)env('APP_ENV','development');
$maintenanceLock=dirname(__DIR__).'/storage/maintenance.lock';
if($environment==='production'){
    ini_set('display_errors','0');
    ini_set('log_errors','1');
}

spl_autoload_register(static function (string $class): void {
    $prefix='FudgeDonuts\\';
    if(!str_starts_with($class,$prefix)) return;
    $relative=substr($class,strlen($prefix));
    $path=__DIR__.'/'.str_replace('\\','/',$relative).'.php';
    if(is_file($path)) require $path;
});

if(PHP_SAPI!=='cli' && is_file($maintenanceLock)){
    \FudgeDonuts\SecurityService::applyHeaders();
if(PHP_SAPI!=='cli') \FudgeDonuts\SeoService::applyRobotsHeader($requestUri??(string)($_SERVER['REQUEST_URI']??'/'));
    \FudgeDonuts\HttpResponseService::send(
        503,
        'We’ll be right back.',
        'The store is temporarily unavailable while we finish a maintenance task. Please try again in a minute.',
        [['label'=>'Try the store again','href'=>'/']],
        null,
        false,
        60
    );
}

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cache_limiter','');
ini_set('session.use_trans_sid','0');
ini_set('session.cookie_httponly','1');
ini_set('session.sid_length','48');
ini_set('session.sid_bits_per_character','6');

$httpsDetected=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
if(!$httpsDetected && (env('TRUST_PROXY_HEADERS','0')??'0')==='1'){
    $httpsDetected=strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
}
$secure=$environment==='production' || $httpsDetected;
session_name('fudge_donuts_session');
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/',
    'secure'=>$secure,
    'httponly'=>true,
    'samesite'=>'Lax',
]);
$requestMethod=(string)($_SERVER['REQUEST_METHOD']??'GET');
$requestUri=(string)($_SERVER['REQUEST_URI']??'/');
$hasSessionCookie=isset($_COOKIE[session_name()]);
$skipSession=PHP_SAPI!=='cli' && \FudgeDonuts\PerformanceService::canSkipSession($requestMethod,$requestUri,$hasSessionCookie);
if($skipSession){
    $_SESSION=[];
}else{
    session_start();
}

\FudgeDonuts\SecurityService::applyHeaders();

$sessionExpired=false;
if(!\FudgeDonuts\SecurityService::touchAuthSession(
    $_SESSION,'admin_id','admin',
    (int)env('ADMIN_SESSION_IDLE_MINUTES','30'),
    (int)env('ADMIN_SESSION_MAX_HOURS','12')
)){
    unset($_SESSION['admin'],$_SESSION['admin_id'],$_SESSION['admin_role'],$_SESSION['admin_authenticated_at'],$_SESSION['admin_last_activity']);
    $sessionExpired=true;
}
if(!\FudgeDonuts\SecurityService::touchAuthSession(
    $_SESSION,'user_id','user',
    (int)env('USER_SESSION_IDLE_MINUTES','120'),
    (int)env('USER_SESSION_MAX_HOURS','168')
)){
    unset($_SESSION['user_id'],$_SESSION['user_authenticated_at'],$_SESSION['user_last_activity']);
    $sessionExpired=true;
}
if($sessionExpired) session_regenerate_id(true);
$authenticated=!empty($_SESSION['admin_id']) || !empty($_SESSION['user_id']);
if(PHP_SAPI!=='cli'){
    \FudgeDonuts\PerformanceService::apply(
        $requestMethod,
        $requestUri,
        $authenticated
    );
}
if($authenticated) \FudgeDonuts\SecurityService::applyPrivateCacheHeaders();

if((env('OBSERVABILITY_ENABLED','1')??'1')!=='0'){
    \FudgeDonuts\ObservabilityService::installRuntimeHandlers(dirname(__DIR__));
}

function asset_url(string $path): string
{
    return \FudgeDonuts\PerformanceService::assetUrl($path,dirname(__DIR__));
}

function analytics_script(): string
{
    if((env('ANALYTICS_ENABLED','0')??'0')!=='1') return '';
    return '<script src="'.htmlspecialchars(asset_url('/assets/analytics.js'),ENT_QUOTES).'" defer></script>';
}

function csrf_token(): string
{
    if(empty($_SESSION['_csrf'])) $_SESSION['_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function rotate_csrf_token(): void
{
    $_SESSION['_csrf']=bin2hex(random_bytes(32));
}

function verify_csrf(?string $token): void
{
    if(!$token || !hash_equals($_SESSION['_csrf']??'',$token)){
        if(PHP_SAPI==='cli') throw new \RuntimeException('Invalid CSRF token');
        \FudgeDonuts\HttpResponseService::send(
            419,
            'Your session has expired.',
            'For your security, this form can’t be submitted anymore. Return to the page and try again.',
            [
                ['label'=>'Return to store','href'=>'/'],
                ['label'=>'View cart','href'=>'/cart.php']
            ]
        );
    }
}

function money(int $cents): string
{
    return '$'.number_format($cents/100,2);
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
    try{
        $admin=(new \FudgeDonuts\AdminAuthService(\FudgeDonuts\Database::connection()))->admin((int)$_SESSION['admin_id']);
    }catch(Throwable){
        $admin=null;
    }
    if(!$admin || !(int)$admin['active']){
        unset($_SESSION['admin'],$_SESSION['admin_id'],$_SESSION['admin_role'],$_SESSION['admin_authenticated_at'],$_SESSION['admin_last_activity']);
        session_regenerate_id(true);
        header('Location: /admin.php');
        exit;
    }
    $_SESSION['admin_role']=(string)$admin['role'];
    \FudgeDonuts\SecurityService::applyPrivateCacheHeaders();
    if(!in_array((string)$admin['role'],$roles,true)){
        \FudgeDonuts\HttpResponseService::send(
            403,
            'Access not available.',
            'Your administrator account does not have permission to open this area.',
            [['label'=>'Back to Admin','href'=>'/admin.php']],
            null,
            true
        );
    }
}
