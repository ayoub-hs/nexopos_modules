<?php

use Illuminate\Support\Facades\Route;
use Modules\NsManufacturing\Http\Controllers\Api\BomApiController;
use Modules\NsManufacturing\Http\Controllers\Api\WorkOrderApiController;

Route::group(['prefix' => 'api/manufacturing', 'middleware' => ['api','auth:api']], function () {
    Route::get('boms', [BomApiController::class, 'index']);
    Route::get('boms/{id}', [BomApiController::class, 'show']);

    Route::get('work-orders', [WorkOrderApiController::class, 'index']);
    Route::get('work-orders/{id}', [WorkOrderApiController::class, 'show']);
    Route::post('work-orders/{id}/start', [WorkOrderApiController::class, 'start']);
    Route::post('work-orders/{id}/complete', [WorkOrderApiController::class, 'complete']);
});