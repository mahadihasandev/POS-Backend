<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Models\AccountTransfer;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\CustomerCollection;
use App\Models\FinancialAccount;
use App\Models\GeneralExpense;
use App\Models\Marketer;
use App\Models\MarketerPayment;
use App\Models\MarketerSlab;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Wastage;
use App\Support\PosRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ErpModulesController extends BaseApiController
{
    // ==========================================
    // 1. PURCHASES MODULE
    // ==========================================

    /**
     * Get paginated purchases list with supplier and search filters.
     */
    public function getPurchases(Request $request): JsonResponse
    {
        $query = Purchase::with(['supplier', 'outlet', 'items.product'])->latest('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('chalan_no')) {
            $query->where('chalan_no', 'like', '%'.$request->input('chalan_no').'%');
        }

        if ($request->filled('start_date')) {
            $query->whereDate('purchase_date', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('purchase_date', '<=', $request->input('end_date'));
        }

        $purchases = $query->paginate(15);

        return $this->successResponse($purchases);
    }

    /**
     * Store new purchase order / chalan.
     */
    public function storePurchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'outlet_id' => 'nullable|exists:outlets,id',
            'chalan_no' => 'required|string|unique:purchases,chalan_no',
            'purchase_date' => 'required|date',
            'note' => 'nullable|string',
            'discount' => 'numeric|min:0',
            'tax' => 'numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_account' => 'required|string|exists:financial_accounts,name',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.free_qty' => 'integer|min:0',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $purchase = DB::transaction(function () use ($validated) {
            Supplier::lockForUpdate()->findOrFail($validated['supplier_id']);
            $subtotal = 0.0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = (int) $item['quantity'];
                $freeQty = (int) ($item['free_qty'] ?? 0);
                $cost = (float) $item['unit_cost'];
                $lineSubtotal = round($qty * $cost, 2);
                $subtotal += $lineSubtotal;

                $product = Product::lockForUpdate()->find($item['product_id']);

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => $qty,
                    'free_qty' => $freeQty,
                    'unit_cost' => $cost,
                    'subtotal' => $lineSubtotal,
                ];

                // Increase stock in warehouse
                $product->increment('available_qty', $qty + $freeQty);
                // Optionally update product cost price
                if ($cost > 0) {
                    $product->update(['cost_price' => $cost]);
                }
            }

            $discount = (float) ($validated['discount'] ?? 0);
            $tax = (float) ($validated['tax'] ?? 0);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'Discount exceeds the purchase subtotal.']);
            }
            $totalPayable = round($subtotal - $discount + $tax, 2);
            $paid = (float) $validated['paid_amount'];
            $due = max(0.0, $totalPayable - $paid);

            $purchase = Purchase::create([
                'chalan_no' => $validated['chalan_no'],
                'supplier_id' => $validated['supplier_id'],
                'outlet_id' => $validated['outlet_id'] ?? null,
                'purchase_date' => $validated['purchase_date'],
                'note' => $validated['note'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total_payable' => $totalPayable,
                'paid_amount' => min($paid, $totalPayable),
                'due_amount' => $due,
                'payment_account' => $validated['payment_account'],
                'status' => 'completed',
            ]);

            foreach ($itemsData as $row) {
                PurchaseItem::create(array_merge($row, ['purchase_id' => $purchase->id]));
            }

            // Deduct payment from financial account if paid
            if ($paid > 0) {
                $account = FinancialAccount::where('name', $validated['payment_account'])->lockForUpdate()->firstOrFail();
                if ($account) {
                    PosRules::debit($account, min($paid, $totalPayable));
                }
            }

            return $purchase;
        });

        $purchase->load(['supplier', 'items']);

        return $this->successResponse($purchase, 'Purchase record and inventory updated successfully.', Response::HTTP_CREATED);
    }

    /**
     * Record supplier payment (due clearing).
     */
    public function storeSupplierPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
            'account' => 'required|string|exists:financial_accounts,name',
            'previous_due' => 'required|numeric|min:0',
            'discount' => 'numeric|min:0',
            'paid_amount' => 'required|numeric|min:0.01|decimal:0,2',
            'note' => 'nullable|string',
        ]);

        $payment = DB::transaction(function () use ($validated) {
            $paymentNo = PosRules::number('SPAY');
            Supplier::lockForUpdate()->findOrFail($validated['supplier_id']);
            $prevDue = PosRules::supplierDue((int) $validated['supplier_id']);
            $paid = (float) $validated['paid_amount'];
            $discount = (float) ($validated['discount'] ?? 0);
            if ($paid + $discount > $prevDue) {
                throw ValidationException::withMessages(['paid_amount' => 'Payment plus discount exceeds the current supplier due.']);
            }
            $remaining = round($prevDue - ($paid + $discount), 2);

            $record = SupplierPayment::create([
                'payment_no' => $paymentNo,
                'supplier_id' => $validated['supplier_id'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'account' => $validated['account'],
                'previous_due' => $prevDue,
                'discount' => $discount,
                'paid_amount' => $paid,
                'remaining_due' => $remaining,
                'note' => $validated['note'] ?? null,
            ]);

            // Deduct cash/bank balance
            $account = FinancialAccount::where('name', $validated['account'])->lockForUpdate()->firstOrFail();
            if ($account) {
                PosRules::debit($account, $paid);
            }

            return $record;
        });

        $payment->load('supplier');

        return $this->successResponse($payment, 'Supplier payment completed successfully.', Response::HTTP_CREATED);
    }

    public function getSupplierPayments(): JsonResponse
    {
        $payments = SupplierPayment::with('supplier')->latest('id')->paginate(15);

        return $this->successResponse($payments);
    }

    public function getPurchaseReturns(Request $request): JsonResponse
    {
        $query = PurchaseReturn::with(['supplier', 'outlet', 'items.product'])->latest('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('return_no')) {
            $query->where('return_no', 'like', '%'.$request->input('return_no').'%');
        }

        $records = $query->paginate(15);

        return $this->successResponse($records);
    }

    public function storePurchaseReturn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'outlet_id' => 'nullable|exists:outlets,id',
            'chalan_no' => 'required|string|exists:purchases,chalan_no',
            'return_date' => 'required|date',
            'cash_refund' => 'numeric|min:0',
            'due_deduction' => 'numeric|min:0',
            'payment_account' => 'required|string|exists:financial_accounts,name',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.reason' => 'required|string',
        ]);

        $purchaseReturn = DB::transaction(function () use ($validated) {
            Supplier::lockForUpdate()->findOrFail($validated['supplier_id']);
            $returnNo = PosRules::number('PRET');
            $original = Purchase::where('chalan_no', $validated['chalan_no'])->where('supplier_id', $validated['supplier_id'])->lockForUpdate()->first();
            if (! $original) {
                throw ValidationException::withMessages(['chalan_no' => 'Select a purchase from this supplier.']);
            }
            $totalReturnAmount = 0.0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $qty = (int) $item['quantity'];
                $originalItem = $original->items()->where('product_id', $item['product_id'])->first();
                $alreadyReturned = PurchaseReturnItem::where('product_id', $item['product_id'])
                    ->whereHas('purchaseReturn', fn ($q) => $q->where('chalan_no', $original->chalan_no))->sum('quantity');
                if (! $originalItem || $qty + $alreadyReturned > $originalItem->quantity + $originalItem->free_qty) {
                    throw ValidationException::withMessages(['items' => 'Return quantity exceeds the original purchase.']);
                }
                // Allocate the original net purchase cost across paid and free units.
                $netFactor = (float) $original->subtotal > 0 ? (float) $original->total_payable / (float) $original->subtotal : 0;
                $lineValue = round((float) $originalItem->subtotal * $netFactor, 2);
                $previousValue = (float) PurchaseReturnItem::where('product_id', $item['product_id'])
                    ->whereHas('purchaseReturn', fn ($q) => $q->where('chalan_no', $original->chalan_no))->sum('subtotal');
                $sub = min(round($lineValue * $qty / ($originalItem->quantity + $originalItem->free_qty), 2), max(0, round($lineValue - $previousValue, 2)));
                $cost = round($sub / $qty, 2);
                $totalReturnAmount += $sub;

                $product = Product::lockForUpdate()->find($item['product_id']);
                // Deduct inventory
                PosRules::requireStock($product, $qty);
                $product->decrement('available_qty', $qty);

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'subtotal' => $sub,
                    'reason' => $item['reason'] ?? 'defective',
                ];
            }

            $cashRefund = (float) ($validated['cash_refund'] ?? 0);
            if ($cashRefund > $totalReturnAmount) {
                throw ValidationException::withMessages(['cash_refund' => 'Refund exceeds the returned value.']);
            }
            $dueDeduction = round($totalReturnAmount - $cashRefund, 2);
            if ($dueDeduction > PosRules::supplierDue((int) $validated['supplier_id'])) {
                throw ValidationException::withMessages(['due_deduction' => 'Return credit exceeds supplier due. Enter the cash refund received.']);
            }

            if ($cashRefund > 0) {
                $acc = FinancialAccount::where('name', $validated['payment_account'])->lockForUpdate()->firstOrFail();
                if ($acc) {
                    $acc->increment('balance', $cashRefund);
                }
            }

            $record = PurchaseReturn::create([
                'return_no' => $returnNo,
                'chalan_no' => $validated['chalan_no'] ?? null,
                'supplier_id' => $validated['supplier_id'],
                'outlet_id' => $validated['outlet_id'] ?? null,
                'return_date' => $validated['return_date'],
                'total_return_amount' => $totalReturnAmount,
                'cash_refund' => $cashRefund,
                'due_deduction' => $dueDeduction,
                'payment_account' => $validated['payment_account'],
                'note' => $validated['note'] ?? null,
                'status' => 'completed',
            ]);

            foreach ($itemsData as $row) {
                PurchaseReturnItem::create(array_merge($row, ['purchase_return_id' => $record->id]));
            }

            return $record;
        });

        $purchaseReturn->load(['supplier', 'items']);

        return $this->successResponse($purchaseReturn, 'Purchase return processed and inventory decremented.', Response::HTTP_CREATED);
    }

    // ==========================================
    // 2. SALE RETURNS & EXCHANGES MODULE
    // ==========================================

    /**
     * Get sale return / exchange records.
     */
    public function getSaleReturns(Request $request): JsonResponse
    {
        $query = SaleReturn::with(['customer', 'supplier', 'items.product'])->latest('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('return_no')) {
            $query->where('return_no', 'like', '%'.$request->input('return_no').'%');
        }

        $records = $query->paginate(15);

        return $this->successResponse($records);
    }

    /**
     * Store return and exchange transaction.
     */
    public function storeSaleReturn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'invoice_id' => 'required|string|exists:sales,invoice_id',
            'return_date' => 'required|date',
            'cash_refund' => 'numeric|min:0',
            'comments' => 'nullable|string',
            'payment_account' => 'required|string|exists:financial_accounts,name',
            'return_items' => 'required|array|min:1',
            'return_items.*.product_id' => 'required|distinct|exists:products,id',
            'return_items.*.quantity' => 'required|integer|min:1',
            'return_items.*.unit_price' => 'required|numeric|min:0',
            'return_items.*.condition' => 'required|string|in:good,damaged,scrap',
            'return_items.*.product_serial' => 'nullable|string',
            'exchange_items' => 'array',
            'exchange_items.*.product_id' => 'required|distinct|exists:products,id',
            'exchange_items.*.quantity' => 'required|integer|min:1',
            'exchange_items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $saleReturn = DB::transaction(function () use ($validated) {
            $returnNo = PosRules::number('RET');
            $customer = Customer::lockForUpdate()->findOrFail($validated['customer_id']);
            $original = Sale::where('invoice_id', $validated['invoice_id'])->where('customer_id', $customer->id)
                ->where('status', 'completed')->lockForUpdate()->first();
            if (! $original) {
                throw ValidationException::withMessages(['invoice_id' => 'Select a completed invoice for this customer.']);
            }
            $totalReturnValue = 0.0;
            $totalExchangeValue = 0.0;

            // 1. Process return items
            $processedReturns = [];
            foreach ($validated['return_items'] ?? [] as $r) {
                $qty = (int) $r['quantity'];
                $originalItem = $original->items()->where('product_id', $r['product_id'])->first();
                $returned = SaleReturnItem::where('product_id', $r['product_id'])->where('item_type', 'return')
                    ->whereHas('saleReturn', fn ($q) => $q->where('invoice_id', $original->invoice_id))->sum('quantity');
                if (! $originalItem || $qty + $returned > $originalItem->quantity) {
                    throw ValidationException::withMessages(['return_items' => 'Return quantity exceeds the remaining sold quantity.']);
                }
                // Refund the actual discounted value, excluding delivery and prior debt.
                $lineShare = $original->invoice_total > 0
                    ? max(0, 1 - ((float) $original->discount + (float) $original->special_discount) / (float) $original->invoice_total) : 0;
                $price = (float) $originalItem->subtotal / $originalItem->quantity * $lineShare;
                $lineSub = round($qty * $price, 2);
                $totalReturnValue += $lineSub;

                $product = Product::lockForUpdate()->find($r['product_id']);
                // Restock only if condition is good
                if ($r['condition'] === 'good') {
                    $product->increment('available_qty', $qty);
                } else {
                    // Record in wastage table
                    Wastage::create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $qty,
                        'unit_cost' => (float) $product->cost_price,
                        'total_loss' => round($qty * (float) $product->cost_price, 2),
                        'reason' => $r['condition'],
                        'wastage_date' => $validated['return_date'],
                        'note' => 'Returned damaged item from customer',
                    ]);
                }

                $processedReturns[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_serial' => $r['product_serial'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'subtotal' => $lineSub,
                    'condition' => $r['condition'],
                    'item_type' => 'return',
                ];
            }

            // 2. Process exchange items
            $processedExchanges = [];
            foreach ($validated['exchange_items'] ?? [] as $e) {
                $qty = (int) $e['quantity'];
                $price = (float) $e['unit_price'];
                $lineSub = round($qty * $price, 2);
                $totalExchangeValue += $lineSub;

                $product = Product::lockForUpdate()->find($e['product_id']);
                PosRules::requireStock($product, $qty);
                $product->decrement('available_qty', $qty);

                $processedExchanges[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_serial' => null,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'subtotal' => $lineSub,
                    'condition' => 'good',
                    'item_type' => 'exchange',
                ];
            }

            // Customer was locked before the original invoice.
            $prevDue = (float) $customer->previous_due;
            $netAdjustment = round($totalReturnValue - $totalExchangeValue, 2);
            $cashRefund = round((float) ($validated['cash_refund'] ?? 0), 2);
            if ($cashRefund > max(0, $netAdjustment)) {
                throw ValidationException::withMessages(['cash_refund' => 'Cash refund exceeds the net return credit.']);
            }
            $account = FinancialAccount::where('name', $validated['payment_account'])->lockForUpdate()->firstOrFail();
            if ($cashRefund > 0) {
                PosRules::debit($account, $cashRefund);
            }

            // New due calculation
            // If return > exchange, credit customer (decrease due or refund cash)
            // If exchange > return, debit customer (increase due)
            $netBalance = round($prevDue - $netAdjustment + $cashRefund - (float) $customer->advanced_amount, 2);
            $newDue = max(0, $netBalance);
            $customer->update(['previous_due' => $newDue, 'advanced_amount' => max(0, -$netBalance)]);

            $record = SaleReturn::create([
                'return_no' => $returnNo,
                'invoice_id' => $validated['invoice_id'] ?? null,
                'customer_id' => $customer->id,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'return_date' => $validated['return_date'],
                'return_amount' => $totalReturnValue,
                'exchange_amount' => $totalExchangeValue,
                'net_adjustment' => $netAdjustment,
                'previous_due' => $prevDue,
                'cash_refund' => $cashRefund,
                'final_due' => $newDue,
                'comments' => $validated['comments'] ?? null,
            ]);

            foreach (array_merge($processedReturns, $processedExchanges) as $itemRow) {
                SaleReturnItem::create(array_merge($itemRow, ['sale_return_id' => $record->id]));
            }

            return $record;
        });

        $saleReturn->load(['customer', 'items']);

        return $this->successResponse($saleReturn, 'Sale return and exchange processed successfully.', Response::HTTP_CREATED);
    }

    // ==========================================
    // 3. MARKETERS MODULE & COMMISSION SLABS
    // ==========================================

    public function getMarketers(): JsonResponse
    {
        $marketers = Marketer::with(['sales'])->get()->map(function ($m) {
            $totalSales = $m->sales->where('status', 'completed')->sum(fn ($sale) => (float) $sale->invoice_total - (float) $sale->discount - (float) $sale->special_discount);
            $slabs = MarketerSlab::where('marketer_id', $m->id)->get();
            $slab = $slabs->first(fn ($slab) => $totalSales >= $slab->start_amount && $totalSales <= $slab->end_amount);
            $commissionEarned = round($totalSales * (float) ($slab?->percentage ?? 0) / 100, 2);
            $amountPaid = MarketerPayment::where('marketer_id', $m->id)->sum('amount');
            $balance = max(0.0, $commissionEarned - $amountPaid);

            return [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'total_sales' => $totalSales,
                'commission_earned' => $commissionEarned,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'slabs' => $slabs,
            ];
        });

        return $this->successResponse($marketers);
    }

    public function storeMarketer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string',
            'slabs' => 'array',
            'slabs.*.start_amount' => 'required|numeric|min:0',
            'slabs.*.end_amount' => 'required|numeric|min:0|gte:slabs.*.start_amount',
            'slabs.*.percentage' => 'required|numeric|min:0|max:100',
        ]);

        $slabs = collect($validated['slabs'] ?? [])->sortBy('start_amount')->values();
        for ($i = 1; $i < $slabs->count(); $i++) {
            if ($slabs[$i]['start_amount'] <= $slabs[$i - 1]['end_amount']) {
                throw ValidationException::withMessages(['slabs' => 'Commission slabs cannot overlap.']);
            }
        }
        $marketer = DB::transaction(function () use ($validated) {
            $m = Marketer::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
            ]);

            foreach ($validated['slabs'] ?? [] as $slab) {
                MarketerSlab::create([
                    'marketer_id' => $m->id,
                    'start_amount' => $slab['start_amount'],
                    'end_amount' => $slab['end_amount'],
                    'percentage' => $slab['percentage'],
                ]);
            }

            return $m;
        });

        return $this->successResponse($marketer, 'Marketer and commission slabs registered successfully.', Response::HTTP_CREATED);
    }

    public function storeMarketerPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'marketer_id' => 'required|exists:marketers,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|exists:financial_accounts,name',
            'note' => 'nullable|string',
        ]);

        $payment = DB::transaction(function () use ($validated) {
            $marketer = Marketer::lockForUpdate()->findOrFail($validated['marketer_id']);
            $sales = Sale::where('marketer_id', $marketer->id)->where('status', 'completed')->sum(DB::raw('invoice_total - discount - special_discount'));
            $slab = MarketerSlab::where('marketer_id', $marketer->id)->where('start_amount', '<=', $sales)->where('end_amount', '>=', $sales)->first();
            $earned = round((float) $sales * (float) ($slab?->percentage ?? 0) / 100, 2);
            if ((float) $validated['amount'] > $earned - (float) MarketerPayment::where('marketer_id', $marketer->id)->sum('amount')) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds earned commission balance.']);
            }
            $account = FinancialAccount::where('name', $validated['payment_method'])->lockForUpdate()->firstOrFail();
            PosRules::debit($account, (float) $validated['amount']);

            return MarketerPayment::create($validated);
        });

        return $this->successResponse($payment, 'Marketer commission paid successfully.', Response::HTTP_CREATED);
    }

    // ==========================================
    // 4. STOCK TRANSFERS MODULE
    // ==========================================

    public function getStockTransfers(Request $request): JsonResponse
    {
        $transfers = StockTransfer::with('items')->latest('id')->paginate(15);

        return $this->successResponse($transfers);
    }

    public function storeStockTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_type' => 'required|string|in:warehouse,company',
            'source_name' => 'required|string',
            'destination_name' => 'required|string|different:source_name',
            'transfer_date' => 'required|date',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $transfer = DB::transaction(function () use ($validated) {
            $transferNo = PosRules::number('TRF');
            $totalQty = 0;

            $record = StockTransfer::create([
                'transfer_no' => $transferNo,
                'transfer_type' => $validated['transfer_type'],
                'source_name' => $validated['source_name'],
                'destination_name' => $validated['destination_name'],
                'transfer_date' => $validated['transfer_date'],
                'total_items' => 0,
                'status' => 'completed',
                'note' => $validated['note'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $product = Product::lockForUpdate()->find($item['product_id']);
                $qty = (int) $item['quantity'];
                $totalQty += $qty;

                // This schema tracks global stock; an internal warehouse move is not a sale.
                PosRules::requireStock($product, $qty);
                if ($validated['transfer_type'] === 'company') {
                    $product->decrement('available_qty', $qty);
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $record->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                ]);
            }

            $record->update(['total_items' => $totalQty]);

            return $record;
        });

        $transfer->load('items');

        return $this->successResponse($transfer, 'Stock transferred successfully.', Response::HTTP_CREATED);
    }

    // ==========================================
    // 5. SUPPLIERS & CUSTOMERS EXTENSIONS
    // ==========================================

    public function storeSupplier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:suppliers,code',
            'phone' => 'nullable|string',
        ]);

        $supplier = Supplier::create($validated);

        return $this->successResponse($supplier, 'Supplier registered successfully.', Response::HTTP_CREATED);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:customers,code',
            'phone' => 'nullable|string',
            'area' => 'nullable|string',
            'previous_due' => 'numeric|min:0',
            'advanced_amount' => 'numeric|min:0',
        ]);

        $customer = Customer::create($validated);

        return $this->successResponse($customer, 'Customer registered successfully.', Response::HTTP_CREATED);
    }

    public function getCustomerCategories(): JsonResponse
    {
        $categories = CustomerCategory::all();

        return $this->successResponse($categories);
    }

    // ==========================================
    // 6. WASTAGE & STOCK ALERT MODULE
    // ==========================================

    public function getWastages(): JsonResponse
    {
        $wastages = Wastage::with('product')->latest('id')->paginate(15);

        return $this->successResponse($wastages);
    }

    public function storeWastage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|in:damaged,expired,lost,scrap',
            'wastage_date' => 'required|date',
            'note' => 'nullable|string',
        ]);

        $record = DB::transaction(function () use ($validated) {
            $product = Product::lockForUpdate()->find($validated['product_id']);
            $qty = (int) $validated['quantity'];
            $cost = (float) $product->cost_price;
            $loss = round($qty * $cost, 2);

            PosRules::requireStock($product, $qty);
            $product->decrement('available_qty', $qty);

            return Wastage::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'total_loss' => $loss,
                'reason' => $validated['reason'],
                'wastage_date' => $validated['wastage_date'],
                'note' => $validated['note'] ?? null,
            ]);
        });

        return $this->successResponse($record, 'Wastage item recorded and inventory decremented.', Response::HTTP_CREATED);
    }

    // ==========================================
    // 7. GENERAL ACCOUNTS: EXPENSES & TRANSFERS
    // ==========================================

    public function getGeneralExpenses(Request $request): JsonResponse
    {
        $query = GeneralExpense::latest('expense_date');
        if ($request->filled('category')) {
            $query->where('expense_category', $request->input('category'));
        }
        if ($request->filled('date')) {
            $query->whereDate('expense_date', $request->input('date'));
        }

        return $this->successResponse($query->paginate(20));
    }

    public function storeGeneralExpense(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expense_category' => 'required|string',
            'title' => 'required|string',
            'amount' => 'required|numeric|min:0.01|decimal:0,2',
            'expense_date' => 'required|date',
            'account_name' => 'required|string|exists:financial_accounts,name',
            'payee_name' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $expense = DB::transaction(function () use ($validated) {
            $voucherNo = PosRules::number('EXP');
            $amount = (float) $validated['amount'];

            $record = GeneralExpense::create([
                'voucher_no' => $voucherNo,
                'expense_category' => $validated['expense_category'],
                'title' => $validated['title'],
                'amount' => $amount,
                'expense_date' => $validated['expense_date'],
                'account_name' => $validated['account_name'],
                'payee_name' => $validated['payee_name'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            // Deduct balance from account
            $account = FinancialAccount::where('name', $validated['account_name'])->lockForUpdate()->firstOrFail();
            if ($account) {
                PosRules::debit($account, $amount);
            }

            return $record;
        });

        return $this->successResponse($expense, 'Expense voucher recorded successfully.', Response::HTTP_CREATED);
    }

    public function getAccountTransfers(): JsonResponse
    {
        $transfers = AccountTransfer::latest('id')->paginate(15);

        return $this->successResponse($transfers);
    }

    public function storeAccountTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_account' => 'required|string|exists:financial_accounts,name',
            'to_account' => 'required|string|exists:financial_accounts,name|different:from_account',
            'amount' => 'required|numeric|min:1',
            'transfer_date' => 'required|date',
            'reference' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $transfer = DB::transaction(function () use ($validated) {
            $transferNo = PosRules::number('TRF-ACC');
            $amount = (float) $validated['amount'];

            $fromAcc = FinancialAccount::where('name', $validated['from_account'])->lockForUpdate()->firstOrFail();
            $toAcc = FinancialAccount::where('name', $validated['to_account'])->lockForUpdate()->firstOrFail();

            if ($fromAcc) {
                PosRules::debit($fromAcc, $amount);
            }
            if ($toAcc) {
                $toAcc->increment('balance', $amount);
            }

            return AccountTransfer::create([
                'transfer_no' => $transferNo,
                'from_account' => $validated['from_account'],
                'to_account' => $validated['to_account'],
                'amount' => $amount,
                'transfer_date' => $validated['transfer_date'],
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);
        });

        return $this->successResponse($transfer, 'Funds transferred between accounts successfully.', Response::HTTP_CREATED);
    }

    // ==========================================
    // 8. COMPREHENSIVE REPORTS MODULE (14+ Reports)
    // ==========================================

    public function getReportData(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date', 'threshold' => 'nullable|integer|min:0']);
        $reportType = (string) $request->input('type', 'supplier_stock');

        switch ($reportType) {
            case 'daily_report':
                // Daily Income & Expense Statement (Screenshot 11.04.12 AM)
                $date = $request->input('date', now()->toDateString());
                $salesDate = Sale::whereDate('sale_date', $date)->where('status', 'completed');
                $collectionsDate = CustomerCollection::whereDate('collection_date', $date);
                $supplierPaymentsDate = SupplierPayment::whereDate('payment_date', $date);
                $expensesDate = GeneralExpense::whereDate('expense_date', $date);

                // Income items
                $collAmount = (float) $collectionsDate->sum('paid_amount');
                $cashSalesAmount = (float) (clone $salesDate)->where('payment_account', 'Cash')->sum('paid_amount');
                $bankSalesAmount = (float) (clone $salesDate)->where('payment_account', '!=', 'Cash')->sum('paid_amount');

                $incomeList = [
                    [
                        'account_name' => 'Collection',
                        'category' => 'Customer Dues',
                        'amount' => round($collAmount, 2),
                    ],
                    [
                        'account_name' => 'Direct Sales (Cash)',
                        'category' => 'POS Sales',
                        'amount' => round($cashSalesAmount, 2),
                    ],
                    [
                        'account_name' => 'Bank / Mobile Pay Sales',
                        'category' => 'POS Sales',
                        'amount' => round($bankSalesAmount, 2),
                    ],
                ];

                $incomeList[] = ['account_name' => 'Purchase refunds', 'category' => 'Supplier returns', 'amount' => round((float) PurchaseReturn::whereDate('return_date', $date)->sum('cash_refund'), 2)];

                // Expense items (Matching exact categories in Screenshot 11.04.12 AM)
                $spPaid = (float) $supplierPaymentsDate->sum('paid_amount');

                $expenseCategories = [
                    'Payment' => round($spPaid, 2),
                    'Purchase payments' => round((float) Purchase::whereDate('purchase_date', $date)->sum('paid_amount'), 2),
                    'Sales refunds' => round((float) SaleReturn::whereDate('return_date', $date)->sum('cash_refund'), 2),
                    'Commission payouts' => round((float) MarketerPayment::whereDate('payment_date', $date)->sum('amount'), 2),
                    'Electricity bill' => round((float) (clone $expensesDate)->where('expense_category', 'Electricity bill')->sum('amount'), 2),
                    'Employee Salary' => round((float) (clone $expensesDate)->where('expense_category', 'Employee Salary')->sum('amount'), 2),
                    'Office Expense' => round((float) (clone $expensesDate)->where('expense_category', 'Office Expense')->sum('amount'), 2),
                    'Transportation Cost' => round((float) (clone $expensesDate)->where('expense_category', 'Transportation Cost')->sum('amount'), 2),
                    'Daily Allowance' => round((float) (clone $expensesDate)->where('expense_category', 'Daily Allowance')->sum('amount'), 2),
                    'Food Expense' => round((float) (clone $expensesDate)->where('expense_category', 'Food Expense')->sum('amount'), 2),
                    'Advanced salary' => round((float) (clone $expensesDate)->where('expense_category', 'Advanced salary')->sum('amount'), 2),
                    'Others' => round((float) (clone $expensesDate)->whereNotIn('expense_category', [
                        'Electricity bill', 'Employee Salary', 'Office Expense', 'Transportation Cost', 'Daily Allowance', 'Food Expense', 'Advanced salary',
                    ])->sum('amount'), 2),
                ];

                $expenseList = [];
                foreach ($expenseCategories as $cat => $amt) {
                    $expenseList[] = [
                        'account_name' => $cat,
                        'category' => 'General Expense',
                        'amount' => $amt,
                    ];
                }

                $totalIncome = array_sum(array_column($incomeList, 'amount'));
                $totalExpense = array_sum(array_column($expenseList, 'amount'));
                $netBalance = $totalIncome - $totalExpense;

                return $this->successResponse([
                    'date' => $date,
                    'incomes' => $incomeList,
                    'expenses' => $expenseList,
                    'total_income' => round($totalIncome, 2),
                    'total_expense' => round($totalExpense, 2),
                    'net_balance' => round($netBalance, 2),
                ], 'Daily income and expense statement');

            case 'ga_parties':
                // G A Parties Report (Screenshot 11.04.25 AM)
                $ledgerRows = [];
                $recentColls = CustomerCollection::with('customer')->latest('id')->limit(20)->get();
                $recentSales = Sale::with('customer')->where('status', 'completed')->latest('id')->limit(20)->get();

                $sl = 1;
                foreach ($recentSales as $s) {
                    $ledgerRows[] = [
                        'sl' => $sl++,
                        'date' => $s->sale_date,
                        'party_name' => $s->customer?->name ?? 'Walk-in Customer',
                        'reference' => $s->invoice_id,
                        'description' => 'Invoice Sale (Chalan Order)',
                        'debit' => (float) $s->payable_amount,
                        'credit' => (float) $s->paid_amount,
                        'balance' => (float) $s->due_amount,
                    ];
                }

                foreach ($recentColls as $rc) {
                    $ledgerRows[] = [
                        'sl' => $sl++,
                        'date' => $rc->collection_date,
                        'party_name' => $rc->customer?->name ?? 'General Party',
                        'reference' => 'COL-'.str_pad((string) $rc->id, 5, '0', STR_PAD_LEFT),
                        'description' => 'Customer Due Collection ('.$rc->account.')',
                        'debit' => 0.0,
                        'credit' => (float) $rc->paid_amount,
                        'balance' => max(0.0, (float) $rc->receivable_due - (float) $rc->paid_amount),
                    ];
                }

                return $this->successResponse($ledgerRows, 'G A Parties ledger report');

            case 'supplier_stock':
                $data = Supplier::with(['products'])->get()->map(function ($sup) {
                    $totalQty = $sup->products->sum('available_qty');
                    $totalCostValue = $sup->products->sum(fn ($p) => $p->available_qty * $p->cost_price);
                    $totalSaleValue = $sup->products->sum(fn ($p) => $p->available_qty * $p->unit_price);

                    return [
                        'supplier_name' => $sup->name,
                        'supplier_code' => $sup->code,
                        'item_count' => $sup->products->count(),
                        'total_quantity' => $totalQty,
                        'cost_value' => round($totalCostValue, 2),
                        'sale_value' => round($totalSaleValue, 2),
                    ];
                });

                return $this->successResponse($data, 'Supplier wise stock report');

            case 'stock_alert':
                // Product Stock Alert Report (Screenshot 11.02.00 AM)
                $threshold = (int) $request->input('threshold', 25);
                $lowStock = Product::with('supplier')
                    ->where('is_active', true)
                    ->when($request->filled('threshold'), fn ($q) => $q->where('available_qty', '<=', $threshold), fn ($q) => $q->whereColumn('available_qty', '<=', 'low_stock_threshold'))
                    ->orderBy('available_qty', 'asc')
                    ->get()
                    ->map(function ($p) {
                        return [
                            'id' => $p->id,
                            'code' => $p->code,
                            'name' => $p->name,
                            'available_qty' => $p->available_qty,
                            'cost_price' => $p->cost_price,
                            'unit_price' => $p->unit_price,
                            'supplier_name' => $p->supplier?->name ?? 'General Supplier',
                            'supplier_phone' => $p->supplier?->phone ?? '',
                        ];
                    });

                return $this->successResponse($lowStock, 'Stock alert report');

            case 'top_sales':
                $topProducts = SaleItem::whereHas('sale', fn ($q) => $q->where('status', 'completed'))->select('product_id', 'product_name', DB::raw('SUM(quantity) as total_qty_sold'), DB::raw('SUM(subtotal) as total_revenue'), DB::raw('SUM(profit) as total_profit'))
                    ->groupBy('product_id', 'product_name')
                    ->orderByDesc('total_qty_sold')
                    ->limit(20)
                    ->get();

                return $this->successResponse($topProducts, 'Top selling products report');

            case 'cash_flow':
                $totalCashSales = Sale::where('payment_account', 'Cash')->where('status', 'completed')->sum('paid_amount');
                $totalBankSales = Sale::where('payment_account', '!=', 'Cash')->where('status', 'completed')->sum('paid_amount');
                $totalCollections = CustomerCollection::sum('paid_amount');
                $totalInflow = $totalCashSales + $totalBankSales + $totalCollections + PurchaseReturn::sum('cash_refund');

                $totalPurchasesPaid = Purchase::sum('paid_amount');
                $totalSupplierPayments = SupplierPayment::sum('paid_amount');
                $totalGeneralExpenses = GeneralExpense::sum('amount');
                $totalOutflow = $totalPurchasesPaid + $totalSupplierPayments + $totalGeneralExpenses + SaleReturn::sum('cash_refund') + MarketerPayment::sum('amount');
                $netCash = $totalInflow - $totalOutflow;

                $accounts = FinancialAccount::all();

                return $this->successResponse([
                    'total_inflow' => round($totalInflow, 2),
                    'total_outflow' => round($totalOutflow, 2),
                    'net_liquid' => round($netCash, 2),
                    'cash_in_hand' => round($accounts->where('name', 'Cash')->sum('balance'), 2),
                    'bank_balances' => round($accounts->where('name', '!=', 'Cash')->sum('balance'), 2),
                    'accounts' => $accounts,
                ], 'Cash flow report');

            case 'daily_closing':
                $date = $request->input('date', now()->toDateString());
                $salesToday = Sale::whereDate('sale_date', $date)->where('status', 'completed');
                $collectionsToday = CustomerCollection::whereDate('collection_date', $date);
                $purchasesToday = Purchase::whereDate('purchase_date', $date);

                return $this->successResponse([
                    'date' => $date,
                    'total_sales' => round($salesToday->sum(DB::raw(PosRules::revenue())), 2),
                    'cash_received' => round($salesToday->sum('paid_amount'), 2),
                    'new_dues' => round($salesToday->sum('due_amount'), 2),
                    'collections' => round($collectionsToday->sum('paid_amount'), 2),
                    'purchases' => round($purchasesToday->sum('total_payable'), 2),
                    'purchases_paid' => round($purchasesToday->sum('paid_amount'), 2),
                    'supplier_payments' => round((float) SupplierPayment::whereDate('payment_date', $date)->sum('paid_amount'), 2),
                    'operating_expenses' => round((float) GeneralExpense::whereDate('expense_date', $date)->sum('amount'), 2),
                    'sales_refunds' => round((float) SaleReturn::whereDate('return_date', $date)->sum('cash_refund'), 2),
                    'purchase_refunds' => round((float) PurchaseReturn::whereDate('return_date', $date)->sum('cash_refund'), 2),
                    'commission_payouts' => round((float) MarketerPayment::whereDate('payment_date', $date)->sum('amount'), 2),
                ], 'Daily closing report');

            default:
                throw ValidationException::withMessages(['type' => 'This report is not supported.']);
        }
    }
}
