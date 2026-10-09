<?php

declare(strict_types=1);

/**
 * Vercel Serverless Function Entry Point for Laravel 13
 *
 * Prepares ephemeral writable /tmp directory structure for Laravel runtime caches,
 * logs, views, and sessions in Vercel's serverless environment.
 */

// Define writable storage directory in /tmp
$storagePath = getenv('APP_STORAGE') ?: '/tmp/storage';
putenv("APP_STORAGE={$storagePath}");
$_ENV['APP_STORAGE'] = $storagePath;

// Signal serverless execution
putenv('VERCEL=1');
$_ENV['VERCEL'] = '1';

// Redirect cache paths to /tmp if not already specified in environment
$cacheMappings = [
    'APP_CONFIG_CACHE' => '/tmp/bootstrap/cache/config.php',
    'APP_EVENTS_CACHE' => '/tmp/bootstrap/cache/events.php',
    'APP_PACKAGES_CACHE' => '/tmp/bootstrap/cache/packages.php',
    'APP_ROUTES_CACHE' => '/tmp/bootstrap/cache/routes.php',
    'APP_SERVICES_CACHE' => '/tmp/bootstrap/cache/services.php',
    'VIEW_COMPILED_PATH' => $storagePath . '/framework/views',
];

foreach ($cacheMappings as $envKey => $envPath) {
    if (!getenv($envKey) && !isset($_ENV[$envKey])) {
        putenv("{$envKey}={$envPath}");
        $_ENV[$envKey] = $envPath;
    }
}

// Pre-create required directory tree in /tmp
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

// Forward request to Laravel's public front controller
require __DIR__ . '/../public/index.php';
