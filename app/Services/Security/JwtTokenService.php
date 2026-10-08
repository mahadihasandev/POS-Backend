<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\EncryptionServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\DTOs\Auth\TokenPayloadDTO;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use UnexpectedValueException;

class JwtTokenService implements TokenServiceInterface
{
    private string $secret;

    private string $algo;

    private int $accessTtlMinutes;

    private int $refreshTtlMinutes;

    private string $issuer;

    private string $audience;

    private string $blacklistPrefix;

    public function __construct(
        private readonly EncryptionServiceInterface $encryptionService
    ) {
        $this->secret = (string) Config::get('jwt.secret', Config::get('app.key'));
        $this->algo = (string) Config::get('jwt.algo', 'HS256');
        $this->accessTtlMinutes = (int) Config::get('jwt.access_ttl', 60);
        $this->refreshTtlMinutes = (int) Config::get('jwt.refresh_ttl', 20160);
        $this->issuer = (string) Config::get('jwt.issuer', 'drive-api');
        $this->audience = (string) Config::get('jwt.audience', 'drive-client');
        $this->blacklistPrefix = (string) Config::get('jwt.blacklist_prefix', 'jwt_blacklist:');
    }

    public function generateAccessToken(User $user, array $customClaims = []): string
    {
        return $this->createToken($user, 'access', $this->accessTtlMinutes, $customClaims);
    }

    public function generateRefreshToken(User $user): string
    {
        return $this->createToken($user, 'refresh', $this->refreshTtlMinutes);
    }

    public function decodeAndDecryptToken(string $token): TokenPayloadDTO
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
            $claims = (array) $decoded;
        } catch (\Throwable $e) {
            throw new UnexpectedValueException('Invalid or malformed token: '.$e->getMessage(), 0, $e);
        }

        if (($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $this->audience || ! in_array($claims['type'] ?? null, ['access', 'refresh'], true)) {
            throw new UnexpectedValueException('Token issuer, audience or type is invalid.');
        }

        $jti = (string) ($claims['jti'] ?? '');
        if (empty($jti)) {
            throw new UnexpectedValueException('Token is missing unique identifier (jti).');
        }

        if ($this->isTokenRevoked($jti)) {
            throw new UnexpectedValueException('Token has been revoked.');
        }

        $exp = (int) ($claims['exp'] ?? 0);
        if (time() >= $exp) {
            throw new UnexpectedValueException('Token has expired.');
        }

        $encryptedPayload = (string) ($claims['enc'] ?? '');
        if (empty($encryptedPayload)) {
            throw new UnexpectedValueException('Token payload is missing or unencrypted.');
        }

        try {
            $decrypted = $this->encryptionService->decrypt($encryptedPayload);
            if (! is_array($decrypted)) {
                throw new UnexpectedValueException('Invalid decrypted token payload.');
            }
        } catch (\Throwable $e) {
            throw new UnexpectedValueException('Failed to decrypt token claims: '.$e->getMessage(), 0, $e);
        }

        return new TokenPayloadDTO(
            userId: (int) ($decrypted['sub'] ?? 0),
            email: (string) ($decrypted['email'] ?? ''),
            jti: $jti,
            tokenType: (string) ($claims['type'] ?? 'access'),
            issuedAt: (int) ($claims['iat'] ?? 0),
            expiresAt: $exp,
            customClaims: (array) ($decrypted['claims'] ?? [])
        );
    }

    public function revokeToken(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
            $jti = (string) ($decoded->jti ?? '');
            $exp = (int) ($decoded->exp ?? 0);

            if (empty($jti)) {
                return false;
            }

            $remainingSeconds = max($exp - time(), 60);
            Cache::put($this->blacklistPrefix.$jti, true, Carbon::now()->addSeconds($remainingSeconds));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isTokenRevoked(string $jti): bool
    {
        return Cache::has($this->blacklistPrefix.$jti);
    }

    private function createToken(User $user, string $type, int $ttlMinutes, array $customClaims = []): string
    {
        $now = time();
        $expiresAt = $now + ($ttlMinutes * 60);
        $jti = (string) Str::uuid();

        // Confidential claims encrypted with AES-256-GCM
        $sensitiveData = [
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'claims' => $customClaims,
        ];

        $encryptedPayload = $this->encryptionService->encrypt($sensitiveData);

        // JWT contains standard envelope + encrypted claims payload
        $jwtClaims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'jti' => $jti,
            'type' => $type,
            'enc' => $encryptedPayload,
        ];

        return JWT::encode($jwtClaims, $this->secret, $this->algo);
    }
}
