<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TokenServiceInterface;
use App\Models\Designation;
use App\Models\Permission;
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
        $role = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = User::factory()->create(['designation_id' => $role->id]);
        $tokenService = app(TokenServiceInterface::class);
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

    public function test_staff_registration_assigns_the_selected_role_in_one_request(): void
    {
        $adminRole = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $cashierRole = Designation::create(['name' => 'Cashier', 'slug' => 'cashier']);
        $admin = User::factory()->create(['designation_id' => $adminRole->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $payload = ['name' => 'New cashier', 'email' => 'cashier@example.com', 'password' => 'SecurePass123!', 'designation_id' => $cashierRole->id];
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated()
            ->assertJsonPath('data.user.designation.id', $cashierRole->id);
        $this->assertDatabaseHas('users', ['email' => 'cashier@example.com', 'designation_id' => $cashierRole->id]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $admin->id);
    }

    public function test_invalid_role_does_not_leave_a_partial_staff_account(): void
    {
        $role = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = User::factory()->create(['designation_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $payload = ['name' => 'New cashier', 'email' => 'retry@example.com', 'password' => 'SecurePass123!', 'designation_id' => 99999];
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('designation_id');
        $this->assertDatabaseMissing('users', ['email' => 'retry@example.com']);
        $payload['designation_id'] = $role->id;
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();
    }

    public function test_all_permissions_can_be_revoked_from_a_custom_role(): void
    {
        $adminRole = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $role = Designation::create(['name' => 'Custom', 'slug' => 'custom']);
        $permission = Permission::firstOrCreate(['slug' => 'inventory.view'], ['name' => 'View stock', 'module' => 'Inventory']);
        $role->permissions()->attach($permission);
        $admin = User::factory()->create(['designation_id' => $adminRole->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $this->putJson('/api/v1/rbac/designations/'.$role->id.'/permissions', ['permission_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data.permissions');
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_final_administrator_cannot_be_demoted_but_other_admins_can(): void
    {
        $adminRole = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $cashierRole = Designation::create(['name' => 'Cashier', 'slug' => 'cashier']);
        $admin = User::factory()->create(['designation_id' => $adminRole->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $url = '/api/v1/rbac/users/'.$admin->id.'/designation';
        $this->putJson($url, ['designation_id' => $cashierRole->id])->assertUnprocessable();
        $this->assertSame($adminRole->id, $admin->fresh()->designation_id);
        User::factory()->create(['designation_id' => $adminRole->id]);
        $this->putJson($url, ['designation_id' => $cashierRole->id])->assertOk();
        $this->assertSame($cashierRole->id, $admin->fresh()->designation_id);
    }

    public function test_refresh_rotates_once_and_invalid_tokens_return_unauthorized(): void
    {
        $user = User::factory()->create();
        $refresh = app(TokenServiceInterface::class)->generateRefreshToken($user);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'invalid-token'])->assertUnauthorized();
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

        $tokenService = app(TokenServiceInterface::class);
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
