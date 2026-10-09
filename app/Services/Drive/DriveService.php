<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Contracts\DriveItemRepositoryInterface;
use App\Contracts\DriveServiceInterface;
use App\Contracts\EncryptionServiceInterface;
use App\DTOs\Drive\CreateFolderDTO;
use App\DTOs\Drive\DriveQueryDTO;
use App\DTOs\Drive\UploadFileDTO;
use App\Models\DriveItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

class DriveService implements DriveServiceInterface
{
    private const DEFAULT_QUOTA_BYTES = 10737418240; // 10 GB default quota

    public function __construct(
        private readonly DriveItemRepositoryInterface $driveItemRepository,
        private readonly EncryptionServiceInterface $encryptionService
    ) {}

    public function createFolder(User $user, CreateFolderDTO $dto): DriveItem
    {
        if ($dto->parentId !== null) {
            $parent = $this->driveItemRepository->findUserItemByUuid($user->id, (string) $dto->parentId)
                ?? $this->driveItemRepository->findById($dto->parentId);

            if (!$parent || $parent->user_id !== $user->id || !$parent->isFolder()) {
                throw new UnexpectedValueException('Invalid parent folder specified.');
            }
        }

        /** @var DriveItem $folder */
        $folder = $this->driveItemRepository->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'parent_id' => $dto->parentId,
            'name' => $dto->name,
            'type' => 'folder',
            'mime_type' => 'application/x-directory',
            'size_bytes' => 0,
            'is_encrypted' => false,
        ]);

        return $folder;
    }

    public function uploadFile(User $user, UploadFileDTO $dto): DriveItem
    {
        $uploaded = $dto->file;
        $originalName = $dto->customName ?? $uploaded->getClientOriginalName();
        $mimeType = $uploaded->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $uploaded->getSize();

        // Check storage quota
        $currentUsage = $this->driveItemRepository->getUserTotalStorageUsed($user->id);
        if (($currentUsage + $sizeBytes) > self::DEFAULT_QUOTA_BYTES) {
            throw new UnexpectedValueException('Storage quota exceeded.');
        }

        $rawContent = file_get_contents($uploaded->getRealPath());
        if ($rawContent === false) {
            throw new UnexpectedValueException('Failed to read uploaded file.');
        }

        $checksum = $this->encryptionService->hash($rawContent);
        $uuid = (string) Str::uuid();
        $storageDisk = Storage::disk(config('filesystems.default', 'local'));
        $relativeDir = "drive/{$user->id}";

        if ($dto->encryptAtRest) {
            $contentToStore = $this->encryptionService->encryptBinary($rawContent);
            $fileName = "{$uuid}.enc";
        } else {
            $contentToStore = $rawContent;
            $fileName = "{$uuid}.dat";
        }

        $storagePath = "{$relativeDir}/{$fileName}";
        $storageDisk->put($storagePath, $contentToStore);

        /** @var DriveItem $item */
        $item = $this->driveItemRepository->create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'parent_id' => $dto->parentId,
            'name' => $originalName,
            'type' => 'file',
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'storage_path' => $storagePath,
            'checksum' => $checksum,
            'is_encrypted' => $dto->encryptAtRest,
            'metadata' => [
                'original_extension' => $uploaded->getClientOriginalExtension(),
                'stored_size_bytes' => strlen($contentToStore),
            ],
        ]);

        return $item;
    }

    public function listItems(User $user, DriveQueryDTO $queryDto): LengthAwarePaginator
    {
        return $this->driveItemRepository->queryUserItems($user->id, $queryDto);
    }

    public function getItem(User $user, string $uuid): DriveItem
    {
        $item = $this->driveItemRepository->findUserItemByUuid($user->id, $uuid);

        if (!$item) {
            throw (new ModelNotFoundException())->setModel(DriveItem::class, [$uuid]);
        }

        return $item;
    }

    public function downloadFile(User $user, string $uuid): StreamedResponse
    {
        $item = $this->getItem($user, $uuid);

        if ($item->isFolder()) {
            throw new UnexpectedValueException('Folders cannot be downloaded directly.');
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));
        if (!$disk->exists((string) $item->storage_path)) {
            throw new ModelNotFoundException('Physical file not found in storage.');
        }

        $rawStored = $disk->get((string) $item->storage_path);
        if ($rawStored === null) {
            throw new UnexpectedValueException('Unable to read file storage.');
        }

        $payload = $item->is_encrypted
            ? $this->encryptionService->decryptBinary($rawStored)
            : $rawStored;

        $fileName = addslashes($item->name);

        return response()->stream(
            function () use ($payload) {
                echo $payload;
            },
            200,
            [
                'Content-Type' => $item->mime_type ?? 'application/octet-stream',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
                'Content-Length' => (string) strlen($payload),
                'X-Checksum-SHA256' => (string) $item->checksum,
            ]
        );
    }

    public function toggleStar(User $user, string $uuid): DriveItem
    {
        $item = $this->getItem($user, $uuid);

        return $this->driveItemRepository->toggleStar($item);
    }

    public function trashItem(User $user, string $uuid): bool
    {
        $item = $this->getItem($user, $uuid);

        return $this->driveItemRepository->trashItem($item);
    }

    public function restoreItem(User $user, string $uuid): bool
    {
        $item = $this->getItem($user, $uuid);

        return $this->driveItemRepository->restoreItem($item);
    }

    public function deletePermanently(User $user, string $uuid): bool
    {
        $item = $this->getItem($user, $uuid);

        if ($item->isFile() && !empty($item->storage_path)) {
            Storage::disk(config('filesystems.default', 'local'))->delete((string) $item->storage_path);
        }

        return $this->driveItemRepository->delete($item);
    }

    public function getStorageSummary(User $user): array
    {
        $usedBytes = $this->driveItemRepository->getUserTotalStorageUsed($user->id);
        $totalBytes = self::DEFAULT_QUOTA_BYTES;
        $freeBytes = max($totalBytes - $usedBytes, 0);

        return [
            'used_bytes' => $usedBytes,
            'total_bytes' => $totalBytes,
            'free_bytes' => $freeBytes,
            'usage_percentage' => round(($usedBytes / $totalBytes) * 100, 2),
        ];
    }
}
