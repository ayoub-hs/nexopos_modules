<?php

use Illuminate\Support\Facades\Route;
use Modules\MobileApi\Http\Controllers\MobileSyncController;
use Modules\MobileApi\Http\Controllers\MobileCategoryController;
use Modules\MobileApi\Http\Controllers\MobileProductController;
use Modules\MobileApi\Http\Controllers\MobileOrdersController;
use Modules\MobileApi\Http\Controllers\MobileRegisterConfigController;

Route::prefix('api/mobile')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::get('sync/bootstrap', [MobileSyncController::class, 'bootstrap']);
        Route::get('sync/delta', [MobileSyncController::class, 'delta']);
        Route::get('sync/status', [MobileSyncController::class, 'status']);

        Route::get('categories/{id}/products', [MobileCategoryController::class, 'products'])
            ->where('id', '[0-9]+');

        Route::post('products/search', [MobileProductController::class, 'search']);
        Route::get('products/{id}', [MobileProductController::class, 'show']);
        Route::get('products/barcode/{barcode}', [MobileProductController::class, 'searchByBarcode']);

        Route::get('orders', [MobileOrdersController::class, 'index']);
        Route::get('orders/{order}', [MobileOrdersController::class, 'show']);
        Route::get('orders/sync', [MobileOrdersController::class, 'sync']);
        Route::post('orders/batch', [MobileOrdersController::class, 'batch']);

        Route::get('register/config', [MobileRegisterConfigController::class, 'show']);
});
