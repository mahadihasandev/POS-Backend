<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\AuthServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly TokenServiceInterface $tokenService
    ) {}

    public function register(RegisterDTO $dto): array
    {
        if ($this->userRepository->emailExists($dto->email)) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email address already exists.'],
            ]);
        }

        /** @var User $user */
        $user = $this->userRepository->create([
            'name' => $dto->name,
            'email' => $dto->email,
            'password' => Hash::make($dto->password),
        ]);

        return $this->generateAuthPayload($user);
    }

    public function login(LoginDTO $dto): array
    {
        $user = $this->userRepository->findByEmail($dto->email);

        if (! $user || ! Hash::check($dto->password, $user->password)) {
            throw new AuthenticationException('Invalid email or password credentials.');
        }

        return $this->generateAuthPayload($user);
    }

    public function refresh(string $refreshToken): array
    {
        return Cache::lock('pos:refresh:'.hash('sha256', $refreshToken), 10)->block(5, function () use ($refreshToken): array {
            try {
                $payload = $this->tokenService->decodeAndDecryptToken($refreshToken);
            } catch (\Throwable) {
                throw new AuthenticationException('Invalid or expired refresh token.');
            }

            if ($payload->tokenType !== 'refresh') {
                throw new AuthenticationException('Invalid token type provided for refresh.');
            }

            /** @var User|null $user */
            $user = $this->userRepository->findById($payload->userId);
            if (! $user) {
                throw new AuthenticationException('User associated with token no longer exists.');
            }

            // Single-use refresh token: revoke old refresh token upon rotation
            $this->tokenService->revokeToken($refreshToken);

            return $this->generateAuthPayload($user);
        });
    }

    public function logout(string $token): bool
    {
        return $this->tokenService->revokeToken($token);
    }

    public function me(int $userId): User
    {
        /** @var User|null $user */
        $user = $this->userRepository->findById($userId);

        if (! $user) {
            throw new AuthenticationException('User not found.');
        }

        return $user;
    }

    /**
     * @return array{user: User, access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    private function generateAuthPayload(User $user): array
    {
        $accessToken = $this->tokenService->generateAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken($user);
        $accessTtlMinutes = (int) Config::get('jwt.access_ttl', 60);

        return [
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtlMinutes * 60,
        ];
    }
}
