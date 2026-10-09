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

// Redirect cache and compiled views to /tmp
$cacheMappings = [
    'APP_CONFIG_CACHE' => '/tmp/bootstrap/cache/config.php',
    'APP_EVENTS_CACHE' => '/tmp/bootstrap/cache/events.php',
    'APP_ROUTES_CACHE' => '/tmp/bootstrap/cache/routes.php',
    'VIEW_COMPILED_PATH' => $storagePath . '/framework/views',
];

foreach ($cacheMappings as $key => $val) {
    if (!getenv($key) && !isset($_ENV[$key])) {
        putenv("{$key}={$val}");
        $_ENV[$key] = $val;
    }
}

// Delegate to Laravel public front controller with diagnostic error trapping
try {
    require __DIR__ . '/../public/index.php';
} catch (\Throwable $e) {
    error_log('[VERCEL_BOOT_ERROR] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Backend initialization error: ' . $e->getMessage(),
        'error_code' => 'ERR_VERCEL_BOOT_FAILED',
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
