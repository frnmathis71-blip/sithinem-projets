<?php

use App\Http\Controllers\Desktop\CardController;
use App\Http\Controllers\Desktop\CartController;
use App\Http\Controllers\Desktop\OrderController as DesktopOrderController;
use App\Http\Controllers\Desktop\ScheduleController;
use App\Http\Controllers\Desktop\SessionController;
use App\Http\Controllers\OrderPrintController;
use App\Http\Middleware\AuthenticateDesktop;
use Illuminate\Support\Facades\Route;

Route::prefix('printing')->middleware('throttle:120,1')->group(function () {
    Route::post('/claim', [OrderPrintController::class, 'claim']);
    Route::post('/jobs/{job}/acknowledge', [OrderPrintController::class, 'acknowledge'])->whereNumber('job');
});

Route::prefix('desktop')->name('desktop.')->group(function () {
    Route::post('/session', [SessionController::class, 'login'])->middleware('throttle:desktop-login')->name('login');
    Route::middleware([AuthenticateDesktop::class, 'throttle:desktop'])->group(function () {
        Route::get('/session', [SessionController::class, 'me'])->name('me');
        Route::delete('/session', [SessionController::class, 'logout'])->name('logout');

        Route::get('/orders', [DesktopOrderController::class, 'index'])->name('orders');
        Route::post('/orders', [DesktopOrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/print-today', [DesktopOrderController::class, 'printToday'])->name('orders.print');
        Route::post('/printing/{job}/retry', [DesktopOrderController::class, 'retryPrint'])->whereNumber('job')->name('printing.retry');
        Route::get('/orders/{order}', [DesktopOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/ticket', [DesktopOrderController::class, 'ticket'])->name('orders.ticket');
        Route::patch('/orders/{order}/status', [DesktopOrderController::class, 'status'])->name('orders.status');
        Route::post('/orders/{order}/paid', [DesktopOrderController::class, 'paid'])->name('orders.paid');

        Route::get('/ordering/catalog', [CartController::class, 'catalog'])->name('ordering.catalog');
        Route::post('/ordering/quote', [CartController::class, 'quote'])->name('ordering.quote');
        Route::post('/ordering/compose', [CartController::class, 'compose'])->name('ordering.compose');
        Route::post('/ordering/suggestion', [CartController::class, 'acceptSuggestion'])->name('ordering.suggestion');
        Route::get('/ordering/slots', [CartController::class, 'slots'])->name('ordering.slots');

        Route::get('/card', [CardController::class, 'index'])->name('card');
        Route::post('/categories', [CardController::class, 'saveCategory'])->name('categories.save');
        Route::delete('/categories/{category}', [CardController::class, 'deleteCategory'])->name('categories.delete');
        Route::get('/card/{kind}/{item?}', [CardController::class, 'item'])->whereIn('kind', ['product', 'offer'])->whereNumber('item')->name('card.item');
        Route::post('/card/{kind}/{item?}', [CardController::class, 'save'])->whereIn('kind', ['product', 'offer'])->whereNumber('item')->name('card.save');
        Route::delete('/card/{kind}/{item}', [CardController::class, 'archive'])->whereIn('kind', ['product', 'menu', 'offer'])->whereNumber('item')->name('card.archive');
        Route::get('/formulas', [CardController::class, 'formulas'])->name('formulas');
        Route::post('/formulas/{rule?}', [CardController::class, 'saveFormula'])->whereNumber('rule')->name('formulas.save');

        Route::get('/slots', [ScheduleController::class, 'slots'])->name('slots');
        Route::patch('/slots/{slot}', [ScheduleController::class, 'saveSlot'])->name('slots.update');
        Route::patch('/capacity', [ScheduleController::class, 'saveCapacity'])->name('capacity');
        Route::get('/calendar', [ScheduleController::class, 'calendar'])->name('calendar');
        Route::post('/hours', [ScheduleController::class, 'saveHours'])->name('hours');
        Route::post('/exceptions', [ScheduleController::class, 'saveException'])->name('exceptions');
        Route::delete('/exceptions/{exception}', [ScheduleController::class, 'deleteException'])->whereNumber('exception')->name('exceptions.delete');
        Route::get('/statistics', [ScheduleController::class, 'statistics'])->name('statistics');
    });
});
