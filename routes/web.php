<?php

use App\Http\Controllers\Operator\AuthController as OperatorAuthController;
use App\Http\Controllers\Operator\DashboardController as OperatorDashboardController;
use App\Http\Controllers\Operator\SettingsController as OperatorSettingsController;
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

// Test-lab iframe shell. Served dynamically so the widget bundle URLs always
// carry the current file mtime as a cache-buster, otherwise Shopify Admin's
// embedded webview will keep showing the previous version of the widget JS/CSS.
//
// We also auto-mirror the extension bundle into public/demo/assets whenever it
// is newer. The storefront widget is deployed from extensions/ to Shopify's
// CDN, but the Test lab iframe loads /demo/assets/rfi-widget.js — if a dev
// edits the extension and forgets to run `npm run demo:assets`, the Test lab
// quietly keeps showing the previous version while the real store shows the
// fix. One source of truth prevents the drift.
Route::get('/admin/lab-frame.html', function () {
    foreach (['rfi-widget.js', 'rfi-widget.css', 'rfi-die3d.js'] as $file) {
        $src = base_path("extensions/roll-for-it/assets/{$file}");
        $dst = public_path("demo/assets/{$file}");
        if (is_file($src) && (! is_file($dst) || filemtime($src) > filemtime($dst))) {
            @copy($src, $dst);
        }
    }

    $widget = public_path('demo/assets/rfi-widget.js');
    $version = is_file($widget) ? (string) filemtime($widget) : (string) time();

    return response()
        ->view('admin.lab-frame', ['assetsVersion' => $version])
        ->header('Cache-Control', 'no-store, private');
})->name('admin.lab-frame');

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

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/mail', [OperatorSettingsController::class, 'mail'])->name('mail');
            Route::post('/mail', [OperatorSettingsController::class, 'updateMail'])->name('mail.update');
            Route::post('/mail/test', [OperatorSettingsController::class, 'testMail'])->name('mail.test');
        });
    });
});
