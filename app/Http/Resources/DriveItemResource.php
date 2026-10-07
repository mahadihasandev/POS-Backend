<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriveItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DriveItem
 */
class DriveItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'formatted_size' => $this->formatBytes($this->size_bytes),
            'checksum' => $this->checksum,
            'is_encrypted' => $this->is_encrypted,
            'is_starred' => $this->is_starred,
            'is_trashed' => $this->is_trashed,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($bytes, 1024);
        $floor = (int) floor($base);

        return round(pow(1024, $base - $floor), $precision) . ' ' . ($units[$floor] ?? 'B');
    }
}
