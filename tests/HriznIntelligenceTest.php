<?php

use Illuminate\Support\Facades\Http;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so the session-authed /api/v1/hrizn/intelligence
// routes are live. Payloads move under the `data` envelope key.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

it('intelligence.list proxies the recommendations endpoint', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/content-intelligence?*' => Http::response([
        'data' => [['id' => 'r1', 'type' => 'missing_model_page', 'description' => 'Add RAV4 page', 'priority_score' => 90, 'status' => 'open', 'created_at' => 'x', 'updated_at' => 'x']],
        'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
    ])]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/intelligence')
        ->assertOk()->assertJson(['data' => ['items' => [['id' => 'r1', 'type' => 'missing_model_page']]]]);
});

it('intelligence.summary proxies the summary endpoint', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/content-intelligence/summary' => Http::response([
        'data' => ['total' => 12, 'high_priority' => 3],
    ])]);

    $this->actingAs(pluginTestUser())->getJson('/api/v1/hrizn/intelligence/summary')
        ->assertOk()->assertJson(['data' => ['total' => 12, 'high_priority' => 3]]);
});

it('intelligence.act creates an ideacloud from a keyword (no audit, mirrors core)', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/ideaclouds' => Http::response([
        'data' => ['id' => 'ic_act', 'status' => 'researching', 'keyword' => 'RAV4 hybrid'],
    ], 202)]);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/intelligence/act', ['keyword' => 'RAV4 hybrid'])
        ->assertOk()->assertJson(['data' => ['id' => 'ic_act']]);

    $row = HriznIdeacloud::withoutTenantScope()->where('hrizn_id', 'ic_act')->firstOrFail();
    expect($row->keyword)->toBe('RAV4 hybrid')->and($row->status)->toBe('researching');
});

it('intelligence.list is forbidden without intelligence.read', function () {
    $this->actingAs(pluginTestUser('vendor'))->getJson('/api/v1/hrizn/intelligence')->assertForbidden();
});
