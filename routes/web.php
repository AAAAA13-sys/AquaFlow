<?php

use App\Http\Controllers\AdminSetupController;
use App\Http\Controllers\AdvisoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\CartAvailabilityController;
use App\Http\Controllers\CashShiftController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
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

        // Till & Prices is now a view onto the stock screen, not a tab of its own.
        Route::get('/admin/till', fn () => redirect('/admin/inventory?view=prices'))
            ->name('admin.till');

        Route::get('/admin/{tab}', fn (string $tab) => view("admin.{$tab}"))
            ->whereIn('tab', ['dashboard', 'sales', 'arima', 'inventory', 'customers', 'suppliers', 'users', 'settings', 'cash'])
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
        Route::post('auth/setup-login', [AdminSetupController::class, 'login']);
        Route::post('auth/setup-owner', [AdminSetupController::class, 'complete']);
        Route::post('auth/login', [AuthController::class, 'login'])->name('api.login');
        Route::post('auth/login-owner', [AuthController::class, 'loginOwner'])->name('api.login.owner');
    });

    Route::middleware(['auth', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.logout');

        Route::get('bootstrap', BootstrapController::class)->name('api.bootstrap');

        // Personal profile and settings
        Route::get('profile', [ProfileController::class, 'show'])->name('api.profile.show');
        Route::put('profile', [ProfileController::class, 'update'])->name('api.profile.update');

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
            // Which stock row a sale consumes - the same shape the POS needs.
            Route::get('products', [ProductController::class, 'index'])->name('api.products.index');
            Route::post('products', [ProductController::class, 'store'])
                ->middleware('owner')
                ->name('api.products.store');
            Route::patch('products/{product}', [ProductController::class, 'update'])
                ->middleware('owner')
                ->name('api.products.update');

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

        // Riders and refillers are attached to an employee record, which the
        // fulfillment history attributes work to. There is no employee screen:
        // staff are managed on the single Team & Access tab.
        Route::middleware('owner')->group(function (): void {
            Route::get('employees', [EmployeeController::class, 'index']);
            Route::post('employees', [EmployeeController::class, 'store']);
            Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
        });
        Route::post('queue/{queueItem}/deliver', [QueueController::class, 'deliver']);

        // Rider / refiller work surface. A delivery order is staged and then
        // driven to completion from here rather than by the cashier, so the
        // fulfilment record captures who handled it.
        Route::get('fulfillment', [FulfillmentController::class, 'index']);

        // Not owner-gated: handover and the buyer code are the cashier's job, and
        // FulfillmentService enforces per-action eligibility with canHandleCash().
        Route::post('fulfillment/{id}', [FulfillmentController::class, 'update'])->whereNumber('id');

        // Delivery evidence and remittance, from the counter side. The rider posts
        // progress through /api/employee/orders; the cashier reads the evidence
        // and accepts the cash here, which is what settles the account balance.
        // canHandleCash() in the controller keeps owners and cashiers off each
        // other's rows, so these are not owner-gated.
        Route::get('deliveries/{id}', [FulfillmentController::class, 'show'])->whereNumber('id');
        Route::get('deliveries/{id}/proof', [FulfillmentController::class, 'proof'])->whereNumber('id');
        Route::post('deliveries/{id}/remit', [FulfillmentController::class, 'remit'])->whereNumber('id');

        // End-of-day cash reconciliation (own drawer).
        Route::get('cash-shift', [CashShiftController::class, 'current'])->name('api.cash-shift.current');
        Route::post('cash-shift/open', [CashShiftController::class, 'open'])->name('api.cash-shift.open');
        Route::post('cash-shift/close', [CashShiftController::class, 'close'])->name('api.cash-shift.close');
        Route::get('cash-shift/overview', [CashShiftController::class, 'overview'])
            ->middleware('owner')
            ->name('api.cash-shift.overview');

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

/*
|--------------------------------------------------------------------------
| Employee work surface (riders and refillers)
|--------------------------------------------------------------------------
|
| A delivery order is staged by the cashier and then driven to completion by
| the rider who handles it, so the fulfilment record captures who was involved.
| Owner-only endpoints stay behind the `owner` middleware; these are the
| operator's own orders.
|
*/
Route::prefix('api/employee')->middleware(['auth', 'throttle:api'])->group(function (): void {
    Route::get('orders', [FulfillmentController::class, 'index']);
    // Reporting progress carries the buyer code, so this endpoint is throttled
    // on its own tighter limiter rather than the general API budget.
    Route::post('orders/{id}', [FulfillmentController::class, 'update'])
        ->middleware('throttle:delivery')
        ->whereNumber('id');
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
});
