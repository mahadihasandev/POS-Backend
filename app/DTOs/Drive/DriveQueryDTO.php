<?php

declare(strict_types=1);

namespace App\DTOs\Drive;

use Illuminate\Http\Request;

final readonly class DriveQueryDTO
{
    public function __construct(
        public ?int $parentId = null,
        public ?string $type = null, // 'file', 'folder', or null for all
        public ?string $search = null,
        public ?bool $isStarred = null,
        public bool $isTrashed = false,
        public string $sortBy = 'created_at',
        public string $sortOrder = 'desc',
        public int $perPage = 25,
        public int $page = 1
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            parentId: $request->has('parent_id') && $request->filled('parent_id')
                ? (int) $request->input('parent_id')
                : null,
            type: $request->filled('type') ? (string) $request->input('type') : null,
            search: $request->filled('q') ? trim((string) $request->input('q')) : null,
            isStarred: $request->has('starred') ? filter_var($request->input('starred'), FILTER_VALIDATE_BOOLEAN) : null,
            isTrashed: filter_var($request->input('trashed', false), FILTER_VALIDATE_BOOLEAN),
            sortBy: (string) $request->input('sort_by', 'created_at'),
            sortOrder: strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc',
            perPage: min(max((int) $request->input('per_page', 25), 1), 100),
            page: max((int) $request->input('page', 1), 1)
        );
    }
}
