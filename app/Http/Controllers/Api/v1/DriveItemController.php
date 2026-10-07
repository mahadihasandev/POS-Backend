<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Contracts\DriveServiceInterface;
use App\DTOs\Drive\CreateFolderDTO;
use App\DTOs\Drive\DriveQueryDTO;
use App\DTOs\Drive\UploadFileDTO;
use App\Http\Requests\Drive\CreateFolderRequest;
use App\Http\Requests\Drive\DriveQueryRequest;
use App\Http\Requests\Drive\UploadFileRequest;
use App\Http\Resources\DriveItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriveItemController extends BaseApiController
{
    public function __construct(
        private readonly DriveServiceInterface $driveService
    ) {}

    /**
     * List user drive files and folders with filtering and pagination.
     */
    public function index(DriveQueryRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $queryDto = DriveQueryDTO::fromRequest($request);
        $paginator = $this->driveService->listItems($user, $queryDto);

        return response()->json([
            'success' => true,
            'message' => 'Drive items retrieved successfully.',
            'data' => DriveItemResource::collection($paginator->items()),
            'pagination' => [
                'total' => $paginator->total(),
                'count' => $paginator->count(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'total_pages' => $paginator->lastPage(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Create a new folder.
     */
    public function storeFolder(CreateFolderRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $dto = CreateFolderDTO::fromRequest($request);
        $folder = $this->driveService->createFolder($user, $dto);

        return $this->successResponse(
            data: new DriveItemResource($folder),
            message: 'Folder created successfully.',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Upload and optionally encrypt a file at rest.
     */
    public function uploadFile(UploadFileRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $dto = UploadFileDTO::fromRequest($request);
        $item = $this->driveService->uploadFile($user, $dto);

        return $this->successResponse(
            data: new DriveItemResource($item),
            message: 'File uploaded and secured successfully.',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Get metadata for a specific file or folder.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $item = $this->driveService->getItem($user, $uuid);

        return $this->successResponse(
            data: new DriveItemResource($item),
            message: 'Drive item retrieved successfully.'
        );
    }

    /**
     * Download or stream decrypted file content.
     */
    public function download(Request $request, string $uuid): StreamedResponse
    {
        $user = $this->getAuthenticatedUser($request);

        return $this->driveService->downloadFile($user, $uuid);
    }

    /**
     * Toggle starred flag on a drive item.
     */
    public function toggleStar(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $item = $this->driveService->toggleStar($user, $uuid);

        return $this->successResponse(
            data: new DriveItemResource($item),
            message: $item->is_starred ? 'Item added to starred.' : 'Item removed from starred.'
        );
    }

    /**
     * Move an item to trash.
     */
    public function trash(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $this->driveService->trashItem($user, $uuid);

        return $this->successResponse(
            message: 'Item moved to trash.'
        );
    }

    /**
     * Restore an item from trash.
     */
    public function restore(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $this->driveService->restoreItem($user, $uuid);

        return $this->successResponse(
            message: 'Item restored from trash.'
        );
    }

    /**
     * Permanently delete an item and its stored file.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $this->driveService->deletePermanently($user, $uuid);

        return $this->successResponse(
            message: 'Item permanently deleted.'
        );
    }

    /**
     * Get storage quota and usage summary.
     */
    public function storageSummary(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $summary = $this->driveService->getStorageSummary($user);

        return $this->successResponse(
            data: $summary,
            message: 'Storage usage calculated successfully.'
        );
    }
}
