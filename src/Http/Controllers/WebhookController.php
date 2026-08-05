<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Events\FeedEventRequested;
use App\Events\TaskRequested;
use App\Http\Controllers\Controller;
use App\Models\PluginNamespace;
use App\Support\SystemContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;
use Vctrs\Plugins\VbHrizn\Support\HriznRelation;
use Vctrs\Plugins\VbHrizn\Support\HriznWebhookSignature;

/**
 * Public inbound Hrizn webhook receiver.
 *
 * Runs OUTSIDE the web/auth/tenant middleware stack (Hrizn has no VCTRS session).
 * The opaque {token} in the URL is the PluginNamespace.id (a uuid) chosen when
 * the webhook was registered; it maps back to (tenant_type, tenant_id) and the
 * per-tenant webhook secret. The HMAC-SHA256 signature (header X-Webhook-Signature)
 * is verified against the raw body before any row is touched; handlers then run
 * under SystemContext::runAsTenant so tenant scopes + audit apply.
 *
 * Ports core inngest.ts (6 handlers) + the Next.js API route's signature check.
 */
class WebhookController extends Controller
{
    public function receive(Request $request, string $token): JsonResponse
    {
        // QOL DIVERGENCE (#6): public webhook route, no tenant context yet — the opaque
        // token IS the capability. Bypass fail-closed RLS for this pre-tenant lookup.
        // Both reads below (the namespace row AND the per-tenant secret) hit FORCE-RLS
        // tenant tables (plugin_namespaces) BEFORE any app.tenant_* GUC is set — the
        // tenant is being resolved FROM this lookup — so each must run under runUnscoped.
        $ns = SystemContext::runUnscoped(
            fn () => PluginNamespace::query()->where('id', $token)->where('plugin_slug', 'vb-hrizn')->first()
        );
        if ($ns === null) {
            return response()->json(['message' => 'Unknown webhook token.'], 404);
        }
        $tenantType = (string) $ns->getAttribute('tenant_type');
        $tenantId = (string) $ns->getAttribute('tenant_id');

        // Same pre-tenant bypass: the secret lives in plugin_namespaces (FORCE RLS) and
        // is read before the GUC is set, so a bare get() is invisible to app_user.
        $secrets = SystemContext::runUnscoped(
            fn () => HriznNamespace::get($tenantType, $tenantId)
        );
        $secret = $secrets['webhookSecret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'Webhook not configured.'], 404);
        }

        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Webhook-Signature', '');
        if (! HriznWebhookSignature::verify($rawBody, $signature, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $envelope = json_decode($rawBody, true);
        if (! is_array($envelope) || ! is_string($envelope['type'] ?? null)) {
            return response()->json(['message' => 'Malformed payload.'], 422);
        }
        $type = $envelope['type'];
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        SystemContext::runAsTenant($tenantType, $tenantId, function () use ($type, $data) {
            $this->dispatch($type, $data);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(string $type, array $data): void
    {
        $rows = match ($type) {
            'ideacloud.completed' => $this->onIdeacloudCompleted($data),
            'ideacloud.failed' => $this->setIdeacloudStatus($data, 'failed'),
            'content.progress' => $this->onContentProgress($data),
            'content.completed' => $this->onContentCompleted($data),
            'content.failed' => $this->onContentFailed($data),
            'compliance.completed' => $this->onComplianceCompleted($data),
            default => null, // content_tools.completed + others: acknowledged, no local write (core has no handler)
        };

        // Mirror core inngest.ts: a verified webhook that matched no local row is a
        // no-op we warn about (each of core's 6 handlers logs `0 rows updated`).
        if ($rows === 0) {
            $id = $data['article_id'] ?? $data['ideacloud_id'] ?? 'unknown';
            Log::warning("[hrizn] {$type}: 0 rows updated (id={$id})");
        }
    }

    /** @param array<string, mixed> $data */
    private function setIdeacloudStatus(array $data, string $status): int
    {
        $hriznId = $data['ideacloud_id'] ?? null;
        if (is_string($hriznId) && $hriznId !== '') {
            return HriznIdeacloud::query()->where('hrizn_id', $hriznId)->update(['status' => $status]);
        }

        return 0;
    }

    /** @param array<string, mixed> $data */
    private function onIdeacloudCompleted(array $data): int
    {
        $hriznId = $data['ideacloud_id'] ?? null;
        if (! is_string($hriznId) || $hriznId === '') {
            return 0;
        }
        $ic = HriznIdeacloud::query()->where('hrizn_id', $hriznId)->first();
        if ($ic === null) {
            return 0;
        }
        $wasComplete = $ic->status === 'complete';
        $ic->update(['status' => 'complete']);

        if (! $wasComplete) {
            try {
                event(new FeedEventRequested(
                    tenantType: (string) $ic->tenant_type, tenantId: (string) $ic->tenant_id,
                    actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                    sourceType: HriznRelation::IDEACLOUD_SOURCE_TYPE, sourceId: (string) $ic->id,
                    pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
                    eventType: HriznRelation::FEED_RESEARCH_READY,
                    summary: "Keyword research ready: {$ic->keyword}",
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return 1;
    }

    /** @param array<string, mixed> $data */
    private function onContentProgress(array $data): int
    {
        $id = $data['article_id'] ?? null;
        if (is_string($id) && $id !== '') {
            return HriznContent::query()->where('hrizn_content_id', $id)->update([
                'status' => 'generating',
                'progress_percent' => (int) ($data['progress_percent'] ?? 0),
                'progress_stage' => $data['stage'] ?? null,
            ]);
        }

        return 0;
    }

    /** @param array<string, mixed> $data */
    private function onContentCompleted(array $data): int
    {
        $id = $data['article_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return 0;
        }
        $content = HriznContent::query()->where('hrizn_content_id', $id)->first();
        if ($content === null) {
            return 0;
        }
        $alreadyComplete = $content->status === 'complete';
        $content->update(['status' => 'complete', 'progress_percent' => 100, 'progress_stage' => 'finalizing']);

        if (! $alreadyComplete) {
            $this->emitContentReady($content);
        }

        return 1;
    }

    private function emitContentReady(HriznContent $content): void
    {
        try {
            $keyword = $content->ideacloud?->keyword ?? 'content';
            $label = HriznRelation::articleLabel((string) $content->article_type);
            $tt = (string) $content->tenant_type;
            $tid = (string) $content->tenant_id;

            event(new FeedEventRequested(
                tenantType: $tt, tenantId: $tid,
                actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                sourceType: HriznRelation::CONTENT_SOURCE_TYPE, sourceId: (string) $content->id,
                pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
                eventType: HriznRelation::FEED_CONTENT_READY,
                summary: "New HRIZN {$label} ready to review: {$keyword}",
                detailPayload: ['article_type' => $content->article_type, 'content_intent' => $content->content_intent],
            ));

            $requester = (string) ($content->created_by ?? TenantContext::SYSTEM_ACTOR);
            event(new TaskRequested(
                pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
                tenantType: $tt, tenantId: $tid,
                requestedBy: $requester,
                title: "Review & publish HRIZN {$label}: {$keyword}",
                description: "A HRIZN {$label} ({$content->content_intent}) finished generating and is ready to review and publish.",
                priority: 'normal',
                assignedTo: $content->created_by !== null ? (string) $content->created_by : null,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param array<string, mixed> $data */
    private function onContentFailed(array $data): int
    {
        $id = $data['article_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return 0;
        }
        $content = HriznContent::query()->where('hrizn_content_id', $id)->first();
        if ($content === null) {
            return 0;
        }
        $wasFailed = $content->status === 'failed';
        $content->update(['status' => 'failed', 'error_message' => $data['error'] ?? null]);

        if (! $wasFailed) {
            try {
                $keyword = $content->ideacloud?->keyword ?? 'content';
                $label = HriznRelation::articleLabel((string) $content->article_type);
                event(new FeedEventRequested(
                    tenantType: (string) $content->tenant_type, tenantId: (string) $content->tenant_id,
                    actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                    sourceType: HriznRelation::CONTENT_SOURCE_TYPE, sourceId: (string) $content->id,
                    pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
                    eventType: HriznRelation::FEED_CONTENT_FAILED,
                    summary: "HRIZN {$label} generation failed: {$keyword}",
                    priority: 'high',
                    detailPayload: ['error' => (string) ($data['error'] ?? '')],
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return 1;
    }

    /** @param array<string, mixed> $data */
    private function onComplianceCompleted(array $data): int
    {
        $id = $data['article_id'] ?? null;
        if (is_string($id) && $id !== '') {
            return HriznContent::query()->where('hrizn_content_id', $id)->update([
                'compliance_status' => $data['overall_status'] ?? null,
                'compliance_score' => isset($data['overall_score']) ? (int) $data['overall_score'] : null,
            ]);
        }

        return 0;
    }
}
