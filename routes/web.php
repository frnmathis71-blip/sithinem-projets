<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DynamicMenuController;
use App\Http\Controllers\OrderPrintController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StaffOrderController;
use App\Http\Controllers\StoreController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

Route::view('/confidentialite', 'restaurant.legal.privacy')->name('privacy');
Route::view('/cgu', 'restaurant.legal.terms')->name('terms');
Route::view('/cookies', 'restaurant.legal.cookies')->name('cookies');
Route::post('/cookies', [SiteController::class, 'consent'])->middleware('throttle:20,1')->name('cookies.save');
Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SiteController::class, 'robots']);
Route::get('/contact', [SiteController::class, 'contact'])->name('contact');

Route::get('/', [StoreController::class, 'catalog'])->name('home');
Route::get('/menu', [StoreController::class, 'catalog'])->name('menu');
Route::get('/offres', [StoreController::class, 'catalog'])->name('offers');
Route::get('/menus', [DynamicMenuController::class, 'index'])->name('menus.index');
Route::get('/menus/{rule}', [DynamicMenuController::class, 'show'])->whereNumber('rule')->name('menus.show');
Route::get('/panier', [StoreController::class, 'cart'])->name('cart');
Route::post('/panier', [StoreController::class, 'updateCart'])->block()->middleware('throttle:60,1')->name('cart.update');
Route::get('/composer-mon-menu', [DynamicMenuController::class, 'builder'])->name('menus.builder');
Route::post('/composer-mon-menu', [DynamicMenuController::class, 'add'])->block()->middleware('throttle:60,1')->name('menus.add');
Route::post('/panier/proposition', [DynamicMenuController::class, 'proposal'])->block()->middleware('throttle:60,1')->name('menus.proposal');
Route::get('/creneaux', [StoreController::class, 'slots'])->middleware('throttle:120,1')->name('slots');
Route::middleware('auth')->group(function () {
    Route::get('/verifier-telephone', [PhoneVerificationController::class, 'show'])->name('phone.show');
    Route::post('/verifier-telephone/envoi', [PhoneVerificationController::class, 'send'])->block()->middleware('throttle:20,1')->name('phone.send');
    Route::post('/verifier-telephone', [PhoneVerificationController::class, 'check'])->block()->middleware('throttle:20,1')->name('phone.check');
    Route::get('/commande', [StoreController::class, 'cart'])->name('checkout');
    Route::post('/commandes', [StoreController::class, 'place'])->block()->middleware('throttle:20,1')->name('orders.store');
    Route::get('/mes-commandes', [StoreController::class, 'orders'])->name('orders.index');
    Route::get('/mes-commandes/{order}', [StoreController::class, 'show'])->name('orders.show');
    Route::get('/mon-compte', [StoreController::class, 'account'])->name('account');
    Route::patch('/mon-compte', [StoreController::class, 'saveAccount'])->name('account.update');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', RequireAdmin::class])->group(function () {
    Route::get('/menus-personnalisables', [DynamicMenuController::class, 'admin'])->name('menu-rules');
    Route::get('/menus-personnalisables/nouveau', [DynamicMenuController::class, 'editRule'])->name('menu-rules.create');
    Route::get('/menus-personnalisables/{rule}', [DynamicMenuController::class, 'editRule'])->whereNumber('rule')->name('menu-rules.edit');
    Route::post('/menus-personnalisables/{rule?}', [DynamicMenuController::class, 'saveRule'])->whereNumber('rule')->name('menu-rules.save');

    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/statistiques', [AdminController::class, 'dashboard'])->name('statistics');
    Route::get('/commandes', [AdminController::class, 'orders'])->name('orders');
    Route::get('/commandes/flux', [AdminController::class, 'orderFeed'])->name('feed');
    Route::get('/commandes/nouvelle', [StaffOrderController::class, 'create'])->name('orders.create');
    Route::post('/commandes', [StaffOrderController::class, 'store'])->block()->middleware('throttle:30,1')->name('orders.store');
    Route::get('/commandes/tickets', [OrderPrintController::class, 'today'])->name('orders.tickets');
    Route::post('/commandes/imprimer', [OrderPrintController::class, 'enqueue'])->name('orders.print');
    Route::post('/impression/{job}/relancer', [OrderPrintController::class, 'retry'])->whereNumber('job')->name('printing.retry');
    Route::get('/commandes/{order}/ticket', [OrderPrintController::class, 'show'])->name('orders.ticket');
    Route::get('/commandes/{order}', [StoreController::class, 'show'])->name('orders.show');
    Route::patch('/commandes/{order}/statut', [AdminController::class, 'status'])->name('orders.status');
    Route::post('/commandes/{order}/encaissement', [AdminController::class, 'paid'])->name('orders.paid');
    Route::get('/creneaux', [AdminController::class, 'slots'])->name('slots');
    Route::patch('/creneaux/{slot}', [AdminController::class, 'saveSlot'])->name('slots.update');
    Route::patch('/parametres', [AdminController::class, 'settings'])->name('settings');
    Route::get('/calendrier', [AdminController::class, 'calendar'])->name('calendar');
    Route::get('/contact', [AdminController::class, 'contact'])->name('contact');
    Route::patch('/contact', [AdminController::class, 'saveContact'])->name('contact.update');
    Route::post('/horaires', [AdminController::class, 'saveHours'])->name('hours');
    Route::post('/exceptions', [AdminController::class, 'saveException'])->name('exceptions');
    Route::delete('/exceptions/{exception}', [AdminController::class, 'deleteException'])->name('exceptions.delete');
    Route::get('/categories', [AdminController::class, 'categories'])->name('categories');
    Route::get('/carte', [AdminController::class, 'card'])->name('card');
    Route::post('/categories', [AdminController::class, 'saveCategory'])->name('categories.save');
    Route::delete('/categories/{category}', [AdminController::class, 'deleteCategory'])->name('categories.delete');
    Route::get('/catalogue/{kind}', [AdminController::class, 'catalog'])->whereIn('kind', ['product', 'menu', 'offer'])->name('catalog');
    Route::get('/catalogue/{kind}/nouveau', [AdminController::class, 'editItem'])->whereIn('kind', ['product', 'menu', 'offer'])->name('catalog.create');
    Route::get('/catalogue/{kind}/{item}', [AdminController::class, 'editItem'])->whereIn('kind', ['product', 'menu', 'offer'])->name('catalog.edit');
    Route::post('/catalogue/{kind}/{item?}', [AdminController::class, 'saveItem'])->whereIn('kind', ['product', 'menu', 'offer'])->name('catalog.save');
    Route::delete('/catalogue/{kind}/{item}', [AdminController::class, 'archiveItem'])->whereIn('kind', ['product', 'menu', 'offer'])->name('catalog.archive');
    Route::post('/push', [PushController::class, 'store'])->name('push.store');
    Route::delete('/push', [PushController::class, 'destroy'])->name('push.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [StoreController::class, 'dashboard'])->name('dashboard');
});

require __DIR__.'/settings.php';
