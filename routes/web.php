<?php

use App\Http\Controllers\AdvisoryController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\CartAvailabilityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockMovementController;
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

Route::view('/employee', 'employee.home')->middleware('auth');
Route::prefix('api/employee')->middleware(['auth', 'throttle:api'])->group(function (): void {
    Route::get('orders', [FulfillmentController::class, 'index']);
    Route::post('orders/{id}', [FulfillmentController::class, 'update'])->whereNumber('id');
    Route::get('attendance', [EmployeePortalController::class, 'attendance']);
    Route::post('attendance/{action}', [EmployeePortalController::class, 'punch'])->whereIn('action', ['time-in', 'time-out']);
    Route::get('profile', [\App\Http\Controllers\ProfileController::class, 'show']);
    Route::put('profile', [\App\Http\Controllers\ProfileController::class, 'update']);
});

Route::middleware('auth')->group(function (): void {
    Route::view('/cashier', 'cashier.terminal')->name('cashier.index');
    Route::view('/cashier/queue', 'cashier.queue')->name('cashier.queue');
    Route::view('/cashier/attendance', 'cashier.attendance')->name('cashier.attendance');
    Route::view('/cashier/history', 'cashier.history')->name('cashier.history');

    // Legacy wizard URLs still resolve to the terminal.
    Route::get('/cashier/{step}', fn () => redirect()->route('cashier.index'))
        ->whereIn('step', ['customer-custody', 'products-intake', 'payment-print'])
        ->name('cashier.step');

    Route::middleware('owner')->group(function (): void {
        Route::redirect('/admin', '/admin/dashboard')->name('admin.index');

        Route::get('/admin/{tab}', fn (string $tab) => view("admin.{$tab}"))
            ->whereIn('tab', ['dashboard', 'sales', 'arima', 'inventory', 'customers', 'suppliers', 'employees', 'attendance', 'users', 'settings'])
            ->name('admin.tab');
    });
});

/*
|--------------------------------------------------------------------------
| API (session authenticated, same origin)
|--------------------------------------------------------------------------
*/
Route::prefix('api')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.health');
    Route::get('auth/session', [AuthController::class, 'session'])->name('api.session');

    // Login is rate limited to slow down credential stuffing.
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('auth/setup-login', [\App\Http\Controllers\AdminSetupController::class, 'login']);
        Route::post('auth/setup-owner', [\App\Http\Controllers\AdminSetupController::class, 'prepare']);
        Route::post('auth/setup-verify', [\App\Http\Controllers\AdminSetupController::class, 'verify']);
        Route::post('auth/verify-email', [AuthController::class, 'verifyEmail']);
        Route::post('auth/recover', [AuthController::class, 'recover']);
        Route::post('auth/login', [AuthController::class, 'login'])->name('api.login');
        Route::post('auth/login-owner', [AuthController::class, 'loginOwner'])->name('api.login.owner');
    });

    Route::middleware(['auth', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.logout');

        Route::get('bootstrap', BootstrapController::class)->name('api.bootstrap');

        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::post('attendance/employees/{employeeId}/{action}', [AttendanceController::class, 'punch'])->whereNumber('employeeId')->whereIn('action', ['time-in', 'time-out']);
        Route::get('attendance/employees/{employeeId}/changes', [AttendanceController::class, 'changes'])->whereNumber('employeeId')->middleware('owner');

        // Personal profile and settings
        Route::get('profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('api.profile.show');
        Route::put('profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('api.profile.update');

        // Customers and receivables
        Route::get('customers', [CustomerController::class, 'index'])->name('api.customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->name('api.customers.store');
        Route::post('customers/{customer}/settle', [CustomerController::class, 'settle'])->name('api.customers.settle');
        Route::post('customers/{customer}/returns', fn () => abort(410, 'Station jug custody is no longer supported.'))->name('api.customers.returns');

        // Inventory and dynamic thresholds
        Route::get('inventory', [InventoryController::class, 'index'])->name('api.inventory.index');
        Route::post('inventory', [InventoryController::class, 'store'])
            ->middleware('owner')
            ->name('api.inventory.store');
        Route::patch('inventory/{inventory}', [InventoryController::class, 'update'])->middleware('owner')->name('api.inventory.update');
        Route::delete('inventory/{inventory}', [InventoryController::class, 'destroy'])
            ->middleware('owner')
            ->name('api.inventory.destroy');
        Route::post('inventory/recalculate', [InventoryController::class, 'recalculate'])
            ->middleware('owner')
            ->name('api.inventory.recalculate');

        Route::middleware('owner')->group(function (): void {
            Route::get('products/recipes', [RecipeController::class, 'index']);
            Route::put('products/{product}/recipe', [RecipeController::class, 'update']);
            Route::get('inventory/{inventory}/movements', [StockMovementController::class, 'index']);
            Route::post('inventory/{inventory}/movements', [StockMovementController::class, 'store']);
        });

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

        Route::post('cart/availability', CartAvailabilityController::class);

        // Sales
        Route::get('transactions', [TransactionController::class, 'index'])->name('api.transactions.index');
        Route::post('transactions', [TransactionController::class, 'store'])->name('api.transactions.store');

        Route::middleware('owner')->group(function (): void {
            Route::get('employees', [EmployeeController::class, 'index']);
            Route::post('employees', [EmployeeController::class, 'store']);
            Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
        });
        Route::post('queue/{queueItem}/deliver', [QueueController::class, 'deliver']);

        Route::get('fulfillment', [FulfillmentController::class, 'index']);
        Route::post('fulfillment/{id}', [FulfillmentController::class, 'update'])->whereNumber('id')->middleware('owner');

        // Production queue
        Route::get('queue', [QueueController::class, 'index'])->name('api.queue.index');
        Route::post('queue/{queueItem}/advance', [QueueController::class, 'advance'])->middleware('owner')->name('api.queue.advance');

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
            ->middleware(['owner', 'throttle:forecast'])
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
