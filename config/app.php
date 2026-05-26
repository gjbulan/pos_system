<?php
// Application settings.

define('APP_NAME', 'POS System');
define('BASE_URL', rtrim(getenv('BASE_URL') ?: 'http://localhost:8080/posdemo/', '/') . '/');

$appBasePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
define('APP_BASE_PATH', rtrim($appBasePath, '/') ?: '/');

define('APP_ENV', getenv('APP_ENV') ?: 'local');
define('APP_DEBUG', (getenv('APP_DEBUG') ?: 'true') === 'true');

if (!APP_DEBUG) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

function app_url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}
