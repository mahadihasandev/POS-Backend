<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_no',
        'transfer_type',
        'source_name',
        'destination_name',
        'transfer_date',
        'total_items',
        'status',
        'note',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'transfer_date' => 'date:Y-m-d',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
