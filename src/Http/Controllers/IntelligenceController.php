<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznClientFactory;
use Vctrs\Plugins\VbHrizn\Support\HriznResponse;

class IntelligenceController extends Controller
{
    public function __construct(private readonly HriznClientFactory $clients) {}

    /** GET /api/v1/hrizn/intelligence (core intelligence.list). */
    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($ctx, $validated) {
            $res = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())
                ->listIntelligenceRecommendations([
                    'limit' => (int) ($validated['limit'] ?? 25),
                    'cursor' => $validated['cursor'] ?? null,
                ]);

            return ApiResponse::success(['items' => $res['data'], 'pagination' => $res['pagination']]);
        });
    }

    /** GET /api/v1/hrizn/intelligence/summary (core intelligence.summary). */
    public function summary(): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(fn () => ApiResponse::success(
            $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->getIntelligenceSummary()
        ));
    }

    /**
     * POST /api/v1/hrizn/intelligence/act — create an ideacloud from a recommended
     * keyword (core intelligence.actOnRecommendation). Core does NOT audit this path.
     */
    public function actOnRecommendation(Request $request): JsonResponse
    {
        $validated = $request->validate(['keyword' => ['required', 'string', 'min:3']]);
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($ctx, $validated) {
            $api = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())
                ->createIdeaCloud($validated['keyword']);
            HriznIdeacloud::create([
                'keyword' => $validated['keyword'],
                'status' => $api['status'] ?? 'researching',
                'hrizn_id' => $api['id'],
                'created_by' => $ctx->userId(),
            ]);

            return ApiResponse::success($api);
        });
    }
}
