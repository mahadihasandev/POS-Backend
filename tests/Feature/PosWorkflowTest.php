<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TokenServiceInterface;
use App\Models\Customer;
use App\Models\Designation;
use App\Models\FinancialAccount;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PosRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Customer $customer;

    private Supplier $supplier;

    private Outlet $outlet;

    private FinancialAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Designation::create(['name' => 'Admin', 'slug' => 'admin']);
        $this->admin = User::factory()->create(['designation_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($this->admin));
        $this->outlet = Outlet::create(['name' => 'Test branch', 'code' => 'BR-1']);
        $this->supplier = Supplier::create(['name' => 'Vendor', 'code' => 'SUP-1']);
        $this->product = Product::create(['name' => 'Switch', 'code' => 'SW-1', 'barcode' => '10001', 'supplier_id' => $this->supplier->id, 'available_qty' => 10, 'unit_price' => 100, 'cost_price' => 60, 'unit' => 'pcs']);
        $this->customer = Customer::create(['name' => 'Customer', 'code' => 'CUS-1', 'previous_due' => 0, 'advanced_amount' => 0]);
        $this->cash = FinancialAccount::create(['name' => 'Cash', 'account_type' => 'cash', 'balance' => 1000]);
    }

    private function sale(array $overrides = []): array
    {
        return array_replace([
            'request_id' => (string) Str::uuid(), 'outlet_id' => $this->outlet->id, 'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(), 'sale_type' => 'normal', 'delivery_payer' => 'company',
            'payment_account' => 'Cash', 'paid_amount' => 200,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100, 'discount_percent' => 0]],
        ], $overrides);
    }

    private function purchase(array $overrides = []): array
    {
        return array_replace([
            'supplier_id' => $this->supplier->id, 'outlet_id' => $this->outlet->id, 'chalan_no' => 'CH-1',
            'purchase_date' => now()->toDateString(), 'paid_amount' => 0, 'payment_account' => 'Cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'free_qty' => 0, 'unit_cost' => 60]],
        ], $overrides);
    }

    public function test_customer_csv_import_is_real_and_atomic(): void
    {
        $csv = "name,code,phone,area\nImported customer,IMP-1,01700000000,Dhaka\nSecond customer,IMP-2,,\n";
        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);
        $this->postJson('/api/v1/pos/customers/import', ['file' => $file])->assertCreated()->assertJsonPath('data.imported', 2);
        $this->assertDatabaseHas('customers', ['code' => 'IMP-1', 'name' => 'Imported customer', 'previous_due' => 0]);
        $duplicate = UploadedFile::fake()->createWithContent('customers.csv', "name,code,phone,area\nNew,IMP-3,,\nDuplicate,IMP-1,,\n");
        $this->postJson('/api/v1/pos/customers/import', ['file' => $duplicate])->assertUnprocessable();
        $this->assertDatabaseMissing('customers', ['code' => 'IMP-3']);
    }

    public function test_free_purchase_units_do_not_inflate_supplier_refunds(): void
    {
        $payload = $this->purchase();
        $payload['items'][0]['free_qty'] = 1;
        $this->postJson('/api/v1/pos/purchases', $payload)->assertCreated();
        $this->postJson('/api/v1/pos/purchases/returns', [
            'supplier_id' => $this->supplier->id, 'chalan_no' => 'CH-1', 'return_date' => now()->toDateString(), 'payment_account' => 'Cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 999, 'reason' => 'Full return']],
        ])->assertCreated()->assertJsonPath('data.total_return_amount', '120.00');
        $this->assertEquals(0, PosRules::supplierDue($this->supplier->id));
    }

    public function test_supplier_csv_import_uses_supplier_columns(): void
    {
        $file = UploadedFile::fake()->createWithContent('vendors.csv', "name,code,phone,address\nVendor import,IMPSUP-1,01700000000,Dhaka\n");
        $this->postJson('/api/v1/pos/suppliers/import', ['file' => $file])->assertCreated()->assertJsonPath('data.imported', 1);
        $this->assertDatabaseHas('suppliers', ['code' => 'IMPSUP-1', 'address' => 'Dhaka']);
    }

    public function test_daily_cash_report_includes_purchase_payments_and_expenses(): void
    {
        $this->postJson('/api/v1/pos/purchases', $this->purchase(['paid_amount' => 60]))->assertCreated();
        $this->postJson('/api/v1/pos/sales', $this->sale())->assertCreated();
        $this->postJson('/api/v1/pos/expenses', ['expense_category' => 'Office Expense', 'title' => 'Paper', 'amount' => 30, 'expense_date' => now()->toDateString(), 'account_name' => 'Cash'])->assertCreated();
        $this->getJson('/api/v1/pos/reports?type=daily_report&date='.now()->toDateString())->assertOk()->assertJsonPath('data.total_income', 200)->assertJsonPath('data.total_expense', 90)->assertJsonPath('data.net_balance', 110);
    }

    public function test_sale_retry_is_idempotent_and_changes_stock_and_cash_once(): void
    {
        $payload = $this->sale();
        $first = $this->postJson('/api/v1/pos/sales', $payload)->assertCreated();
        $this->postJson('/api/v1/pos/sales', $payload)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(8, $this->product->fresh()->available_qty);
        $this->assertEquals(1200, $this->cash->fresh()->balance);
    }

    public function test_overselling_and_duplicate_product_lines_roll_back(): void
    {
        $items = $this->sale()['items'];
        $items[0]['quantity'] = 11;
        $this->postJson('/api/v1/pos/sales', $this->sale(['items' => $items]))->assertUnprocessable();
        $items = $this->sale()['items'];
        $items[] = $items[0];
        $this->postJson('/api/v1/pos/sales', $this->sale(['items' => $items]))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10, $this->product->fresh()->available_qty);
        $this->assertEquals(1000, $this->cash->fresh()->balance);
    }

    public function test_advance_is_consumed_once_and_remaining_credit_is_preserved(): void
    {
        $this->customer->update(['advanced_amount' => 300]);
        $this->postJson('/api/v1/pos/sales', $this->sale(['paid_amount' => 0]))->assertCreated()->assertJsonPath('data.advanced', '200.00');
        $this->assertEquals(100, $this->customer->fresh()->advanced_amount);
        $this->postJson('/api/v1/pos/sales', $this->sale(['paid_amount' => 100]))->assertCreated();
        $this->assertEquals(0, $this->customer->fresh()->advanced_amount);
        $this->assertEquals(1100, $this->cash->fresh()->balance);
    }

    public function test_walk_in_credit_discount_overflow_and_missing_account_are_rejected(): void
    {
        $this->postJson('/api/v1/pos/sales', $this->sale(['customer_id' => null, 'paid_amount' => 0]))->assertUnprocessable();
        $this->postJson('/api/v1/pos/sales', $this->sale(['discount' => 201]))->assertUnprocessable();
        $this->postJson('/api/v1/pos/sales', $this->sale(['payment_account' => 'Missing']))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cash_change_and_actual_deposit_are_correct(): void
    {
        $this->postJson('/api/v1/pos/sales', $this->sale(['paid_amount' => 250]))->assertCreated()->assertJsonPath('data.change_return', '50.00')->assertJsonPath('data.paid_amount', '200.00');
        $this->assertEquals(1200, $this->cash->fresh()->balance);
    }

    public function test_hold_is_non_destructive_and_checkout_updates_original_invoice(): void
    {
        $held = $this->postJson('/api/v1/pos/sales', $this->sale(['is_hold' => true]))->assertCreated()->json('data');
        $this->assertSame(10, $this->product->fresh()->available_qty);
        $this->assertEquals(1000, $this->cash->fresh()->balance);
        $this->getJson('/api/v1/pos/sales/held')->assertOk()->assertJsonPath('data.0.items.0.product_id', $this->product->id);
        $this->postJson('/api/v1/pos/sales', $this->sale(['held_sale_id' => $held['id']]))->assertCreated()->assertJsonPath('data.invoice_id', $held['invoice_id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->getJson('/api/v1/pos/sales/held')->assertJsonCount(0, 'data');
    }

    public function test_failed_held_checkout_preserves_queue(): void
    {
        $held = $this->postJson('/api/v1/pos/sales', $this->sale(['is_hold' => true]))->json('data');
        $this->product->update(['available_qty' => 1]);
        $this->postJson('/api/v1/pos/sales', $this->sale(['held_sale_id' => $held['id']]))->assertUnprocessable();
        $this->assertDatabaseHas('sales', ['id' => $held['id'], 'status' => 'hold']);
        $this->assertDatabaseCount('sale_items', 1);
    }

    public function test_collection_uses_current_due_and_rejects_overpayment(): void
    {
        $this->customer->update(['previous_due' => 100]);
        $payload = ['customer_id' => $this->customer->id, 'collection_date' => now()->toDateString(), 'payment_method' => 'Cash', 'account' => 'Cash', 'receivable_due' => 9999, 'paid_amount' => 90, 'discount_amount' => 20];
        $this->postJson('/api/v1/pos/collections', $payload)->assertUnprocessable();
        $payload['discount_amount'] = 10;
        $this->postJson('/api/v1/pos/collections', $payload)->assertCreated()->assertJsonPath('data.receivable_due', '100.00');
        $this->assertEquals(0, $this->customer->fresh()->previous_due);
        $this->assertEquals(1090, $this->cash->fresh()->balance);
    }

    public function test_permissions_are_enforced_for_api_and_staff_registration(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Authorization', 'Bearer '.app(TokenServiceInterface::class)->generateAccessToken($user));
        $this->getJson('/api/v1/pos/dashboard')->assertForbidden();
        $this->postJson('/api/v1/pos/sales', $this->sale())->assertForbidden();
        $this->getJson('/api/v1/rbac/designations')->assertForbidden();
        $this->postJson('/api/v1/auth/register', ['name' => 'New staff', 'email' => 'staff@example.com', 'password' => 'LongPassword123!'])->assertForbidden();
    }

    public function test_catalog_edit_and_stock_counts_are_audited_and_detect_stale_counts(): void
    {
        $this->postJson('/api/v1/pos/products/'.$this->product->id.'/adjustments', ['counted_quantity' => 7, 'expected_quantity' => 10, 'reason' => 'Physical count'])->assertOk();
        $this->assertDatabaseHas('stock_adjustments', ['product_id' => $this->product->id, 'previous_quantity' => 10, 'difference' => -3, 'user_id' => $this->admin->id]);
        $this->postJson('/api/v1/pos/products/'.$this->product->id.'/adjustments', ['counted_quantity' => 8, 'expected_quantity' => 10, 'reason' => 'Stale count'])->assertUnprocessable();
        $this->assertSame(7, $this->product->fresh()->available_qty);
        $this->assertDatabaseCount('stock_adjustments', 1);
        $this->getJson('/api/v1/pos/stock-adjustments')->assertOk()->assertJsonPath('data.data.0.product_name', 'Switch');
    }

    public function test_product_creation_has_unique_barcode_and_opening_stock_audit(): void
    {
        $data = ['name' => 'Lamp', 'code' => 'LP-1', 'barcode' => '20001', 'unit' => 'pcs', 'available_qty' => 3, 'unit_price' => 20, 'cost_price' => 10, 'low_stock_threshold' => 2, 'is_active' => true];
        $this->postJson('/api/v1/pos/products', $data)->assertCreated();
        $this->postJson('/api/v1/pos/products', array_replace($data, ['code' => 'LP-2']))->assertUnprocessable();
        $this->assertDatabaseCount('stock_adjustments', 1);
        $this->putJson('/api/v1/pos/products/'.$this->product->id, array_replace($data, ['code' => 'SW-1', 'barcode' => '10001', 'is_active' => false, 'available_qty' => 999]))->assertOk();
        $this->assertSame(10, $this->product->fresh()->available_qty);
        $this->postJson('/api/v1/pos/sales', $this->sale())->assertUnprocessable();
    }

    public function test_purchase_supplier_payment_and_purchase_return_balance(): void
    {
        $this->postJson('/api/v1/pos/purchases', $this->purchase())->assertCreated();
        $this->assertSame(12, $this->product->fresh()->available_qty);
        $payment = ['supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(), 'payment_method' => 'Cash', 'account' => 'Cash', 'previous_due' => 9999, 'paid_amount' => 60];
        $this->postJson('/api/v1/pos/purchases/payments', $payment)->assertCreated()->assertJsonPath('data.previous_due', 120);
        $this->postJson('/api/v1/pos/purchases/returns', ['supplier_id' => $this->supplier->id, 'chalan_no' => 'CH-1', 'return_date' => now()->toDateString(), 'cash_refund' => 0, 'payment_account' => 'Cash', 'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 9999, 'reason' => 'defective']]])->assertCreated()->assertJsonPath('data.total_return_amount', '60.00');
        $this->getJson('/api/v1/pos/bootstrap')->assertOk()->assertJsonPath('data.suppliers.0.previous_due', 0);
        $this->postJson('/api/v1/pos/purchases/payments', $payment)->assertUnprocessable();
        $this->assertEquals(940, $this->cash->fresh()->balance);
    }

    public function test_return_is_bound_to_original_sale_and_cannot_be_repeated(): void
    {
        $sale = $this->postJson('/api/v1/pos/sales', $this->sale(['discount' => 20, 'paid_amount' => 180]))->json('data');
        $payload = ['customer_id' => $this->customer->id, 'invoice_id' => $sale['invoice_id'], 'return_date' => now()->toDateString(), 'cash_refund' => 90, 'payment_account' => 'Cash', 'return_items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 9999, 'condition' => 'good']]];
        $this->postJson('/api/v1/pos/returns', $payload)->assertCreated()->assertJsonPath('data.return_amount', 90);
        $this->assertEquals(1090, $this->cash->fresh()->balance);
        $this->assertSame(9, $this->product->fresh()->available_qty);
        $payload['return_items'][0]['quantity'] = 2;
        $this->postJson('/api/v1/pos/returns', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_return_credit_is_preserved_as_advance_and_damaged_items_are_not_restocked(): void
    {
        $sale = $this->postJson('/api/v1/pos/sales', $this->sale())->json('data');
        $this->postJson('/api/v1/pos/returns', ['customer_id' => $this->customer->id, 'invoice_id' => $sale['invoice_id'], 'return_date' => now()->toDateString(), 'payment_account' => 'Cash', 'return_items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100, 'condition' => 'damaged']]])->assertCreated();
        $this->assertEquals(100, $this->customer->fresh()->advanced_amount);
        $this->assertSame(8, $this->product->fresh()->available_qty);
        $this->assertDatabaseCount('wastages', 1);
    }

    public function test_inventory_loss_and_external_transfers_cannot_overdraw_stock(): void
    {
        $loss = ['product_id' => $this->product->id, 'quantity' => 11, 'reason' => 'damaged', 'wastage_date' => now()->toDateString()];
        $this->postJson('/api/v1/pos/wastages', $loss)->assertUnprocessable();
        $loss['quantity'] = 1;
        $this->postJson('/api/v1/pos/wastages', $loss)->assertCreated();
        $transfer = ['transfer_type' => 'warehouse', 'source_name' => 'A', 'destination_name' => 'B', 'transfer_date' => now()->toDateString(), 'items' => [['product_id' => $this->product->id, 'quantity' => 2]]];
        $this->postJson('/api/v1/pos/transfers', $transfer)->assertCreated();
        $this->assertSame(9, $this->product->fresh()->available_qty);
        $transfer['transfer_type'] = 'company';
        $transfer['items'][0]['quantity'] = 10;
        $this->postJson('/api/v1/pos/transfers', $transfer)->assertUnprocessable();
        $this->assertDatabaseCount('stock_transfers', 1);
    }

    public function test_unknown_account_and_insufficient_funds_leave_no_expense_or_transfer(): void
    {
        $expense = ['expense_category' => 'Office Expense', 'title' => 'Rent', 'expense_date' => now()->toDateString(), 'amount' => 1001, 'account_name' => 'Cash'];
        $this->postJson('/api/v1/pos/expenses', $expense)->assertUnprocessable();
        $this->postJson('/api/v1/pos/accounts/transfers', ['from_account' => 'Missing', 'to_account' => 'Cash', 'amount' => 10, 'transfer_date' => now()->toDateString()])->assertUnprocessable();
        $this->assertDatabaseCount('general_expenses', 0);
        $this->assertDatabaseCount('account_transfers', 0);
        $this->assertEquals(1000, $this->cash->fresh()->balance);
    }

    public function test_dashboard_and_date_reports_have_real_zeroes_and_exclude_held_sales(): void
    {
        $this->postJson('/api/v1/pos/sales', $this->sale(['is_hold' => true]))->assertCreated();
        $this->getJson('/api/v1/pos/dashboard')->assertOk()->assertJsonPath('data.metrics.today.sale', 0)->assertJsonCount(0, 'data.recent_sales');
        $this->postJson('/api/v1/pos/sales', $this->sale(['sale_date' => now()->subDay()->toDateString()]))->assertCreated();
        $this->getJson('/api/v1/pos/reports?type=daily_report&date='.now()->toDateString())->assertOk()->assertJsonPath('data.total_income', 0);
        $this->getJson('/api/v1/pos/reports?type=top_sales')->assertOk()->assertJsonPath('data.0.total_qty_sold', 2);
        $this->getJson('/api/v1/pos/reports?type=unknown')->assertUnprocessable();
    }

    public function test_sales_revenue_does_not_count_previous_customer_debt(): void
    {
        $this->customer->update(['previous_due' => 500]);
        $this->postJson('/api/v1/pos/sales', $this->sale(['paid_amount' => 700]))->assertCreated();
        $this->getJson('/api/v1/pos/dashboard')->assertOk()->assertJsonPath('data.metrics.today.sale', 200);
    }

    public function test_logout_revokes_refresh_token_and_cors_preserves_origin(): void
    {
        $tokens = app(TokenServiceInterface::class);
        $refresh = $tokens->generateRefreshToken($this->admin);
        $this->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/v1/health')->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }
}
