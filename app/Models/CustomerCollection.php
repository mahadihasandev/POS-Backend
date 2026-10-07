<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_number',
        'customer_id',
        'user_id',
        'collection_date',
        'payment_method',
        'account',
        'receivable_due',
        'discount_amount',
        'paid_amount',
        'send_sms',
    ];

    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'receivable_due' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'send_sms' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
