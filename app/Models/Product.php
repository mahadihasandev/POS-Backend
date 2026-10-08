<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'low_stock_threshold',
        'is_active',
        'supplier_id',
        'name',
        'code',
        'barcode',
        'available_qty',
        'unit_price',
        'cost_price',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'low_stock_threshold' => 'integer',
            'available_qty' => 'integer',
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
