<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\EncryptionService;
use Tests\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EncryptionService('0123456789abcdef0123456789abcdef');
    }

    public function test_it_encrypts_and_decrypts_strings_and_arrays(): void
    {
        $payload = [
            'user_id' => 123,
            'email' => 'alex@example.com',
            'roles' => ['admin', 'owner'],
        ];

        $encrypted = $this->service->encrypt($payload);
        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals(json_encode($payload), $encrypted);

        $decrypted = $this->service->decrypt($encrypted);
        $this->assertEquals($payload, $decrypted);
    }

    public function test_it_encrypts_and_decrypts_binary_data(): void
    {
        $binary = random_bytes(1024);

        $encrypted = $this->service->encryptBinary($binary);
        $this->assertNotEquals($binary, $encrypted);

        $decrypted = $this->service->decryptBinary($encrypted);
        $this->assertEquals($binary, $decrypted);
    }

    public function test_it_generates_sha256_hash(): void
    {
        $data = 'Antigravity High Performance Drive File';
        $hash = $this->service->hash($data);

        $this->assertEquals(hash('sha256', $data), $hash);
    }
}
