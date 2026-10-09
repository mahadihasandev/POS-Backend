<?php

declare(strict_types=1);

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnforceTlsAndSecurityHeaders;
use App\Http\Middleware\GzipCompression;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\JwtOptionalAuthenticate;
use App\Http\Middleware\ResponsePerformanceHeader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust Render & Reverse Proxy headers (HTTPS termination)
        $middleware->trustProxies(at: '*');

        // Global and API Security + Performance Pipeline
        $middleware->api(prepend: [
            EnforceTlsAndSecurityHeaders::class,
            ResponsePerformanceHeader::class,
        ]);

        $middleware->api(append: [
            GzipCompression::class,
        ]);

        // Route Middleware Aliases
        $middleware->alias([
            'permission' => CheckPermission::class,
            'jwt.auth' => JwtAuthenticate::class,
            'jwt.optional' => JwtOptionalAuthenticate::class,
            'tls.security' => EnforceTlsAndSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Enforce JSON responses for ALL routes in this headless API backend
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true
        );

        // Validation Exceptions
        $exceptions->render(function (ValidationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'error_code' => 'ERR_VALIDATION_FAILED',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        // Authentication Exceptions
        $exceptions->render(function (AuthenticationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unauthenticated.',
                'error_code' => 'ERR_UNAUTHENTICATED',
            ], Response::HTTP_UNAUTHORIZED);
        });

        // Model / Route Not Found
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'The requested resource was not found.',
                'error_code' => 'ERR_NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        });

        // Authorization / Access Denied
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized.',
                'error_code' => 'ERR_FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        });

        // Rate Limiting (Throttle)
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down.',
                'error_code' => 'ERR_RATE_LIMIT_EXCEEDED',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        });

        // General / Fallback Exceptions
        $exceptions->render(function (Throwable $e, Request $request): JsonResponse {
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            $isDebug = (bool) config('app.debug');

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'An unexpected server error occurred.',
                'error_code' => 'ERR_SERVER_ERROR',
                'exception' => get_class($e),
                'file' => $isDebug ? $e->getFile() : null,
                'line' => $isDebug ? $e->getLine() : null,
            ], $statusCode >= 400 && $statusCode < 600 ? $statusCode : Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();

// Dynamic storage path for serverless / Vercel execution (writable /tmp filesystem)
$customStoragePath = env('APP_STORAGE') ?: ((isset($_ENV['VERCEL']) || getenv('VERCEL')) ? '/tmp/storage' : null);
if ($customStoragePath) {
    $app->useStoragePath($customStoragePath);
}

// Fallback 'view' binding to prevent RegisterErrorViewPaths crash in serverless/early exception handler
if (!$app->bound('view')) {
    $app->singleton('view', function () {
        return new class {
            public function replaceNamespace($namespace, $hints) { return $this; }
            public function addNamespace($namespace, $hints) { return $this; }
            public function exists($view) { return false; }
            public function make($view, $data = [], $mergeData = []) {
                return new class {
                    public function render() { return ''; }
                    public function with($key, $value = null) { return $this; }
                };
            }
            public function share($key, $value = null) {}
        };
    });
}

return $app;
