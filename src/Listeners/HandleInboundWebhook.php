<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Listeners;

use App\Events\FeedEventRequested;
use App\Events\InboundWebhookReceived;
use App\Events\TaskRequested;
use App\Support\Integration\IntegrationRunRecorder;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznRelation;

/**
 * Synchronous listener for the core inbound-webhook event. The core
 * InboundWebhookManager has already resolved the tenant from the opaque slug,
 * verified the HMAC over the raw body, enforced freshness, and deduped replays —
 * and fires this event INSIDE the resolved tenant scope. We only act on our own
 * deliveries (routing_key = 'vb-hrizn') and record each dispatch as an
 * IntegrationRun (start → succeed/fail, no cadence: hrizn's inbound traffic is
 * sporadic, so silence-detection is meaningless — the value is the fail/success
 * ledger). NOT ShouldQueue: hrizn has no queue/jobs.
 *
 * Ports the six handlers from the retired WebhookController::dispatch verbatim.
 */
class HandleInboundWebhook
{
    public function handle(InboundWebhookReceived $event): void
    {
        if ($event->routingKey !== HriznRelation::PLUGIN_NAMESPACE) {
            return; // not ours
        }

        $payload = $event->payload;
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;
        if ($type === null) {
            return; // core accepted a JSON body without our envelope shape
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $run = app(IntegrationRunRecorder::class)->start('hrizn_webhook', $type, 'webhook');
        try {
            $rows = $this->dispatch($type, $data);
            $run->succeed(['type' => $type, 'rows' => (int) $rows]);

            if ($rows === 0) {
                $id = $data['article_id'] ?? $data['ideacloud_id'] ?? 'unknown';
                Log::warning("[hrizn] {$type}: 0 rows updated (id={$id})");
            }
        } catch (\Throwable $e) {
            // Core deduped this delivery BEFORE firing, so a re-throw → 500 → sender
            // retry would just be a Duplicate (no event, handler never re-runs). The
            // failed run IS the observable record; swallow so the request still ACKs.
            $run->fail($e);
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function dispatch(string $type, array $data): ?int
    {
        return match ($type) {
            'ideacloud.completed' => $this->onIdeacloudCompleted($data),
            'ideacloud.failed' => $this->setIdeacloudStatus($data, 'failed'),
            'content.progress' => $this->onContentProgress($data),
            'content.completed' => $this->onContentCompleted($data),
            'content.failed' => $this->onContentFailed($data),
            'compliance.completed' => $this->onComplianceCompleted($data),
            default => null, // content_tools.completed + others: acknowledged, no local write
        };
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
