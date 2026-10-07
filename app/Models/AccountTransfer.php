<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_no',
        'from_account',
        'to_account',
        'amount',
        'transfer_date',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }
}
