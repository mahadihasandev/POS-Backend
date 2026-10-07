<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemHealthController extends BaseApiController
{
    /**
     * Get system health and security diagnostic status.
     */
    public function health(Request $request): JsonResponse
    {
        $dbStatus = 'healthy';
        $dbLatencyMs = 0.0;

        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $dbLatencyMs = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable) {
            $dbStatus = 'unreachable';
        }

        $cacheStatus = 'healthy';
        try {
            $testKey = 'health_check_' . uniqid();
            Cache::put($testKey, 'ok', 5);
            $cacheStatus = Cache::get($testKey) === 'ok' ? 'healthy' : 'degraded';
            Cache::forget($testKey);
        } catch (\Throwable) {
            $cacheStatus = 'unreachable';
        }

        $encryptionWorking = function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);

        return $this->successResponse(
            data: [
                'status' => ($dbStatus === 'healthy' && $cacheStatus === 'healthy') ? 'healthy' : 'degraded',
                'environment' => config('app.env'),
                'php_version' => PHP_VERSION,
                'framework' => 'Laravel ' . app()->version(),
                'tls_enforced' => (bool) config('security.force_https'),
                'security' => [
                    'cipher' => 'aes-256-gcm',
                    'jwt_algo' => config('jwt.algo'),
                    'encryption_ready' => $encryptionWorking,
                    'hsts_enabled' => (bool) config('security.hsts.enabled'),
                ],
                'database' => [
                    'connection' => config('database.default'),
                    'status' => $dbStatus,
                    'latency' => "{$dbLatencyMs}ms",
                ],
                'cache' => [
                    'driver' => config('cache.default'),
                    'status' => $cacheStatus,
                ],
                'timestamp' => now()->toIso8601String(),
            ],
            message: 'System is operational.'
        );
    }
}
