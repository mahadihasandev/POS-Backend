<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\ContactImportController;
use App\Http\Controllers\Api\v1\DesignationController;
use App\Http\Controllers\Api\v1\DriveItemController;
use App\Http\Controllers\Api\v1\ErpModulesController;
use App\Http\Controllers\Api\v1\InventoryController;
use App\Http\Controllers\Api\v1\PosController;
use App\Http\Controllers\Api\v1\SystemHealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {

    // Public Health Check
    Route::get('/health', [SystemHealthController::class, 'health'])->name('api.v1.health');

    // Public Authentication Routes (Rate limited for brute-force protection)
    Route::prefix('auth')->middleware('throttle:15,1')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('api.v1.auth.refresh');
    });

    // Protected Routes (Encrypted JWT Token Required)
    Route::middleware(['jwt.auth', 'throttle:300,1'])->group(function (): void {

        // User Auth Profile, Logout & Registration (Only logged-in users can register new accounts from inside the webapp)
        Route::prefix('auth')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('/register', [AuthController::class, 'register'])->middleware('permission:users.manage')->name('api.v1.auth.register');
        });

        // POS Billing, Real-Time Inventory, Collections & Financial Accounts
        Route::prefix('pos')->group(function (): void {
            Route::get('/bootstrap', [PosController::class, 'bootstrap'])->middleware('permission:inventory.view')->name('api.v1.pos.bootstrap');
            Route::post('/products', [InventoryController::class, 'store'])->middleware('permission:inventory.manage');
            Route::put('/products/{product}', [InventoryController::class, 'update'])->middleware('permission:inventory.manage');
            Route::post('/products/{product}/adjustments', [InventoryController::class, 'adjust'])->middleware('permission:inventory.manage');
            Route::get('/stock-adjustments', [InventoryController::class, 'adjustments'])->middleware('permission:inventory.view');
            Route::get('/products', [PosController::class, 'searchProducts'])->middleware('permission:inventory.view')->name('api.v1.pos.products');
            Route::post('/sales', [PosController::class, 'storeSale'])->middleware('permission:sales.create')->name('api.v1.pos.sales.store');
            Route::get('/sales', [PosController::class, 'getSales'])->middleware('permission:sales.view')->name('api.v1.pos.sales.index');
            Route::get('/sales/held', [PosController::class, 'getHeldSales'])->middleware('permission:sales.hold')->name('api.v1.pos.sales.held');
            Route::delete('/sales/held/{id}', [PosController::class, 'resumeSale'])->middleware('permission:sales.hold')->name('api.v1.pos.sales.resume');
            Route::post('/collections', [PosController::class, 'storeCollection'])->middleware('permission:collections.create')->name('api.v1.pos.collections.store');
            Route::get('/collections', [PosController::class, 'getCollections'])->middleware('permission:collections.view')->name('api.v1.pos.collections.index');
            Route::get('/dashboard', [PosController::class, 'dashboard'])->middleware('permission:reports.view')->name('api.v1.pos.dashboard');

            // Extended ERP Modules: Purchases, Returns, Marketers, Transfers, Reports
            Route::get('/purchases', [ErpModulesController::class, 'getPurchases'])->middleware('permission:purchases.view')->name('api.v1.pos.purchases.index');
            Route::post('/purchases', [ErpModulesController::class, 'storePurchase'])->middleware('permission:purchases.create')->name('api.v1.pos.purchases.store');
            Route::get('/purchases/payments', [ErpModulesController::class, 'getSupplierPayments'])->middleware('permission:purchases.view')->name('api.v1.pos.purchases.payments.index');
            Route::post('/purchases/payments', [ErpModulesController::class, 'storeSupplierPayment'])->middleware('permission:purchases.payments')->name('api.v1.pos.purchases.payments.store');
            Route::get('/purchases/returns', [ErpModulesController::class, 'getPurchaseReturns'])->middleware('permission:purchases.view')->name('api.v1.pos.purchases.returns.index');
            Route::post('/purchases/returns', [ErpModulesController::class, 'storePurchaseReturn'])->middleware('permission:purchases.returns')->name('api.v1.pos.purchases.returns.store');
            Route::get('/expenses', [ErpModulesController::class, 'getGeneralExpenses'])->middleware('permission:accounts.view')->name('api.v1.pos.expenses.index');
            Route::post('/expenses', [ErpModulesController::class, 'storeGeneralExpense'])->middleware('permission:accounts.expenses')->name('api.v1.pos.expenses.store');
            Route::get('/accounts/transfers', [ErpModulesController::class, 'getAccountTransfers'])->middleware('permission:accounts.view')->name('api.v1.pos.accounts.transfers.index');
            Route::post('/accounts/transfers', [ErpModulesController::class, 'storeAccountTransfer'])->middleware('permission:accounts.transfers')->name('api.v1.pos.accounts.transfers.store');
            Route::get('/returns', [ErpModulesController::class, 'getSaleReturns'])->middleware('permission:sales.view')->name('api.v1.pos.returns.index');
            Route::post('/returns', [ErpModulesController::class, 'storeSaleReturn'])->middleware('permission:sales.returns')->name('api.v1.pos.returns.store');
            Route::get('/marketers', [ErpModulesController::class, 'getMarketers'])->middleware('permission:reports.view')->name('api.v1.pos.marketers.index');
            Route::post('/marketers', [ErpModulesController::class, 'storeMarketer'])->middleware('permission:users.manage')->name('api.v1.pos.marketers.store');
            Route::post('/marketers/payments', [ErpModulesController::class, 'storeMarketerPayment'])->middleware('permission:accounts.expenses')->name('api.v1.pos.marketers.payments.store');
            Route::get('/transfers', [ErpModulesController::class, 'getStockTransfers'])->middleware('permission:inventory.view')->name('api.v1.pos.transfers.index');
            Route::post('/transfers', [ErpModulesController::class, 'storeStockTransfer'])->middleware('permission:inventory.manage')->name('api.v1.pos.transfers.store');
            Route::get('/wastages', [ErpModulesController::class, 'getWastages'])->middleware('permission:inventory.view')->name('api.v1.pos.wastages.index');
            Route::post('/wastages', [ErpModulesController::class, 'storeWastage'])->middleware('permission:inventory.manage')->name('api.v1.pos.wastages.store');
            Route::post('/suppliers/import', [ContactImportController::class, 'suppliers'])->middleware('permission:purchases.create');
            Route::post('/suppliers', [ErpModulesController::class, 'storeSupplier'])->middleware('permission:purchases.create')->name('api.v1.pos.suppliers.store');
            Route::post('/customers/import', [ContactImportController::class, 'store'])->middleware('permission:sales.create');
            Route::post('/customers', [ErpModulesController::class, 'storeCustomer'])->middleware('permission:sales.create')->name('api.v1.pos.customers.store');
            Route::get('/customer-categories', [ErpModulesController::class, 'getCustomerCategories'])->middleware('permission:sales.view')->name('api.v1.pos.customer-categories.index');
            Route::get('/reports', [ErpModulesController::class, 'getReportData'])->middleware('permission:reports.view')->name('api.v1.pos.reports.index');
        });

        // Role-Based Access Control (RBAC) & Designations Permissions Management
        Route::prefix('rbac')->group(function (): void {
            Route::get('/designations', [DesignationController::class, 'index'])->middleware('permission:designations.manage')->name('api.v1.rbac.designations.index');
            Route::post('/designations', [DesignationController::class, 'store'])->middleware('permission:designations.manage')->name('api.v1.rbac.designations.store');
            Route::put('/designations/{id}/permissions', [DesignationController::class, 'updatePermissions'])->middleware('permission:designations.manage')->name('api.v1.rbac.designations.permissions');
            Route::get('/permissions', [DesignationController::class, 'getAllPermissions'])->middleware('permission:designations.manage')->name('api.v1.rbac.permissions');
            Route::get('/users', [DesignationController::class, 'getUsers'])->middleware('permission:users.manage')->name('api.v1.rbac.users');
            Route::put('/users/{id}/designation', [DesignationController::class, 'updateUserDesignation'])->middleware('permission:users.manage')->name('api.v1.rbac.users.designation');
        });

        // Drive File & Folder Management
        Route::prefix('drive')->group(function (): void {
            Route::get('/summary', [DriveItemController::class, 'storageSummary'])->name('api.v1.drive.summary');
            Route::get('/items', [DriveItemController::class, 'index'])->name('api.v1.drive.items');
            Route::post('/folders', [DriveItemController::class, 'storeFolder'])->name('api.v1.drive.folders.create');
            Route::post('/upload', [DriveItemController::class, 'uploadFile'])->name('api.v1.drive.upload');
            Route::get('/items/{uuid}', [DriveItemController::class, 'show'])->name('api.v1.drive.show');
            Route::get('/items/{uuid}/download', [DriveItemController::class, 'download'])->name('api.v1.drive.download');
            Route::patch('/items/{uuid}/star', [DriveItemController::class, 'toggleStar'])->name('api.v1.drive.star');
            Route::post('/items/{uuid}/trash', [DriveItemController::class, 'trash'])->name('api.v1.drive.trash');
            Route::post('/items/{uuid}/restore', [DriveItemController::class, 'restore'])->name('api.v1.drive.restore');
            Route::delete('/items/{uuid}', [DriveItemController::class, 'destroy'])->name('api.v1.drive.destroy');
        });
    });
});
