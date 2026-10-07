<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_authenticated_user(): void
    {
        $payload = [
            'name' => 'Alice Cooper',
            'email' => 'alice@example.com',
            'password' => 'SecurePass123!',
        ];

        // 1. Unauthenticated registration must be rejected with 401
        $guestResponse = $this->postJson('/api/v1/auth/register', $payload);
        $guestResponse->assertStatus(401);

        // 2. Authenticated user can register a new user from inside the webapp
        $admin = User::factory()->create();
        $tokenService = app(\App\Contracts\TokenServiceInterface::class);
        $token = $tokenService->generateAccessToken($admin);

        $authResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/register', $payload);

        $authResponse->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'User registered and authenticated successfully.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                ],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
    }

    public function test_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'bob@example.com',
            'password' => bcrypt('Secret123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['access_token', 'refresh_token', 'user'],
            ]);
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'charlie@example.com',
        ]);

        $tokenService = app(\App\Contracts\TokenServiceInterface::class);
        $accessToken = $tokenService->generateAccessToken($user);

        // Fetch profile
        $meResponse = $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'charlie@example.com',
                ],
            ]);

        // Logout
        $logoutResponse = $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        // Request with revoked token should fail
        $deniedResponse = $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->getJson('/api/v1/auth/me');

        $deniedResponse->assertStatus(401);
    }
}
