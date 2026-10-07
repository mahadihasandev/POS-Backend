<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerSlab extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketer_id',
        'start_amount',
        'end_amount',
        'percentage',
    ];

    protected $casts = [
        'start_amount' => 'float',
        'end_amount' => 'float',
        'percentage' => 'float',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }
}
