<?php

declare(strict_types=1);

namespace App\DTOs\Drive;

use Illuminate\Http\Request;

final readonly class CreateFolderDTO
{
    public function __construct(
        public string $name,
        public ?int $parentId = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: trim((string) $request->validated('name')),
            parentId: $request->has('parent_id') && $request->validated('parent_id') !== null
                ? (int) $request->validated('parent_id')
                : null
        );
    }
}
