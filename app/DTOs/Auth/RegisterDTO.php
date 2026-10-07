<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use Illuminate\Http\Request;

final readonly class RegisterDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?string $ipAddress = null,
        public ?string $userAgent = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: (string) $request->validated('name'),
            email: strtolower(trim((string) $request->validated('email'))),
            password: (string) $request->validated('password'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );
    }
}
