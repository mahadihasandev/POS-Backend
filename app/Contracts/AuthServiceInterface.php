<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;

interface AuthServiceInterface
{
    /**
     * Register a new user and return auth tokens with user model.
     *
     * @param RegisterDTO $dto
     * @return array{user: User, access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function register(RegisterDTO $dto): array;

    /**
     * Authenticate a user with email and password.
     *
     * @param LoginDTO $dto
     * @return array{user: User, access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function login(LoginDTO $dto): array;

    /**
     * Refresh an access token using a valid encrypted refresh token.
     *
     * @param string $refreshToken
     * @return array{user: User, access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function refresh(string $refreshToken): array;

    /**
     * Invalidate/blacklist the current token.
     *
     * @param string $token
     * @return bool
     */
    public function logout(string $token): bool;

    /**
     * Get the authenticated user profile.
     *
     * @param int $userId
     * @return User
     */
    public function me(int $userId): User;
}
