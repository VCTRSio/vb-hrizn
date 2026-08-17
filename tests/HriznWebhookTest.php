<?php

use App\Events\InboundWebhookReceived;
use App\Models\IntegrationRun;
use App\Models\WebhookEndpoint;
use App\Plugins\PluginSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Listeners\HandleInboundWebhook;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so the session-authed
// /api/v1/hrizn/settings/webhook routes are live. Inbound deliveries now go
// through core's ingress at /api/webhooks/inbound/{slug} (202 accepted /
// 400 rejected) and are handled by the HandleInboundWebhook listener.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

it('registerWebhook provisions a WebhookEndpoint, stores the SaaS secret on it, keeps webhookId in the namespace', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    app()->instance(TenantContext::class, new TenantContext('u', 'rooftop', PLUGIN_TEST_TENANT, ''));
    app(PluginSettings::class)->setOverride('vb-hrizn', 'rooftop', PLUGIN_TEST_TENANT, [
        'webhookCallbackUrl' => 'https://hooks.example.com/legacy/path',
    ]);
    app()->forgetInstance(PluginSettings::class);

    Http::fake(['api.app.hrizn.io/v1/public/webhooks' => Http::response([
        'data' => ['id' => 'wh_1', 'secret' => 'whsec_live', 'url' => 'x', 'events' => [], 'active' => true, 'created_at' => 'x'],
    ])]);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/api/v1/hrizn/settings/webhook')
        ->assertOk()->assertJson(['data' => ['success' => true, 'webhookId' => 'wh_1']]);

    $ep = WebhookEndpoint::query()->where('routing_key', 'vb-hrizn')->firstOrFail();
    expect($ep->status)->toBe('active')
        ->and($ep->secrets['signing_secret'])->toBe('whsec_live')
        ->and($ep->slug)->not->toBeEmpty();

    $ns = HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT);
    expect($ns['webhookId'])->toBe('wh_1')
        ->and($ns['webhookRegisteredAt'])->not->toBeNull()
        ->and($ns['webhookSecret'] ?? null)->toBeNull();

    Http::assertSent(fn ($req) => $req->url() === 'https://api.app.hrizn.io/v1/public/webhooks'
        && str_starts_with($req['url'], 'https://hooks.example.com/api/webhooks/inbound/')
        && str_contains($req['url'], $ep->slug));
});

it('registerWebhook derives the callback origin from app.url when no override is set', function () {
    config(['app.url' => 'https://app.example.com']);
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);

    Http::fake(['api.app.hrizn.io/v1/public/webhooks' => Http::response([
        'data' => ['id' => 'wh_2', 'secret' => 'whsec_x'],
    ])]);

    $this->actingAs(pluginTestUser('rooftop_owner'))->postJson('/api/v1/hrizn/settings/webhook')->assertOk();

    $ep = WebhookEndpoint::query()->where('routing_key', 'vb-hrizn')->firstOrFail();
    Http::assertSent(fn ($req) => str_starts_with($req['url'], 'https://app.example.com/api/webhooks/inbound/')
        && str_contains($req['url'], $ep->slug));
});

/** Provision this tenant's core WebhookEndpoint; return [slug, signingSecret]. */
function hzProvisionEndpoint(): array
{
    $ep = WebhookEndpoint::provision('rooftop', PLUGIN_TEST_TENANT, 'vb-hrizn');

    return [$ep->slug, $ep->secrets['signing_secret']];
}

function postInbound($test, string $slug, array $envelope, string $secret)
{
    $raw = json_encode($envelope);
    $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

    return $test->call('POST', "/api/webhooks/inbound/{$slug}", [], [], [],
        ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $raw);
}

it('rejects a bad signature (400) and mutates nothing', function () {
    [$slug] = hzProvisionEndpoint();
    $ic = HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'researching', 'hrizn_id' => 'ic_x', 'created_by' => (string) Str::uuid(),
    ]);

    $raw = json_encode(['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_x']]);
    $this->call('POST', "/api/webhooks/inbound/{$slug}", [], [], [],
        ['HTTP_X-Webhook-Signature' => 'sha256=deadbeef', 'CONTENT_TYPE' => 'application/json'], $raw)
        ->assertStatus(400);

    expect($ic->refresh()->status)->toBe('researching');
    expect(IntegrationRun::query()->where('integration_type', 'hrizn_webhook')->count())->toBe(0);
});

it('rejects an unknown slug (400)', function () {
    $this->call('POST', '/api/webhooks/inbound/'.bin2hex(random_bytes(16)), [], [], [],
        ['HTTP_X-Webhook-Signature' => 'sha256=x', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['type' => 'test.ping', 'data' => []]))
        ->assertStatus(400);
});

it('ideacloud.completed marks the row complete and records a succeeded run (no cadence)', function () {
    [$slug, $secret] = hzProvisionEndpoint();
    $ic = HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'researching', 'hrizn_id' => 'ic_done', 'created_by' => (string) Str::uuid(),
    ]);

    postInbound($this, $slug, ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_done']], $secret)
        ->assertStatus(202);

    expect($ic->refresh()->status)->toBe('complete');

    $run = IntegrationRun::query()->where('integration_type', 'hrizn_webhook')
        ->where('target_ref', 'ideacloud.completed')->firstOrFail();
    expect($run->status->value)->toBe('succeeded')
        ->and($run->expected_next_at)->toBeNull()
        ->and($run->triggered_by)->toBe('webhook')
        ->and($run->stats['rows'] ?? null)->toBe(1)
        ->and($run->error_message)->toBeNull();
});

it('content progress/completed/failed/compliance update the row and each records a run', function () {
    [$slug, $secret] = hzProvisionEndpoint();
    $content = HriznContent::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => (string) Str::uuid(), 'hrizn_content_id' => 'art_1',
        'article_type' => 'basic', 'status' => 'generating', 'created_by' => (string) Str::uuid(),
    ]);

    postInbound($this, $slug, ['type' => 'content.progress', 'data' => [
        'article_id' => 'art_1', 'stage' => 'writing', 'progress_percent' => 40]], $secret)->assertStatus(202);
    expect($content->refresh()->progress_percent)->toBe(40)->and($content->progress_stage)->toBe('writing');

    postInbound($this, $slug, ['type' => 'compliance.completed', 'data' => [
        'article_id' => 'art_1', 'overall_status' => 'pass', 'overall_score' => 92]], $secret)->assertStatus(202);
    $content->refresh();
    expect($content->compliance_status)->toBe('pass')->and($content->compliance_score)->toBe(92);

    postInbound($this, $slug, ['type' => 'content.completed', 'data' => ['article_id' => 'art_1']], $secret)->assertStatus(202);
    expect($content->refresh()->status)->toBe('complete')->and($content->progress_percent)->toBe(100);

    postInbound($this, $slug, ['type' => 'content.failed', 'data' => [
        'article_id' => 'art_1', 'error' => 'boom']], $secret)->assertStatus(202);
    $content->refresh();
    expect($content->status)->toBe('failed')->and($content->error_message)->toBe('boom');

    // one recorded run per distinct delivery
    expect(IntegrationRun::query()->where('integration_type', 'hrizn_webhook')->count())->toBe(4);
});

it('dedupes an identical redelivery (core webhook_deliveries) — one run, one effect', function () {
    [$slug, $secret] = hzProvisionEndpoint();
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'researching', 'hrizn_id' => 'ic_dupe', 'created_by' => (string) Str::uuid(),
    ]);
    $env = ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_dupe']];

    postInbound($this, $slug, $env, $secret)->assertStatus(202);
    postInbound($this, $slug, $env, $secret)->assertStatus(202); // deduped: no second event

    expect(IntegrationRun::query()->where('integration_type', 'hrizn_webhook')
        ->where('target_ref', 'ideacloud.completed')->count())->toBe(1);
});

it('a verified delivery matching no local row returns 202, warns, and records a run with rows=0', function () {
    [$slug, $secret] = hzProvisionEndpoint();
    Log::spy();

    postInbound($this, $slug, ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_missing']], $secret)
        ->assertStatus(202);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => str_contains($msg, 'ideacloud.completed') && str_contains($msg, '0 rows updated'))
        ->once();

    $run = IntegrationRun::query()->where('integration_type', 'hrizn_webhook')
        ->where('target_ref', 'ideacloud.completed')->firstOrFail();
    expect($run->status->value)->toBe('succeeded')->and($run->stats['rows'] ?? null)->toBe(0);
});

it('records a FAILED run and swallows when a handler throws (still ACKs)', function () {
    // Unit-level: drive the listener directly with a subclass whose dispatch throws.
    hzBindTenant(pluginTestUser('rooftop_owner')->id); // tenant scope for the run insert
    $listener = new class extends HandleInboundWebhook
    {
        protected function dispatch(string $type, array $data): ?int
        {
            throw new \RuntimeException('boom-handler');
        }
    };

    $event = new InboundWebhookReceived(
        'ep-x', 'vb-hrizn', 'rooftop', PLUGIN_TEST_TENANT,
        ['type' => 'content.completed', 'data' => []],
    );

    $listener->handle($event); // MUST NOT throw (this is what lets core ACK 202)

    $run = IntegrationRun::query()->where('integration_type', 'hrizn_webhook')
        ->where('target_ref', 'content.completed')->firstOrFail();
    expect($run->status->value)->toBe('failed')
        ->and($run->error_message)->toContain('boom-handler')
        ->and($run->expected_next_at)->toBeNull();
});

it('ignores deliveries routed to another plugin', function () {
    hzBindTenant(pluginTestUser('rooftop_owner')->id);
    $listener = new HandleInboundWebhook;
    $listener->handle(new InboundWebhookReceived(
        'ep-y', 'some-other-plugin', 'rooftop', PLUGIN_TEST_TENANT, ['type' => 'content.completed', 'data' => []]
    ));
    expect(IntegrationRun::query()->where('integration_type', 'hrizn_webhook')->count())->toBe(0);
});
