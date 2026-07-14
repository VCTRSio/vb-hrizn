<?php

declare(strict_types=1);

/**
 * THE proof: the shipping HRIZN plugin, packaged and SIGNED with the real VCTRS
 * first-party key, installs into the app, boots its server code, serves an
 * enveloped JSON route over HTTP, creates its schema — AND its public inbound
 * webhook receiver verifies an HMAC signature and mutates a local row.
 *
 * This is the exact regression the vb-native spike caught (uploaded server-code
 * plugins that never boot in a web request → routes 404). The plugin tree is
 * mounted read-only at env HZ_SRC; the keypair comes from env HZ_PRIV / HZ_PUB —
 * never hardcoded, never committed.
 */

use App\Models\Plugin;
use App\Models\PluginNamespace;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

afterEach(function () {
    File::deleteDirectory(storage_path('app/plugins/vb-hrizn'));
});

it('installs the signed vb-hrizn, boots it, serves an enveloped route, creates its schema and receives a webhook', function () {
    // Guard: the real key material must be present (harness passes it via env).
    expect(getenv('HZ_PRIV'))->not->toBeFalse();
    expect(is_dir(hzSrc()))->toBeTrue();

    $user = pluginTestUser('rooftop_owner', ['+vb-hrizn.read.rooftop', '+vb-hrizn.write.rooftop']);
    $ctx = hzBindTenant($user->id);

    // Signed install → refresh → explicit migrate (core-gap workaround) → boot.
    hzInstallSignedAndBoot($ctx);

    // The installer persisted the first-party trust tier from the signature.
    expect(Plugin::where('slug', 'vb-hrizn')->value('trust'))->toBe('signed_first_party');

    // The plugin's server code actually executed (register() ran).
    expect(app(PluginManager::class)->serverCodeRan('vb-hrizn'))->toBeTrue();

    // Migrations ran: both hrizn tables exist.
    expect(Schema::hasTable('hrizn_content'))->toBeTrue();
    expect(Schema::hasTable('hrizn_ideaclouds'))->toBeTrue();

    // A session-authed enveloped read route resolves (200 + {status:success}),
    // proving src/routes.php loaded — NOT the 404 the vb-native spike caught.
    $this->actingAs($user)
        ->getJson('/api/v1/hrizn/overview')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    // ── WEBHOOK RECEIVE PROOF ────────────────────────────────────────────────
    // Seed a local content row + a per-tenant webhook secret, then post a signed
    // content.completed envelope to the PUBLIC inbound receiver and prove the row
    // transitioned to 'complete'. The HMAC is computed over the EXACT bytes sent
    // (raw content string via ->call()), so the signature matches what the
    // receiver verifies — mirrors HriznWebhookTest::postHriznWebhook exactly.
    $secret = 'whsec_boot';
    $content = HriznContent::withoutTenantScope()->create([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => (string) Str::uuid(),
        'hrizn_content_id' => 'art_x',
        'article_type' => 'basic',
        'status' => 'generating',
        'created_by' => $user->id,
    ]);

    // Register the token namespace with the known secret; the receiver maps the
    // token → tenant via PluginNamespace (namespace = 'vb-hrizn:'.tenant).
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok', 'webhookSecret' => $secret]);
    $token = (string) PluginNamespace::query()
        ->where('namespace', 'vb-hrizn:'.PLUGIN_TEST_TENANT)
        ->value('id');
    expect($token)->not->toBeEmpty();

    $raw = json_encode(['type' => 'content.completed', 'data' => ['article_id' => 'art_x']]);
    $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

    $this->call('POST', "/integrations/hrizn/webhook/{$token}", [], [], [],
        ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $raw)
        ->assertOk();

    expect($content->refresh()->status)->toBe('complete');
});
