<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_no',
        'invoice_id',
        'customer_id',
        'supplier_id',
        'return_date',
        'return_amount',
        'exchange_amount',
        'net_adjustment',
        'previous_due',
        'cash_refund',
        'final_due',
        'comments',
    ];

    protected $casts = [
        'return_amount' => 'float',
        'exchange_amount' => 'float',
        'net_adjustment' => 'float',
        'previous_due' => 'float',
        'cash_refund' => 'float',
        'final_due' => 'float',
        'return_date' => 'date:Y-m-d',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
