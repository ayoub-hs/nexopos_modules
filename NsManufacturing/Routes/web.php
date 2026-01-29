<?php

use Illuminate\Support\Facades\Route;
use Modules\NsManufacturing\Http\Controllers\ManufacturingController;

Route::prefix('dashboard/manufacturing')->middleware([
    'web', 
    'auth',
    \App\Http\Middleware\Authenticate::class,
    \App\Http\Middleware\CheckApplicationHealthMiddleware::class,
    \App\Http\Middleware\HandleCommonRoutesMiddleware::class,
])->group(function () {
    // BOMs
    Route::get('boms', [ManufacturingController::class, 'boms'])->name('ns.dashboard.manufacturing-boms');
    Route::get('boms/create', [ManufacturingController::class, 'createBom'])->name('ns.dashboard.manufacturing-boms.create');
    Route::get('boms/edit/{id}', [ManufacturingController::class, 'editBom'])->name('ns.dashboard.manufacturing-boms.edit');
    Route::get('boms/explode/{id}', [ManufacturingController::class, 'explodeBom'])->name('ns.dashboard.manufacturing-boms.explode');

    // BOM Items
    Route::get('bom-items', [ManufacturingController::class, 'bomItems'])->name('ns.dashboard.manufacturing-bom-items');
    Route::get('bom-items/create', [ManufacturingController::class, 'createBomItem'])->name('ns.dashboard.manufacturing-bom-items.create');
    Route::get('bom-items/edit/{id}', [ManufacturingController::class, 'editBomItem'])->name('ns.dashboard.manufacturing-bom-items.edit');

    // Orders
    Route::get('orders', [ManufacturingController::class, 'orders'])->name('ns.dashboard.manufacturing-orders');
    Route::get('orders/create', [ManufacturingController::class, 'createOrder'])->name('ns.dashboard.manufacturing-orders.create');
    Route::get('orders/edit/{id}', [ManufacturingController::class, 'editOrder'])->name('ns.dashboard.manufacturing-orders.edit');
    
    // Actions
    Route::match(['get', 'post'], 'orders/{id}/start', [ManufacturingController::class, 'startOrder'])->name('ns.dashboard.manufacturing-orders.start');
    Route::match(['get', 'post'], 'orders/{id}/complete', [ManufacturingController::class, 'completeOrder'])->name('ns.dashboard.manufacturing-orders.complete');
    
    // Analytics & Reports
    Route::get('analytics', [ManufacturingController::class, 'reports'])->name('ns.dashboard.manufacturing-analytics');
    Route::get('reports', [ManufacturingController::class, 'reports'])->name('ns.dashboard.manufacturing-reports'); 
    Route::get('reports/summary', [\Modules\NsManufacturing\Http\Controllers\ManufacturingReportController::class, 'getSummary'])->name('ns.manufacturing.reports.summary');
    Route::get('reports/history', [\Modules\NsManufacturing\Http\Controllers\ManufacturingReportController::class, 'getHistory'])->name('ns.manufacturing.reports.history');
    Route::get('reports/consumption', [\Modules\NsManufacturing\Http\Controllers\ManufacturingReportController::class, 'getConsumption'])->name('ns.manufacturing.reports.consumption');
    Route::get('reports/filters', [\Modules\NsManufacturing\Http\Controllers\ManufacturingReportController::class, 'getFilters'])->name('ns.manufacturing.reports.filters');
});
