<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Drive\DriveQueryDTO;
use App\Models\DriveItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface DriveItemRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find item by user ID and item UUID.
     */
    public function findUserItemByUuid(int $userId, string $uuid): ?DriveItem;

    /**
     * Get paginated or filtered drive items for a user.
     */
    public function queryUserItems(int $userId, DriveQueryDTO $queryDto): LengthAwarePaginator;

    /**
     * Get all child items within a parent folder.
     */
    public function getFolderContents(int $userId, ?int $parentId = null): Collection;

    /**
     * Calculate total storage size in bytes used by a user.
     */
    public function getUserTotalStorageUsed(int $userId): int;

    /**
     * Soft delete an item.
     */
    public function trashItem(DriveItem $item): bool;

    /**
     * Restore a trashed item.
     */
    public function restoreItem(DriveItem $item): bool;

    /**
     * Toggle starred status on an item.
     */
    public function toggleStar(DriveItem $item): DriveItem;
}
