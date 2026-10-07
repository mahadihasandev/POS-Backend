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
        // 1. Purchases
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('chalan_no')->unique()->index();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->date('purchase_date');
            $table->text('note')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('tax', 12, 2)->default(0.00);
            $table->decimal('total_payable', 12, 2)->default(0.00);
            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->decimal('due_amount', 12, 2)->default(0.00);
            $table->string('payment_account')->default('Cash');
            $table->string('status')->default('completed'); // completed, hold, returned
            $table->timestamps();
        });

        // 2. Purchase Items
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->integer('quantity');
            $table->integer('free_qty')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0.00);
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->timestamps();
        });

        // 3. Supplier Payments
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_no')->unique();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date');
            $table->string('payment_method')->default('Cash');
            $table->string('account')->default('Cash');
            $table->decimal('previous_due', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->decimal('remaining_due', 12, 2)->default(0.00);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 4. Sales Returns & Exchanges
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_no')->unique()->index();
            $table->string('invoice_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->date('return_date');
            $table->decimal('return_amount', 12, 2)->default(0.00);
            $table->decimal('exchange_amount', 12, 2)->default(0.00);
            $table->decimal('net_adjustment', 12, 2)->default(0.00);
            $table->decimal('previous_due', 12, 2)->default(0.00);
            $table->decimal('cash_refund', 12, 2)->default(0.00);
            $table->decimal('final_due', 12, 2)->default(0.00);
            $table->text('comments')->nullable();
            $table->timestamps();
        });

        // 5. Sale Return / Exchange Items
        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->string('product_serial')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2)->default(0.00);
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->string('condition')->default('good'); // good, damaged, scrap
            $table->string('item_type')->default('return'); // return, exchange
            $table->timestamps();
        });

        // 6. Marketer Commission Slabs & History
        Schema::create('marketer_slabs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketer_id')->constrained()->cascadeOnDelete();
            $table->decimal('start_amount', 12, 2);
            $table->decimal('end_amount', 12, 2);
            $table->decimal('percentage', 5, 2);
            $table->timestamps();
        });

        Schema::create('marketer_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketer_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->default('Cash');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 7. Stock Transfers (Warehouse to Warehouse & Company to Company)
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no')->unique()->index();
            $table->string('transfer_type')->default('warehouse'); // warehouse, company
            $table->string('source_name');
            $table->string('destination_name');
            $table->date('transfer_date');
            $table->integer('total_items')->default(0);
            $table->string('status')->default('completed'); // completed, pending
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->integer('quantity');
            $table->timestamps();
        });

        // 8. Wastage / Damage Stock Records
        Schema::create('wastages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_loss', 12, 2);
            $table->string('reason')->default('damaged'); // damaged, expired, lost
            $table->date('wastage_date');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 9. Customer Areas & Categories
        Schema::create('customer_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('area'); // area, tier
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_categories');
        Schema::dropIfExists('wastages');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('marketer_payments');
        Schema::dropIfExists('marketer_slabs');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
