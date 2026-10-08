<?php

use App\Models\Designation;
use App\Models\FinancialAccount;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pos:create-admin {email} {--name=Administrator}', function (): void {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email address.');

        return;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('This email already exists. Use the staff screen to assign its role.');

        return;
    }
    $password = $this->secret('Password (at least 12 characters)');
    if (! is_string($password) || strlen($password) < 12) {
        $this->error('Password must be at least 12 characters.');

        return;
    }
    if ($password !== $this->secret('Confirm password')) {
        $this->error('Passwords do not match.');

        return;
    }
    $role = Designation::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    User::create(['email' => $email, 'name' => $this->option('name'), 'password' => $password, 'designation_id' => $role->id]);
    $this->info('Administrator created. Sign in through the frontend.');
})->purpose('Create a POS owner without loading demonstration data');

Artisan::command('pos:setup-store {name} {--code=MAIN} {--address=} {--phone=}', function (): void {
    DB::transaction(function (): void {
        Outlet::firstOrCreate(['code' => $this->option('code')], ['name' => $this->argument('name'), 'address' => $this->option('address'), 'phone' => $this->option('phone')]);
        FinancialAccount::firstOrCreate(['name' => 'Cash'], ['account_type' => 'cash', 'balance' => 0]);
    });
    $this->info('Store and zero-balance Cash account ready. Existing balances were preserved.');
})->purpose('Initialize a production branch and its first payment account');
