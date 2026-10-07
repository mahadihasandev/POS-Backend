<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Auth\TokenPayloadDTO;
use App\Models\User;

interface TokenServiceInterface
{
    /**
     * Generate an encrypted access token for a given user.
     *
     * @param User $user
     * @param array<string, mixed> $customClaims
     * @return string
     */
    public function generateAccessToken(User $user, array $customClaims = []): string;

    /**
     * Generate an encrypted refresh token.
     *
     * @param User $user
     * @return string
     */
    public function generateRefreshToken(User $user): string;

    /**
     * Decode, decrypt, and validate a signed JWT token.
     *
     * @param string $token
     * @return TokenPayloadDTO
     */
    public function decodeAndDecryptToken(string $token): TokenPayloadDTO;

    /**
     * Revoke / blacklist a token by its unique identifier (jti).
     *
     * @param string $token
     * @return bool
     */
    public function revokeToken(string $token): bool;

    /**
     * Check if a token identifier is blacklisted in cache.
     *
     * @param string $jti
     * @return bool
     */
    public function isTokenRevoked(string $jti): bool;
}
