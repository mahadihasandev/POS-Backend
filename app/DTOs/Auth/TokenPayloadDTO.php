<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class TokenPayloadDTO
{
    /**
     * @param int $userId
     * @param string $email
     * @param string $jti
     * @param string $tokenType ('access' or 'refresh')
     * @param int $issuedAt
     * @param int $expiresAt
     * @param array<string, mixed> $customClaims
     */
    public function __construct(
        public int $userId,
        public string $email,
        public string $jti,
        public string $tokenType,
        public int $issuedAt,
        public int $expiresAt,
        public array $customClaims = []
    ) {}

    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    public function toArray(): array
    {
        return [
            'sub' => $this->userId,
            'email' => $this->email,
            'jti' => $this->jti,
            'type' => $this->tokenType,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
            'claims' => $this->customClaims,
        ];
    }
}
