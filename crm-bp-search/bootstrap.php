<?php

declare(strict_types=1);

define('PROJECT_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CrmBpSearch\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = PROJECT_ROOT . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function app_config(string $key, ?string $default = null): ?string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = PROJECT_ROOT . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
    }

    return $env[$key] ?? $default;
}

function activity_config(): array
{
    return require PROJECT_ROOT . '/config/activity.php';
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function app_public_base_url(): string
{
    $configured = app_config('APP_PUBLIC_BASE');
    if ($configured !== null && $configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . $dir;
}

function app_endpoint_url(string $filename): string
{
    $envKey = 'APP_' . strtoupper(str_replace(['/', '.php'], ['_', ''], $filename)) . '_URL';
    $override = app_config($envKey);
    if ($override !== null && $override !== '') {
        return $override;
    }

    return app_public_base_url() . '/' . ltrim($filename, '/');
}

/** Заголовки для placement-редактора во фрейме Bitrix24 (+ ngrok). */
function app_iframe_headers(): void
{
    $ancestors = [
        "'self'",
        'https://*.bitrix24.ru',
        'https://*.bitrix24.com',
        'https://*.bitrix24.de',
        'https://*.bitrix24.fr',
        'https://*.bitrix24.ua',
        'https://*.bitrix24.kz',
        'https://*.bitrix24.by',
    ];
    header('Content-Security-Policy: frame-ancestors ' . implode(' ', $ancestors));
    header('ngrok-skip-browser-warning: true');
}
