<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_no',
        'supplier_id',
        'payment_date',
        'payment_method',
        'account',
        'previous_due',
        'discount',
        'paid_amount',
        'remaining_due',
        'note',
    ];

    protected $casts = [
        'previous_due' => 'float',
        'discount' => 'float',
        'paid_amount' => 'float',
        'remaining_due' => 'float',
        'payment_date' => 'date:Y-m-d',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
