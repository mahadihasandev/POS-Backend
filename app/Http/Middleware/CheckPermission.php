<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Traits\ApiResponses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    use ApiResponses;

    /**
     * Handle an incoming request and verify permission.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse(
                message: 'Unauthenticated.',
                errorCode: 'ERR_UNAUTHENTICATED',
                statusCode: Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$user->hasPermission($permission)) {
            return $this->errorResponse(
                message: "Access Denied: You do not have the required permission '{$permission}' for this action.",
                errorCode: 'ERR_PERMISSION_DENIED',
                statusCode: Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
