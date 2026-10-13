<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;

interface AuthServiceInterface
{
    /**
     * Register a verified staff user.
     */
    public function register(RegisterDTO $dto): User;

    /**
     * Authenticate a user with email and password.
     *
     * @return array{user: User, access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function login(LoginDTO $dto): array;

    /**
     * Refresh an access token using a valid encrypted refresh token.
     *
     * @return array{user: User, access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function refresh(string $refreshToken): array;

    /**
     * Invalidate/blacklist the current token.
     */
    public function logout(string $token): bool;

    /**
     * Get the authenticated user profile.
     */
    public function me(int $userId): User;
}
