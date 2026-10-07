<?php

declare(strict_types=1);

namespace App\Contracts;

interface EncryptionServiceInterface
{
    /**
     * Encrypt an arbitrary string or data array using AES-256-GCM.
     */
    public function encrypt(mixed $data): string;

    /**
     * Decrypt an AES-256-GCM ciphertext payload back to its original value.
     */
    public function decrypt(string $payload): mixed;

    /**
     * Encrypt binary file content at rest.
     */
    public function encryptBinary(string $binaryData): string;

    /**
     * Decrypt binary file content from at-rest ciphertext.
     */
    public function decryptBinary(string $encryptedData): string;

    /**
     * Generate a cryptographic hash for data integrity verification (e.g. SHA-256).
     */
    public function hash(string $data): string;
}
