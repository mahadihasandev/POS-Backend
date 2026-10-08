<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Models\Customer;
use App\Models\CustomerCollection;
use App\Models\FinancialAccount;
use App\Models\GeneralExpense;
use App\Models\Marketer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PosRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends BaseApiController
{
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'outlets' => Outlet::select('id', 'name', 'code', 'address', 'phone')->get(),
            'suppliers' => Supplier::select('id', 'name', 'code', 'phone')->get()->map(function ($supplier) use ($user) {
                if ($user->hasPermission('purchases.view')) {
                    $supplier->setAttribute('previous_due', PosRules::supplierDue($supplier->id));
                }

                return $supplier;
            }),
            'customers' => Customer::select('id', 'name', 'code', 'phone', 'area', 'previous_due', 'advanced_amount')->get(),
            'marketers' => Marketer::select('id', 'name')->get(),
            'products' => Product::all(),
            'accounts' => FinancialAccount::select($user->hasPermission('accounts.view')
                ? ['id', 'name', 'account_type', 'account_number', 'balance']
                : ['id', 'name', 'account_type'])->get(),
            'held_count' => $user->hasPermission('sales.hold') ? Sale::where('status', 'hold')->count() : 0,
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:255', 'supplier_id' => 'nullable|exists:suppliers,id']);
        $term = $data['q'] ?? '';

        return $this->successResponse(Product::where('is_active', true)
            ->when($data['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->where(fn ($q) => $q->where('barcode', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))
            ->limit(30)->get());
    }

    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_id' => 'nullable|uuid',
            'held_sale_id' => 'nullable|integer|exists:sales,id',
            'outlet_id' => 'required|exists:outlets,id',
            'customer_id' => 'nullable|exists:customers,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'marketer_id' => 'nullable|exists:marketers,id',
            'sale_date' => 'required|date', 'sale_type' => 'required|in:normal,supplier_wise',
            'note' => 'nullable|string|max:2000',
            'discount' => 'numeric|min:0', 'special_discount' => 'numeric|min:0',
            'delivery_charge' => 'numeric|min:0', 'delivery_payer' => 'required|in:company,customer',
            'payment_account' => 'required|string|exists:financial_accounts,name',
            'paid_amount' => 'required|numeric|min:0', 'is_hold' => 'boolean',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0|max:9999999999.99',
            'items.*.discount_percent' => 'numeric|min:0|max:100',
        ]);
        $user = $request->user();
        $hold = (bool) ($data['is_hold'] ?? false);
        if ($hold || ! empty($data['held_sale_id'])) {
            abort_unless($user->hasPermission('sales.hold'), 403);
        }
        $sale = DB::transaction(function () use ($data, $user, $hold) {
            // Serialize retries from one cashier; stock/customer rows protect other cashiers.
            User::lockForUpdate()->findOrFail($user->id);
            if (! empty($data['request_id'])) {
                $existing = Sale::where('request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless($existing->user_id === $user->id, 403);

                    return $existing;
                }
            }
            $held = ! empty($data['held_sale_id'])
                ? Sale::where('status', 'hold')->lockForUpdate()->findOrFail($data['held_sale_id']) : null;
            if ($held && ($held->outlet_id !== (int) $data['outlet_id'] || $held->sale_type !== $data['sale_type'])) {
                throw ValidationException::withMessages(['held_sale_id' => 'Resume this sale in its original branch and checkout type.']);
            }
            $customer = ! empty($data['customer_id']) ? Customer::lockForUpdate()->findOrFail($data['customer_id']) : null;
            $account = FinancialAccount::where('name', $data['payment_account'])->lockForUpdate()->firstOrFail();
            $products = Product::whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0.0;
            $rows = [];
            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['items' => 'An item is unavailable. Refresh the catalog.']);
                }
                $qty = (int) $item['quantity'];
                if (! $hold) {
                    PosRules::requireStock($product, $qty);
                    $product->decrement('available_qty', $qty);
                }
                $price = round((float) $item['unit_price'], 2);
                $percent = round((float) ($item['discount_percent'] ?? 0), 2);
                $subtotal = round($qty * $price * (1 - $percent / 100), 2);
                $total += $subtotal;
                $rows[] = [
                    'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->code,
                    'quantity' => $qty, 'unit_price' => $price, 'cost_price' => $product->cost_price,
                    'discount_percent' => $percent, 'subtotal' => $subtotal,
                    'profit' => round($subtotal - $qty * (float) $product->cost_price, 2),
                ];
            }
            $discount = round((float) ($data['discount'] ?? 0), 2);
            $special = round((float) ($data['special_discount'] ?? 0), 2);
            if ($discount + $special > $total) {
                throw ValidationException::withMessages(['discount' => 'Discounts cannot exceed the invoice total.']);
            }
            $delivery = round((float) ($data['delivery_charge'] ?? 0), 2);
            $prior = (float) ($customer?->previous_due ?? 0);
            $gross = round($total - $discount - $special + ($data['delivery_payer'] === 'customer' ? $delivery : 0) + $prior, 2);
            $advanceUsed = min((float) ($customer?->advanced_amount ?? 0), $gross);
            $payable = round($gross - $advanceUsed, 2);
            $tendered = $hold ? 0 : round((float) $data['paid_amount'], 2);
            $paid = min($tendered, $payable);
            $due = round($payable - $paid, 2);
            if (! $hold && ! $customer && $due > 0) {
                throw ValidationException::withMessages(['customer_id' => 'Choose a customer for a credit sale, or receive the full amount.']);
            }
            $attributes = [
                'request_id' => $data['request_id'] ?? null,
                'outlet_id' => $data['outlet_id'], 'customer_id' => $data['customer_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null, 'marketer_id' => $data['marketer_id'] ?? null,
                'user_id' => $user->id, 'sale_date' => $data['sale_date'], 'sale_type' => $data['sale_type'],
                'note' => $data['note'] ?? null, 'invoice_total' => $total, 'discount' => $discount,
                'special_discount' => $special, 'delivery_charge' => $delivery, 'delivery_payer' => $data['delivery_payer'],
                'previous_due' => $prior, 'advanced' => $advanceUsed, 'payable_amount' => $payable,
                'paid_amount' => $paid, 'due_amount' => $due, 'change_return' => round(max(0, $tendered - $payable), 2),
                'payment_account' => $data['payment_account'], 'status' => $hold ? 'hold' : 'completed',
            ];
            if ($held) {
                $held->update($attributes);
                $held->items()->delete();
                $sale = $held;
            } else {
                $sale = Sale::create(['invoice_id' => PosRules::number('S')] + $attributes);
            }
            if ($total > 0 && $discount + $special > 0) {
                $remainingDiscount = $discount + $special;
                foreach ($rows as $index => &$row) {
                    $share = $index === array_key_last($rows) ? $remainingDiscount : round(($discount + $special) * $row['subtotal'] / $total, 2);
                    $remainingDiscount = round($remainingDiscount - $share, 2);
                    $row['profit'] = round($row['profit'] - $share, 2);
                }
                unset($row);
            }
            $sale->items()->createMany($rows);
            if (! $hold) {
                $customer?->update(['previous_due' => $due, 'advanced_amount' => round((float) $customer->advanced_amount - $advanceUsed, 2)]);
                $account->increment('balance', $paid);
            }

            return $sale;
        });

        return $this->successResponse($sale->load(['items', 'customer', 'supplier', 'user', 'marketer', 'outlet']), $hold ? 'Sale held.' : 'Sale completed.', 201);
    }

    public function getSales(Request $request): JsonResponse
    {
        $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date', 'page' => 'nullable|integer|min:1']);

        return $this->successResponse(Sale::with(['customer', 'supplier', 'user', 'marketer', 'items', 'outlet'])
            ->where('status', '!=', 'hold')
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('sale_date', '>=', $request->input('start_date')))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('sale_date', '<=', $request->input('end_date')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->when($request->filled('invoice_id'), fn ($q) => $q->where('invoice_id', 'like', '%'.$request->input('invoice_id').'%'))
            ->latest('id')->paginate(15));
    }

    public function getHeldSales(): JsonResponse
    {
        return $this->successResponse(Sale::with(['items', 'customer', 'supplier', 'marketer', 'outlet'])->where('status', 'hold')->latest('id')->get());
    }

    public function resumeSale(int $id): JsonResponse
    {
        // DELETE is discard only. Resuming is read-only until an atomic checkout/re-hold.
        DB::transaction(fn () => Sale::where('status', 'hold')->lockForUpdate()->findOrFail($id)->delete());

        return $this->successResponse(null, 'Held sale discarded.');
    }

    public function storeCollection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id', 'collection_date' => 'required|date',
            'payment_method' => 'required|string', 'account' => 'required|string|exists:financial_accounts,name',
            'receivable_due' => 'required|numeric|min:0', 'discount_amount' => 'numeric|min:0',
            'paid_amount' => 'required|numeric|min:0.01', 'send_sms' => 'boolean',
        ]);
        $record = DB::transaction(function () use ($data, $request) {
            $customer = Customer::lockForUpdate()->findOrFail($data['customer_id']);
            $paid = round((float) $data['paid_amount'], 2);
            $discount = round((float) ($data['discount_amount'] ?? 0), 2);
            if ($paid + $discount > (float) $customer->previous_due) {
                throw ValidationException::withMessages(['paid_amount' => 'Payment plus discount exceeds the current customer due.']);
            }
            $record = CustomerCollection::create(array_merge($data, [
                'collection_number' => PosRules::number('COL'), 'user_id' => $request->user()->id,
                'receivable_due' => $customer->previous_due, 'paid_amount' => $paid,
                'discount_amount' => $discount, 'send_sms' => false,
            ]));
            $customer->decrement('previous_due', $paid + $discount);
            FinancialAccount::where('name', $data['account'])->lockForUpdate()->firstOrFail()->increment('balance', $paid);

            return $record;
        });

        return $this->successResponse($record->load(['customer', 'user']), 'Collection recorded.', 201);
    }

    public function getCollections(): JsonResponse
    {
        return $this->successResponse(CustomerCollection::with(['customer', 'user'])->latest('id')->paginate(15));
    }

    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $start = now()->startOfMonth()->toDateString();
        $period = function (string $from, string $to): array {
            return [
                'sale' => round((float) Sale::where('status', 'completed')->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)->sum(DB::raw(PosRules::revenue())), 2),
                'purchase' => round((float) Purchase::whereBetween('purchase_date', [$from, $to])->sum('total_payable'), 2),
            ];
        };
        $cash = function (string $from, string $to): array {
            return [
                'income' => round((float) Sale::where('status', 'completed')->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)->sum('paid_amount')
                    + (float) CustomerCollection::whereBetween('collection_date', [$from, $to])->sum('paid_amount'), 2),
                'expense' => round((float) GeneralExpense::whereBetween('expense_date', [$from, $to])->sum('amount'), 2),
            ];
        };
        $accounts = FinancialAccount::all();

        return $this->successResponse([
            'metrics' => [
                'today' => $period($today, $today), 'yesterday' => $period($yesterday, $yesterday), 'monthly' => $period($start, $today),
                'today_ga' => $cash($today, $today), 'monthly_ga' => $cash($start, $today),
                'liabilities' => ['payable_due' => Supplier::all()->sum(fn ($s) => PosRules::supplierDue($s->id)), 'receivable_due' => (float) Customer::sum('previous_due')],
                'available_amount' => (float) $accounts->sum('balance'),
            ],
            'accounts' => $accounts,
            'recent_sales' => Sale::with(['customer', 'outlet'])->where('status', 'completed')->latest('id')->limit(8)->get(),
            'stock' => [
                'products' => Product::where('is_active', true)->count(),
                'low' => Product::where('is_active', true)->whereColumn('available_qty', '<=', 'low_stock_threshold')->count(),
                'out' => Product::where('is_active', true)->where('available_qty', '<=', 0)->count(),
            ],
            'sales_trend' => collect(range(6, 0))->map(function ($offset) use ($period) {
                $day = now()->subDays($offset)->toDateString();

                return ['date' => $day, 'sale' => $period($day, $day)['sale']];
            }),
        ]);
    }
}
