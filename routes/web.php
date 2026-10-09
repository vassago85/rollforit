<?php

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
});
