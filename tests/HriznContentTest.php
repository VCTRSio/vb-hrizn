<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so /api/v1/hrizn/content is live. Every endpoint
// now returns the {traceId,data,status} envelope; the payloads move under `data`.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

function seedContentRow(array $overrides = []): HriznContent
{
    $ideacloudId = $overrides['ideacloud_id'] ?? HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'complete', 'hrizn_id' => (string) Str::uuid(), 'created_by' => (string) Str::uuid(),
    ])->id;

    return HriznContent::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => $ideacloudId, 'article_type' => 'basic', 'status' => 'complete',
        'created_by' => (string) Str::uuid(),
    ], $overrides));
}

it('content.list proxies the API (source=api) when a key is set', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/content?*' => Http::response([
        'data' => [['id' => 'c1', 'status' => 'complete', 'article_type' => 'basic']],
        'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
    ])]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')
        ->assertOk()->assertJson(['data' => ['source' => 'api', 'items' => [['id' => 'c1']]]]);
});

it('content.list enriches API items with the local mirror uuid (localId, T2B-16)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // One external item has a local mirror (hrizn_content_id=c_mapped); the other has none.
    $mirror = seedContentRow(['hrizn_content_id' => 'c_mapped']);
    Http::fake(['api.app.hrizn.io/v1/public/content?*' => Http::response([
        'data' => [
            ['id' => 'c_mapped', 'status' => 'complete', 'article_type' => 'basic'],
            ['id' => 'c_orphan', 'status' => 'complete', 'article_type' => 'qa'],
        ],
        'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 2],
    ])]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')->assertOk();
    $items = collect($res->json('data.items'))->keyBy('id');
    expect($items['c_mapped']['localId'])->toBe($mirror->id);
    expect($items['c_orphan']['localId'])->toBeNull();
});

it('content.list localId does not leak another tenant mirror uuid (T2B-16 tenant isolation)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // A content row with the SAME external id in a DIFFERENT tenant must not be used.
    $other = seedContentRow([
        'tenant_id' => '22222222-2222-2222-2222-222222222222',
        'hrizn_content_id' => 'c_shared',
    ]);
    Http::fake(['api.app.hrizn.io/v1/public/content?*' => Http::response([
        'data' => [['id' => 'c_shared', 'status' => 'complete', 'article_type' => 'basic']],
        'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
    ])]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')->assertOk();
    $item = collect($res->json('data.items'))->firstWhere('id', 'c_shared');
    expect($item['localId'])->toBeNull();
    expect($item['localId'])->not->toBe($other->id);
});

it('content.list localId prefers the same-tenant mirror over a foreign one sharing the external id (T2B-16)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // Same external id in BOTH the bound tenant and a foreign tenant: the bound row must win.
    $mine = seedContentRow(['hrizn_content_id' => 'c_shared']);
    $foreign = seedContentRow([
        'tenant_id' => '22222222-2222-2222-2222-222222222222',
        'hrizn_content_id' => 'c_shared',
    ]);
    Http::fake(['api.app.hrizn.io/v1/public/content?*' => Http::response([
        'data' => [['id' => 'c_shared', 'status' => 'complete', 'article_type' => 'basic']],
        'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
    ])]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')->assertOk();
    $item = collect($res->json('data.items'))->firstWhere('id', 'c_shared');
    expect($item['localId'])->toBe($mine->id);
    expect($item['localId'])->not->toBe($foreign->id);
});

it('content.list local fallback with a NULL external id sets both id and localId to the local uuid (T2B-16)', function () {
    Http::fake();
    // hrizn_content_id is nullable; a mirror never assigned an external id still needs a localId.
    $row = seedContentRow(['hrizn_content_id' => null]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')->assertOk();
    $res->assertJson(['data' => ['source' => 'local']]);
    $item = collect($res->json('data.items'))->firstWhere('localId', $row->id);
    expect($item)->not->toBeNull();
    expect($item['id'])->toBe($row->id); // id falls back to the local uuid when the external id is null
    Http::assertNothingSent();
});

it('content.list local fallback carries localId = the local row id (T2B-16)', function () {
    Http::fake();
    $row = seedContentRow(['hrizn_content_id' => 'c_local']);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')->assertOk();
    $res->assertJson(['data' => ['source' => 'local']]);
    $item = collect($res->json('data.items'))->firstWhere('id', 'c_local');
    expect($item['localId'])->toBe($row->id);
    Http::assertNothingSent();
});

it('content.list falls back to local DB and hides soft-deleted rows (T2B-17 fix)', function () {
    Http::fake();
    seedContentRow(['hrizn_content_id' => 'c_visible']);
    seedContentRow(['hrizn_content_id' => 'c_hidden', 'deleted_at' => now()]);

    $res = $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content')->assertOk();
    $res->assertJson(['data' => ['source' => 'local']]);
    $ids = collect($res->json('data.items'))->pluck('id');
    expect($ids)->toContain('c_visible')->not->toContain('c_hidden');
    Http::assertNothingSent();
});

it('content.generate uses settings defaults, records a local generating row and audits', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // ideacloud_id is a local uuid PK; seed the matching local row so generate stores its uuid.
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'complete', 'hrizn_id' => 'ic_1', 'created_by' => (string) Str::uuid(),
    ]);
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response([
        'data' => ['id' => 'c_new', 'status' => 'awaiting_input', 'article_type' => 'basic'],
    ], 202)]);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/content', ['ideacloudId' => 'ic_1'])
        ->assertOk()->assertJson(['data' => ['id' => 'c_new']]);

    $row = HriznContent::withoutTenantScope()->where('hrizn_content_id', 'c_new')->firstOrFail();
    expect($row->status)->toBe('generating')->and($row->article_type)->toBe('basic');
    expect(DB::table('audit_events')->where('resource_type', 'hrizn_content')->count())->toBeGreaterThan(0);

    // Default content_intent 'general' was sent (manifest default).
    Http::assertSent(fn ($r) => ($r->data()['content_intent'] ?? null) === 'general'
        && ($r->data()['article_type'] ?? null) === 'basic');
});

it('content.generate returns 412 with no API key', function () {
    Http::fake();
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/content', ['ideacloudId' => 'ic_1'])->assertStatus(412);
    Http::assertNothingSent();
});

it('content.generateBatch records one local row per item', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // Seed the matching local ideaclouds so each generated row stores a valid uuid ideacloud_id.
    foreach (['ic1', 'ic2'] as $hrizn) {
        HriznIdeacloud::withoutTenantScope()->create([
            'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
            'status' => 'complete', 'hrizn_id' => $hrizn, 'created_by' => (string) Str::uuid(),
        ]);
    }
    Http::fake(['api.app.hrizn.io/v1/public/content/batch' => Http::response([
        'data' => ['items' => [['id' => 'cb1'], ['id' => 'cb2']]],
    ])]);

    $this->actingAs(pluginTestUser('rooftop_owner'))->postJson('/api/v1/hrizn/content/batch', [
        'items' => [
            ['ideacloudId' => 'ic1', 'articleType' => 'basic'],
            ['ideacloudId' => 'ic2', 'articleType' => 'qa'],
        ],
    ])->assertOk();

    expect(HriznContent::withoutTenantScope()->whereIn('hrizn_content_id', ['cb1', 'cb2'])->count())->toBe(2);
});

it('content.getHtml returns the raw markup', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/content/c1/html' => Http::response('<article>Hi</article>')]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content/c1/html')
        ->assertOk()->assertJson(['data' => ['html' => '<article>Hi</article>']]);
});

it('content.getComponents returns the components envelope', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/content/c1/components' => Http::response([
        'data' => ['components' => [['id' => 'k1', 'type' => 'headline', 'content' => ['text' => 'Hi']]]],
    ])]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/content/c1/components')
        ->assertOk()->assertJson(['data' => ['components' => [['id' => 'k1', 'type' => 'headline']]]]);
});
