<?php

use App\Http\Controllers\AdvisoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

Route::view('/', 'auth.cashier-login')->name('cashier.login');
Route::view('/owner/login', 'auth.owner-login')->name('owner.login');

Route::middleware('auth')->group(function (): void {
    Route::view('/cashier', 'cashier.terminal')->name('cashier.index');
    Route::view('/cashier/queue', 'cashier.queue')->name('cashier.queue');
    Route::view('/cashier/history', 'cashier.history')->name('cashier.history');

    // Legacy wizard URLs still resolve to the terminal.
    Route::get('/cashier/{step}', fn () => redirect()->route('cashier.index'))
        ->whereIn('step', ['customer-custody', 'products-intake', 'payment-print'])
        ->name('cashier.step');

    Route::middleware('owner')->group(function (): void {
        Route::redirect('/admin', '/admin/dashboard')->name('admin.index');

        Route::get('/admin/{tab}', fn (string $tab) => view("admin.{$tab}"))
            ->whereIn('tab', ['dashboard', 'sales', 'arima', 'inventory', 'customers', 'suppliers', 'users', 'settings'])
            ->name('admin.tab');
    });
});

/*
|--------------------------------------------------------------------------
| API (session authenticated, same origin)
|--------------------------------------------------------------------------
*/
Route::prefix('api')->group(function (): void {    Route::get('health', HealthController::class)->name('api.health');
    Route::get('auth/session', [AuthController::class, 'session'])->name('api.session');

    // Login is rate limited to slow down credential stuffing.
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'login'])->name('api.login');
        Route::post('auth/login-owner', [AuthController::class, 'loginOwner'])->name('api.login.owner');
    });

    Route::middleware('auth')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.logout');

        Route::get('bootstrap', BootstrapController::class)->name('api.bootstrap');

        // Customers and receivables
        Route::get('customers', [CustomerController::class, 'index'])->name('api.customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->name('api.customers.store');
        Route::post('customers/{customer}/settle', [CustomerController::class, 'settle'])->name('api.customers.settle');
        Route::post('customers/{customer}/returns', [CustomerController::class, 'logReturn'])->name('api.customers.returns');

        // Inventory and dynamic thresholds
        Route::get('inventory', [InventoryController::class, 'index'])->name('api.inventory.index');
        Route::post('inventory', [InventoryController::class, 'store'])
            ->middleware('owner')
            ->name('api.inventory.store');
        Route::patch('inventory/{inventory}', [InventoryController::class, 'update'])->name('api.inventory.update');
        Route::delete('inventory/{inventory}', [InventoryController::class, 'destroy'])
            ->middleware('owner')
            ->name('api.inventory.destroy');
        Route::post('inventory/recalculate', [InventoryController::class, 'recalculate'])
            ->middleware('owner')
            ->name('api.inventory.recalculate');

        // Suppliers directory
        Route::get('suppliers', [SupplierController::class, 'index'])->name('api.suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])
            ->middleware('owner')
            ->name('api.suppliers.store');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])
            ->middleware('owner')
            ->name('api.suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('owner')
            ->name('api.suppliers.destroy');

        // Sales
        Route::get('transactions', [TransactionController::class, 'index'])->name('api.transactions.index');
        Route::post('transactions', [TransactionController::class, 'store'])->name('api.transactions.store');

        // Production queue
        Route::get('queue', [QueueController::class, 'index'])->name('api.queue.index');
        Route::post('queue/{queueItem}/advance', [QueueController::class, 'advance'])->name('api.queue.advance');

        // Settings
        Route::get('settings', [SettingsController::class, 'index'])->name('api.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])
            ->middleware('owner')
            ->name('api.settings.update');

        // Forecasting (Tier 3 output)
        Route::get('forecast', [ForecastController::class, 'index'])->name('api.forecast.index');
        Route::post('forecast', [ForecastController::class, 'store'])
            ->middleware('owner')
            ->name('api.forecast.store');
        Route::post('forecast/run', [ForecastController::class, 'run'])
            ->middleware('owner')
            ->name('api.forecast.run');

        // Replenishment advisories
        Route::get('advisories', AdvisoryController::class)->name('api.advisories');

        // Users and access
        Route::get('users', [UserController::class, 'index'])
            ->middleware('owner')
            ->name('api.users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('owner')
            ->name('api.users.store');
        Route::patch('users/{user}', [UserController::class, 'update'])
            ->middleware('owner')
            ->name('api.users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->middleware('owner')
            ->name('api.users.destroy');
    });
});

