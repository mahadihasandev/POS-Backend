<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Contracts\AuthServiceInterface;
use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends BaseApiController
{
    public function __construct(
        private readonly AuthServiceInterface $authService
    ) {}

    /**
     * Register a new user and generate access/refresh tokens.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $dto = RegisterDTO::fromRequest($request);
        $result = $this->authService->register($dto);

        return $this->successResponse(
            data: [
                'user' => new UserResource($result['user']),
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type' => $result['token_type'],
                'expires_in' => $result['expires_in'],
            ],
            message: 'User registered and authenticated successfully.',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Authenticate an existing user with credentials.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $dto = LoginDTO::fromRequest($request);
        $result = $this->authService->login($dto);

        return $this->successResponse(
            data: [
                'user' => new UserResource($result['user']),
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type' => $result['token_type'],
                'expires_in' => $result['expires_in'],
            ],
            message: 'Authentication successful.'
        );
    }

    /**
     * Refresh an expired access token using an encrypted single-use refresh token.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $refreshToken = (string) $request->validated('refresh_token');
        $result = $this->authService->refresh($refreshToken);

        return $this->successResponse(
            data: [
                'user' => new UserResource($result['user']),
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type' => $result['token_type'],
                'expires_in' => $result['expires_in'],
            ],
            message: 'Token refreshed successfully.'
        );
    }

    /**
     * Log out user by revoking and blacklisting the current Bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (!empty($token)) {
            $this->authService->logout($token);
        }

        return $this->successResponse(
            message: 'Logged out successfully. Token has been revoked.'
        );
    }

    /**
     * Get the authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'Profile retrieved successfully.'
        );
    }
}
