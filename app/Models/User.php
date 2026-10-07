<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'designation_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function driveItems(): HasMany
    {
        return $this->hasMany(DriveItem::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Check if user has specific permission.
     * Admin role bypasses all checks.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if (!$this->relationLoaded('designation')) {
            $this->load(['designation.permissions']);
        }

        if ($this->designation?->slug === 'admin') {
            return true;
        }

        if (!$this->designation) {
            return false;
        }

        return $this->designation->permissions->contains('slug', $permissionSlug);
    }

    /**
     * Get array of all user permission slugs.
     *
     * @return array<string>
     */
    public function getPermissionSlugs(): array
    {
        if (!$this->relationLoaded('designation')) {
            $this->load(['designation.permissions']);
        }

        if ($this->designation?->slug === 'admin') {
            return Permission::pluck('slug')->all();
        }

        return $this->designation?->permissions->pluck('slug')->all() ?? [];
    }
}
