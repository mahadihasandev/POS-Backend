<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'uuid',
    'user_id',
    'parent_id',
    'name',
    'type',
    'mime_type',
    'size_bytes',
    'storage_path',
    'checksum',
    'is_encrypted',
    'is_starred',
    'is_trashed',
    'metadata',
])]
class DriveItem extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
            'is_starred' => 'boolean',
            'is_trashed' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * User who owns the item.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parent folder if nested.
     *
     * @return BelongsTo<DriveItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DriveItem::class, 'parent_id');
    }

    /**
     * Child files and folders.
     *
     * @return HasMany<DriveItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DriveItem::class, 'parent_id');
    }

    /**
     * Scope to non-trashed items.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_trashed', false);
    }

    /**
     * Scope to trashed items.
     */
    public function scopeTrashedItems(Builder $query): Builder
    {
        return $query->where('is_trashed', true);
    }

    /**
     * Scope to starred items.
     */
    public function scopeStarred(Builder $query): Builder
    {
        return $query->where('is_starred', true);
    }

    /**
     * Scope to only files.
     */
    public function scopeFiles(Builder $query): Builder
    {
        return $query->where('type', 'file');
    }

    /**
     * Scope to only folders.
     */
    public function scopeFolders(Builder $query): Builder
    {
        return $query->where('type', 'folder');
    }

    public function isFolder(): bool
    {
        return $this->type === 'folder';
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }
}
