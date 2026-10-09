<?php

use Illuminate\Support\Facades\Route;

Route::middleware('shopify.embedded')->name('admin.')->group(function () {
    Route::view('/', 'admin.settings')->name('settings');
    Route::view('/terms', 'admin.terms')->name('terms');
    Route::view('/advert-copy', 'admin.advert')->name('advert');
    Route::view('/reporting', 'admin.reporting')->name('reporting');
});
