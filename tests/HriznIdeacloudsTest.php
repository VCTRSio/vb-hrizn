<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so /api/v1/hrizn/ideaclouds is live. Every
// endpoint now returns the {traceId,data,status} envelope; the list/get payloads
// the core test asserted move under `data`.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

function seedIdeacloud(array $overrides = []): HriznIdeacloud
{
    return HriznIdeacloud::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'keyword' => 'brakes', 'status' => 'researching',
        'hrizn_id' => (string) Str::uuid(), 'created_by' => (string) Str::uuid(),
    ], $overrides));
}

it('ideaclouds.list proxies the API when a key is configured (source=api)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake([
        'api.app.hrizn.io/v1/public/ideaclouds?*' => Http::response([
            'data' => [['id' => 'ic1', 'keyword' => 'suv deals', 'status' => 'complete']],
            'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
        ]),
    ]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds')
        ->assertOk()->assertJson(['data' => ['source' => 'api', 'items' => [['id' => 'ic1']]]]);
});

it('ideaclouds.list enriches API items with the local mirror uuid (localId, T2B-16)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // One external item has a local mirror (hrizn_id=ic_mapped); the other has none.
    $mirror = seedIdeacloud(['hrizn_id' => 'ic_mapped', 'keyword' => 'brakes']);
    Http::fake([
        'api.app.hrizn.io/v1/public/ideaclouds?*' => Http::response([
            'data' => [
                ['id' => 'ic_mapped', 'keyword' => 'brakes', 'status' => 'complete'],
                ['id' => 'ic_orphan', 'keyword' => 'suv deals', 'status' => 'researching'],
            ],
            'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 2],
        ]),
    ]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds')->assertOk();
    $items = collect($res->json('data.items'))->keyBy('id');
    expect($items['ic_mapped']['localId'])->toBe($mirror->id);
    expect($items['ic_orphan']['localId'])->toBeNull();
});

it('ideaclouds.list localId does not leak another tenant mirror uuid (T2B-16 tenant isolation)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // A row with the SAME external id but in a DIFFERENT tenant must not be used.
    $other = seedIdeacloud([
        'tenant_id' => '22222222-2222-2222-2222-222222222222',
        'hrizn_id' => 'ic_shared',
    ]);
    Http::fake([
        'api.app.hrizn.io/v1/public/ideaclouds?*' => Http::response([
            'data' => [['id' => 'ic_shared', 'keyword' => 'brakes', 'status' => 'complete']],
            'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
        ]),
    ]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds')->assertOk();
    $item = collect($res->json('data.items'))->firstWhere('id', 'ic_shared');
    expect($item['localId'])->toBeNull();
    expect($item['localId'])->not->toBe($other->id);
});

it('ideaclouds.list localId prefers the same-tenant mirror over a foreign one sharing the external id (T2B-16)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // Same external id in BOTH the bound tenant and a foreign tenant: the bound row must win.
    $mine = seedIdeacloud(['hrizn_id' => 'ic_shared']);
    $foreign = seedIdeacloud([
        'tenant_id' => '22222222-2222-2222-2222-222222222222',
        'hrizn_id' => 'ic_shared',
    ]);
    Http::fake([
        'api.app.hrizn.io/v1/public/ideaclouds?*' => Http::response([
            'data' => [['id' => 'ic_shared', 'keyword' => 'brakes', 'status' => 'complete']],
            'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
        ]),
    ]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds')->assertOk();
    $item = collect($res->json('data.items'))->firstWhere('id', 'ic_shared');
    expect($item['localId'])->toBe($mine->id);
    expect($item['localId'])->not->toBe($foreign->id);
});

it('ideaclouds.list local fallback carries localId = the local row id (T2B-16)', function () {
    Http::fake();
    $row = seedIdeacloud(['hrizn_id' => 'ic_local']);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds')->assertOk();
    $res->assertJson(['data' => ['source' => 'local']]);
    $item = collect($res->json('data.items'))->firstWhere('id', 'ic_local');
    expect($item['localId'])->toBe($row->id);
    Http::assertNothingSent();
});

it('ideaclouds.list falls back to the local DB when no key, hiding soft-deleted rows (T2B-17 fix)', function () {
    Http::fake();
    seedIdeacloud(['keyword' => 'visible']);
    $deleted = seedIdeacloud(['keyword' => 'hidden']);
    $deleted->update(['deleted_at' => now()]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds')->assertOk();
    $res->assertJson(['data' => ['source' => 'local']]);
    $keywords = collect($res->json('data.items'))->pluck('keyword');
    expect($keywords)->toContain('visible')->not->toContain('hidden');
    Http::assertNothingSent();
});

it('ideaclouds.get syncs the local status from the API', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    $ic = seedIdeacloud(['hrizn_id' => 'ic_sync', 'status' => 'researching']);
    Http::fake(['api.app.hrizn.io/v1/public/ideaclouds/ic_sync' => Http::response([
        'data' => ['id' => 'ic_sync', 'keyword' => 'brakes', 'status' => 'complete'],
    ])]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/ideaclouds/ic_sync')
        ->assertOk()->assertJson(['data' => ['status' => 'complete']]);
    expect($ic->refresh()->status)->toBe('complete');
});

it('ideaclouds.poll re-fetches and updates local status', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    $ic = seedIdeacloud(['hrizn_id' => 'ic_poll', 'status' => 'researching']);
    Http::fake(['api.app.hrizn.io/v1/public/ideaclouds/ic_poll' => Http::response([
        'data' => ['id' => 'ic_poll', 'keyword' => 'brakes', 'status' => 'failed'],
    ])]);

    $this->actingAs(pluginTestUser())->postJson('/api/v1/hrizn/ideaclouds/ic_poll/poll')
        ->assertOk()->assertJson(['data' => ['status' => 'failed']]);
    expect($ic->refresh()->status)->toBe('failed');
});
