<?php

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

class OverviewController extends Controller
{
    public function index(): Response
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

        return Inertia::render('Hrizn/Index', [
            'stats' => $stats,
            'recentContent' => $recentContent,
        ]);
    }
}
