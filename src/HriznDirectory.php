<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn;

use App\Support\SystemContext;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

/**
 * PII-free outbound content-health seam. Other plugins/pages consume this to show
 * per-rooftop marketing-content health without touching HRIZN's own screens.
 * Reads only HRIZN's two tenant tables, explicitly tenant-filtered under RLS.
 */
class HriznDirectory
{
    /** @return array<string, int|null> */
    public function contentHealth(string $tenantType, string $tenantId): array
    {
        return SystemContext::runAsTenant($tenantType, $tenantId, function () use ($tenantType, $tenantId): array {
            $content = fn () => HriznContent::withoutTenantScope()
                ->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)
                ->whereNull('deleted_at');

            $lastPublish = (clone $content())->where('status', 'complete')->max('updated_at');
            $days = $lastPublish !== null
                ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($lastPublish)->startOfDay())
                : null;

            return [
                'publishedLast90' => (clone $content())->where('status', 'complete')
                    ->where('updated_at', '>=', now()->subDays(90))->count(),
                'daysSinceLastPublish' => $days,
                'pendingContent' => (clone $content())->whereIn('status', ['pending', 'generating'])->count(),
                'fixedOpsCount' => (clone $content())->where('status', 'complete')->where('content_intent', 'fixed_ops')->count(),
                'variableCount' => (clone $content())->where('status', 'complete')->where('content_intent', 'variable')->count(),
                'complianceFlagged' => (clone $content())->whereIn('compliance_status', ['flagged', 'fail', 'pending'])->count(),
                'ideacloudsActive' => HriznIdeacloud::withoutTenantScope()
                    ->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')->count(),
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function contentFor(string $tenantType, string $tenantId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        return SystemContext::runAsTenant($tenantType, $tenantId, fn (): array => HriznContent::withoutTenantScope()
            ->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'article_type', 'content_intent', 'status', 'hrizn_content_id', 'created_at'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'article_type' => $r->article_type,
                'content_intent' => $r->content_intent,
                'status' => $r->status,
                'hrizn_content_id' => $r->hrizn_content_id,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ])->all());
    }
}
