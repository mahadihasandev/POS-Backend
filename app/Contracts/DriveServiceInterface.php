<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Drive\CreateFolderDTO;
use App\DTOs\Drive\DriveQueryDTO;
use App\DTOs\Drive\UploadFileDTO;
use App\Models\DriveItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface DriveServiceInterface
{
    /**
     * Create a new folder.
     */
    public function createFolder(User $user, CreateFolderDTO $dto): DriveItem;

    /**
     * Upload and securely encrypt a file on disk.
     */
    public function uploadFile(User $user, UploadFileDTO $dto): DriveItem;

    /**
     * Query drive items for a user.
     */
    public function listItems(User $user, DriveQueryDTO $queryDto): LengthAwarePaginator;

    /**
     * Get item details by UUID.
     */
    public function getItem(User $user, string $uuid): DriveItem;

    /**
     * Download or stream decrypted file content.
     */
    public function downloadFile(User $user, string $uuid): StreamedResponse;

    /**
     * Toggle starred flag.
     */
    public function toggleStar(User $user, string $uuid): DriveItem;

    /**
     * Trash an item (soft delete).
     */
    public function trashItem(User $user, string $uuid): bool;

    /**
     * Restore an item.
     */
    public function restoreItem(User $user, string $uuid): bool;

    /**
     * Permanently delete an item and its stored file from disk.
     */
    public function deletePermanently(User $user, string $uuid): bool;

    /**
     * Get user storage quota and usage summary.
     */
    public function getStorageSummary(User $user): array;
}
