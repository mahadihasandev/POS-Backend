<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\EncryptionServiceInterface;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class EncryptionService implements EncryptionServiceInterface
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12; // 96 bits recommended for GCM

    private string $key;

    public function __construct(?string $key = null)
    {
        $rawKey = $key ?? (string) Config::get('jwt.encryption_key', Config::get('app.key'));

        // If base64 encoded Laravel key, extract raw binary
        if (str_starts_with($rawKey, 'base64:')) {
            $rawKey = base64_decode(substr($rawKey, 7), true) ?: $rawKey;
        }

        // Derive deterministic 256-bit binary key using SHA-256
        $this->key = hash('sha256', $rawKey, true);
    }

    /**
     * Encrypt an arbitrary string or data array using AES-256-GCM.
     */
    public function encrypt(mixed $data): string
    {
        $serialized = json_encode($data, JSON_THROW_ON_ERROR);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $serialized,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Output format: iv (12) + tag (16) + ciphertext, base64url encoded
        $binary = $iv . $tag . $ciphertext;

        return self::base64UrlEncode($binary);
    }

    /**
     * Decrypt an AES-256-GCM ciphertext payload back to its original value.
     */
    public function decrypt(string $payload): mixed
    {
        $binary = self::base64UrlDecode($payload);

        $minLen = self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($binary) < $minLen) {
            throw new RuntimeException('Invalid encrypted payload length.');
        }

        $iv = substr($binary, 0, self::IV_LENGTH);
        $tag = substr($binary, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($binary, $minLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption or authentication tag verification failed.');
        }

        return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Encrypt binary file content at rest.
     */
    public function encryptBinary(string $binaryData): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $binaryData,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Binary encryption failed: ' . openssl_error_string());
        }

        return $iv . $tag . $ciphertext;
    }

    /**
     * Decrypt binary file content from at-rest ciphertext.
     */
    public function decryptBinary(string $encryptedData): string
    {
        $minLen = self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($encryptedData) < $minLen) {
            throw new RuntimeException('Encrypted binary data corrupted or invalid.');
        }

        $iv = substr($encryptedData, 0, self::IV_LENGTH);
        $tag = substr($encryptedData, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($encryptedData, $minLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Binary decryption or authentication failed.');
        }

        return $plaintext;
    }

    /**
     * Generate SHA-256 hash for data integrity.
     */
    public function hash(string $data): string
    {
        return hash('sha256', $data);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
