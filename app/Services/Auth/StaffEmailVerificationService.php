<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\StaffVerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class StaffEmailVerificationService
{
    private const CODE_LIFETIME_SECONDS = 600;

    private const RESEND_DELAY_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function send(string $email, int $administratorId): void
    {
        $email = strtolower(trim($email));
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['A staff account with this email address already exists.']]);
        }

        // Production must use a real mail transport. The default log mailer is
        // useful locally but does not prove that the employee received a code.
        if (app()->environment('production') && (in_array(config('mail.default'), ['log', 'array', 'null'], true)
            || in_array(config('mail.from.address'), [null, '', 'hello@example.com'], true))) {
            throw ValidationException::withMessages(['email' => ['Email delivery is not configured. Contact the system administrator.']]);
        }

        if (app()->environment('production') && in_array(config('cache.default'), ['array', 'null'], true)) {
            throw ValidationException::withMessages(['email' => ['A persistent cache is required for email verification. Contact the system administrator.']]);
        }

        $key = $this->key($email, $administratorId);
        $cooldownKey = $key.':cooldown';
        if (! Cache::add($cooldownKey, true, self::RESEND_DELAY_SECONDS)) {
            throw ValidationException::withMessages(['email' => ['Please wait one minute before sending another code.']]);
        }

        $code = (string) random_int(100000, 999999);
        try {
            Mail::to($email)->send(new StaffVerificationCodeMail($code));
            Cache::put($key, [
                'hash' => $this->hashCode($code),
                'attempts' => 0,
                'expires_at' => now()->addSeconds(self::CODE_LIFETIME_SECONDS)->timestamp,
            ], self::CODE_LIFETIME_SECONDS);
        } catch (\Throwable $error) {
            Cache::forget($cooldownKey);
            throw $error;
        }
    }

    /** @template T */
    /** @param callable(): T $register */
    /** @return T */
    public function complete(string $email, int $administratorId, string $code, callable $register): mixed
    {
        $key = $this->key(strtolower(trim($email)), $administratorId);

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $code, $register): mixed {
            /** @var array{hash: string, attempts: int, expires_at: int}|null $stored */
            $stored = Cache::get($key);
            if (! $stored || $stored['expires_at'] <= now()->timestamp) {
                Cache::forget($key);
                throw ValidationException::withMessages(['verification_code' => ['The code expired. Send a new code to continue.']]);
            }

            if (! hash_equals($stored['hash'], $this->hashCode($code))) {
                $stored['attempts']++;
                if ($stored['attempts'] >= self::MAX_ATTEMPTS) {
                    Cache::forget($key);
                    throw ValidationException::withMessages(['verification_code' => ['Too many incorrect attempts. Send a new code.']]);
                }
                Cache::put($key, $stored, max(1, $stored['expires_at'] - now()->timestamp));
                throw ValidationException::withMessages(['verification_code' => ['Incorrect code. Please check the email and try again.']]);
            }

            $result = $register();
            Cache::forget($key);

            return $result;
        });
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function key(string $email, int $administratorId): string
    {
        return 'staff-email-code:'.$administratorId.':'.hash('sha256', $email);
    }
}
