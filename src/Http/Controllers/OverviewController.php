<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

class OverviewController extends Controller
{
    /** GET /api/v1/hrizn/overview — dashboard aggregates (was the Hrizn/Index server-rendered page). */
    public function overview(): JsonResponse
    {
        $stats = [
            'totalContent' => HriznContent::query()->whereNull('deleted_at')->count(),
            'contentThisMonth' => HriznContent::query()
                ->whereNull('deleted_at')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'ideacloudCount' => HriznIdeacloud::query()->whereNull('deleted_at')->count(),
            'inProgressCount' => HriznContent::query()
                ->whereNull('deleted_at')
                ->whereIn('status', ['pending', 'generating', 'awaiting_input'])
                ->count(),
        ];

        $recentContent = HriznContent::query()
            ->with('ideacloud:id,keyword')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return ApiResponse::success([
            'stats' => $stats,
            'recentContent' => $recentContent,
        ]);
    }
}
