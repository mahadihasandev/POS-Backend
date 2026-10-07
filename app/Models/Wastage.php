<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wastage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_name',
        'quantity',
        'unit_cost',
        'total_loss',
        'reason',
        'wastage_date',
        'note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'float',
        'total_loss' => 'float',
        'wastage_date' => 'date:Y-m-d',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
