<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\TokenServiceInterface;
use App\Contracts\UserRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class JwtOptionalAuthenticate
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Optional JWT verification: attaches user if valid token present, otherwise continues as guest.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!empty($bearerToken)) {
            try {
                $payload = $this->tokenService->decodeAndDecryptToken($bearerToken);
                if ($payload->tokenType === 'access') {
                    $user = $this->userRepository->findById($payload->userId);
                    if ($user) {
                        Auth::setUser($user);
                        $request->attributes->set('authenticated_user', $user);
                        $request->attributes->set('token_payload', $payload);
                    }
                }
            } catch (\Throwable) {
                // Gracefully continue as unauthenticated guest
            }
        }

        return $next($request);
    }
}
