<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| PHASE 1 — health probe.
| PHASE 2 — auth (login/logout/me) + RBAC (roles/permissions) + route protection.
| Later phases add module routes (see docs/api-spec.md).
*/

Route::get('/ping', HealthController::class)->name('api.ping');

// Named 'login' route so the auth middleware has a redirect target for
// non-JSON guests; this API only ever answers 401.
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::get('/user', fn (Request $request) => $request->user())->name('api.user');

    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:role.view')->name('api.roles.index');
    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:permission.view')->name('api.permissions.index');

    /*
    |--------------------------------------------------------------------------
    | PHASE 3 — Master Data + Inventory + Calculation Engine
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:master.category.view')->group(function () {
        Route::get('/categories', [MasterDataController::class, 'categories'])->name('api.categories');
        Route::get('/categories/tree', [MasterDataController::class, 'categoryTree'])->name('api.categories.tree');
    });
    Route::get('/units', [MasterDataController::class, 'units'])
        ->middleware('permission:master.unit.view')->name('api.units');
    Route::get('/warehouses', [MasterDataController::class, 'warehouses'])
        ->middleware('permission:master.warehouse.view')->name('api.warehouses');
    Route::get('/warehouse-locations', [MasterDataController::class, 'warehouseLocations'])
        ->middleware('permission:master.location.view')->name('api.warehouse-locations');

    Route::middleware('permission:item.view')->group(function () {
        Route::get('/items', [ItemController::class, 'index'])->name('api.items.index');
        Route::get('/items/{item}', [ItemController::class, 'show'])->name('api.items.show');
    });
    Route::post('/items', [ItemController::class, 'store'])
        ->middleware('permission:item.create')->name('api.items.store');
    Route::match(['put', 'patch'], '/items/{item}', [ItemController::class, 'update'])
        ->middleware('permission:item.update')->name('api.items.update');
    Route::delete('/items/{item}', [ItemController::class, 'destroy'])
        ->middleware('permission:item.delete')->name('api.items.destroy');

    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('api.inventory.index');
        Route::get('/inventory/analysis', [InventoryController::class, 'analysis'])
            ->middleware('permission:inventory.view_analysis')->name('api.inventory.analysis');
        Route::get('/inventory/{item}', [InventoryController::class, 'show'])->name('api.inventory.show');
    });
    Route::post('/inventory/analysis/recompute', [InventoryController::class, 'recompute'])
        ->middleware('permission:inventory.view_analysis')->name('api.inventory.recompute');
    Route::post('/inventory/projected', [InventoryController::class, 'projected'])
        ->middleware('permission:request.create|ppb.create|inventory.view')->name('api.inventory.projected');
});
