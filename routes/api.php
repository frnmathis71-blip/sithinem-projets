<?php

use App\Http\Controllers\OrderPrintController;
use Illuminate\Support\Facades\Route;

Route::prefix('printing')->middleware('throttle:120,1')->group(function () {
    Route::post('/claim', [OrderPrintController::class, 'claim']);
    Route::post('/jobs/{job}/acknowledge', [OrderPrintController::class, 'acknowledge'])->whereNumber('job');
});
