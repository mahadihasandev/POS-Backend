<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\DesignationController;
use App\Http\Controllers\Api\v1\DriveItemController;
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
    Route::middleware('jwt.auth')->group(function (): void {

        // User Auth Profile, Logout & Registration (Only logged-in users can register new accounts from inside the webapp)
        Route::prefix('auth')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        });

        // POS Billing, Real-Time Inventory, Collections & Financial Accounts
        Route::prefix('pos')->group(function (): void {
            Route::get('/bootstrap', [PosController::class, 'bootstrap'])->name('api.v1.pos.bootstrap');
            Route::get('/products', [PosController::class, 'searchProducts'])->name('api.v1.pos.products');
            Route::post('/sales', [PosController::class, 'storeSale'])->name('api.v1.pos.sales.store');
            Route::get('/sales', [PosController::class, 'getSales'])->name('api.v1.pos.sales.index');
            Route::get('/sales/held', [PosController::class, 'getHeldSales'])->name('api.v1.pos.sales.held');
            Route::delete('/sales/held/{id}', [PosController::class, 'resumeSale'])->name('api.v1.pos.sales.resume');
            Route::post('/collections', [PosController::class, 'storeCollection'])->name('api.v1.pos.collections.store');
            Route::get('/collections', [PosController::class, 'getCollections'])->name('api.v1.pos.collections.index');
            Route::get('/dashboard', [PosController::class, 'dashboard'])->name('api.v1.pos.dashboard');

            // Extended ERP Modules: Purchases, Returns, Marketers, Transfers, Reports
            Route::get('/purchases', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getPurchases'])->name('api.v1.pos.purchases.index');
            Route::post('/purchases', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storePurchase'])->name('api.v1.pos.purchases.store');
            Route::get('/purchases/payments', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getSupplierPayments'])->name('api.v1.pos.purchases.payments.index');
            Route::post('/purchases/payments', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeSupplierPayment'])->name('api.v1.pos.purchases.payments.store');
            Route::get('/purchases/returns', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getPurchaseReturns'])->name('api.v1.pos.purchases.returns.index');
            Route::post('/purchases/returns', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storePurchaseReturn'])->name('api.v1.pos.purchases.returns.store');
            Route::get('/expenses', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getGeneralExpenses'])->name('api.v1.pos.expenses.index');
            Route::post('/expenses', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeGeneralExpense'])->name('api.v1.pos.expenses.store');
            Route::get('/accounts/transfers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getAccountTransfers'])->name('api.v1.pos.accounts.transfers.index');
            Route::post('/accounts/transfers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeAccountTransfer'])->name('api.v1.pos.accounts.transfers.store');
            Route::get('/returns', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getSaleReturns'])->name('api.v1.pos.returns.index');
            Route::post('/returns', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeSaleReturn'])->name('api.v1.pos.returns.store');
            Route::get('/marketers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getMarketers'])->name('api.v1.pos.marketers.index');
            Route::post('/marketers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeMarketer'])->name('api.v1.pos.marketers.store');
            Route::post('/marketers/payments', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeMarketerPayment'])->name('api.v1.pos.marketers.payments.store');
            Route::get('/transfers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getStockTransfers'])->name('api.v1.pos.transfers.index');
            Route::post('/transfers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeStockTransfer'])->name('api.v1.pos.transfers.store');
            Route::get('/wastages', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getWastages'])->name('api.v1.pos.wastages.index');
            Route::post('/wastages', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeWastage'])->name('api.v1.pos.wastages.store');
            Route::post('/suppliers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeSupplier'])->name('api.v1.pos.suppliers.store');
            Route::post('/customers', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'storeCustomer'])->name('api.v1.pos.customers.store');
            Route::get('/customer-categories', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getCustomerCategories'])->name('api.v1.pos.customer-categories.index');
            Route::get('/reports', [\App\Http\Controllers\Api\v1\ErpModulesController::class, 'getReportData'])->name('api.v1.pos.reports.index');
        });

        // Role-Based Access Control (RBAC) & Designations Permissions Management
        Route::prefix('rbac')->group(function (): void {
            Route::get('/designations', [DesignationController::class, 'index'])->name('api.v1.rbac.designations.index');
            Route::post('/designations', [DesignationController::class, 'store'])->name('api.v1.rbac.designations.store');
            Route::put('/designations/{id}/permissions', [DesignationController::class, 'updatePermissions'])->name('api.v1.rbac.designations.permissions');
            Route::get('/permissions', [DesignationController::class, 'getAllPermissions'])->name('api.v1.rbac.permissions');
            Route::get('/users', [DesignationController::class, 'getUsers'])->name('api.v1.rbac.users');
            Route::put('/users/{id}/designation', [DesignationController::class, 'updateUserDesignation'])->name('api.v1.rbac.users.designation');
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
