<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TokenServiceInterface;
use App\Mail\StaffVerificationCodeMail;
use App\Models\Designation;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_account_is_created_only_after_the_email_code_is_entered(): void
    {
        Mail::fake();
        $payload = ['name' => 'Alice Cooper', 'email' => 'alice@example.com', 'password' => 'SecurePass123!'];
        $this->postJson('/api/v1/auth/register/request-code', ['email' => $payload['email']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/register', $payload)->assertUnauthorized();

        $adminRole = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = User::factory()->create(['designation_id' => $adminRole->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');
        $this->postJson('/api/v1/auth/register/request-code', ['email' => 'ALICE@example.com'])->assertOk();
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        Mail::assertSent(StaffVerificationCodeMail::class, 1);
        $code = $this->sentCodeTo('alice@example.com');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertStringContainsString($code, (new StaffVerificationCodeMail($code))->render());

        $this->postJson('/api/v1/auth/register', $payload + ['verification_code' => $code])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonMissingPath('data.access_token');
        $this->assertNotNull(User::where('email', 'alice@example.com')->firstOrFail()->email_verified_at);
        $this->getJson('/api/v1/rbac/users')->assertOk()->assertJsonPath('data.1.email_verified_at', User::where('email', 'alice@example.com')->firstOrFail()->email_verified_at->toJSON());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $admin->id);
        $this->postJson('/api/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'SecurePass123!'])->assertOk();
    }

    public function test_staff_registration_assigns_role_and_rejects_invalid_role_without_consuming_code(): void
    {
        Mail::fake();
        $adminRole = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $cashierRole = Designation::create(['name' => 'Cashier', 'slug' => 'cashier']);
        $admin = User::factory()->create(['designation_id' => $adminRole->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $payload = ['name' => 'New cashier', 'email' => 'cashier@example.com', 'password' => 'SecurePass123!', 'designation_id' => 99999];
        $this->postJson('/api/v1/auth/register/request-code', ['email' => $payload['email']])->assertOk();
        $payload['verification_code'] = $this->sentCodeTo($payload['email']);
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('designation_id');
        $this->assertDatabaseMissing('users', ['email' => 'cashier@example.com']);
        $payload['designation_id'] = $cashierRole->id;
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated()
            ->assertJsonPath('data.user.designation.id', $cashierRole->id);
        $this->assertDatabaseHas('users', ['email' => 'cashier@example.com', 'designation_id' => $cashierRole->id]);
    }

    public function test_code_is_bound_to_requesting_admin_and_email_and_has_attempt_limit(): void
    {
        Mail::fake();
        $role = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = User::factory()->create(['designation_id' => $role->id]);
        $otherAdmin = User::factory()->create(['designation_id' => $role->id]);
        $tokenService = app(TokenServiceInterface::class);
        $this->withHeader('Authorization', 'Bearer '.$tokenService->generateAccessToken($admin));
        $this->postJson('/api/v1/auth/register/request-code', ['email' => 'new@example.com'])->assertOk();
        $code = $this->sentCodeTo('new@example.com');
        $payload = ['name' => 'New staff', 'email' => 'new@example.com', 'password' => 'SecurePass123!', 'verification_code' => $code];
        $this->withHeader('Authorization', 'Bearer '.$tokenService->generateAccessToken($otherAdmin));
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('verification_code');
        $this->withHeader('Authorization', 'Bearer '.$tokenService->generateAccessToken($admin));
        $this->postJson('/api/v1/auth/register', array_replace($payload, ['email' => 'other@example.com']))->assertUnprocessable();
        $this->postJson('/api/v1/auth/register/request-code', ['email' => $payload['email']])->assertUnprocessable();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', array_replace($payload, ['verification_code' => $wrong]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_code_expires_after_ten_minutes_and_can_be_resent_after_cooldown(): void
    {
        Mail::fake();
        $role = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin = User::factory()->create(['designation_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($admin));
        $payload = ['name' => 'New staff', 'email' => 'later@example.com', 'password' => 'SecurePass123!'];
        $this->postJson('/api/v1/auth/register/request-code', ['email' => $payload['email']])->assertOk();
        $oldCode = $this->sentCodeTo($payload['email']);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/register', $payload + ['verification_code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/v1/auth/register/request-code', ['email' => $payload['email']])->assertOk();
        Mail::assertSent(StaffVerificationCodeMail::class, 2);
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

    private function sentCodeTo(string $email): string
    {
        $code = null;
        Mail::assertSent(StaffVerificationCodeMail::class, function (StaffVerificationCodeMail $mail) use ($email, &$code): bool {
            if (! $mail->hasTo($email)) {
                return false;
            }
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code);

        return $code;
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
