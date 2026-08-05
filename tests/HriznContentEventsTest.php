<?php

declare(strict_types=1);

use App\Events\FeedEventRequested;
use App\Events\TaskRequested;
use App\Models\PluginNamespace;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

function hzEvSeedToken(string $secret = 'whsec_ev'): string
{
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok', 'webhookSecret' => $secret]);

    return (string) PluginNamespace::query()->where('namespace', 'vb-hrizn:'.PLUGIN_TEST_TENANT)->value('id');
}

function hzEvPost($test, string $token, array $envelope, string $secret = 'whsec_ev')
{
    $raw = json_encode($envelope);
    $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

    return $test->call('POST', "/integrations/hrizn/webhook/{$token}", [], [], [],
        ['HTTP_X-Webhook-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $raw);
}

function hzEvContent(string $hriznContentId, string $status = 'generating', string $articleType = 'modellanding', ?string $creator = null): HriznContent
{
    $ic = HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'winter tires',
        'status' => 'complete', 'hrizn_id' => 'ic_'.$hriznContentId, 'created_by' => (string) Str::uuid(),
    ]);

    return HriznContent::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => $ic->id, 'hrizn_content_id' => $hriznContentId,
        'article_type' => $articleType, 'content_intent' => 'variable', 'status' => $status,
        'created_by' => $creator ?? (string) Str::uuid(),
    ]);
}

it('fires a feed event + a task assigned to the requester when content completes', function () {
    Event::fake([FeedEventRequested::class, TaskRequested::class]);
    $creator = (string) Str::uuid();
    $content = hzEvContent('art_ok', 'generating', 'modellanding', $creator);
    $token = hzEvSeedToken();

    hzEvPost($this, $token, ['type' => 'content.completed', 'data' => ['article_id' => 'art_ok']])->assertOk();

    expect($content->refresh()->status)->toBe('complete');
    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->eventType === 'hrizn.content.ready'
        && $e->sourceType === 'vb-hrizn.content' && $e->sourceId === $content->id
        && str_contains($e->summary, 'winter tires'));
    Event::assertDispatched(TaskRequested::class, fn ($e) => $e->pluginNamespace === 'vb-hrizn'
        && $e->assignedTo === $creator && $e->requestedBy === $creator
        && str_contains($e->title, 'winter tires'));
});

it('fires a high-priority feed event (no task) when content fails', function () {
    Event::fake([FeedEventRequested::class, TaskRequested::class]);
    hzEvContent('art_bad', 'generating');
    $token = hzEvSeedToken();

    hzEvPost($this, $token, ['type' => 'content.failed', 'data' => ['article_id' => 'art_bad', 'error' => 'boom']])->assertOk();

    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->eventType === 'hrizn.content.failed' && $e->priority === 'high');
    Event::assertNotDispatched(TaskRequested::class);
});

it('fires a research-ready feed event when an ideacloud completes', function () {
    Event::fake([FeedEventRequested::class, TaskRequested::class]);
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'oil change',
        'status' => 'researching', 'hrizn_id' => 'ic_r', 'created_by' => (string) Str::uuid(),
    ]);
    $token = hzEvSeedToken();

    hzEvPost($this, $token, ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_r']])->assertOk();

    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->eventType === 'hrizn.ideacloud.ready'
        && str_contains($e->summary, 'oil change'));
});

it('does not fire on re-delivery of an already-complete content webhook (idempotent)', function () {
    $content = hzEvContent('art_dup', 'complete');
    $token = hzEvSeedToken();
    Event::fake([FeedEventRequested::class, TaskRequested::class]);

    hzEvPost($this, $token, ['type' => 'content.completed', 'data' => ['article_id' => 'art_dup']])->assertOk();

    Event::assertNotDispatched(FeedEventRequested::class);
    Event::assertNotDispatched(TaskRequested::class);
});

it('fires nothing and still 200s when the content id is unknown', function () {
    $token = hzEvSeedToken();
    Event::fake([FeedEventRequested::class, TaskRequested::class]);

    hzEvPost($this, $token, ['type' => 'content.completed', 'data' => ['article_id' => 'nope']])->assertOk();

    Event::assertNotDispatched(FeedEventRequested::class);
    Event::assertNotDispatched(TaskRequested::class);
});
