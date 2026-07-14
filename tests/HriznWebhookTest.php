<?php

use App\Models\PluginNamespace;
use App\Plugins\PluginSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so BOTH the session-authed
// /api/v1/hrizn/settings/webhook routes AND the public inbound receiver
// (/integrations/hrizn/webhook/{token}) are live. The public receiver's response
// stays RAW ({ok:true} / {message}); only the settings routes are enveloped.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

it('registerWebhook creates a Hrizn webhook and stores its id + secret', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // Provide a callback URL via the settings cascade (slug is now vb-hrizn).
    app()->instance(TenantContext::class, new TenantContext('u', 'rooftop', PLUGIN_TEST_TENANT, ''));
    app(PluginSettings::class)->setOverride('vb-hrizn', 'rooftop', PLUGIN_TEST_TENANT, [
        'webhookCallbackUrl' => 'https://hooks.example.com/integrations/hrizn/webhook/TOKEN',
    ]);
    app()->forgetInstance(PluginSettings::class);

    Http::fake(['api.app.hrizn.io/v1/public/webhooks' => Http::response([
        'data' => ['id' => 'wh_1', 'secret' => 'whsec_live', 'url' => 'x', 'events' => [], 'active' => true, 'created_at' => 'x'],
    ])]);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/settings/webhook')
        ->assertOk()->assertJson(['data' => ['success' => true, 'webhookId' => 'wh_1']]);

    $ns = HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT);
    expect($ns['webhookId'])->toBe('wh_1')->and($ns['webhookSecret'])->toBe('whsec_live')
        ->and($ns['webhookRegisteredAt'])->not->toBeNull();
});

it('registerWebhook is a 412 when no callback URL is configured', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    Http::fake();
    $this->actingAs(pluginTestUser('rooftop_owner'))->postJson('/api/v1/hrizn/settings/webhook')->assertStatus(412);
});

/** Register a token namespace with a known secret, return its id (the webhook token). */
function seedHriznWebhookToken(string $secret = 'whsec_test'): string
{
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok', 'webhookSecret' => $secret]);

    // Slug moved to vb-hrizn (Task 2): the namespace key + the receiver's plugin_slug
    // lookup both use vb-hrizn, so read the row back by the same key.
    return (string) PluginNamespace::query()->where('namespace', 'vb-hrizn:'.PLUGIN_TEST_TENANT)->value('id');
}

function postHriznWebhook($test, string $token, array $envelope, string $secret)
{
    $raw = json_encode($envelope);
    $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

    return $test->call('POST', "/integrations/hrizn/webhook/{$token}", [], [], [],
        ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $raw);
}

it('rejects a webhook with a bad signature (401) and does not mutate rows', function () {
    $token = seedHriznWebhookToken();
    $ic = HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'researching', 'hrizn_id' => 'ic_x', 'created_by' => (string) Str::uuid(),
    ]);

    $raw = json_encode(['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_x']]);
    $this->call('POST', "/integrations/hrizn/webhook/{$token}", [], [], [],
        ['HTTP_X-Webhook-Signature' => 'sha256=deadbeef', 'CONTENT_TYPE' => 'application/json'], $raw)
        ->assertStatus(401);

    expect($ic->refresh()->status)->toBe('researching');
});

it('rejects an unknown token (404)', function () {
    $this->call('POST', '/integrations/hrizn/webhook/'.Str::uuid(), [], [], [],
        ['HTTP_X-Webhook-Signature' => 'sha256=x', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['type' => 'test.ping', 'data' => []]))
        ->assertStatus(404);
});

it('ideacloud.completed marks the local row complete', function () {
    $token = seedHriznWebhookToken();
    $ic = HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'researching', 'hrizn_id' => 'ic_done', 'created_by' => (string) Str::uuid(),
    ]);

    postHriznWebhook($this, $token, ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_done']], 'whsec_test')
        ->assertOk();

    expect($ic->refresh()->status)->toBe('complete');
});

it('content.progress, completed, failed and compliance.completed update the content row', function () {
    $token = seedHriznWebhookToken();
    $content = HriznContent::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => (string) Str::uuid(), 'hrizn_content_id' => 'art_1',
        'article_type' => 'basic', 'status' => 'generating', 'created_by' => (string) Str::uuid(),
    ]);

    postHriznWebhook($this, $token, ['type' => 'content.progress', 'data' => [
        'article_id' => 'art_1', 'stage' => 'writing', 'progress_percent' => 40]], 'whsec_test')->assertOk();
    expect($content->refresh()->progress_percent)->toBe(40)->and($content->progress_stage)->toBe('writing');

    postHriznWebhook($this, $token, ['type' => 'compliance.completed', 'data' => [
        'article_id' => 'art_1', 'overall_status' => 'pass', 'overall_score' => 92]], 'whsec_test')->assertOk();
    $content->refresh();
    expect($content->compliance_status)->toBe('pass')->and($content->compliance_score)->toBe(92);

    postHriznWebhook($this, $token, ['type' => 'content.completed', 'data' => ['article_id' => 'art_1']], 'whsec_test')->assertOk();
    expect($content->refresh()->status)->toBe('complete')->and($content->progress_percent)->toBe(100);

    postHriznWebhook($this, $token, ['type' => 'content.failed', 'data' => [
        'article_id' => 'art_1', 'error' => 'boom']], 'whsec_test')->assertOk();
    $content->refresh();
    expect($content->status)->toBe('failed')->and($content->error_message)->toBe('boom');
});

it('a verified webhook matching no local row returns 200 and warns (mirrors core inngest 0-rows-updated)', function () {
    $token = seedHriznWebhookToken();
    Log::spy();

    postHriznWebhook($this, $token, ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_missing']], 'whsec_test')
        ->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => str_contains($msg, 'ideacloud.completed') && str_contains($msg, '0 rows updated'))
        ->once();
});
