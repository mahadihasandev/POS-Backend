<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\TokenServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Traits\ApiResponses;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    use ApiResponses;

    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Handle an incoming request with Bearer JWT token verification.
     *
     * @param Request $request
     * @param Closure(Request): (Response) $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (empty($bearerToken)) {
            return $this->errorResponse(
                message: 'Authentication token is missing. Please provide a Bearer token.',
                statusCode: Response::HTTP_UNAUTHORIZED,
                errorCode: 'AUTH_TOKEN_MISSING'
            );
        }

        try {
            $payload = $this->tokenService->decodeAndDecryptToken($bearerToken);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                message: 'Invalid or expired authentication token: ' . $e->getMessage(),
                statusCode: Response::HTTP_UNAUTHORIZED,
                errorCode: 'AUTH_TOKEN_INVALID'
            );
        }

        if ($payload->tokenType !== 'access') {
            return $this->errorResponse(
                message: 'Invalid token type. Access token is required.',
                statusCode: Response::HTTP_UNAUTHORIZED,
                errorCode: 'AUTH_TOKEN_TYPE_INVALID'
            );
        }

        $user = $this->userRepository->findById($payload->userId);

        if (!$user) {
            return $this->errorResponse(
                message: 'Authenticated user no longer exists.',
                statusCode: Response::HTTP_UNAUTHORIZED,
                errorCode: 'AUTH_USER_NOT_FOUND'
            );
        }

        Auth::setUser($user);
        $request->attributes->set('authenticated_user', $user);
        $request->attributes->set('token_payload', $payload);

        return $next($request);
    }
}
