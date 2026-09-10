<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\MaterialRequestController;
use App\Http\Controllers\Api\NpbgController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PpbController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReceivingController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockOpnameController;
use App\Http\Controllers\Api\Tracking;
use App\Http\Controllers\Api\VendorController;
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
    ->middleware('throttle:20,1')
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
        ->middleware('permission:master.unit.view|request.create|ppb.create')->name('api.units');
    Route::get('/warehouses', [MasterDataController::class, 'warehouses'])
        ->middleware('permission:master.warehouse.view')->name('api.warehouses');
    Route::get('/warehouse-locations', [MasterDataController::class, 'warehouseLocations'])
        ->middleware('permission:master.location.view')->name('api.warehouse-locations');

    Route::get('/items/lookup', [ItemController::class, 'lookup'])
        ->middleware('permission:item.lookup|item.view')->name('api.items.lookup');
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

    /*
    |--------------------------------------------------------------------------
    | PHASE 4 — Material Request
    |--------------------------------------------------------------------------
    */
    Route::get('/requests', [MaterialRequestController::class, 'index'])
        ->middleware('permission:request.view|request.view_own')->name('api.requests.index');
    Route::get('/requests/{materialRequest}', [MaterialRequestController::class, 'show'])
        ->middleware('permission:request.view|request.view_own')->name('api.requests.show');
    Route::post('/requests', [MaterialRequestController::class, 'store'])
        ->middleware('permission:request.create')->name('api.requests.store');
    Route::match(['put', 'patch'], '/requests/{materialRequest}', [MaterialRequestController::class, 'update'])
        ->middleware('permission:request.update_own')->name('api.requests.update');
    Route::post('/requests/{materialRequest}/submit', [MaterialRequestController::class, 'submit'])
        ->middleware('permission:request.update_own')->name('api.requests.submit');
    Route::post('/requests/{materialRequest}/review', [MaterialRequestController::class, 'review'])
        ->middleware('permission:request.review')->name('api.requests.review');
    Route::match(['put', 'patch'], '/requests/{materialRequest}/refs', [MaterialRequestController::class, 'setRefs'])
        ->middleware('permission:request.review')->name('api.requests.refs');
    Route::post('/requests/{materialRequest}/items/{item}/physical-check', [MaterialRequestController::class, 'physicalCheck'])
        ->middleware('permission:request.physical_check')->name('api.requests.physical-check');
    Route::post('/requests/{materialRequest}/reserve', [MaterialRequestController::class, 'reserve'])
        ->middleware('permission:request.reserve')->name('api.requests.reserve');
    Route::post('/requests/{materialRequest}/need-purchase', [MaterialRequestController::class, 'needPurchase'])
        ->middleware('permission:request.set_need_purchase')->name('api.requests.need-purchase');
    Route::post('/requests/{materialRequest}/cancel', [MaterialRequestController::class, 'cancel'])
        ->middleware('permission:request.cancel_own|request.cancel_any')->name('api.requests.cancel');

    /*
    |--------------------------------------------------------------------------
    | PHASE 5 — NPBG + Pickup
    |--------------------------------------------------------------------------
    */
    Route::get('/npbg', [NpbgController::class, 'index'])
        ->middleware('permission:npbg.view|npbg.view_own')->name('api.npbg.index');
    Route::get('/npbg/{npbg}', [NpbgController::class, 'show'])
        ->middleware('permission:npbg.view|npbg.view_own')->name('api.npbg.show');
    Route::post('/npbg/from-request', [NpbgController::class, 'storeFromRequest'])
        ->middleware('permission:npbg.create')->name('api.npbg.from-request');
    Route::post('/npbg', [NpbgController::class, 'storeManual'])
        ->middleware('permission:npbg.create')->name('api.npbg.store');
    Route::post('/npbg/{npbg}/prepare', [NpbgController::class, 'prepare'])
        ->middleware('permission:npbg.prepare')->name('api.npbg.prepare');
    Route::post('/npbg/{npbg}/ready', [NpbgController::class, 'ready'])
        ->middleware('permission:npbg.ready')->name('api.npbg.ready');
    Route::post('/npbg/{npbg}/pickup', [NpbgController::class, 'pickup'])
        ->middleware('permission:npbg.pickup')->name('api.npbg.pickup');
    Route::post('/npbg/{npbg}/cancel', [NpbgController::class, 'cancel'])
        ->middleware('permission:npbg.cancel')->name('api.npbg.cancel');

    /*
    |--------------------------------------------------------------------------
    | PHASE 6 — Stock Opname
    |--------------------------------------------------------------------------
    */
    Route::get('/stock-opnames', [StockOpnameController::class, 'index'])
        ->middleware('permission:opname.view')->name('api.opnames.index');
    Route::get('/stock-opnames/{stockOpname}', [StockOpnameController::class, 'show'])
        ->middleware('permission:opname.view')->name('api.opnames.show');
    Route::post('/stock-opnames', [StockOpnameController::class, 'store'])
        ->middleware('permission:opname.schedule')->name('api.opnames.store');
    Route::post('/stock-opnames/{stockOpname}/start', [StockOpnameController::class, 'start'])
        ->middleware('permission:opname.count')->name('api.opnames.start');
    Route::put('/stock-opnames/{stockOpname}/items/{item}', [StockOpnameController::class, 'count'])
        ->middleware('permission:opname.count')->name('api.opnames.count');
    Route::post('/stock-opnames/{stockOpname}/submit', [StockOpnameController::class, 'submit'])
        ->middleware('permission:opname.submit')->name('api.opnames.submit');
    Route::post('/stock-opnames/{stockOpname}/review', [StockOpnameController::class, 'review'])
        ->middleware('permission:opname.approve')->name('api.opnames.review');

    /*
    |--------------------------------------------------------------------------
    | PHASE 7 — PPB + Purchasing (PPB -> PO -> Receiving -> STOCK_IN)
    |--------------------------------------------------------------------------
    */
    Route::get('/vendors', [VendorController::class, 'index'])
        ->middleware('permission:master.vendor.view')->name('api.vendors.index');
    Route::post('/vendors', [VendorController::class, 'store'])
        ->middleware('permission:master.vendor.manage')->name('api.vendors.store');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])
        ->middleware('permission:master.vendor.manage')->name('api.vendors.update');

    Route::get('/ppb', [PpbController::class, 'index'])
        ->middleware('permission:ppb.view|ppb.view_own')->name('api.ppb.index');
    Route::get('/ppb/{ppb}', [PpbController::class, 'show'])
        ->middleware('permission:ppb.view|ppb.view_own')->name('api.ppb.show');
    Route::post('/ppb/from-request', [PpbController::class, 'storeFromRequest'])
        ->middleware('permission:ppb.create|request.set_need_purchase')->name('api.ppb.from-request');
    Route::post('/ppb', [PpbController::class, 'store'])
        ->middleware('permission:ppb.create')->name('api.ppb.store');
    Route::post('/ppb/{ppb}/submit', [PpbController::class, 'submit'])
        ->middleware('permission:ppb.submit|ppb.create')->name('api.ppb.submit');
    Route::post('/ppb/{ppb}/review', [PpbController::class, 'review'])
        ->middleware('permission:ppb.review')->name('api.ppb.review');
    Route::post('/ppb/{ppb}/approve', [PpbController::class, 'approve'])
        ->middleware('permission:ppb.approve')->name('api.ppb.approve');
    Route::post('/ppb/{ppb}/reject', [PpbController::class, 'reject'])
        ->middleware('permission:ppb.reject')->name('api.ppb.reject');
    Route::post('/ppb/{ppb}/amend', [PpbController::class, 'amend'])
        ->middleware('permission:ppb.amend|ppb.close')->name('api.ppb.amend');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->middleware('permission:po.view')->name('api.po.index');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->middleware('permission:po.view')->name('api.po.show');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('permission:po.create')->name('api.po.store');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
        ->middleware('permission:po.approve')->name('api.po.approve');
    Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])
        ->middleware('permission:po.send')->name('api.po.send');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('permission:po.cancel')->name('api.po.cancel');

    Route::get('/receivings', [ReceivingController::class, 'index'])
        ->middleware('permission:receiving.view')->name('api.receivings.index');
    Route::get('/receivings/{receiving}', [ReceivingController::class, 'show'])
        ->middleware('permission:receiving.view')->name('api.receivings.show');
    Route::post('/receivings', [ReceivingController::class, 'store'])
        ->middleware('permission:receiving.create')->name('api.receivings.store');
    Route::post('/receivings/{receiving}/confirm', [ReceivingController::class, 'confirm'])
        ->middleware('permission:receiving.confirm')->name('api.receivings.confirm');
    Route::post('/receivings/{receiving}/reject', [ReceivingController::class, 'reject'])
        ->middleware('permission:receiving.reject')->name('api.receivings.reject');

    // PHASE 8 — Tracking masters + modules.
    Route::get('/customers', [MasterDataController::class, 'customers'])
        ->middleware('permission:master.customer.view')->name('api.customers');
    Route::get('/projects', [MasterDataController::class, 'projects'])
        ->middleware('permission:master.project.view')->name('api.projects');
    Route::get('/workshops', [MasterDataController::class, 'workshops'])
        ->middleware('permission:master.workshop.view')->name('api.workshops');
    Route::get('/assets', [MasterDataController::class, 'assets'])
        ->middleware('permission:maintenance.view|tyre.view|item.view')->name('api.assets');
    Route::get('/serial-units', [MasterDataController::class, 'serialUnits'])
        ->middleware('permission:stpp.view|tyre.view|manufacturing.view')->name('api.serial-units');

    Route::get('/lend', [Tracking\LendController::class, 'index'])->middleware('permission:lend.view')->name('api.lend.index');
    Route::get('/lend/{lend}', [Tracking\LendController::class, 'show'])->middleware('permission:lend.view')->name('api.lend.show');
    Route::post('/lend', [Tracking\LendController::class, 'store'])->middleware('permission:lend.create')->name('api.lend.store');
    Route::post('/lend/{lend}/return', [Tracking\LendController::class, 'return'])->middleware('permission:lend.return')->name('api.lend.return');

    Route::get('/borrow', [Tracking\BorrowController::class, 'index'])->middleware('permission:borrow.view')->name('api.borrow.index');
    Route::get('/borrow/{borrow}', [Tracking\BorrowController::class, 'show'])->middleware('permission:borrow.view')->name('api.borrow.show');
    Route::post('/borrow', [Tracking\BorrowController::class, 'store'])->middleware('permission:borrow.create')->name('api.borrow.store');
    Route::post('/borrow/{borrow}/return', [Tracking\BorrowController::class, 'return'])->middleware('permission:borrow.return')->name('api.borrow.return');

    Route::get('/stpp', [Tracking\StppController::class, 'index'])->middleware('permission:stpp.view')->name('api.stpp.index');
    Route::get('/stpp/{stpp}', [Tracking\StppController::class, 'show'])->middleware('permission:stpp.view')->name('api.stpp.show');
    Route::post('/stpp', [Tracking\StppController::class, 'store'])->middleware('permission:stpp.create')->name('api.stpp.store');
    Route::post('/stpp/{stpp}/withdraw', [Tracking\StppController::class, 'withdraw'])->middleware('permission:stpp.return')->name('api.stpp.withdraw');
    Route::post('/stpp/{stpp}/reissue', [Tracking\StppController::class, 'reissue'])->middleware('permission:stpp.create')->name('api.stpp.reissue');

    Route::get('/tyre-changes', [Tracking\TyreChangeController::class, 'index'])->middleware('permission:tyre.view')->name('api.tyre.index');
    Route::get('/tyre-changes/{tyreChange}', [Tracking\TyreChangeController::class, 'show'])->middleware('permission:tyre.view')->name('api.tyre.show');
    Route::post('/tyre-changes', [Tracking\TyreChangeController::class, 'store'])->middleware('permission:tyre.create')->name('api.tyre.store');
    Route::post('/tyre-changes/{tyreChange}/close', [Tracking\TyreChangeController::class, 'close'])->middleware('permission:tyre.close')->name('api.tyre.close');

    Route::get('/maintenance-orders', [Tracking\MaintenanceController::class, 'index'])->middleware('permission:maintenance.view')->name('api.maintenance.index');
    Route::get('/maintenance-orders/{maintenanceOrder}', [Tracking\MaintenanceController::class, 'show'])->middleware('permission:maintenance.view')->name('api.maintenance.show');
    Route::post('/maintenance-orders', [Tracking\MaintenanceController::class, 'store'])->middleware('permission:maintenance.create')->name('api.maintenance.store');
    Route::post('/maintenance-orders/{maintenanceOrder}/subs', [Tracking\MaintenanceController::class, 'addSub'])->middleware('permission:maintenance.update')->name('api.maintenance.subs');
    Route::post('/maintenance-subs/{sub}/complete', [Tracking\MaintenanceController::class, 'completeSub'])->middleware('permission:maintenance.complete')->name('api.maintenance.subs.complete');

    Route::get('/manufacturing-orders', [Tracking\ManufacturingController::class, 'index'])->middleware('permission:manufacturing.view')->name('api.manufacturing.index');
    Route::get('/manufacturing-orders/{manufacturingOrder}', [Tracking\ManufacturingController::class, 'show'])->middleware('permission:manufacturing.view')->name('api.manufacturing.show');
    Route::post('/manufacturing-orders', [Tracking\ManufacturingController::class, 'store'])->middleware('permission:manufacturing.create')->name('api.manufacturing.store');
    Route::post('/manufacturing-orders/{manufacturingOrder}/subs', [Tracking\ManufacturingController::class, 'addSub'])->middleware('permission:manufacturing.update')->name('api.manufacturing.subs');
    Route::post('/manufacturing-subs/{sub}/complete', [Tracking\ManufacturingController::class, 'completeSub'])->middleware('permission:manufacturing.complete')->name('api.manufacturing.subs.complete');

    Route::get('/used-returns', [Tracking\UsedReturnController::class, 'index'])->middleware('permission:used_return.view')->name('api.used-returns.index');
    Route::get('/used-returns/{usedReturn}', [Tracking\UsedReturnController::class, 'show'])->middleware('permission:used_return.view')->name('api.used-returns.show');
    Route::post('/used-returns', [Tracking\UsedReturnController::class, 'store'])->middleware('permission:used_return.create')->name('api.used-returns.store');
    Route::post('/used-returns/{usedReturn}/close', [Tracking\UsedReturnController::class, 'close'])->middleware('permission:used_return.close')->name('api.used-returns.close');

    // PHASE 9 — Dashboard (ringkasan per-peran).
    Route::get('/dashboard', DashboardController::class)->name('api.dashboard');

    // PHASE 10 — Laporan & export (xlsx / csv / pdf-print).
    Route::get('/export/{dataset}', ExportController::class)->name('api.export');
});
