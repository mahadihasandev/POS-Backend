<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Designation;
use App\Models\FinancialAccount;
use App\Models\GeneralExpense;
use App\Models\Marketer;
use App\Models\MarketerSlab;
use App\Models\Outlet;
use App\Models\Permission;
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
use App\Models\User;
use App\Models\Wastage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Demo seeding is disabled in production. Use pos:create-admin to provision an owner.');
        }
        // 1. Create Permissions
        $permissionsData = [
            ['slug' => 'sales.returns', 'name' => 'Return & exchange', 'module' => 'Sales'],
            ['slug' => 'purchases.view', 'name' => 'View purchases', 'module' => 'Purchases'],
            ['slug' => 'purchases.create', 'name' => 'Create purchases', 'module' => 'Purchases'],
            ['slug' => 'purchases.payments', 'name' => 'Pay suppliers', 'module' => 'Purchases'],
            ['slug' => 'purchases.returns', 'name' => 'Return purchases', 'module' => 'Purchases'],
            ['slug' => 'accounts.expenses', 'name' => 'Record expenses', 'module' => 'General Accounts'],
            ['slug' => 'accounts.transfers', 'name' => 'Transfer funds', 'module' => 'General Accounts'],
            // Sales
            ['name' => 'Create Sale', 'slug' => 'sales.create', 'module' => 'Sales', 'description' => 'Can perform new sales and supplier-wise sales'],
            ['name' => 'View Sales', 'slug' => 'sales.view', 'module' => 'Sales', 'description' => 'Can view sales invoices and sale history'],
            ['name' => 'Edit Sale', 'slug' => 'sales.edit', 'module' => 'Sales', 'description' => 'Can edit existing invoices and adjust discounts'],
            ['name' => 'Delete Sale', 'slug' => 'sales.delete', 'module' => 'Sales', 'description' => 'Can void or cancel completed sales'],
            ['name' => 'Hold Sales', 'slug' => 'sales.hold', 'module' => 'Sales', 'description' => 'Can hold active sales carts and restore them'],

            // Due Collections
            ['name' => 'Create Collection', 'slug' => 'collections.create', 'module' => 'Collections', 'description' => 'Can collect customer dues and print receipts'],
            ['name' => 'View Collections', 'slug' => 'collections.view', 'module' => 'Collections', 'description' => 'Can view customer due collection records'],

            // Inventory & Products
            ['name' => 'View Inventory', 'slug' => 'inventory.view', 'module' => 'Inventory', 'description' => 'Can view product stock and prices'],
            ['name' => 'Manage Inventory', 'slug' => 'inventory.manage', 'module' => 'Inventory', 'description' => 'Can add, edit, or adjust product stock'],

            // Accounts & Reports
            ['name' => 'View Accounts', 'slug' => 'accounts.view', 'module' => 'General Accounts', 'description' => 'Can view ledger balances and bank accounts'],
            ['name' => 'View Reports', 'slug' => 'reports.view', 'module' => 'Reports', 'description' => 'Can view sales, profit, and liability reports'],

            // RBAC & Users
            ['name' => 'Manage Designations', 'slug' => 'designations.manage', 'module' => 'System & Users', 'description' => 'Can grant and revoke permissions for designations'],
            ['name' => 'Manage Users', 'slug' => 'users.manage', 'module' => 'System & Users', 'description' => 'Can create and manage employees/cashiers'],
        ];

        $permissionModels = [];
        foreach ($permissionsData as $p) {
            $permissionModels[$p['slug']] = Permission::updateOrCreate(
                ['slug' => $p['slug']],
                $p
            );
        }

        // 2. Create Designations
        $adminRole = Designation::updateOrCreate(['slug' => 'admin'], [
            'name' => 'Admin',
            'description' => 'System Administrator with unrestricted access to all modules and configurations',
        ]);

        $managerRole = Designation::updateOrCreate(['slug' => 'branch-manager'], [
            'name' => 'Branch Manager',
            'description' => 'Manages sales operations, due collections, inventory, and views reports',
        ]);

        $cashierRole = Designation::updateOrCreate(['slug' => 'cashier'], [
            'name' => 'Cashier',
            'description' => 'Fast checkout operator with sales creation, barcode scanning, and hold list access',
        ]);

        $accountantRole = Designation::updateOrCreate(['slug' => 'accountant'], [
            'name' => 'Accountant',
            'description' => 'Handles due collections, ledgers, accounts balances, and financial audits',
        ]);

        // Assign Permissions to Roles
        // Admin gets all
        $adminRole->permissions()->sync(array_values(array_map(fn ($p) => $p->id, $permissionModels)));

        // Manager gets sales, collections, inventory, accounts, reports
        $managerRole->permissions()->sync([
            $permissionModels['sales.returns']->id,
            $permissionModels['purchases.view']->id,
            $permissionModels['purchases.create']->id,
            $permissionModels['purchases.returns']->id,
            $permissionModels['purchases.payments']->id,
            $permissionModels['sales.create']->id,
            $permissionModels['sales.view']->id,
            $permissionModels['sales.edit']->id,
            $permissionModels['sales.hold']->id,
            $permissionModels['collections.create']->id,
            $permissionModels['collections.view']->id,
            $permissionModels['inventory.view']->id,
            $permissionModels['inventory.manage']->id,
            $permissionModels['accounts.view']->id,
            $permissionModels['reports.view']->id,
        ]);

        // Cashier gets POS selling, viewing sales, holding sales, and collections
        $cashierRole->permissions()->sync([
            $permissionModels['collections.view']->id,
            $permissionModels['sales.create']->id,
            $permissionModels['sales.view']->id,
            $permissionModels['sales.hold']->id,
            $permissionModels['collections.create']->id,
            $permissionModels['inventory.view']->id,
        ]);

        // Accountant gets collections, accounts, reports
        $accountantRole->permissions()->sync([
            $permissionModels['inventory.view']->id,
            $permissionModels['accounts.expenses']->id,
            $permissionModels['accounts.transfers']->id,
            $permissionModels['purchases.view']->id,
            $permissionModels['purchases.payments']->id,
            $permissionModels['sales.view']->id,
            $permissionModels['collections.create']->id,
            $permissionModels['collections.view']->id,
            $permissionModels['accounts.view']->id,
            $permissionModels['reports.view']->id,
        ]);

        // 3. Create Default Users
        $defaultPassword = Hash::make('password123');

        $adminUser = User::updateOrCreate(['email' => 'admin@smartpos.com'], [
            'name' => 'Admin User',
            'password' => $defaultPassword,
            'designation_id' => $adminRole->id,
            'email_verified_at' => now(),
        ]);

        $cashierUser = User::updateOrCreate(['email' => 'cashier@smartpos.com'], [
            'name' => 'Mitu Das (Cashier)',
            'password' => $defaultPassword,
            'designation_id' => $cashierRole->id,
            'email_verified_at' => now(),
        ]);

        $managerUser = User::updateOrCreate(['email' => 'manager@smartpos.com'], [
            'name' => 'Rahim Chowdhury (Manager)',
            'password' => $defaultPassword,
            'designation_id' => $managerRole->id,
            'email_verified_at' => now(),
        ]);

        // 4. Outlets (Branches)
        $outlet1 = Outlet::updateOrCreate(['code' => 'OUT-01'], [
            'name' => 'DATTA & BROTHERS ELECTRICS',
            'address' => 'Nawabpur Road, Dhaka',
            'phone' => '+880 1711-000001',
        ]);

        $outlet2 = Outlet::updateOrCreate(['code' => 'OUT-02'], [
            'name' => 'SMART ACCOUNT CENTRAL',
            'address' => 'Motijheel C/A, Dhaka',
            'phone' => '+880 1811-000002',
        ]);

        // 5. Suppliers
        $sup1 = Supplier::updateOrCreate(['code' => 'SUP-01'], [
            'name' => 'SUPERSTAR Electronics Limited--BRIGHT',
            'phone' => '01912345671',
        ]);

        $sup2 = Supplier::updateOrCreate(['code' => 'SUP-02'], [
            'name' => 'Walton Hi-Tech Industries',
            'phone' => '01912345672',
        ]);

        $sup3 = Supplier::updateOrCreate(['code' => 'SUP-03'], [
            'name' => 'Singer Bangladesh Ltd',
            'phone' => '01912345673',
        ]);

        // 6. Customers
        $c1 = Customer::updateOrCreate(['code' => 'CUST-794944'], [
            'name' => 'RASHAL ELECTRIC (794944)',
            'phone' => '01812345001',
            'area' => 'Nawabpur Market',
            'previous_due' => 12500.00,
            'advanced_amount' => 0.00,
        ]);

        $c2 = Customer::updateOrCreate(['code' => 'CUST-794945'], [
            'name' => 'Piplu Electric /Hardware',
            'phone' => '01812345002',
            'area' => 'Sadarghat',
            'previous_due' => 8400.00,
            'advanced_amount' => 500.00,
        ]);

        $c3 = Customer::updateOrCreate(['code' => 'CUST-794946'], [
            'name' => 'Mafi Electric /Hardware',
            'phone' => '01812345003',
            'area' => 'Mirpur-10',
            'previous_due' => 4384.80,
            'advanced_amount' => 0.00,
        ]);

        $c4 = Customer::updateOrCreate(['code' => 'CUST-794947'], [
            'name' => 'Abir Electric',
            'phone' => '01812345004',
            'area' => 'Uttara Sector 7',
            'previous_due' => 918.00,
            'advanced_amount' => 0.00,
        ]);

        $c5 = Customer::updateOrCreate(['code' => 'CUST-794948'], [
            'name' => 'SHAHIN ENTERPRISE',
            'phone' => '01812345005',
            'area' => 'Dhanmondi',
            'previous_due' => 4.00,
            'advanced_amount' => 1200.00,
        ]);

        $c6 = Customer::updateOrCreate(['code' => 'CUST-794949'], [
            'name' => 'MA.Treads Swopon paul',
            'phone' => '01812345006',
            'area' => 'Kawran Bazar',
            'previous_due' => 14208.00,
            'advanced_amount' => 0.00,
        ]);

        $c7 = Customer::updateOrCreate(['code' => 'CUST-794950'], [
            'name' => 'MATRI VANDER',
            'phone' => '01812345007',
            'area' => 'Chittagong Road',
            'previous_due' => 50286.60,
            'advanced_amount' => 0.00,
        ]);

        // 7. Marketers (Sales Reps)
        $m1 = Marketer::updateOrCreate(['name' => 'Md. Nazmul Hossain Munsi'], [
            'phone' => '01712399901',
        ]);
        $m2 = Marketer::updateOrCreate(['name' => 'Mitu Das'], [
            'phone' => '01712399902',
        ]);
        $m3 = Marketer::updateOrCreate(['name' => 'Tanvir Ahmed'], [
            'phone' => '01712399903',
        ]);

        // 8. Products
        $p1 = Product::updateOrCreate(['barcode' => '890123450001'], [
            'supplier_id' => $sup1->id,
            'name' => 'Electric Wire 1.5mm Red Coil (100m)',
            'code' => 'BR-0101',
            'available_qty' => 150,
            'unit_price' => 3450.00,
            'cost_price' => 2900.00,
            'unit' => 'coil',
        ]);

        $p2 = Product::updateOrCreate(['barcode' => '890123450002'], [
            'supplier_id' => $sup1->id,
            'name' => 'LED Bulb 18W Bright DayLight E27',
            'code' => 'BR-0102',
            'available_qty' => 420,
            'unit_price' => 420.00,
            'cost_price' => 310.00,
            'unit' => 'pcs',
        ]);

        $p3 = Product::updateOrCreate(['barcode' => '890123450003'], [
            'supplier_id' => $sup1->id,
            'name' => 'Switch Socket Gang 4 Modular Piano',
            'code' => 'BR-0103',
            'available_qty' => 85,
            'unit_price' => 680.00,
            'cost_price' => 490.00,
            'unit' => 'pcs',
        ]);

        $p4 = Product::updateOrCreate(['barcode' => '890123450004'], [
            'supplier_id' => $sup1->id,
            'name' => 'Circuit Breaker 32A Double Pole MCB',
            'code' => 'BR-0104',
            'available_qty' => 60,
            'unit_price' => 1250.00,
            'cost_price' => 950.00,
            'unit' => 'pcs',
        ]);

        $p5 = Product::updateOrCreate(['barcode' => '890123450005'], [
            'supplier_id' => $sup2->id,
            'name' => 'Ceiling Fan 56 inch Royal Deluxe White',
            'code' => 'BR-0105',
            'available_qty' => 34,
            'unit_price' => 3950.00,
            'cost_price' => 3200.00,
            'unit' => 'pcs',
        ]);

        $p6 = Product::updateOrCreate(['barcode' => '890123450006'], [
            'supplier_id' => $sup1->id,
            'name' => 'Copper Cable 2.5 RM Heavy Duty',
            'code' => 'BR-0106',
            'available_qty' => 95,
            'unit_price' => 5120.00,
            'cost_price' => 4300.00,
            'unit' => 'coil',
        ]);

        // 9. Financial Accounts (Exact replica from Image 5)
        $accounts = [
            ['name' => 'Cash', 'account_type' => 'cash', 'account_number' => 'CASH-MAIN', 'balance' => 28395.62],
            ['name' => 'Bkash', 'account_type' => 'mfs', 'account_number' => '01812345000', 'balance' => 0.00],
            ['name' => 'DATTA & BROTHERS ELECTRICS (UCB)', 'account_type' => 'bank', 'account_number' => '0981101000042', 'balance' => 806075.00],
            ['name' => 'DATTA & BROTHERS (UCB)', 'account_type' => 'bank', 'account_number' => '0981101000043', 'balance' => 1168272.00],
            ['name' => 'DBBL-123456', 'account_type' => 'bank', 'account_number' => '1151200001234', 'balance' => 0.00],
            ['name' => 'MOHITUSH DATTA (UCB)', 'account_type' => 'bank', 'account_number' => '0981101000099', 'balance' => 0.00],
            ['name' => 'MOHITUSH DATTA (PUBALI)', 'account_type' => 'bank', 'account_number' => '3321101000456', 'balance' => 160708.00],
            ['name' => 'DATTA & BROTHERS ELECTRICS (PUBALI)', 'account_type' => 'bank', 'account_number' => '3321101000789', 'balance' => 1286043.00],
            ['name' => 'DATTA & BROTHERS ELECTRIC (MERCANTILE)', 'account_type' => 'bank', 'account_number' => '4411101000123', 'balance' => 335450.00],
        ];

        foreach ($accounts as $acc) {
            FinancialAccount::updateOrCreate(['name' => $acc['name']], $acc);
        }

        // 10. Initial Sales Records (From Image 4)
        $salesList = [
            ['invoice_id' => '#S-20261005023', 'date' => '2026-10-04', 'customer_id' => $c1->id, 'supplier_id' => $sup1->id, 'payable' => 6093.36, 'paid' => 0.00, 'due' => 6093.36],
            ['invoice_id' => '#S-20261005022', 'date' => '2026-10-04', 'customer_id' => $c2->id, 'supplier_id' => $sup1->id, 'payable' => 7104.00, 'paid' => 0.00, 'due' => 7104.00],
            ['invoice_id' => '#S-20261005021', 'date' => '2026-10-04', 'customer_id' => $c3->id, 'supplier_id' => $sup1->id, 'payable' => 4384.80, 'paid' => 0.00, 'due' => 4384.80],
            ['invoice_id' => '#S-20261005020', 'date' => '2026-10-04', 'customer_id' => $c4->id, 'supplier_id' => $sup1->id, 'payable' => 918.00, 'paid' => 0.00, 'due' => 918.00],
            ['invoice_id' => '#S-20261005019', 'date' => '2026-10-05', 'customer_id' => $c5->id, 'supplier_id' => $sup1->id, 'payable' => 704.00, 'paid' => 700.00, 'due' => 4.00],
            ['invoice_id' => '#S-20261005018', 'date' => '2026-10-05', 'customer_id' => $c6->id, 'supplier_id' => $sup1->id, 'payable' => 14208.00, 'paid' => 0.00, 'due' => 14208.00],
            ['invoice_id' => '#S-20261005017', 'date' => '2026-10-05', 'customer_id' => $c7->id, 'supplier_id' => $sup1->id, 'payable' => 3927.00, 'paid' => 0.00, 'due' => 3927.00],
            ['invoice_id' => '#S-20261005016', 'date' => '2026-10-05', 'customer_id' => $c7->id, 'supplier_id' => $sup1->id, 'payable' => 46359.60, 'paid' => 0.00, 'due' => 46359.60],
        ];

        foreach ($salesList as $saleData) {
            $sale = Sale::updateOrCreate(['invoice_id' => $saleData['invoice_id']], [
                'outlet_id' => $outlet1->id,
                'customer_id' => $saleData['customer_id'],
                'supplier_id' => $saleData['supplier_id'],
                'user_id' => $cashierUser->id,
                'marketer_id' => $m1->id,
                'sale_date' => $saleData['date'],
                'sale_type' => 'supplier_wise',
                'note' => 'Standard credit sale',
                'invoice_total' => $saleData['payable'],
                'discount' => 0.00,
                'special_discount' => 0.00,
                'delivery_charge' => 0.00,
                'delivery_payer' => 'company',
                'previous_due' => 0.00,
                'advanced' => 0.00,
                'payable_amount' => $saleData['payable'],
                'paid_amount' => $saleData['paid'],
                'due_amount' => $saleData['due'],
                'change_return' => 0.00,
                'payment_account' => 'Cash',
                'status' => 'completed',
            ]);

            // Add sample items
            SaleItem::firstOrCreate([
                'sale_id' => $sale->id,
                'product_id' => $p1->id,
            ], [
                'product_name' => $p1->name,
                'product_code' => $p1->code,
                'quantity' => 2,
                'unit_price' => $p1->unit_price,
                'cost_price' => $p1->cost_price,
                'discount_percent' => 0.00,
                'subtotal' => $p1->unit_price * 2,
                'profit' => ($p1->unit_price - $p1->cost_price) * 2,
            ]);
        }

        // 11. Initial Purchases & Chalan Records (From Image 11.00.39 AM)
        $purchase1 = Purchase::firstOrCreate(['chalan_no' => 'CH-2026-0891'], [
            'supplier_id' => $sup1->id,
            'outlet_id' => $outlet1->id,
            'purchase_date' => '2026-10-02',
            'note' => 'Standard warehouse stock intake',
            'subtotal' => 245000.00,
            'discount' => 2500.00,
            'tax' => 0.00,
            'total_payable' => 242500.00,
            'paid_amount' => 150000.00,
            'due_amount' => 92500.00,
            'payment_account' => 'DATTA & BROTHERS ELECTRICS (UCB)',
            'status' => 'completed',
        ]);

        PurchaseItem::firstOrCreate([
            'purchase_id' => $purchase1->id,
            'product_id' => $p1->id,
        ], [
            'product_name' => $p1->name,
            'product_code' => $p1->code,
            'quantity' => 80,
            'free_qty' => 5,
            'unit_cost' => $p1->cost_price,
            'subtotal' => 80 * $p1->cost_price,
        ]);

        SupplierPayment::firstOrCreate(['payment_no' => 'SPAY-20261003-0001'], [
            'supplier_id' => $sup1->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'Bank Transfer',
            'account' => 'DATTA & BROTHERS ELECTRICS (UCB)',
            'previous_due' => 125000.00,
            'discount' => 500.00,
            'paid_amount' => 50000.00,
            'remaining_due' => 74500.00,
            'note' => 'Partial payment against invoice CH-2026-0891',
        ]);

        // 12. Initial Sale Returns & Exchanges (From Image 11.00.22 AM & 11.00.28 AM)
        $return1 = SaleReturn::firstOrCreate(['return_no' => 'RET-20261004-001'], [
            'invoice_id' => '#S-20261005021',
            'customer_id' => $c3->id,
            'supplier_id' => $sup1->id,
            'return_date' => '2026-10-04',
            'return_amount' => 1450.00,
            'exchange_amount' => 1890.00,
            'net_adjustment' => -440.00,
            'previous_due' => 4384.80,
            'cash_refund' => 0.00,
            'final_due' => 4824.80,
            'comments' => 'Customer replaced defective wire coil with 2 gang switch',
        ]);

        SaleReturnItem::firstOrCreate([
            'sale_return_id' => $return1->id,
            'product_id' => $p1->id,
            'item_type' => 'return',
        ], [
            'product_name' => $p1->name,
            'product_serial' => 'SN-WIRE-9921',
            'quantity' => 1,
            'unit_price' => 1450.00,
            'subtotal' => 1450.00,
            'condition' => 'damaged',
            'item_type' => 'return',
        ]);

        // 13. Marketer Slabs (From Image 11.00.53 AM)
        MarketerSlab::firstOrCreate(['marketer_id' => $m1->id, 'start_amount' => 1.00], [
            'end_amount' => 100000.00,
            'percentage' => 3.00,
        ]);
        MarketerSlab::firstOrCreate(['marketer_id' => $m1->id, 'start_amount' => 100001.00], [
            'end_amount' => 500000.00,
            'percentage' => 5.00,
        ]);
        MarketerSlab::firstOrCreate(['marketer_id' => $m1->id, 'start_amount' => 500001.00], [
            'end_amount' => 2000000.00,
            'percentage' => 7.50,
        ]);

        // 14. Stock Transfers (From Image 11.01.04 AM & 11.01.08 AM)
        $trf1 = StockTransfer::firstOrCreate(['transfer_no' => 'TRF-20261003-001'], [
            'transfer_type' => 'warehouse',
            'source_name' => 'DATTA & BROTHERS ELECTRICS (Main Hub)',
            'destination_name' => 'SMART ACCOUNT CENTRAL (Branch 2)',
            'transfer_date' => '2026-10-03',
            'total_items' => 25,
            'status' => 'completed',
            'note' => 'Replenishing central showroom display units',
        ]);

        StockTransferItem::firstOrCreate([
            'stock_transfer_id' => $trf1->id,
            'product_id' => $p1->id,
        ], [
            'product_name' => $p1->name,
            'quantity' => 25,
        ]);

        // 15. Wastage Records (From Image 11.02.09 AM)
        Wastage::firstOrCreate(['product_id' => $p1->id, 'wastage_date' => '2026-10-03'], [
            'product_name' => $p1->name,
            'quantity' => 2,
            'unit_cost' => $p1->cost_price,
            'total_loss' => $p1->cost_price * 2,
            'reason' => 'damaged',
            'note' => 'Damaged during unloading at dock',
        ]);

        // 16. General Expenses (Image 11.04.12 AM - Daily Report Expenses)
        $expenses = [
            ['voucher_no' => 'EXP-20261005-001', 'expense_category' => 'Electricity bill', 'title' => 'DESCO Commercial Bill October', 'amount' => 12500.00, 'expense_date' => '2026-10-05', 'account_name' => 'Cash', 'payee_name' => 'DESCO'],
            ['voucher_no' => 'EXP-20261005-002', 'expense_category' => 'Employee Salary', 'title' => 'Warehouse & Sales Staff Salary', 'amount' => 45000.00, 'expense_date' => '2026-10-05', 'account_name' => 'Bank Asia (6633)', 'payee_name' => 'Staff Payroll'],
            ['voucher_no' => 'EXP-20261005-003', 'expense_category' => 'Office Expense', 'title' => 'Stationery & Printing Paper Rolls', 'amount' => 3200.00, 'expense_date' => '2026-10-05', 'account_name' => 'Cash', 'payee_name' => 'Popular Stationers'],
            ['voucher_no' => 'EXP-20261005-004', 'expense_category' => 'Transportation Cost', 'title' => 'Goods Delivery Truck Fare Nawabpur', 'amount' => 4800.00, 'expense_date' => '2026-10-05', 'account_name' => 'Cash', 'payee_name' => 'Karim Transport'],
            ['voucher_no' => 'EXP-20261005-005', 'expense_category' => 'Daily Allowance', 'title' => 'Evening shift tea & conveyance', 'amount' => 1250.00, 'expense_date' => '2026-10-05', 'account_name' => 'Cash', 'payee_name' => 'Cashier Box'],
            ['voucher_no' => 'EXP-20261005-006', 'expense_category' => 'Food Expense', 'title' => 'Staff lunch allowance', 'amount' => 2800.00, 'expense_date' => '2026-10-05', 'account_name' => 'Cash', 'payee_name' => 'Al-Razzak Restaurant'],
            ['voucher_no' => 'EXP-20261005-007', 'expense_category' => 'Advanced salary', 'title' => 'Advance salary requested by senior tech', 'amount' => 8000.00, 'expense_date' => '2026-10-05', 'account_name' => 'Cash', 'payee_name' => 'Md. Rafiqul Islam'],
        ];

        foreach ($expenses as $exp) {
            GeneralExpense::firstOrCreate(['voucher_no' => $exp['voucher_no']], $exp);
        }

        // 17. Sample Purchase Return (Image 11.00.35 AM)
        $pret = PurchaseReturn::firstOrCreate(['return_no' => 'PRET-20261004-001'], [
            'chalan_no' => 'CH-88219-SUPER',
            'supplier_id' => $sup1->id,
            'outlet_id' => $outlet1->id,
            'return_date' => '2026-10-04',
            'total_return_amount' => 3800.00,
            'cash_refund' => 0.00,
            'due_deduction' => 3800.00,
            'payment_account' => 'Cash',
            'note' => 'Faulty wiring harnesses returned to vendor',
            'status' => 'completed',
        ]);

        PurchaseReturnItem::firstOrCreate([
            'purchase_return_id' => $pret->id,
            'product_id' => $p1->id,
        ], [
            'product_name' => $p1->name,
            'product_code' => $p1->code,
            'quantity' => 10,
            'unit_cost' => 380.00,
            'subtotal' => 3800.00,
            'reason' => 'defective',
        ]);
    }
}
