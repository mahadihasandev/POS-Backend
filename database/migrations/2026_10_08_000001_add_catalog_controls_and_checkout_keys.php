<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('low_stock_threshold')->default(25);
            $table->boolean('is_active')->default(true);
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->unique();
            $table->index(['status', 'sale_date']);
        });
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->integer('previous_quantity');
            $table->unsignedInteger('counted_quantity');
            $table->integer('difference');
            $table->string('reason', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['status', 'sale_date']);
            $table->dropColumn('request_id');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['low_stock_threshold', 'is_active']));
    }
};
