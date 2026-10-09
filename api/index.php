<?php

declare(strict_types=1);

/**
 * Vercel Serverless Function Entry Point for Laravel 13
 */

// Set up writable /tmp directory structure for serverless execution
$storagePath = getenv('APP_STORAGE') ?: '/tmp/storage';
putenv("APP_STORAGE={$storagePath}");
$_ENV['APP_STORAGE'] = $storagePath;
putenv('VERCEL=1');
$_ENV['VERCEL'] = '1';

// Ensure required writable directories exist in /tmp
$directories = [
    $storagePath . '/app/public',
    $storagePath . '/app/private',
    $storagePath . '/framework/cache/data',
    $storagePath . '/framework/sessions',
    $storagePath . '/framework/views',
    $storagePath . '/logs',
    '/tmp/bootstrap/cache',
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Redirect all Laravel cache and compiled views to writable /tmp
$cacheMappings = [
    'APP_CONFIG_CACHE' => '/tmp/bootstrap/cache/config.php',
    'APP_EVENTS_CACHE' => '/tmp/bootstrap/cache/events.php',
    'APP_PACKAGES_CACHE' => '/tmp/bootstrap/cache/packages.php',
    'APP_ROUTES_CACHE' => '/tmp/bootstrap/cache/routes.php',
    'APP_SERVICES_CACHE' => '/tmp/bootstrap/cache/services.php',
    'VIEW_COMPILED_PATH' => $storagePath . '/framework/views',
];

foreach ($cacheMappings as $key => $val) {
    if (!getenv($key) && !isset($_ENV[$key])) {
        putenv("{$key}={$val}");
        $_ENV[$key] = $val;
    }
}

// Seed packages and services cache from build if available
foreach (['packages.php', 'services.php'] as $cacheFile) {
    $src = __DIR__ . '/../bootstrap/cache/' . $cacheFile;
    $dest = '/tmp/bootstrap/cache/' . $cacheFile;
    if (file_exists($src) && !file_exists($dest)) {
        @copy($src, $dest);
    }
}

// Normalize SCRIPT_NAME and PHP_SELF so Symfony does not treat /api as a base subdirectory
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// Universal CORS preflight interceptor & response headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if ($origin !== '*') {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-Response-Time');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Normalize URI: Handle requests missing /api or /api/v1 prefix gracefully
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$requestQuery = parse_url($requestUri, PHP_URL_QUERY);
$queryString = $requestQuery !== null && $requestQuery !== '' ? '?' . $requestQuery : '';

if ($requestPath === '/favicon.ico') {
    http_response_code(204);
    exit;
}

if (!str_starts_with($requestPath, '/api/')) {
    if (str_starts_with($requestPath, '/v1/')) {
        $_SERVER['REQUEST_URI'] = '/api' . $requestPath . $queryString;
    } elseif (
        str_starts_with($requestPath, '/auth/') ||
        str_starts_with($requestPath, '/pos/') ||
        str_starts_with($requestPath, '/rbac/') ||
        str_starts_with($requestPath, '/drive/') ||
        $requestPath === '/health'
    ) {
        $_SERVER['REQUEST_URI'] = '/api/v1' . $requestPath . $queryString;
    }
}

// Delegate to Laravel public front controller with diagnostic error trapping
try {
    require __DIR__ . '/../public/index.php';
} catch (\Throwable $e) {
    error_log('[VERCEL_BOOT_ERROR] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    $prev = $e->getPrevious();
    echo json_encode([
        'success' => false,
        'message' => 'Backend initialization error: ' . $e->getMessage(),
        'error_code' => 'ERR_VERCEL_BOOT_FAILED',
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'previous' => $prev ? [
            'message' => $prev->getMessage(),
            'file' => $prev->getFile(),
            'line' => $prev->getLine(),
            'trace' => array_slice(explode("\n", $prev->getTraceAsString()), 0, 10),
        ] : null,
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
