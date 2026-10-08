<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['slug' => 'sales.create', 'name' => 'Create sales', 'module' => 'Sales'],
            ['slug' => 'sales.view', 'name' => 'View sales', 'module' => 'Sales'],
            ['slug' => 'sales.hold', 'name' => 'Hold sales', 'module' => 'Sales'],
            ['slug' => 'collections.create', 'name' => 'Collect dues', 'module' => 'Collections'],
            ['slug' => 'collections.view', 'name' => 'View collections', 'module' => 'Collections'],
            ['slug' => 'inventory.view', 'name' => 'View inventory', 'module' => 'Inventory'],
            ['slug' => 'inventory.manage', 'name' => 'Manage inventory', 'module' => 'Inventory'],
            ['slug' => 'accounts.view', 'name' => 'View accounts', 'module' => 'General Accounts'],
            ['slug' => 'reports.view', 'name' => 'View reports', 'module' => 'Reports'],
            ['slug' => 'designations.manage', 'name' => 'Manage designations', 'module' => 'System & Users'],
            ['slug' => 'users.manage', 'name' => 'Manage users', 'module' => 'System & Users'],
            ['slug' => 'sales.returns', 'name' => 'Return & exchange', 'module' => 'Sales'],
            ['slug' => 'purchases.view', 'name' => 'View purchases', 'module' => 'Purchases'],
            ['slug' => 'purchases.create', 'name' => 'Create purchases', 'module' => 'Purchases'],
            ['slug' => 'purchases.payments', 'name' => 'Pay suppliers', 'module' => 'Purchases'],
            ['slug' => 'purchases.returns', 'name' => 'Return purchases', 'module' => 'Purchases'],
            ['slug' => 'accounts.expenses', 'name' => 'Record expenses', 'module' => 'General Accounts'],
            ['slug' => 'accounts.transfers', 'name' => 'Transfer funds', 'module' => 'General Accounts'],
        ];
        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], $permission + ['created_at' => now(), 'updated_at' => now()]);
        }
        // Existing roles receive no new powers implicitly; admins grant them in the staff screen.
    }

    public function down(): void
    {
        $slugs = ['sales.returns', 'purchases.view', 'purchases.create', 'purchases.payments', 'purchases.returns', 'accounts.expenses', 'accounts.transfers'];
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
