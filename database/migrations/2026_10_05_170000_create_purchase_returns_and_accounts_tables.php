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
        // 1. Purchase Returns (Vendor debit notes)
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_no')->unique()->index();
            $table->string('chalan_no')->nullable();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->date('return_date');
            $table->decimal('total_return_amount', 12, 2)->default(0.00);
            $table->decimal('cash_refund', 12, 2)->default(0.00);
            $table->decimal('due_deduction', 12, 2)->default(0.00);
            $table->string('payment_account')->default('Cash');
            $table->text('note')->nullable();
            $table->string('status')->default('completed'); // completed, pending
            $table->timestamps();
        });

        // 2. Purchase Return Items
        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2)->default(0.00);
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->string('reason')->default('defective'); // defective, wrong_item, excess, damaged
            $table->timestamps();
        });

        // 3. General Expenses (Image 11.04.12 AM - Daily Report Expenses)
        Schema::create('general_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('voucher_no')->unique()->index();
            $table->string('expense_category'); // Electricity Bill, Employee Salary, Office Expense, Transportation Cost, Daily Allowance, Food Expense, Advanced Salary, Maintenance, Others
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('account_name')->default('Cash');
            $table->string('payee_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 4. Internal Bank & Cash Transfers
        Schema::create('account_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no')->unique()->index();
            $table->string('from_account');
            $table->string('to_account');
            $table->decimal('amount', 12, 2);
            $table->date('transfer_date');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
        Schema::dropIfExists('general_expenses');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
