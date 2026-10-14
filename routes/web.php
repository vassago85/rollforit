<?php

use App\Http\Controllers\Operator\AuthController as OperatorAuthController;
use App\Http\Controllers\Operator\DashboardController as OperatorDashboardController;
use App\Http\Controllers\Operator\ShopsController as OperatorShopsController;
use App\Shopify\ShopDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    if (ShopDomain::isValid((string) $request->query('shop', ''))) {
        return response()->view('admin.settings');
    }

    return redirect('/demo/');
})->middleware('shopify.embedded')->name('admin.settings');

Route::middleware('shopify.embedded')->name('admin.')->group(function () {
    Route::view('/terms', 'admin.terms')->name('terms');
    Route::view('/advert-copy', 'admin.advert')->name('advert');
    Route::view('/reporting', 'admin.reporting')->name('reporting');
    Route::view('/simulator', 'admin.simulator')->name('simulator');
});

Route::prefix('op')->name('op.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('/login', [OperatorAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [OperatorAuthController::class, 'login'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:web', 'operator'])->group(function () {
        Route::post('/logout', [OperatorAuthController::class, 'logout'])->name('logout');
        Route::get('/', [OperatorDashboardController::class, 'index'])->name('dashboard');
        Route::get('/shops', [OperatorShopsController::class, 'index'])->name('shops.index');
        Route::get('/shops/{shop}', [OperatorShopsController::class, 'show'])->name('shops.show');
    });
});
