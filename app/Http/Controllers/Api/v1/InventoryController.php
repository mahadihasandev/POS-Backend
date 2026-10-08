<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends BaseApiController
{
    private function rules(?Product $product = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:100', Rule::unique('products')->ignore($product?->id)],
            'barcode' => ['required', 'string', 'max:100', Rule::unique('products')->ignore($product?->id)],
            'supplier_id' => 'nullable|exists:suppliers,id',
            'unit' => 'required|string|max:30',
            'unit_price' => 'required|numeric|min:0|max:9999999999.99',
            'cost_price' => 'required|numeric|min:0|max:9999999999.99',
            'low_stock_threshold' => 'required|integer|min:0|max:1000000',
            'is_active' => 'required|boolean',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['available_qty' => 'required|integer|min:0|max:1000000']);
        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create($data);
            if ($data['available_qty'] > 0) {
                $this->recordAdjustment($request, $product, 0, $data['available_qty'], 'Opening stock');
            }

            return $product;
        });

        return $this->successResponse($product, 'Product created.', 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        // Quantity can only change through an audited stock count or a transaction.
        $data = $request->validate($this->rules($product));
        $product->update($data);

        return $this->successResponse($product, 'Product updated.');
    }

    public function adjust(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'counted_quantity' => 'required|integer|min:0|max:1000000',
            'expected_quantity' => 'required|integer|min:0',
            'reason' => 'required|string|max:255',
        ]);
        DB::transaction(function () use ($request, $product, $data): void {
            $locked = Product::lockForUpdate()->findOrFail($product->id);
            if ($locked->available_qty !== $data['expected_quantity']) {
                throw ValidationException::withMessages([
                    'expected_quantity' => 'Stock changed since you opened this count. Refresh and count again.',
                ]);
            }
            $this->recordAdjustment($request, $locked, $locked->available_qty, $data['counted_quantity'], $data['reason']);
            $locked->update(['available_qty' => $data['counted_quantity']]);
        });

        return $this->successResponse($product->fresh(), 'Stock count recorded.');
    }

    private function recordAdjustment(Request $request, Product $product, int $previous, int $counted, string $reason): void
    {
        DB::table('stock_adjustments')->insert([
            'product_id' => $product->id, 'user_id' => $request->user()->id,
            'previous_quantity' => $previous, 'counted_quantity' => $counted,
            'difference' => $counted - $previous, 'reason' => $reason,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => 'nullable|exists:products,id']);
        $rows = DB::table('stock_adjustments')
            ->join('products', 'products.id', '=', 'stock_adjustments.product_id')
            ->join('users', 'users.id', '=', 'stock_adjustments.user_id')
            ->select('stock_adjustments.*', 'products.name as product_name', 'users.name as user_name')
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->orderByDesc('stock_adjustments.id')->paginate(20);

        $rows->getCollection()->transform(function ($row) {
            $row->created_at = Carbon::parse($row->created_at)->toISOString();

            return $row;
        });

        return $this->successResponse($rows);
    }
}
