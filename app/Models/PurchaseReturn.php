<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_no',
        'chalan_no',
        'supplier_id',
        'outlet_id',
        'return_date',
        'total_return_amount',
        'cash_refund',
        'due_deduction',
        'payment_account',
        'note',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'total_return_amount' => 'decimal:2',
            'cash_refund' => 'decimal:2',
            'due_deduction' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
