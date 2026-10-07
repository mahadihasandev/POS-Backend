<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'phone',
        'area',
        'previous_due',
        'advanced_amount',
    ];

    protected function casts(): array
    {
        return [
            'previous_due' => 'decimal:2',
            'advanced_amount' => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CustomerCollection::class);
    }
}
