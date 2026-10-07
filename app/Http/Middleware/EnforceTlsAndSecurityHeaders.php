<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class EnforceTlsAndSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response) $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Enforce TLS/HTTPS redirection if configured or in production
        $forceHttps = (bool) Config::get('security.force_https', false);

        if ($forceHttps && !$request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        /** @var Response $response */
        $response = $next($request);

        // 2. Strict-Transport-Security (HSTS)
        $hstsConfig = Config::get('security.hsts', []);
        if (!empty($hstsConfig['enabled']) && ($request->isSecure() || $forceHttps)) {
            $maxAge = (int) ($hstsConfig['max_age'] ?? 31536000);
            $hstsHeader = "max-age={$maxAge}";

            if (!empty($hstsConfig['include_subdomains'])) {
                $hstsHeader .= '; includeSubDomains';
            }
            if (!empty($hstsConfig['preload'])) {
                $hstsHeader .= '; preload';
            }

            $response->headers->set('Strict-Transport-Security', $hstsHeader);
        }

        // 3. Defensive Security Headers
        $headers = Config::get('security.headers', []);
        foreach ($headers as $header => $value) {
            if (!empty($value) && !$response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
