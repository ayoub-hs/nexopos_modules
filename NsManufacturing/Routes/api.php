<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/ns-manufacturing')->middleware(['api'])->group(function () {
    // Routes will be added here
});
