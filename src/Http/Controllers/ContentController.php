<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Plugins\PluginSettings;
use App\Support\ApiResponse;
use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\InventoryHub\InventoryDirectory;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznClientFactory;
use Vctrs\Plugins\VbHrizn\Support\HriznPreconditionException;
use Vctrs\Plugins\VbHrizn\Support\HriznRelation;
use Vctrs\Plugins\VbHrizn\Support\HriznResponse;

class ContentController extends Controller
{
    /** All 7 article types from the Hrizn API (core router.ts articleTypeEnum). */
    private const ARTICLE_TYPES = ['basic', 'qa', 'expert', 'modellanding', 'comparison', 'salesevent', 'emailtemplate'];

    /** Content intents (core router.ts contentIntentEnum). */
    private const CONTENT_INTENTS = ['fixed_ops', 'variable', 'general'];

    /** Article types that describe a specific vehicle and can be VIN-linked. */
    private const VEHICLE_ARTICLE_TYPES = ['modellanding', 'comparison'];

    public function __construct(private readonly HriznClientFactory $clients) {}

    /** GET /api/v1/hrizn/content — API-first, local fallback (core content.list). */
    public function apiList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
            'article_type' => ['sometimes', 'in:'.implode(',', self::ARTICLE_TYPES)],
            'content_intent' => ['sometimes', 'in:'.implode(',', self::CONTENT_INTENTS)],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);
        $ctx = app(TenantContext::class);

        try {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
        } catch (HriznPreconditionException) {
            // QOL DIVERGENCE (correctness, README T2B-17): core's content.list local
            // fallback (router.ts:575-583) skips activeOnly, leaking soft-deleted rows.
            // Here we apply whereNull(deleted_at) on the fallback.
            $rows = HriznContent::query()
                ->whereNull('deleted_at')->orderByDesc('created_at')->limit($limit)->get();

            return ApiResponse::success([
                // QOL DIVERGENCE (id-correlation, README T2B-16): admin mutation routes key on the
                // local UUID; carry localId = the local row id so the fallback shape matches the
                // enriched API path below and admins can correlate a listed item to the row to mutate.
                'items' => $rows->map(fn (HriznContent $r) => [
                    'id' => $r->hrizn_content_id ?? $r->id,
                    'status' => $r->status, 'article_type' => $r->article_type,
                    'localId' => $r->id,
                ])->all(),
                'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => $rows->count()],
                'source' => 'local',
            ]);
        }

        return HriznResponse::guard(function () use ($client, $validated, $limit) {
            $res = $client->listContent([
                'limit' => $limit,
                'cursor' => $validated['cursor'] ?? null,
                'article_type' => $validated['article_type'] ?? null,
                'content_intent' => $validated['content_intent'] ?? null,
            ]);

            $items = $this->enrichWithLocalId($res['data']);

            return ApiResponse::success(['items' => $items, 'pagination' => $res['pagination'], 'source' => 'api']);
        });
    }

    /**
     * QOL DIVERGENCE (id-correlation, README T2B-16): core's content.list returns external Hrizn ids
     * with no local correlation, so an admin viewing the API-backed list cannot map a listed item to
     * the local row the admin mutation routes (which key on local UUIDs) must act on. We additively
     * enrich each item with 'localId' = the (non-soft-deleted) local mirror row's uuid, or null when no
     * local mirror exists. The mirror query is tenant-scoped via the model's BelongsToTenant global scope.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithLocalId(array $items): array
    {
        $externalIds = array_values(array_filter(
            array_map(static fn (array $i) => is_string($i['id'] ?? null) ? $i['id'] : null, $items),
            static fn (?string $id) => $id !== null,
        ));

        $map = $externalIds === []
            ? []
            : HriznContent::query()
                ->whereNull('deleted_at')
                ->whereIn('hrizn_content_id', $externalIds)
                // No UNIQUE on hrizn_content_id; if two active mirrors ever share one, order
                // ascending so pluck's last-key-wins deterministically keeps the newest.
                ->orderBy('created_at')
                ->pluck('id', 'hrizn_content_id');

        return array_map(static function (array $item) use ($map) {
            $item['localId'] = is_string($item['id'] ?? null) ? ($map[$item['id']] ?? null) : null;

            return $item;
        }, $items);
    }

    /** GET /api/v1/hrizn/content/{id} — direct API fetch (core content.get). */
    public function apiGet(string $id): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($ctx, $id) {
            $payload = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->getContent($id);
            $payload['linkedVehicles'] = $this->linkedVehiclesFor($ctx, $id);

            return ApiResponse::success($payload);
        });
    }

    /** POST /api/v1/hrizn/content — generate one item (core content.generate). */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ideacloudId' => ['required', 'string'],
            'articleType' => ['sometimes', 'in:'.implode(',', self::ARTICLE_TYPES)],
            'contentIntent' => ['sometimes', 'in:'.implode(',', self::CONTENT_INTENTS)],
            'autoCompliance' => ['sometimes', 'boolean'],
            'autoContentTools' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'string'],
            'contentLength' => ['sometimes', 'integer', 'min:200', 'max:5000'],
            'vehicleVin' => ['sometimes', 'string', 'max:32'],
        ]);
        $ctx = app(TenantContext::class);
        $settings = app(PluginSettings::class)->resolve('vb-hrizn');

        // Cascade defaults from plugin settings (core router.ts:639-656).
        $articleType = $validated['articleType'] ?? ($settings['defaultArticleType'] ?? 'basic');
        if (! in_array($articleType, self::ARTICLE_TYPES, true)) {
            return ApiResponse::error("Invalid articleType: {$articleType}", 400);
        }
        $contentIntent = $validated['contentIntent'] ?? ($settings['defaultContentIntent'] ?? 'general');
        if (! in_array($contentIntent, self::CONTENT_INTENTS, true)) {
            $contentIntent = 'general';
        }
        $autoCompliance = (bool) ($validated['autoCompliance'] ?? false);
        $autoContentTools = (bool) ($validated['autoContentTools'] ?? false);

        return HriznResponse::guard(function () use ($ctx, $validated, $articleType, $contentIntent, $autoCompliance, $autoContentTools) {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
            $api = $client->generateContent([
                'ideacloud_id' => $validated['ideacloudId'],
                'article_type' => $articleType,
                'content_intent' => $contentIntent,
                'auto_compliance' => $autoCompliance,
                'auto_content_tools' => $autoContentTools,
                'title' => $validated['title'] ?? null,
                'content_length' => $validated['contentLength'] ?? null,
            ]);

            $content = null;
            DB::transaction(function () use ($ctx, $validated, $api, $articleType, $contentIntent, $autoCompliance, $autoContentTools, &$content) {
                AuditContext::tag('hrizn.content.generate');
                // ideacloud_id is a local uuid PK (schema: FK to hrizn_ideaclouds.id); resolve the
                // local row from the Hrizn ideacloud id. If no local row exists yet, fall back to
                // the incoming id (only valid when it is itself a uuid — matches core's insert of
                // input.ideacloudId into the uuid column, router.ts:695).
                $ideacloud = HriznIdeacloud::query()
                    ->where('hrizn_id', $validated['ideacloudId'])->first();
                $content = HriznContent::create([
                    'ideacloud_id' => $ideacloud !== null ? $ideacloud->id : $validated['ideacloudId'],
                    'hrizn_content_id' => $api['id'] ?? null,
                    'article_type' => $articleType,
                    'content_intent' => $contentIntent,
                    'auto_compliance' => $autoCompliance,
                    'auto_content_tools' => $autoContentTools,
                    'status' => 'generating',
                    'created_by' => $ctx->userId(),
                ]);
            });

            $this->maybeLinkVehicle($ctx, $content, $articleType, $validated['vehicleVin'] ?? null);

            return ApiResponse::success($api);
        });
    }

    /**
     * Link a freshly-generated vehicle-specific article to an inventory vehicle by VIN.
     *
     * Degrades gracefully: no-ops when inventory-hub is not installed (the seam is
     * unbound), when the article type is not vehicle-specific, or when the VIN does not
     * resolve — the content row is already created either way. Any failure is reported,
     * never surfaced, so a linking hiccup can't fail the generate call.
     */
    private function maybeLinkVehicle(TenantContext $ctx, ?HriznContent $content, string $articleType, ?string $vin): void
    {
        if ($content === null || $vin === null || trim($vin) === '' || ! in_array($articleType, self::VEHICLE_ARTICLE_TYPES, true)) {
            return;
        }
        if (! app()->bound(InventoryDirectory::class)) {
            return;
        }
        try {
            $tt = $ctx->activeTenantType();
            $tid = $ctx->activeTenantId();
            $vehicle = app(InventoryDirectory::class)->lookupByVin($tt, $tid, $vin);
            if ($vehicle === null) {
                return; // unknown VIN — skip link, content already created
            }
            app(EntityReferenceService::class)->link(
                $tt, $tid,
                HriznRelation::CONTENT_SOURCE_TYPE, (string) $content->id,
                HriznRelation::VEHICLE_TARGET_TYPE, strtoupper($vin),
                HriznRelation::COVERS, $ctx->userId(),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Resolve the inventory vehicles a content article covers (by the local row's
     * entity_references), enriched with the Directory's picker fields. Degrades to []
     * when the article has no local mirror or inventory-hub is not installed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkedVehiclesFor(TenantContext $ctx, string $externalId): array
    {
        try {
            $local = HriznContent::query()->where('hrizn_content_id', $externalId)->first();
            if ($local === null || ! app()->bound(InventoryDirectory::class)) {
                return [];
            }
            $tt = $ctx->activeTenantType();
            $tid = $ctx->activeTenantId();
            $refs = app(EntityReferenceService::class)->forSource($tt, $tid, HriznRelation::CONTENT_SOURCE_TYPE, (string) $local->id);
            $dir = app(InventoryDirectory::class);
            $out = [];
            foreach ($refs as $ref) {
                if (($ref['target_type'] ?? null) !== HriznRelation::VEHICLE_TARGET_TYPE) {
                    continue;
                }
                $v = $dir->lookupByVin($tt, $tid, (string) $ref['target_id']);
                $out[] = $v ?? ['vin' => $ref['target_id']];
            }

            return $out;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** POST /api/v1/hrizn/content/batch — up to 10 items (core content.generateBatch). */
    public function generateBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.ideacloudId' => ['required', 'string'],
            'items.*.articleType' => ['required', 'in:'.implode(',', self::ARTICLE_TYPES)],
            'items.*.contentIntent' => ['sometimes', 'in:'.implode(',', self::CONTENT_INTENTS)],
            'items.*.autoCompliance' => ['sometimes', 'boolean'],
            'items.*.autoContentTools' => ['sometimes', 'boolean'],
        ]);
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($ctx, $validated) {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
            $payload = array_map(fn (array $i) => [
                'ideacloud_id' => $i['ideacloudId'],
                'article_type' => $i['articleType'],
                'content_intent' => $i['contentIntent'] ?? null,
                'auto_compliance' => (bool) ($i['autoCompliance'] ?? false),
                'auto_content_tools' => (bool) ($i['autoContentTools'] ?? false),
            ], $validated['items']);

            $results = $client->generateContentBatch($payload);

            if ($results !== []) {
                DB::transaction(function () use ($ctx, $validated, $results) {
                    foreach ($validated['items'] as $i => $item) {
                        $ideacloud = HriznIdeacloud::query()
                            ->where('hrizn_id', $item['ideacloudId'])->first();
                        HriznContent::create([
                            'ideacloud_id' => $ideacloud !== null ? $ideacloud->id : $item['ideacloudId'],
                            'hrizn_content_id' => $results[$i]['id'] ?? null,
                            'article_type' => $item['articleType'],
                            'content_intent' => $item['contentIntent'] ?? null,
                            'auto_compliance' => (bool) ($item['autoCompliance'] ?? false),
                            'auto_content_tools' => (bool) ($item['autoContentTools'] ?? false),
                            'status' => 'generating',
                            'created_by' => $ctx->userId(),
                        ]);
                    }
                });
            }

            return ApiResponse::success($results);
        });
    }

    /** GET /api/v1/hrizn/content/{id}/html (core content.getHtml). */
    public function getHtml(string $id): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(fn () => ApiResponse::success([
            'html' => $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->getContentHtml($id),
        ]));
    }

    /** GET /api/v1/hrizn/content/{id}/components (core content.getComponents). */
    public function getComponents(string $id): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(fn () => ApiResponse::success(
            $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->getContentComponents($id)
        ));
    }
}
