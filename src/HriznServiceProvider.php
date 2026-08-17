<?php

namespace Vctrs\Plugins\VbHrizn;

use App\Audit\AuditableRegistry;
use App\Events\InboundWebhookReceived;
use App\Plugins\Contracts\PluginModule;
use App\Plugins\PluginManifest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Vctrs\Plugins\VbHrizn\Listeners\HandleInboundWebhook;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznClientFactory;

class HriznServiceProvider implements PluginModule
{
    public function __construct(private readonly PluginManifest $manifest, private readonly string $dir) {}

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function register(): void
    {
        Route::group([], $this->dir.'/src/routes.php');

        app()->singleton(HriznClientFactory::class);
        app()->singleton(HriznDirectory::class);

        AuditableRegistry::register(HriznIdeacloud::class);
        AuditableRegistry::register(HriznContent::class);

        Event::listen(InboundWebhookReceived::class, [HandleInboundWebhook::class, 'handle']);
    }

    public function navItems(): array
    {
        return $this->manifest->nav;
    }

    /** Friendly labels for the 7 article types (core widgets.ts ARTICLE_TYPE_LABELS). */
    private const ARTICLE_TYPE_LABELS = [
        'basic' => 'Basic', 'qa' => 'Q&A', 'expert' => 'Expert', 'modellanding' => 'Model Landing',
        'comparison' => 'Comparison', 'salesevent' => 'Sales Event', 'emailtemplate' => 'Email Template',
    ];

    public function widgets(): array
    {
        return [
            // Total IdeaClouds + 30-day delta (core widgets.ts totalIdeaclouds).
            'hrizn.totalIdeaclouds' => [
                'hrizn.content.read.rooftop',
                fn () => [
                    'type' => 'metric',
                    'payload' => [
                        'label' => 'Total IdeaClouds',
                        'value' => HriznIdeacloud::query()->whereNull('deleted_at')->count(),
                        'delta' => HriznIdeacloud::query()->whereNull('deleted_at')
                            ->where('created_at', '>=', now()->subDays(30))->count(),
                        'deltaLabel' => 'last 30d',
                    ],
                ],
            ],
            // 5 most recent content rows (core widgets.ts latestContent).
            'hrizn.latestContent' => [
                'hrizn.content.read.rooftop',
                fn () => [
                    'type' => 'list',
                    'payload' => [
                        'label' => 'Latest Hrizn Content',
                        'rows' => HriznContent::query()->whereNull('deleted_at')
                            ->orderByDesc('created_at')->limit(5)->get()
                            ->map(fn (HriznContent $r) => [
                                'label' => $r->hrizn_content_id ?? $r->id,
                                'sublabel' => $r->article_type,
                                'href' => '/dashboard/hrizn/content/'.$r->id,
                            ])->all(),
                    ],
                ],
            ],
            // Donut of content grouped by article_type (core widgets.ts contentByType).
            'hrizn.contentByType' => [
                'hrizn.content.read.rooftop',
                fn () => [
                    'type' => 'chart',
                    'payload' => [
                        'label' => 'Content by Type',
                        'data' => HriznContent::query()->whereNull('deleted_at')
                            ->selectRaw('article_type, count(*) as value')->groupBy('article_type')
                            ->toBase()->get()
                            ->map(fn (object $r) => [
                                'label' => self::ARTICLE_TYPE_LABELS[$r->article_type]
                                    ?? ucfirst((string) $r->article_type),
                                'value' => (int) $r->value,
                            ])->all(),
                    ],
                ],
            ],
            // 5 most recent IdeaClouds (core widgets.ts recentIdeaclouds).
            'hrizn.recentIdeaclouds' => [
                'hrizn.content.read.rooftop',
                fn () => [
                    'type' => 'list',
                    'payload' => [
                        'label' => 'Recent IdeaClouds',
                        'rows' => HriznIdeacloud::query()->whereNull('deleted_at')
                            ->orderByDesc('created_at')->limit(5)->get()
                            ->map(fn (HriznIdeacloud $r) => [
                                'label' => $r->keyword !== '' ? $r->keyword : $r->hrizn_id,
                                'sublabel' => 'IdeaCloud',
                                'href' => '/dashboard/hrizn/ideaclouds/'.$r->id,
                            ])->all(),
                    ],
                ],
            ],
        ];
    }

    public function permissions(): array
    {
        return [];
    }
}
