<?php

use App\Http\Controllers\Admin\AdvertController;
use App\Http\Controllers\Admin\ReportingController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SimulatorController;
use App\Http\Controllers\Admin\TermsController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('shopify.session')->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/preview', [SettingsController::class, 'preview'])->name('settings.preview');
    Route::post('settings/pos-secret', [SettingsController::class, 'rotatePosSecret'])->name('settings.pos.rotate');
    Route::delete('settings/pos-secret', [SettingsController::class, 'disablePos'])->name('settings.pos.disable');

    Route::get('terms', [TermsController::class, 'show'])->name('terms.show');
    Route::put('terms', [TermsController::class, 'update'])->name('terms.update');
    Route::post('terms/publish', [TermsController::class, 'publish'])->name('terms.publish');
    Route::post('terms/reviewed', [TermsController::class, 'reviewed'])->name('terms.reviewed');
    Route::get('terms/pages', [TermsController::class, 'pages'])->name('terms.pages');
    Route::get('terms/versions/{version}', [TermsController::class, 'version'])->whereNumber('version')->name('terms.version');

    Route::get('adverts', [AdvertController::class, 'show'])->name('adverts.show');
    Route::post('adverts/acknowledge', [AdvertController::class, 'acknowledge'])->name('adverts.acknowledge');

    Route::get('reporting', [ReportingController::class, 'index'])->name('reporting.index');
    Route::get('reporting/metrics', [ReportingController::class, 'metrics'])->name('reporting.metrics');
    Route::post('reporting/recompute', [ReportingController::class, 'recompute'])->name('reporting.recompute');
    Route::get('reporting/export', [ReportingController::class, 'export'])->name('reporting.export');

    Route::get('simulator', [SimulatorController::class, 'show'])->name('simulator.show');
    Route::post('simulator/roll', [SimulatorController::class, 'roll'])->name('simulator.roll');
});

Route::prefix('proxy')->name('proxy.')->group(function () {
    Route::get('status', [ProxyController::class, 'status'])->middleware('shopify.proxy')->name('status');
    Route::post('roll', [ProxyController::class, 'roll'])->middleware('shopify.proxy:once')->name('roll');
});

Route::prefix('pos')->middleware('shopify.pos')->name('pos.')->group(function () {
    Route::post('status', [PosController::class, 'status'])->name('status');
    Route::post('roll', [PosController::class, 'roll'])->name('roll');
});

Route::post('webhooks', [WebhookController::class, 'handle'])->middleware('shopify.webhook')->name('webhooks');
