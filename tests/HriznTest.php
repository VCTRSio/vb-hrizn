<?php

/**
 * Post-install / API-surface proof for the standalone harness.
 *
 * The original core HriznTest exercised a server-rendered Inertia surface
 * (/dashboard/hrizn Index, /dashboard/hrizn/content library) plus an in-tree
 * PluginManager discovery (`new PluginManager(base_path('plugins'))`). Neither
 * applies to the EXTRACTED plugin: vb-hrizn is an uploaded plugin (not in
 * base_path('plugins')), and its React UI left the core Vite build — the Inertia
 * pages are retired. The behaviour those pages covered is now proven against the
 * JSON API:
 *   - the overview aggregates (was Hrizn/Index) → GET /api/v1/hrizn/overview;
 *   - the content-library keyword join (was Hrizn/Content) → HriznContentTest;
 *   - the create-via-write path (was POST /dashboard/hrizn/ideaclouds) →
 *     POST /api/v1/hrizn/ideaclouds (enveloped; 412 on no key instead of a
 *     back()->with('error') redirect).
 *
 * This file keeps the manifest→nav discovery proof (built from the mounted src),
 * the overview aggregates, the permission gate, and the audited create path.
 */

use App\Plugins\PluginManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\HriznServiceProvider;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser()->id));
});

/** Build the plugin provider from the read-only mounted source (HZ_SRC). */
function hriznTestProvider(): HriznServiceProvider
{
    $dir = hzSrc();
    $manifest = PluginManifest::fromArray(json_decode((string) file_get_contents($dir.'/manifest.json'), true));

    return new HriznServiceProvider($manifest, $dir);
}

function hriznSeedIdeacloud(string $userId, string $keyword = 'Test keyword research'): HriznIdeacloud
{
    return HriznIdeacloud::create([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'keyword' => $keyword,
        'status' => 'researching',
        'hrizn_id' => (string) Str::uuid(),
        'created_by' => $userId,
    ]);
}

it('discovers the hrizn plugin manifest and includes the hrizn nav item', function () {
    expect(array_column(hriznTestProvider()->navItems(), 'key'))->toContain('hrizn');
});

it('returns the overview aggregates for a permitted user', function () {
    $u = pluginTestUser();

    $ideacloud = hriznSeedIdeacloud($u->id);

    HriznContent::create([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => $ideacloud->id,
        'article_type' => 'basic',
        'status' => 'complete',
        'progress_percent' => 100,
        'created_by' => $u->id,
    ]);

    $res = $this->actingAs($u)->getJson('/api/v1/hrizn/overview')
        ->assertOk()
        ->assertJsonPath('data.stats.totalContent', 1)
        ->assertJsonPath('data.stats.ideacloudCount', 1);
    expect($res->json('data.recentContent'))->toHaveCount(1);
});

it('denies the hrizn overview without permission', function () {
    $u = pluginTestUser('vendor');

    $this->actingAs($u)->getJson('/api/v1/hrizn/overview')->assertForbidden();
});

it('creates an ideacloud via the write path (API-backed, audited)', function () {
    // RECONCILED to core: core router.ts ideaclouds.create (475-512) calls
    // client.createIdeaCloud, stores the hrizn_id RETURNED by the API (line 496), and
    // writes an AUDIT event (action 'ideacloud.create') — no feed event. The extracted
    // store returns the ApiResponse envelope instead of a back() redirect.
    Http::fake([
        'api.app.hrizn.io/v1/public/ideaclouds' => Http::response([
            'data' => ['id' => 'ic_ext_777', 'status' => 'researching', 'keyword' => '2026 Toyota RAV4 vs Honda CR-V'],
        ], 202),
    ]);
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    $u = pluginTestUser();

    $this->actingAs($u)->postJson('/api/v1/hrizn/ideaclouds', [
        'keyword' => '2026 Toyota RAV4 vs Honda CR-V',
    ])->assertOk();

    $ideacloud = HriznIdeacloud::withoutTenantScope()
        ->where('keyword', '2026 Toyota RAV4 vs Honda CR-V')->firstOrFail();

    expect($ideacloud->status)->toBe('researching')
        ->and($ideacloud->hrizn_id)->toBe('ic_ext_777')   // ← the API id, not a random uuid (router.ts:496)
        ->and((string) $ideacloud->created_by)->toBe((string) $u->id)
        ->and((string) $ideacloud->tenant_id)->toBe(PLUGIN_TEST_TENANT);

    // Core writes an AUDIT event (action 'ideacloud.create'), not a feed event.
    expect(DB::table('audit_events')
        ->where('resource_type', 'hrizn_ideaclouds')
        ->where('resource_id', $ideacloud->id)->count())->toBeGreaterThan(0);
});

it('rejects ideacloud creation with a 412 precondition when no API key is set', function () {
    Http::fake();
    // Core getClient() throws PRECONDITION_FAILED (router.ts:96-103); the extracted store
    // surfaces it through HriznResponse::guard as a 412 error envelope and never touches
    // the API or the DB.
    $this->actingAs(pluginTestUser())
        ->postJson('/api/v1/hrizn/ideaclouds', ['keyword' => 'brakes'])
        ->assertStatus(412);
    expect(HriznIdeacloud::withoutTenantScope()->where('keyword', 'brakes')->exists())->toBeFalse();
    Http::assertNothingSent();
});
