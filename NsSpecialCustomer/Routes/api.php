<?php

use Illuminate\Support\Facades\Route;
use Modules\NsSpecialCustomer\Http\Controllers\SpecialCustomerController;
use Modules\NsSpecialCustomer\Http\Controllers\CashbackController;

// Main API routes with permission middleware
Route::middleware(['auth:sanctum', 'ns.special-customer.permission:settings'])->prefix('special-customer')->group(function () {
    
    // Config endpoint - requires settings permission
    Route::get('/config', [SpecialCustomerController::class, 'getConfig']);
    
    // Dashboard stats endpoint
    Route::get('/stats', [SpecialCustomerController::class, 'getStats']);
    
    // Customer management endpoints with view permission
    Route::middleware('ns.special-customer.permission:view')->group(function () {
        Route::get('/check/{customerId}', [SpecialCustomerController::class, 'checkCustomerSpecialStatus']);
        
        // CRITICAL: Balance endpoint requires view permission (fixed IDOR vulnerability)
        Route::get('/balance/{customerId}', [SpecialCustomerController::class, 'getCustomerBalance']);
    });
    
    Route::get('/customers', [SpecialCustomerController::class, 'getCustomersList'])
        ->middleware('ns.special-customer.permission:manage');
    
    // Financial operations with rate limiting and permission checks
    Route::middleware(['throttle:10,1', 'ns.special-customer.permission:topup'])->group(function () {
        Route::post('/topup', [SpecialCustomerController::class, 'topupAccount']);
        
        Route::post('/settings', [SpecialCustomerController::class, 'updateSettings'])
            ->middleware('ns.special-customer.permission:settings');
    });
});

// Cashback routes with stricter rate limiting
Route::middleware(['auth:sanctum', 'throttle:20,1', 'ns.special-customer.permission:cashback'])
    ->prefix('special-customer/cashback')
    ->group(function () {
        
    // Read operations
    Route::get('/', [CashbackController::class, 'index']);
    Route::get('/statistics', [CashbackController::class, 'statistics']);
    Route::get('/customer/{customerId}', [CashbackController::class, 'customerSummary']);
    
    // Financial operations with stricter rate limiting (5 per minute)
    Route::middleware(['throttle:5,1'])->group(function () {
        Route::post('/', [CashbackController::class, 'process']);
        Route::delete('/{id}', [CashbackController::class, 'delete']);
    });
});

// CRUD API endpoints (following NexoPOS pattern)
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Special Customers CRUD
    Route::get('/crud/ns.special-customers', function () {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCustomerCrud::class;
        $resource = new $crudClass;
        return $resource->getEntries();
    });
    
    Route::post('/crud/ns.special-customers', function () {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCustomerCrud::class;
        $resource = new $crudClass;
        return $resource->createEntry(request());
    });
    
    Route::get('/crud/ns.special-customers/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCustomerCrud::class;
        $resource = new $crudClass;
        return $resource->getEntry($id);
    });
    
    Route::put('/crud/ns.special-customers/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCustomerCrud::class;
        $resource = new $crudClass;
        return $resource->updateEntry($id, request());
    });
    
    Route::delete('/crud/ns.special-customers/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCustomerCrud::class;
        $resource = new $crudClass;
        return $resource->deleteEntry($id);
    });

    // Cashback CRUD
    Route::get('/crud/ns.special-customer-cashback', function () {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCashbackCrud::class;
        $resource = new $crudClass;
        return $resource->getEntries();
    });
    
    Route::post('/crud/ns.special-customer-cashback', function () {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCashbackCrud::class;
        $resource = new $crudClass;
        return $resource->createEntry(request());
    });
    
    Route::get('/crud/ns.special-customer-cashback/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCashbackCrud::class;
        $resource = new $crudClass;
        return $resource->getEntry($id);
    });
    
    Route::put('/crud/ns.special-customer-cashback/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCashbackCrud::class;
        $resource = new $crudClass;
        return $resource->updateEntry($id, request());
    });
    
    Route::delete('/crud/ns.special-customer-cashback/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\SpecialCashbackCrud::class;
        $resource = new $crudClass;
        return $resource->deleteEntry($id);
    });

    // Top-up CRUD - Read only (create is handled by /api/special-customer/topup)
    Route::get('/crud/ns.special-customer-topup', function () {
        $crudClass = \Modules\NsSpecialCustomer\Crud\CustomerTopupCrud::class;
        $resource = new $crudClass;
        return $resource->getEntries();
    });
    
    Route::get('/crud/ns.special-customer-topup/{id}', function ($id) {
        $crudClass = \Modules\NsSpecialCustomer\Crud\CustomerTopupCrud::class;
        $resource = new $crudClass;
        return $resource->getEntry($id);
    });
});

