<?php

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so the session-authed /api/v1/hrizn/settings
// routes are live. Responses are now the canonical ApiResponse envelope
// ({traceId,data,status}); the payload the core test asserted moves under `data`.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

it('settings.get returns hasApiKey=false and null preview when no key set', function () {
    $u = pluginTestUser('rooftop_owner');

    $this->actingAs($u)->getJson('/api/v1/hrizn/settings')
        ->assertOk()
        ->assertJson(['data' => ['hasApiKey' => false, 'apiKeyPreview' => null]]);
});

it('settings.get masks a stored key as hzk_****last4 and echoes site fields', function () {
    $u = pluginTestUser('rooftop_owner');
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, [
        'apiKey' => 'hzk_live_ABCD1234',
        'siteId' => 'site_1', 'siteName' => 'Acme Ford', 'siteDomain' => 'acmeford.com',
    ]);

    $this->actingAs($u)->getJson('/api/v1/hrizn/settings')
        ->assertOk()
        ->assertJson(['data' => [
            'hasApiKey' => true,
            'apiKeyPreview' => 'hzk_••••••••1234',
            'siteName' => 'Acme Ford',
            'siteDomain' => 'acmeford.com',
        ]]);
});

it('settings.get is forbidden without the settings read permission', function () {
    $this->actingAs(pluginTestUser('vendor'))->getJson('/api/v1/hrizn/settings')->assertForbidden();
});

it('setApiKey validates the key against /site and stores the site fields', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/site' => Http::response([
            'data' => ['id' => 'site_9', 'name' => 'Downtown Toyota', 'domain' => 'dttoyota.com',
                'city' => 'Dallas', 'state' => 'TX'],
        ]),
    ]);
    $u = pluginTestUser('rooftop_owner');

    $this->actingAs($u)->postJson('/api/v1/hrizn/settings/api-key', ['apiKey' => 'hzk_live_ZZZZ9999'])
        ->assertOk()
        ->assertJson(['data' => ['success' => true, 'siteName' => 'Downtown Toyota', 'city' => 'Dallas']]);

    $ns = HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT);
    expect($ns['apiKey'])->toBe('hzk_live_ZZZZ9999')->and($ns['siteId'])->toBe('site_9');
});

it('setApiKey rejects a key that does not start with hzk_ (422, no network)', function () {
    Http::fake();
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/settings/api-key', ['apiKey' => 'nope_123'])
        ->assertStatus(422);
    Http::assertNothingSent();
});

it('setApiKey surfaces a 401 from the API as a 401 (invalid key)', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/site' => Http::response([
            'error' => ['code' => 'unauthorized', 'message' => 'Invalid API key'],
        ], 401),
    ]);
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/settings/api-key', ['apiKey' => 'hzk_bad_0000'])
        ->assertStatus(401);
});

it('removeApiKey clears the key and deactivates the tenant webhook endpoint', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_a']);
    WebhookEndpoint::provision('rooftop', PLUGIN_TEST_TENANT, 'vb-hrizn');

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson('/api/v1/hrizn/settings/api-key')->assertOk()->assertJson(['data' => ['success' => true]]);

    expect(HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT))->toBe([]);
    expect(WebhookEndpoint::query()->where('routing_key', 'vb-hrizn')->firstOrFail()->status)->toBe('inactive');
});

it('getSiteInfo returns 412 when no API key is configured', function () {
    Http::fake();
    $this->actingAs(pluginTestUser('rooftop_owner'))->getJson('/api/v1/hrizn/settings/site')->assertStatus(412);
    Http::assertNothingSent();
});

it('getSiteInfo proxies the live /site when a key is set', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake(['api.app.hrizn.io/v1/public/site' => Http::response(['data' => ['id' => 's1', 'name' => 'X']])]);
    $this->actingAs(pluginTestUser('rooftop_owner'))->getJson('/api/v1/hrizn/settings/site')
        ->assertOk()->assertJson(['data' => ['id' => 's1', 'name' => 'X']]);
});
