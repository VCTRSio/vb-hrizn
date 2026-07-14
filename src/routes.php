<?php

use Illuminate\Support\Facades\Route;
use Vctrs\Plugins\VbHrizn\Http\Controllers\ContentAdminController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\ContentController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\IdeacloudAdminController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\IdeacloudController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\IntelligenceController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\OverviewController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\SettingsController;
use Vctrs\Plugins\VbHrizn\Http\Controllers\WebhookController;

// ── Session-authed /api/v1/hrizn/* (extracted-plugin browser surface) ─────────
Route::middleware(['web', 'session-api'])->prefix('api/v1/hrizn')->name('hrizn.api.')->group(function () {
    Route::get('/overview', [OverviewController::class, 'overview'])->middleware('can:hrizn.content.read.rooftop')->name('overview');

    Route::get('/ideaclouds', [IdeacloudController::class, 'apiList'])->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.list');
    Route::post('/ideaclouds', [IdeacloudController::class, 'store'])->middleware('can:hrizn.ideacloud.write.rooftop')->name('ideaclouds.store');
    Route::get('/ideaclouds/{id}', [IdeacloudController::class, 'apiGet'])->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.get');
    Route::post('/ideaclouds/{id}/poll', [IdeacloudController::class, 'poll'])->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.poll');
    Route::put('/ideaclouds/{id}/admin', [IdeacloudAdminController::class, 'update'])->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('ideaclouds.admin.update');
    Route::delete('/ideaclouds/{id}/admin', [IdeacloudAdminController::class, 'softDelete'])->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('ideaclouds.admin.softDelete');
    Route::post('/ideaclouds/{id}/admin/restore', [IdeacloudAdminController::class, 'restore'])->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('ideaclouds.admin.restore');

    Route::get('/content', [ContentController::class, 'apiList'])->middleware('can:hrizn.content.read.rooftop')->name('content.list');
    Route::get('/content/{id}/html', [ContentController::class, 'getHtml'])->middleware('can:hrizn.content.read.rooftop')->name('content.html');
    Route::get('/content/{id}/components', [ContentController::class, 'getComponents'])->middleware('can:hrizn.content.read.rooftop')->name('content.components');
    Route::get('/content/{id}', [ContentController::class, 'apiGet'])->middleware('can:hrizn.content.read.rooftop')->name('content.get');
    Route::post('/content/batch', [ContentController::class, 'generateBatch'])->middleware('can:hrizn.content.write.rooftop')->name('content.batch');
    Route::post('/content', [ContentController::class, 'generate'])->middleware('can:hrizn.content.write.rooftop')->name('content.generate');
    Route::put('/content/{id}/admin', [ContentAdminController::class, 'update'])->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('content.admin.update');
    Route::delete('/content/{id}/admin', [ContentAdminController::class, 'softDelete'])->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('content.admin.softDelete');
    Route::post('/content/{id}/admin/restore', [ContentAdminController::class, 'restore'])->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('content.admin.restore');

    Route::get('/intelligence/summary', [IntelligenceController::class, 'summary'])->middleware('can:hrizn.intelligence.read.rooftop')->name('intelligence.summary');
    Route::get('/intelligence', [IntelligenceController::class, 'list'])->middleware('can:hrizn.intelligence.read.rooftop')->name('intelligence.list');
    Route::post('/intelligence/act', [IntelligenceController::class, 'actOnRecommendation'])->middleware('can:hrizn.ideacloud.write.rooftop')->name('intelligence.act');

    Route::get('/settings', [SettingsController::class, 'get'])->middleware('can:hrizn.settings.read.rooftop')->name('settings.get');
    Route::get('/settings/site', [SettingsController::class, 'getSiteInfo'])->middleware('can:hrizn.settings.read.rooftop')->name('settings.site');
    Route::post('/settings/api-key', [SettingsController::class, 'setApiKey'])->middleware('can:hrizn.settings.write.rooftop')->name('settings.setApiKey');
    Route::delete('/settings/api-key', [SettingsController::class, 'removeApiKey'])->middleware('can:hrizn.settings.write.rooftop')->name('settings.removeApiKey');
    Route::post('/settings/webhook', [SettingsController::class, 'registerWebhook'])->middleware('can:hrizn.settings.write.rooftop')->name('settings.registerWebhook');
    Route::post('/settings/webhook/test', [SettingsController::class, 'testWebhook'])->middleware('can:hrizn.settings.write.rooftop')->name('settings.testWebhook');
});

// ── Public inbound Hrizn webhook receiver (NO auth/tenant; HMAC-verified) ──────
Route::middleware('api')
    ->post('/integrations/hrizn/webhook/{token}', [WebhookController::class, 'receive'])
    ->where('token', '[0-9a-f-]{36}')
    ->name('hrizn.webhook.receive');
