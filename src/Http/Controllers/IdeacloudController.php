<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\VbHrizn\Http\Requests\StoreIdeacloudRequest;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Services\HriznApiException;
use Vctrs\Plugins\VbHrizn\Support\HriznClientFactory;
use Vctrs\Plugins\VbHrizn\Support\HriznPreconditionException;
use Vctrs\Plugins\VbHrizn\Support\HriznResponse;

class IdeacloudController extends Controller
{
    public function __construct(private readonly HriznClientFactory $clients) {}

    /**
     * GET /api/v1/hrizn/ideaclouds — API-first, local-DB fallback (core ideaclouds.list, router.ts:360-416).
     */
    public function apiList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:researching,complete,failed'],
            'keyword' => ['sometimes', 'string'],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);
        $ctx = app(TenantContext::class);

        try {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
        } catch (HriznPreconditionException) {
            // QOL DIVERGENCE (correctness): the sibling content.list local fallback in core
            // (router.ts:575-583) omits activeOnly, leaking soft-deleted rows (README T2B-17).
            // Core's ideaclouds.list fallback (router.ts:399) does apply activeOnly; we keep it
            // here AND apply the same whereNull(deleted_at) on the content fallback (Task 4), so
            // NO fallback ever leaks soft-deleted rows. Consistent with the PHP soft-delete scopes.
            $rows = HriznIdeacloud::query()
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'hrizn_id', 'keyword', 'status']);

            return ApiResponse::success([
                // QOL DIVERGENCE (id-correlation, README T2B-16): admin mutation routes key on the
                // local UUID; carry localId = the local row id so the fallback shape matches the
                // enriched API path below and admins can correlate a listed item to the row to mutate.
                'items' => $rows->map(fn (HriznIdeacloud $r) => [
                    'id' => $r->hrizn_id, 'keyword' => $r->keyword, 'status' => $r->status,
                    'localId' => $r->id,
                ])->all(),
                'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => $rows->count()],
                'source' => 'local',
            ]);
        }

        return HriznResponse::guard(function () use ($client, $validated, $limit) {
            $res = $client->listIdeaClouds([
                'limit' => $limit,
                'cursor' => $validated['cursor'] ?? null,
                'status' => $validated['status'] ?? null,
                'keyword' => $validated['keyword'] ?? null,
            ]);

            $items = $this->enrichWithLocalId($res['data']);

            return ApiResponse::success(['items' => $items, 'pagination' => $res['pagination'], 'source' => 'api']);
        });
    }

    /**
     * QOL DIVERGENCE (id-correlation, README T2B-16): core's ideaclouds.list returns external Hrizn
     * ids with no local correlation, so an admin viewing the API-backed list cannot map a listed item
     * to the local row the admin mutation routes (which key on local UUIDs) must act on. We additively
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
            : HriznIdeacloud::query()
                ->whereNull('deleted_at')
                ->whereIn('hrizn_id', $externalIds)
                // No UNIQUE on hrizn_id; if two active mirrors ever share one, order
                // ascending so pluck's last-key-wins deterministically keeps the newest.
                ->orderBy('created_at')
                ->pluck('id', 'hrizn_id');

        return array_map(static function (array $item) use ($map) {
            $item['localId'] = is_string($item['id'] ?? null) ? ($map[$item['id']] ?? null) : null;

            return $item;
        }, $items);
    }

    /**
     * GET /api/v1/hrizn/ideaclouds/{id} — local row (status-synced) then API (core ideaclouds.get, router.ts:422-473).
     */
    public function apiGet(string $id): JsonResponse
    {
        $ctx = app(TenantContext::class);

        $local = HriznIdeacloud::query()->where('hrizn_id', $id)->first();

        return HriznResponse::guard(function () use ($id, $ctx, $local) {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
            try {
                $api = $client->getIdeaCloud($id);
            } catch (HriznApiException $e) {
                if ($local !== null) {
                    // Return the local snapshot if the API read fails (core get() catch, router.ts:460-467).
                    return ApiResponse::success([
                        'id' => $local->hrizn_id, 'keyword' => $local->keyword, 'status' => $local->status,
                    ]);
                }
                throw $e;
            }
            if ($local !== null && ($api['status'] ?? null) !== null && $api['status'] !== $local->status) {
                $local->update(['status' => $api['status']]);
            }

            return ApiResponse::success($api);
        });
    }

    /**
     * POST /api/v1/hrizn/ideaclouds — create an ideacloud (core ideaclouds.create). The
     * ESM create form calls this via the axios kit; on client/precondition failure return the
     * ApiResponse error envelope instead of a server-side back() redirect.
     */
    public function store(StoreIdeacloudRequest $request): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($request, $ctx) {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());

            $api = $client->createIdeaCloud($request->keyword);

            $created = DB::transaction(function () use ($request, $api, $ctx) {
                AuditContext::tag('hrizn.ideacloud.create');

                return HriznIdeacloud::create([
                    'keyword' => $request->keyword,
                    'status' => $api['status'] ?? 'researching',
                    'hrizn_id' => $api['id'],
                    'created_by' => $ctx->userId(),
                ]);
            });

            return ApiResponse::success($created);
        });
    }

    /**
     * POST /api/v1/hrizn/ideaclouds/{id}/poll — re-fetch status + sync local (core ideaclouds.poll, router.ts:514-533).
     */
    public function poll(string $id): JsonResponse
    {
        $ctx = app(TenantContext::class);

        return HriznResponse::guard(function () use ($id, $ctx) {
            $client = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId());
            $api = $client->getIdeaCloud($id);
            HriznIdeacloud::query()->where('hrizn_id', $id)
                ->update(['status' => $api['status'] ?? 'researching']);

            return ApiResponse::success($api);
        });
    }
}
