<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TokenServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriveApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->user = User::factory()->create([
            'email' => 'driver@example.com',
        ]);

        $tokenService = app(TokenServiceInterface::class);
        $this->token = $tokenService->generateAccessToken($this->user);
    }

    public function test_user_can_create_folder(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/drive/folders', [
                'name' => 'Project Documents',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Project Documents',
                    'type' => 'folder',
                ],
            ]);

        $this->assertDatabaseHas('drive_items', [
            'user_id' => $this->user->id,
            'name' => 'Project Documents',
            'type' => 'folder',
        ]);
    }

    public function test_user_can_upload_and_download_encrypted_file(): void
    {
        $fileContent = 'Secret Confidential Payload Content';
        $uploadedFile = UploadedFile::fake()->createWithContent('secret_report.txt', $fileContent);

        // Upload
        $uploadResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/drive/upload', [
                'file' => $uploadedFile,
                'encrypt' => true,
            ]);

        $uploadResponse->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'secret_report.txt',
                    'type' => 'file',
                    'is_encrypted' => true,
                ],
            ]);

        $uuid = $uploadResponse->json('data.uuid');
        $this->assertNotEmpty($uuid);

        // Download and verify decrypted content matches original
        $downloadResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->get("/api/v1/drive/items/{$uuid}/download");

        $downloadResponse->assertStatus(200);
        $this->assertEquals($fileContent, $downloadResponse->streamedContent());
    }

    public function test_user_can_list_drive_items_and_view_storage_summary(): void
    {
        // Create a folder first
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/drive/folders', ['name' => 'My Photos']);

        // List
        $listResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/drive/items');

        $listResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'pagination' => ['total', 'current_page'],
            ]);

        // Summary
        $summaryResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/drive/summary');

        $summaryResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['used_bytes', 'total_bytes', 'free_bytes', 'usage_percentage'],
            ]);
    }
}
