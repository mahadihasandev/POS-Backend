<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketer_id',
        'payment_date',
        'amount',
        'payment_method',
        'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'payment_date' => 'date:Y-m-d',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }
}
