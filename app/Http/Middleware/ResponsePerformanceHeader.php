<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResponsePerformanceHeader
{
    /**
     * Measure request execution time and memory peak.
     *
     * @param Request $request
     * @param Closure(Request): (Response) $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);
        $peakMemoryMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);

        $response->headers->set('X-Response-Time', "{$executionTimeMs}ms");
        $response->headers->set('X-Memory-Peak', "{$peakMemoryMb}MB");

        return $response;
    }
}
