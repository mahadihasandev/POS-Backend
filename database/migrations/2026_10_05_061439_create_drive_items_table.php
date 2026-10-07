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
        Schema::create('drive_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('drive_items')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('type', 20)->default('file'); // 'file' or 'folder'
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('storage_path')->nullable();
            $table->string('checksum', 64)->nullable(); // SHA-256 integrity hash
            $table->boolean('is_encrypted')->default(true); // Encrypted at-rest on disk
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_trashed')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // High-performance composite indexes
            $table->index(['user_id', 'parent_id', 'is_trashed']);
            $table->index(['user_id', 'type', 'is_trashed']);
            $table->index(['user_id', 'is_starred']);
            $table->index(['checksum']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drive_items');
    }
};
