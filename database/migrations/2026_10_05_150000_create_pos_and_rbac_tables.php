<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Designations (Roles)
        Schema::create('designations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // 2. Permissions
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('module');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // 3. Designation-Permission Pivot
        Schema::create('designation_permission', function (Blueprint $table): void {
            $table->foreignId('designation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['designation_id', 'permission_id']);
        });

        // 4. Add designation_id to users
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('designation_id')->nullable()->after('password')->constrained('designations')->nullOnDelete();
        });

        // 5. Outlets (Branches)
        Schema::create('outlets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        // 6. Customers
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('phone')->nullable();
            $table->string('area')->nullable();
            $table->decimal('previous_due', 12, 2)->default(0.00);
            $table->decimal('advanced_amount', 12, 2)->default(0.00);
            $table->timestamps();
        });

        // 7. Suppliers
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        // 8. Marketers (Sales Representatives)
        Schema::create('marketers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        // 9. Products & Inventory
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('barcode')->unique()->index();
            $table->integer('available_qty')->default(0);
            $table->decimal('unit_price', 12, 2)->default(0.00);
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->string('unit')->default('pcs');
            $table->timestamps();
        });

        // 10. Financial Accounts
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('account_type')->default('bank'); // cash, bank, mfs
            $table->string('account_number')->nullable();
            $table->decimal('balance', 14, 2)->default(0.00);
            $table->timestamps();
        });

        // 11. Sales (Invoices)
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_id')->unique()->index();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('sale_date');
            $table->string('sale_type')->default('normal'); // normal, supplier_wise
            $table->text('note')->nullable();
            $table->decimal('invoice_total', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('special_discount', 12, 2)->default(0.00);
            $table->decimal('delivery_charge', 12, 2)->default(0.00);
            $table->string('delivery_payer')->default('company'); // company, customer
            $table->decimal('previous_due', 12, 2)->default(0.00);
            $table->decimal('advanced', 12, 2)->default(0.00);
            $table->decimal('payable_amount', 12, 2)->default(0.00);
            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->decimal('due_amount', 12, 2)->default(0.00);
            $table->decimal('change_return', 12, 2)->default(0.00);
            $table->string('payment_account')->default('Cash');
            $table->string('status')->default('completed'); // completed, hold, returned
            $table->timestamps();
        });

        // 12. Sale Items
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->string('product_code');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->decimal('discount_percent', 5, 2)->default(0.00);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('profit', 12, 2)->default(0.00);
            $table->timestamps();
        });

        // 13. Customer Due Collections
        Schema::create('customer_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('collection_number')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('collection_date');
            $table->string('payment_method')->default('Cash');
            $table->string('account')->default('Cash');
            $table->decimal('receivable_due', 12, 2)->default(0.00);
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->boolean('send_sms')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_collections');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('products');
        Schema::dropIfExists('marketers');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('outlets');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['designation_id']);
            $table->dropColumn('designation_id');
        });
        Schema::dropIfExists('designation_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('designations');
    }
};
