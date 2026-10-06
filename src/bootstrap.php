<?php
declare(strict_types=1);

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
