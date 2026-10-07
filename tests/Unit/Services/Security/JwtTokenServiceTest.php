<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Contracts\TokenServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use UnexpectedValueException;

class JwtTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private TokenServiceInterface $tokenService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = app(TokenServiceInterface::class);

        $this->user = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
        ]);
    }

    public function test_it_generates_and_validates_encrypted_access_token(): void
    {
        $token = $this->tokenService->generateAccessToken($this->user, ['role' => 'admin']);
        $this->assertNotEmpty($token);

        // Verify claims are not plaintext base64 in the token payload
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
        $this->assertStringNotContainsString('demo@example.com', $payloadJson);
        $this->assertStringContainsString('"enc":', $payloadJson);

        // Decrypt and verify claims
        $payload = $this->tokenService->decodeAndDecryptToken($token);
        $this->assertEquals($this->user->id, $payload->userId);
        $this->assertEquals($this->user->email, $payload->email);
        $this->assertEquals('access', $payload->tokenType);
        $this->assertEquals(['role' => 'admin'], $payload->customClaims);
    }

    public function test_it_revokes_token_via_cache_blacklist(): void
    {
        $token = $this->tokenService->generateAccessToken($this->user);

        // Ensure token decodes successfully first
        $payload = $this->tokenService->decodeAndDecryptToken($token);
        $this->assertEquals($this->user->id, $payload->userId);

        // Revoke token
        $revoked = $this->tokenService->revokeToken($token);
        $this->assertTrue($revoked);

        // Subsequent decoding must throw exception
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Token has been revoked.');
        $this->tokenService->decodeAndDecryptToken($token);
    }
}
