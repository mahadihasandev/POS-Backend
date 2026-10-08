<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierPayment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PosRules
{
    public static function number(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.Str::ulid();
    }

    public static function requireStock(Product $product, int $quantity): void
    {
        if (! $product->is_active || $product->available_qty < $quantity) {
            throw ValidationException::withMessages(['items' => "Insufficient available stock for {$product->name}."]);
        }
    }

    public static function debit(FinancialAccount $account, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount > (float) $account->balance) {
            throw ValidationException::withMessages(['account' => "Insufficient funds in {$account->name}."]);
        }
        $account->decrement('balance', $amount);
    }

    public static function supplierDue(int $supplierId): float
    {
        return round(max(0,
            Purchase::where('supplier_id', $supplierId)->sum('due_amount')
            - SupplierPayment::where('supplier_id', $supplierId)->sum('paid_amount')
            - SupplierPayment::where('supplier_id', $supplierId)->sum('discount')
            - PurchaseReturn::where('supplier_id', $supplierId)->sum('due_deduction')
        ), 2);
    }

    public static function revenue(): string
    {
        // Previous debt and customer advance are settlement amounts, not new sales.
        return 'invoice_total - discount - special_discount + CASE WHEN delivery_payer = \'customer\' THEN delivery_charge ELSE 0 END';
    }
}
