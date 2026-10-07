<?php

declare(strict_types=1);

namespace App\DTOs\Drive;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final readonly class UploadFileDTO
{
    public function __construct(
        public UploadedFile $file,
        public ?string $customName = null,
        public ?int $parentId = null,
        public bool $encryptAtRest = true
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        return new self(
            file: $file,
            customName: $request->filled('name') ? trim((string) $request->validated('name')) : null,
            parentId: $request->has('parent_id') && $request->validated('parent_id') !== null
                ? (int) $request->validated('parent_id')
                : null,
            encryptAtRest: filter_var($request->input('encrypt', true), FILTER_VALIDATE_BOOLEAN)
        );
    }
}
