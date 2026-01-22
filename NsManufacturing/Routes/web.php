<?php

use Illuminate\Support\Facades\Route;
use Modules\NsManufacturing\Http\Controllers\BomController;
use Modules\NsManufacturing\Http\Controllers\WorkOrderController;

Route::group(['prefix' => 'manufacturing', 'middleware' => ['web','auth']], function () {
    Route::get('boms', [BomController::class, 'index'])->name('nsmanufacturing.boms.index');
    Route::get('boms/create', [BomController::class, 'create'])->name('nsmanufacturing.boms.create');
    Route::post('boms', [BomController::class, 'store'])->name('nsmanufacturing.boms.store');
    Route::get('boms/{id}/edit', [BomController::class, 'edit'])->name('nsmanufacturing.boms.edit');
    Route::put('boms/{id}', [BomController::class, 'update'])->name('nsmanufacturing.boms.update');
    Route::get('boms/{id}', [BomController::class, 'show'])->name('nsmanufacturing.boms.show');
    Route::delete('boms/{id}', [BomController::class, 'destroy'])->name('nsmanufacturing.boms.destroy');

    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('nsmanufacturing.work_orders.index');
    Route::get('work-orders/create', [WorkOrderController::class, 'create'])->name('nsmanufacturing.work_orders.create');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->name('nsmanufacturing.work_orders.store');
    Route::get('work-orders/{id}', [WorkOrderController::class, 'show'])->name('nsmanufacturing.work_orders.show');
    Route::post('work-orders/{id}/start', [WorkOrderController::class, 'start'])->name('nsmanufacturing.work_orders.start');
    Route::post('work-orders/{id}/complete', [WorkOrderController::class, 'complete'])->name('nsmanufacturing.work_orders.complete');
});