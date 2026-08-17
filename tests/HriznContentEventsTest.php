<?php

declare(strict_types=1);

use App\Events\FeedEventRequested;
use App\Events\TaskRequested;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

require_once __DIR__.'/hz_bootstrap.php';

beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

/** Provision this tenant's core WebhookEndpoint; return [slug, signingSecret]. */
function hzEvProvisionEndpoint(): array
{
    $ep = WebhookEndpoint::provision('rooftop', PLUGIN_TEST_TENANT, 'vb-hrizn');

    return [$ep->slug, $ep->secrets['signing_secret']];
}

function hzEvPost($test, string $slug, array $envelope, string $secret)
{
    $raw = json_encode($envelope);
    $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

    return $test->call('POST', "/api/webhooks/inbound/{$slug}", [], [], [],
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
    [$slug, $secret] = hzEvProvisionEndpoint();

    hzEvPost($this, $slug, ['type' => 'content.completed', 'data' => ['article_id' => 'art_ok']], $secret)->assertStatus(202);

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
    [$slug, $secret] = hzEvProvisionEndpoint();

    hzEvPost($this, $slug, ['type' => 'content.failed', 'data' => ['article_id' => 'art_bad', 'error' => 'boom']], $secret)->assertStatus(202);

    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->eventType === 'hrizn.content.failed' && $e->priority === 'high');
    Event::assertNotDispatched(TaskRequested::class);
});

it('fires a research-ready feed event when an ideacloud completes', function () {
    Event::fake([FeedEventRequested::class, TaskRequested::class]);
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'oil change',
        'status' => 'researching', 'hrizn_id' => 'ic_r', 'created_by' => (string) Str::uuid(),
    ]);
    [$slug, $secret] = hzEvProvisionEndpoint();

    hzEvPost($this, $slug, ['type' => 'ideacloud.completed', 'data' => ['ideacloud_id' => 'ic_r']], $secret)->assertStatus(202);

    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->eventType === 'hrizn.ideacloud.ready'
        && str_contains($e->summary, 'oil change'));
});

it('does not fire on re-delivery of an already-complete content webhook (idempotent)', function () {
    $content = hzEvContent('art_dup', 'complete');
    [$slug, $secret] = hzEvProvisionEndpoint();
    Event::fake([FeedEventRequested::class, TaskRequested::class]);

    hzEvPost($this, $slug, ['type' => 'content.completed', 'data' => ['article_id' => 'art_dup']], $secret)->assertStatus(202);

    Event::assertNotDispatched(FeedEventRequested::class);
    Event::assertNotDispatched(TaskRequested::class);
});

it('fires nothing and still 202s when the content id is unknown', function () {
    [$slug, $secret] = hzEvProvisionEndpoint();
    Event::fake([FeedEventRequested::class, TaskRequested::class]);

    hzEvPost($this, $slug, ['type' => 'content.completed', 'data' => ['article_id' => 'nope']], $secret)->assertStatus(202);

    Event::assertNotDispatched(FeedEventRequested::class);
    Event::assertNotDispatched(TaskRequested::class);
});
