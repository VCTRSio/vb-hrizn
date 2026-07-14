<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Plugins\PluginSettings;
use App\Support\ApiResponse;
use App\Support\OutboundUrl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\VbHrizn\Services\HriznClient;
use Vctrs\Plugins\VbHrizn\Support\HriznClientFactory;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;
use Vctrs\Plugins\VbHrizn\Support\HriznResponse;

class SettingsController extends Controller
{
    public function __construct(private readonly HriznClientFactory $clients) {}

    /** GET /api/v1/hrizn/settings — masked key + cached site info (core settings.get). */
    public function get(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $ns = HriznNamespace::get($ctx->activeTenantType(), $ctx->activeTenantId());

        $apiKey = is_string($ns['apiKey'] ?? null) ? $ns['apiKey'] : null;

        return ApiResponse::success([
            'hasApiKey' => $apiKey !== null && $apiKey !== '',
            'apiKeyPreview' => $apiKey ? 'hzk_••••••••'.substr($apiKey, -4) : null,
            'webhookId' => $ns['webhookId'] ?? null,
            'webhookRegisteredAt' => $ns['webhookRegisteredAt'] ?? null,
            'siteId' => $ns['siteId'] ?? null,
            'siteName' => $ns['siteName'] ?? null,
            'siteDomain' => $ns['siteDomain'] ?? null,
        ]);
    }

    /** POST /api/v1/hrizn/settings/api-key — validate against /site, then persist (core setApiKey). */
    public function setApiKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apiKey' => ['required', 'string', 'starts_with:hzk_'],
        ]);
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($validated, $ctx) {
            $client = new HriznClient($validated['apiKey']);
            $site = $client->getSite();

            DB::transaction(function () use ($validated, $site, $ctx) {
                HriznNamespace::patch($ctx->activeTenantType(), $ctx->activeTenantId(), [
                    'apiKey' => $validated['apiKey'],
                    'siteId' => $site['id'] ?? null,
                    'siteName' => $site['name'] ?? null,
                    'siteDomain' => $site['domain'] ?? null,
                ]);
                AuditContext::tag('hrizn.settings.setApiKey');
            });

            return ApiResponse::success([
                'success' => true,
                'siteName' => $site['name'] ?? null,
                'siteDomain' => $site['domain'] ?? null,
                'city' => $site['city'] ?? null,
                'state' => $site['state'] ?? null,
            ]);
        });
    }

    /** DELETE /api/v1/hrizn/settings/api-key — wipe the secret blob (core removeApiKey). */
    public function removeApiKey(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        DB::transaction(function () use ($ctx) {
            HriznNamespace::clear($ctx->activeTenantType(), $ctx->activeTenantId());
            AuditContext::tag('hrizn.settings.removeApiKey');
        });

        return ApiResponse::success(['success' => true]);
    }

    /** GET /api/v1/hrizn/settings/site — live /site fetch (core getSiteInfo). */
    public function getSiteInfo(): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(fn () => ApiResponse::success(
            $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->getSite()
        ));
    }

    /** POST /api/v1/hrizn/settings/webhook — register the inbound webhook (core registerWebhook). */
    public function registerWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate(['callbackUrl' => ['sometimes', 'string', 'url']]);
        $ctx = app(TenantContext::class);

        // Prefer the manifest-cascaded webhookCallbackUrl over the per-call input (core:289-295).
        $settings = app(PluginSettings::class)->resolve('vb-hrizn');
        $settingUrl = is_string($settings['webhookCallbackUrl'] ?? null) && $settings['webhookCallbackUrl'] !== ''
            ? $settings['webhookCallbackUrl'] : null;
        $callbackUrl = $settingUrl ?? ($validated['callbackUrl'] ?? null);
        if ($callbackUrl === null) {
            return ApiResponse::error('No webhook callback URL configured. Set the webhookCallbackUrl setting or pass callbackUrl.', 412);
        }

        return HriznResponse::guard(function () use ($ctx, $callbackUrl) {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
            $ns = HriznNamespace::get($ctx->activeTenantType(), $ctx->activeTenantId());

            // Replace an existing webhook (best-effort, core:305-307).
            if (is_string($ns['webhookId'] ?? null) && $ns['webhookId'] !== '') {
                try {
                    $client->deleteWebhook($ns['webhookId']);
                } catch (\Throwable) {
                    // ignore — registering a fresh one supersedes it
                }
            }

            $webhook = $client->createWebhook([
                'url' => OutboundUrl::assertSafe($callbackUrl),
                'events' => [
                    'ideacloud.completed', 'ideacloud.failed', 'content.progress',
                    'content.completed', 'content.failed', 'compliance.completed', 'content_tools.completed',
                ],
            ]);

            HriznNamespace::patch($ctx->activeTenantType(), $ctx->activeTenantId(), [
                'webhookId' => $webhook['id'] ?? null,
                'webhookSecret' => $webhook['secret'] ?? null,
                'webhookRegisteredAt' => now()->toIso8601String(),
            ]);

            return ApiResponse::success(['success' => true, 'webhookId' => $webhook['id'] ?? null]);
        });
    }

    /** POST /api/v1/hrizn/settings/webhook/test — trigger a test delivery (core testWebhook). */
    public function testWebhook(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $ns = HriznNamespace::get($ctx->activeTenantType(), $ctx->activeTenantId());
        if (! is_string($ns['webhookId'] ?? null) || $ns['webhookId'] === '') {
            return ApiResponse::error('No webhook registered.', 412);
        }

        return HriznResponse::guard(function () use ($ctx, $ns) {
            $result = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->testWebhook($ns['webhookId']);

            return ApiResponse::success([
                'http_status' => $result['http_status'] ?? null,
                'success' => $result['success'] ?? false,
            ]);
        });
    }
}
