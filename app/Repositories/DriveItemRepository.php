<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DriveItemRepositoryInterface;
use App\DTOs\Drive\DriveQueryDTO;
use App\Models\DriveItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class DriveItemRepository extends BaseRepository implements DriveItemRepositoryInterface
{
    public function __construct(DriveItem $model)
    {
        parent::__construct($model);
    }

    public function findUserItemByUuid(int $userId, string $uuid): ?DriveItem
    {
        /** @var DriveItem|null $item */
        $item = $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('uuid', $uuid)
            ->first();

        return $item;
    }

    public function queryUserItems(int $userId, DriveQueryDTO $queryDto): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('user_id', $userId);

        if ($queryDto->isTrashed) {
            $query->trashedItems();
        } else {
            $query->active();
        }

        if ($queryDto->parentId !== null) {
            $query->where('parent_id', $queryDto->parentId);
        } elseif (!$queryDto->isTrashed && empty($queryDto->search) && $queryDto->isStarred === null) {
            // Default to root directory if no search or special filter
            $query->whereNull('parent_id');
        }

        if ($queryDto->type !== null) {
            $query->where('type', $queryDto->type);
        }

        if ($queryDto->isStarred !== null) {
            $query->where('is_starred', $queryDto->isStarred);
        }

        if (!empty($queryDto->search)) {
            $searchTerm = '%' . $queryDto->search . '%';
            $query->where('name', 'like', $searchTerm);
        }

        $allowedSort = ['name', 'created_at', 'updated_at', 'size_bytes', 'type'];
        $sortBy = in_array($queryDto->sortBy, $allowedSort, true) ? $queryDto->sortBy : 'created_at';

        // Folders always first, then requested sort
        $query->orderByRaw("CASE WHEN type = 'folder' THEN 0 ELSE 1 END")
            ->orderBy($sortBy, $queryDto->sortOrder);

        return $query->paginate($queryDto->perPage, ['*'], 'page', $queryDto->page);
    }

    public function getFolderContents(int $userId, ?int $parentId = null): Collection
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->active()
            ->where('parent_id', $parentId)
            ->orderByRaw("CASE WHEN type = 'folder' THEN 0 ELSE 1 END")
            ->orderBy('name', 'asc')
            ->get();
    }

    public function getUserTotalStorageUsed(int $userId): int
    {
        return (int) $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('type', 'file')
            ->where('is_trashed', false)
            ->sum('size_bytes');
    }

    public function trashItem(DriveItem $item): bool
    {
        return $item->update(['is_trashed' => true]);
    }

    public function restoreItem(DriveItem $item): bool
    {
        return $item->update(['is_trashed' => false]);
    }

    public function toggleStar(DriveItem $item): DriveItem
    {
        $item->update(['is_starred' => !$item->is_starred]);

        return $item->fresh();
    }
}
