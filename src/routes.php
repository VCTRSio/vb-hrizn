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

Route::middleware(['web', 'auth', 'tenant'])->prefix('dashboard/hrizn')->name('hrizn.')->group(function () {
    Route::get('/', [OverviewController::class, 'index'])
        ->middleware('can:hrizn.content.read.rooftop')->name('index');

    Route::get('/content', [ContentController::class, 'index'])
        ->middleware('can:hrizn.content.read.rooftop')->name('content.index');
    Route::get('/content/{id}', [ContentController::class, 'show'])
        ->middleware('can:hrizn.content.read.rooftop')->name('content.show');

    Route::get('/ideaclouds', [IdeacloudController::class, 'index'])
        ->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.index');
    Route::post('/ideaclouds', [IdeacloudController::class, 'store'])
        ->middleware('can:hrizn.ideacloud.write.rooftop')->name('ideaclouds.store');
    Route::get('/ideaclouds/{id}', [IdeacloudController::class, 'show'])
        ->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.show');

    // ── Admin CRUD ────────────────────────────────────────────────────────────
    Route::put('/ideaclouds/{id}/admin', [IdeacloudAdminController::class, 'update'])
        ->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('ideaclouds.admin.update');
    Route::delete('/ideaclouds/{id}/admin', [IdeacloudAdminController::class, 'softDelete'])
        ->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('ideaclouds.admin.softDelete');
    Route::post('/ideaclouds/{id}/admin/restore', [IdeacloudAdminController::class, 'restore'])
        ->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('ideaclouds.admin.restore');

    Route::put('/content/{id}/admin', [ContentAdminController::class, 'update'])
        ->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('content.admin.update');
    Route::delete('/content/{id}/admin', [ContentAdminController::class, 'softDelete'])
        ->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('content.admin.softDelete');
    Route::post('/content/{id}/admin/restore', [ContentAdminController::class, 'restore'])
        ->middleware('can:hrizn.admin.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('content.admin.restore');
});

// ── Session-authed /api/v1/hrizn/* resource proxy routes ─────────────────────
Route::middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/hrizn')->name('hrizn.api.')->group(function () {
        Route::get('/ideaclouds', [IdeacloudController::class, 'apiList'])
            ->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.list');
        Route::get('/ideaclouds/{id}', [IdeacloudController::class, 'apiGet'])
            ->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.get');
        Route::post('/ideaclouds/{id}/poll', [IdeacloudController::class, 'poll'])
            ->middleware('can:hrizn.ideacloud.read.rooftop')->name('ideaclouds.poll');

        // ── Content proxy routes (specific segments before the bare {id}/collection) ──
        Route::get('/content', [ContentController::class, 'apiList'])
            ->middleware('can:hrizn.content.read.rooftop')->name('content.list');
        Route::get('/content/{id}/html', [ContentController::class, 'getHtml'])
            ->middleware('can:hrizn.content.read.rooftop')->name('content.html');
        Route::get('/content/{id}/components', [ContentController::class, 'getComponents'])
            ->middleware('can:hrizn.content.read.rooftop')->name('content.components');
        Route::get('/content/{id}', [ContentController::class, 'apiGet'])
            ->middleware('can:hrizn.content.read.rooftop')->name('content.get');
        Route::post('/content/batch', [ContentController::class, 'generateBatch'])
            ->middleware('can:hrizn.content.write.rooftop')->name('content.batch');
        Route::post('/content', [ContentController::class, 'generate'])
            ->middleware('can:hrizn.content.write.rooftop')->name('content.generate');

        // ── Content-intelligence proxy routes (summary before the bare collection) ──
        Route::get('/intelligence/summary', [IntelligenceController::class, 'summary'])
            ->middleware('can:hrizn.intelligence.read.rooftop')->name('intelligence.summary');
        Route::get('/intelligence', [IntelligenceController::class, 'list'])
            ->middleware('can:hrizn.intelligence.read.rooftop')->name('intelligence.list');
        Route::post('/intelligence/act', [IntelligenceController::class, 'actOnRecommendation'])
            ->middleware('can:hrizn.ideacloud.write.rooftop')->name('intelligence.act');
    });

// ── Session-authed /api/v1/hrizn/settings/* integration settings ─────────────
Route::middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/hrizn/settings')->name('hrizn.api.settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'get'])
            ->middleware('can:hrizn.settings.read.rooftop')->name('get');
        Route::get('/site', [SettingsController::class, 'getSiteInfo'])
            ->middleware('can:hrizn.settings.read.rooftop')->name('site');
        Route::post('/api-key', [SettingsController::class, 'setApiKey'])
            ->middleware('can:hrizn.settings.write.rooftop')->name('setApiKey');
        Route::delete('/api-key', [SettingsController::class, 'removeApiKey'])
            ->middleware('can:hrizn.settings.write.rooftop')->name('removeApiKey');
        Route::post('/webhook', [SettingsController::class, 'registerWebhook'])
            ->middleware('can:hrizn.settings.write.rooftop')->name('registerWebhook');
        Route::post('/webhook/test', [SettingsController::class, 'testWebhook'])
            ->middleware('can:hrizn.settings.write.rooftop')->name('testWebhook');
    });

// ── Public inbound Hrizn webhook receiver (NO auth/tenant — Hrizn has no VCTRS session) ──
//
// {token} is the PluginNamespace.id chosen at registration; it maps back to the
// tenant + the per-tenant HMAC secret. The controller verifies X-Webhook-Signature
// before touching any row. Ports core inngest.ts handlers + the Next.js route's
// signature check.
Route::middleware('api')
    ->post('/integrations/hrizn/webhook/{token}', [WebhookController::class, 'receive'])
    ->where('token', '[0-9a-f-]{36}')
    ->name('hrizn.webhook.receive');
