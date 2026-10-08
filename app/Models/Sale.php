<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'invoice_id',
        'outlet_id',
        'customer_id',
        'supplier_id',
        'user_id',
        'marketer_id',
        'sale_date',
        'sale_type',
        'note',
        'invoice_total',
        'discount',
        'special_discount',
        'delivery_charge',
        'delivery_payer',
        'previous_due',
        'advanced',
        'payable_amount',
        'paid_amount',
        'due_amount',
        'change_return',
        'payment_account',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'invoice_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'special_discount' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'previous_due' => 'decimal:2',
            'advanced' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'change_return' => 'decimal:2',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
