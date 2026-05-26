<?php
// Application settings for Phase 18.
// Change APP_BASE_PATH when you rename the folder in htdocs.

define('APP_NAME', 'POS System');
define('APP_BASE_PATH', '/posdemo');
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
    return rtrim(APP_BASE_PATH, '/') . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}
