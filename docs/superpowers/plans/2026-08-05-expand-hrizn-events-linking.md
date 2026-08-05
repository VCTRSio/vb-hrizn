# HRIZN Expand — Events, Vehicle Linking & Content-Health Directory — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make HRIZN acted-on, not generate-and-forget: fire feed/task events when the platform finishes an article, link vehicle-specific content to inventory vehicles, and expose a PII-free per-rooftop content-health seam — all zero-core.

**Architecture:** Add a plugin-local relation vocabulary; hook the existing webhook `dispatch()` to emit `FeedEventRequested`/`TaskRequested`; accept an optional VIN on content generation and link content→vehicle via `EntityReferenceService`; add a `HriznDirectory` outbound seam; add a vehicle picker + badges in the ESM UI backed by a new session-API vehicle-search passthrough.

**Tech Stack:** PHP 8 / Laravel, Pest (via `scripts/test-in-app.sh`), React-via-host ESM UI (Vitest/jsdom), Postgres FORCE-RLS.

## Global Constraints

- **Core Change Firewall:** ZERO edits to the core tree `/home/carmelo/Work/VCTRS/vctrbase-php`. Every change lands under `/home/carmelo/Work/VCTRS/vctrbase-plugins/vb-hrizn`. After each task, `git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` MUST be empty.
- **Seams only:** consume host functionality by class import + `app()`/static call. Sanctioned imports used here: `App\Support\EntityReferenceService`, `App\Events\FeedEventRequested`, `App\Events\TaskRequested`, `App\Support\TenantContext`, `App\Support\SystemContext`, and the Directory seam `Vctrs\Plugins\InventoryHub\InventoryDirectory` (a service, not a model — the established cross-plugin contract; the `tasks` plugin uses it identically). NEVER import a sibling plugin's Eloquent model.
- **Best-effort side effects:** every new event dispatch and entity link is wrapped `try { … } catch (\Throwable $e) { report($e); }` and must never break the primary path (webhook ack / content generation).
- **Graceful standalone:** guard every `InventoryDirectory` use with `app()->bound(InventoryDirectory::class)`; when unbound (inventory-hub not installed) the feature silently no-ops (no link, empty picker).
- **RLS/PII:** all new reads stay tenant-scoped (explicit tenant `where` + `withoutTenantScope`/`runAsTenant`), PII-free. Do not relax RLS.
- **Idempotency:** content-completion events fire only on the completing transition (guard on prior status), so webhook re-delivery is inert.
- **Version/author:** manifest `author` stays `"Angus Fox"`; version bumps 1.0.0 → 1.1.0 in the final gate only.
- **Release hold:** batched Touchpoint 5 — do NOT build-zip/sign/publish. Local branch/commits only.

---

### Task 1: Relation vocab + content-lifecycle events

**Files:**
- Create: `src/Support/HriznRelation.php`
- Modify: `src/Http/Controllers/WebhookController.php`
- Test: `tests/HriznContentEventsTest.php` (create)

**Interfaces:**
- Produces: `HriznRelation` string consts (consumed by Tasks 2–4): `PLUGIN_NAMESPACE`, `CONTENT_SOURCE_TYPE`, `IDEACLOUD_SOURCE_TYPE`, `VEHICLE_TARGET_TYPE`, `COVERS`, `FEED_CONTENT_READY`, `FEED_CONTENT_FAILED`, `FEED_RESEARCH_READY`, and `static articleLabel(string $type): string`.
- Consumes: `App\Events\FeedEventRequested`, `App\Events\TaskRequested`, `App\Support\TenantContext` (`SYSTEM_ACTOR`).

- [ ] **Step 1: Create the relation-vocabulary file**

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

/**
 * Plugin-local relation & event vocabulary. Core does not validate the relation
 * string on EntityReferenceService::link(), so the plugin owns these consts —
 * keeping cross-plugin linking and feed/task emission zero-core.
 */
final class HriznRelation
{
    public const PLUGIN_NAMESPACE = 'vb-hrizn';

    /** EntityReference source/target types. */
    public const CONTENT_SOURCE_TYPE = 'vb-hrizn.content';
    public const IDEACLOUD_SOURCE_TYPE = 'vb-hrizn.ideacloud';
    public const VEHICLE_TARGET_TYPE = 'inventory_vehicle';

    /** Relation verb: a content piece covers a specific vehicle. */
    public const COVERS = 'covers';

    /** Feed event types. */
    public const FEED_CONTENT_READY = 'hrizn.content.ready';
    public const FEED_CONTENT_FAILED = 'hrizn.content.failed';
    public const FEED_RESEARCH_READY = 'hrizn.ideacloud.ready';

    /** Human labels for the 7 article types (display only, not validation). */
    private const ARTICLE_LABELS = [
        'basic' => 'Article',
        'qa' => 'Q&A',
        'expert' => 'Expert Article',
        'modellanding' => 'Model Landing Page',
        'comparison' => 'Comparison',
        'salesevent' => 'Sales Event',
        'emailtemplate' => 'Email Template',
    ];

    public static function articleLabel(string $type): string
    {
        return self::ARTICLE_LABELS[$type] ?? ucfirst($type);
    }
}
```

- [ ] **Step 2: Write the failing events test**

Create `tests/HriznContentEventsTest.php`. Use uniquely-named webhook helpers (`hzEv*`) to avoid a redeclare collision with `tests/HriznWebhookTest.php`'s `seedHriznWebhookToken`/`postHriznWebhook` (Pest loads all test files into one process).

```php
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
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `bash scripts/test-in-app.sh tests/HriznContentEventsTest.php`
Expected: FAIL (no events dispatched; `HriznRelation` referenced by the modified controller does not yet emit).

- [ ] **Step 4: Refactor the three webhook handlers to fetch-first + emit events**

In `src/Http/Controllers/WebhookController.php`:
- Add imports: `use App\Events\FeedEventRequested; use App\Events\TaskRequested; use App\Support\TenantContext; use Vctrs\Plugins\VbHrizn\Support\HriznRelation;`
- Replace `onContentCompleted()`:

```php
/** @param array<string, mixed> $data */
private function onContentCompleted(array $data): int
{
    $id = $data['article_id'] ?? null;
    if (! is_string($id) || $id === '') {
        return 0;
    }
    $content = HriznContent::query()->where('hrizn_content_id', $id)->first();
    if ($content === null) {
        return 0;
    }
    $alreadyComplete = $content->status === 'complete';
    $content->update(['status' => 'complete', 'progress_percent' => 100, 'progress_stage' => 'finalizing']);

    if (! $alreadyComplete) {
        $this->emitContentReady($content);
    }

    return 1;
}

private function emitContentReady(HriznContent $content): void
{
    try {
        $keyword = $content->ideacloud?->keyword ?? 'content';
        $label = HriznRelation::articleLabel((string) $content->article_type);
        $tt = (string) $content->tenant_type;
        $tid = (string) $content->tenant_id;

        event(new FeedEventRequested(
            tenantType: $tt, tenantId: $tid,
            actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
            sourceType: HriznRelation::CONTENT_SOURCE_TYPE, sourceId: (string) $content->id,
            pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
            eventType: HriznRelation::FEED_CONTENT_READY,
            summary: "New HRIZN {$label} ready to review: {$keyword}",
            detailPayload: ['article_type' => $content->article_type, 'content_intent' => $content->content_intent],
        ));

        $requester = (string) ($content->created_by ?? TenantContext::SYSTEM_ACTOR);
        event(new TaskRequested(
            pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
            tenantType: $tt, tenantId: $tid,
            requestedBy: $requester,
            title: "Review & publish HRIZN {$label}: {$keyword}",
            description: "A HRIZN {$label} ({$content->content_intent}) finished generating and is ready to review and publish.",
            priority: 'normal',
            assignedTo: $content->created_by !== null ? (string) $content->created_by : null,
        ));
    } catch (\Throwable $e) {
        report($e);
    }
}
```

- Replace `onContentFailed()` to fetch-first and emit a high-priority feed event:

```php
/** @param array<string, mixed> $data */
private function onContentFailed(array $data): int
{
    $id = $data['article_id'] ?? null;
    if (! is_string($id) || $id === '') {
        return 0;
    }
    $content = HriznContent::query()->where('hrizn_content_id', $id)->first();
    if ($content === null) {
        return 0;
    }
    $wasFailed = $content->status === 'failed';
    $content->update(['status' => 'failed', 'error_message' => $data['error'] ?? null]);

    if (! $wasFailed) {
        try {
            $keyword = $content->ideacloud?->keyword ?? 'content';
            $label = HriznRelation::articleLabel((string) $content->article_type);
            event(new FeedEventRequested(
                tenantType: (string) $content->tenant_type, tenantId: (string) $content->tenant_id,
                actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                sourceType: HriznRelation::CONTENT_SOURCE_TYPE, sourceId: (string) $content->id,
                pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
                eventType: HriznRelation::FEED_CONTENT_FAILED,
                summary: "HRIZN {$label} generation failed: {$keyword}",
                priority: 'high',
                detailPayload: ['error' => (string) ($data['error'] ?? '')],
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    return 1;
}
```

- Change the `ideacloud.completed` arm only to fetch-first + emit (keep `ideacloud.failed` on the existing `setIdeacloudStatus`). Update the `match` in `dispatch()`:

```php
'ideacloud.completed' => $this->onIdeacloudCompleted($data),
'ideacloud.failed' => $this->setIdeacloudStatus($data, 'failed'),
```

  and add:

```php
/** @param array<string, mixed> $data */
private function onIdeacloudCompleted(array $data): int
{
    $hriznId = $data['ideacloud_id'] ?? null;
    if (! is_string($hriznId) || $hriznId === '') {
        return 0;
    }
    $ic = HriznIdeacloud::query()->where('hrizn_id', $hriznId)->first();
    if ($ic === null) {
        return 0;
    }
    $wasComplete = $ic->status === 'complete';
    $ic->update(['status' => 'complete']);

    if (! $wasComplete) {
        try {
            event(new FeedEventRequested(
                tenantType: (string) $ic->tenant_type, tenantId: (string) $ic->tenant_id,
                actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                sourceType: HriznRelation::IDEACLOUD_SOURCE_TYPE, sourceId: (string) $ic->id,
                pluginNamespace: HriznRelation::PLUGIN_NAMESPACE,
                eventType: HriznRelation::FEED_RESEARCH_READY,
                summary: "Keyword research ready: {$ic->keyword}",
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    return 1;
}
```

Keep `setIdeacloudStatus()` in place (still used by the `failed` arm). The `dispatch()` `0 rows` warning path is unchanged (handlers still return `int`, `0` when no row matched).

- [ ] **Step 5: Run the events test to verify it passes**

Run: `bash scripts/test-in-app.sh tests/HriznContentEventsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Regression — run the existing webhook test**

Run: `bash scripts/test-in-app.sh tests/HriznWebhookTest.php`
Expected: PASS (unchanged behavior; row updates still applied, 0-rows warning intact).

- [ ] **Step 7: Firewall audit + commit**

```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain   # MUST be empty
git add src/Support/HriznRelation.php src/Http/Controllers/WebhookController.php tests/HriznContentEventsTest.php
git commit -m "feat(hrizn): content-lifecycle feed/task events + relation vocab (Expand T1)"
```

---

### Task 2: Content → vehicle link at generate + read-back

**Files:**
- Modify: `src/Http/Controllers/ContentController.php`
- Test: `tests/HriznContentLinkTest.php` (create)

**Interfaces:**
- Consumes: `HriznRelation` (T1), `App\Support\EntityReferenceService`, `Vctrs\Plugins\InventoryHub\InventoryDirectory`.
- Produces: an `entity_references` row (`covers`, `vb-hrizn.content` → `inventory_vehicle`/VIN) on vehicle-article generation; `linkedVehicles[]` on the `apiGet` payload.

- [ ] **Step 1: Write the failing link test**

Create `tests/HriznContentLinkTest.php`. Bind a stub `InventoryDirectory` under its FQCN string so the test is self-contained (independent of whether inventory-hub is booted in the test app). Drive generation through the real session-API route.

```php
<?php

declare(strict_types=1);

use App\Models\EntityReference;
use Illuminate\Support\Facades\Http;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

const HZ_INV_DIR = 'Vctrs\\Plugins\\InventoryHub\\InventoryDirectory';

function hzBindInventoryStub(array $byVin): void
{
    app()->instance(HZ_INV_DIR, new class($byVin)
    {
        /** @param array<string, array<string, mixed>> $byVin */
        public function __construct(private array $byVin) {}

        public function lookupByVin(string $tt, string $tid, string $vin): ?array
        {
            return $this->byVin[strtoupper($vin)] ?? null;
        }

        /** @return array<int, array<string, mixed>> */
        public function search(string $tt, string $tid, ?string $q = null, ?string $status = 'active', int $limit = 20): array
        {
            return array_values($this->byVin);
        }
    });
}

beforeEach(function () {
    $user = hzFeatureUser(['+hrizn.content.read.rooftop', '+hrizn.content.write.rooftop', '+hrizn.ideacloud.write.rooftop']);
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    $this->user = $user;
});

it('links content to a vehicle by VIN for a modellanding article', function () {
    hzBindInventoryStub(['1HGCM82633A004352' => ['vin' => '1HGCM82633A004352', 'year' => 2022, 'make' => 'Honda', 'model' => 'Accord', 'trim' => 'EX']]);
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response(['data' => ['id' => 'art_link']])]);

    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'modellanding', 'vehicleVin' => '1hgcm82633a004352',
    ])->assertOk();

    $ref = EntityReference::query()
        ->where('source_type', 'vb-hrizn.content')
        ->where('target_type', 'inventory_vehicle')
        ->where('target_id', '1HGCM82633A004352')
        ->where('relation', 'covers')->first();
    expect($ref)->not->toBeNull();
});

it('does not link when the article type is not vehicle-specific', function () {
    hzBindInventoryStub(['1HGCM82633A004352' => ['vin' => '1HGCM82633A004352', 'make' => 'Honda', 'model' => 'Accord']]);
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response(['data' => ['id' => 'art_basic']])]);

    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'basic', 'vehicleVin' => '1HGCM82633A004352',
    ])->assertOk();

    expect(EntityReference::query()->where('source_type', 'vb-hrizn.content')->count())->toBe(0);
});

it('does not link (but still creates content) when the VIN does not resolve', function () {
    hzBindInventoryStub([]); // no vehicles
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response(['data' => ['id' => 'art_novin']])]);

    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'comparison', 'vehicleVin' => 'ZZZZZZZZZZZZZZZZZ',
    ])->assertOk();

    expect(EntityReference::query()->where('source_type', 'vb-hrizn.content')->count())->toBe(0);
});

it('exposes linkedVehicles on apiGet', function () {
    hzBindInventoryStub(['1HGCM82633A004352' => ['vin' => '1HGCM82633A004352', 'year' => 2022, 'make' => 'Honda', 'model' => 'Accord', 'trim' => 'EX']]);
    Http::fake([
        'api.app.hrizn.io/v1/public/content*' => Http::response(['data' => ['id' => 'art_get', 'title' => 'X']]),
    ]);
    // Generate first (creates local row + link).
    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'modellanding', 'vehicleVin' => '1HGCM82633A004352',
    ])->assertOk();

    $res = $this->actingAs($this->user)->getJson('/api/v1/hrizn/content/art_get')->assertOk()->json();
    expect($res['data']['linkedVehicles'][0]['vin'])->toBe('1HGCM82633A004352')
        ->and($res['data']['linkedVehicles'][0]['make'])->toBe('Honda');
});
```

Note: the HRIZN `generateContent` API returns the article id at `data.id` (the client unwraps the envelope); the local row stores it as `hrizn_content_id`. `apiGet('art_get')` fetches by that same external id, so its local-row resolution (`where('hrizn_content_id','art_get')`) finds the row created by the generate call.

- [ ] **Step 2: Run to verify it fails**

Run: `bash scripts/test-in-app.sh tests/HriznContentLinkTest.php`
Expected: FAIL (no `entity_references` row; no `linkedVehicles` key).

- [ ] **Step 3: Add the VIN param, link on generate, expose on apiGet**

In `src/Http/Controllers/ContentController.php`:
- Add imports: `use App\Support\EntityReferenceService; use Vctrs\Plugins\InventoryHub\InventoryDirectory; use Vctrs\Plugins\VbHrizn\Support\HriznRelation;`
- Add a private const for the vehicle-specific types:

```php
private const VEHICLE_ARTICLE_TYPES = ['modellanding', 'comparison'];
```

- In `generate()`, add to the validate array: `'vehicleVin' => ['sometimes', 'string', 'max:32'],`
- Capture the created content out of the transaction and link after it. Replace the `DB::transaction(...)` block + `return` with:

```php
$content = null;
DB::transaction(function () use ($ctx, $validated, $api, $articleType, $contentIntent, $autoCompliance, $autoContentTools, &$content) {
    AuditContext::tag('hrizn.content.generate');
    $ideacloud = HriznIdeacloud::query()->where('hrizn_id', $validated['ideacloudId'])->first();
    $content = HriznContent::create([
        'ideacloud_id' => $ideacloud !== null ? $ideacloud->id : $validated['ideacloudId'],
        'hrizn_content_id' => $api['id'] ?? null,
        'article_type' => $articleType,
        'content_intent' => $contentIntent,
        'auto_compliance' => $autoCompliance,
        'auto_content_tools' => $autoContentTools,
        'status' => 'generating',
        'created_by' => $ctx->userId(),
    ]);
});

$this->maybeLinkVehicle($ctx, $content, $articleType, $validated['vehicleVin'] ?? null);

return ApiResponse::success($api);
```

  (Widen the `HriznResponse::guard(function () use (...) {...})` capture list to include `$articleType` already present; add nothing else — `$content` is a local.)

- Add the helper methods:

```php
private function maybeLinkVehicle(TenantContext $ctx, ?HriznContent $content, string $articleType, ?string $vin): void
{
    if ($content === null || $vin === null || trim($vin) === '' || ! in_array($articleType, self::VEHICLE_ARTICLE_TYPES, true)) {
        return;
    }
    if (! app()->bound(InventoryDirectory::class)) {
        return;
    }
    try {
        $tt = $ctx->activeTenantType();
        $tid = $ctx->activeTenantId();
        $vehicle = app(InventoryDirectory::class)->lookupByVin($tt, $tid, $vin);
        if ($vehicle === null) {
            return; // unknown VIN — skip link, content already created
        }
        app(EntityReferenceService::class)->link(
            $tt, $tid,
            HriznRelation::CONTENT_SOURCE_TYPE, (string) $content->id,
            HriznRelation::VEHICLE_TARGET_TYPE, strtoupper($vin),
            HriznRelation::COVERS, $ctx->userId(),
        );
    } catch (\Throwable $e) {
        report($e);
    }
}

/** @return array<int, array<string, mixed>> */
private function linkedVehiclesFor(TenantContext $ctx, string $externalId): array
{
    try {
        $local = HriznContent::query()->where('hrizn_content_id', $externalId)->first();
        if ($local === null || ! app()->bound(InventoryDirectory::class)) {
            return [];
        }
        $tt = $ctx->activeTenantType();
        $tid = $ctx->activeTenantId();
        $refs = app(EntityReferenceService::class)->forSource($tt, $tid, HriznRelation::CONTENT_SOURCE_TYPE, (string) $local->id);
        $dir = app(InventoryDirectory::class);
        $out = [];
        foreach ($refs as $ref) {
            if (($ref['target_type'] ?? null) !== HriznRelation::VEHICLE_TARGET_TYPE) {
                continue;
            }
            $v = $dir->lookupByVin($tt, $tid, (string) $ref['target_id']);
            $out[] = $v ?? ['vin' => $ref['target_id']];
        }

        return $out;
    } catch (\Throwable $e) {
        report($e);

        return [];
    }
}
```

- In `apiGet()`, enrich the passthrough payload with `linkedVehicles`:

```php
public function apiGet(string $id): JsonResponse
{
    $ctx = app(TenantContext::class);

    return HriznResponse::guard(function () use ($ctx, $id) {
        $payload = $this->clients->for($ctx->activeTenantType(), $ctx->activeTenantId())->getContent($id);
        $payload['linkedVehicles'] = $this->linkedVehiclesFor($ctx, $id);

        return ApiResponse::success($payload);
    });
}
```

  (Ensure `use App\Support\TenantContext;` is already imported — it is, per current `generate()`.)

- [ ] **Step 4: Run the link test to verify it passes**

Run: `bash scripts/test-in-app.sh tests/HriznContentLinkTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Regression — existing content tests**

Run: `bash scripts/test-in-app.sh tests/HriznContentTest.php`
Expected: PASS (generate/list/get unchanged for the no-VIN path; `linkedVehicles` is additive).

- [ ] **Step 6: Firewall audit + commit**

```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain   # MUST be empty
git add src/Http/Controllers/ContentController.php tests/HriznContentLinkTest.php
git commit -m "feat(hrizn): content↔vehicle link on generate + linkedVehicles read-back (Expand T2)"
```

---

### Task 3: HriznDirectory (content-health outbound seam)

**Files:**
- Create: `src/HriznDirectory.php`
- Modify: `src/HriznServiceProvider.php` (bind singleton)
- Test: `tests/HriznDirectoryTest.php` (create)

**Interfaces:**
- Produces: `HriznDirectory::contentHealth(string $tt, string $tid): array` and `HriznDirectory::contentFor(string $tt, string $tid, int $limit = 50): array` — PII-free, tenant-scoped. Bound as a container singleton.

- [ ] **Step 1: Write the failing directory test**

Create `tests/HriznDirectoryTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\HriznDirectory;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

require_once __DIR__.'/hz_bootstrap.php';

beforeEach(function () {
    hzBindTenant(pluginTestUser('rooftop_owner')->id);
    hzRunMigrations();
});

function hzDirContent(array $attrs): HriznContent
{
    return HriznContent::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => (string) Str::uuid(), 'hrizn_content_id' => (string) Str::uuid(),
        'article_type' => 'basic', 'content_intent' => 'variable', 'status' => 'complete',
        'created_by' => (string) Str::uuid(),
    ], $attrs));
}

it('aggregates content health for the tenant', function () {
    hzDirContent(['status' => 'complete', 'content_intent' => 'fixed_ops']);
    hzDirContent(['status' => 'complete', 'content_intent' => 'variable']);
    hzDirContent(['status' => 'generating']);
    hzDirContent(['status' => 'complete', 'compliance_status' => 'flagged']);
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'complete', 'hrizn_id' => 'ic1', 'created_by' => (string) Str::uuid(),
    ]);

    $h = app(HriznDirectory::class)->contentHealth('rooftop', PLUGIN_TEST_TENANT);

    expect($h['publishedLast90'])->toBe(3)          // 3 complete (fixed_ops, variable, flagged)
        ->and($h['pendingContent'])->toBe(1)
        ->and($h['fixedOpsCount'])->toBe(1)
        ->and($h['variableCount'])->toBe(1)
        ->and($h['complianceFlagged'])->toBe(1)
        ->and($h['ideacloudsActive'])->toBe(1)
        ->and($h['daysSinceLastPublish'])->toBe(0);
});

it('lists content PII-free, newest first', function () {
    hzDirContent(['article_type' => 'qa']);
    $rows = app(HriznDirectory::class)->contentFor('rooftop', PLUGIN_TEST_TENANT);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys(['id', 'article_type', 'content_intent', 'status', 'hrizn_content_id', 'created_at'])
        ->and($rows[0])->not->toHaveKey('created_by');
});

it('excludes another tenant\'s rows', function () {
    hzDirContent(['tenant_id' => (string) Str::uuid(), 'status' => 'complete']); // other tenant
    $h = app(HriznDirectory::class)->contentHealth('rooftop', PLUGIN_TEST_TENANT);
    expect($h['publishedLast90'])->toBe(0);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `bash scripts/test-in-app.sh tests/HriznDirectoryTest.php`
Expected: FAIL (`HriznDirectory` does not exist / not bound).

- [ ] **Step 3: Create the directory**

Create `src/HriznDirectory.php`:

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn;

use App\Support\SystemContext;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

/**
 * PII-free outbound content-health seam. Other plugins/pages consume this to show
 * per-rooftop marketing-content health without touching HRIZN's own screens.
 * Reads only HRIZN's two tenant tables, explicitly tenant-filtered under RLS.
 */
class HriznDirectory
{
    /** @return array<string, int|null> */
    public function contentHealth(string $tenantType, string $tenantId): array
    {
        return SystemContext::runAsTenant($tenantType, $tenantId, function () use ($tenantType, $tenantId): array {
            $content = fn () => HriznContent::withoutTenantScope()
                ->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)
                ->whereNull('deleted_at');

            $lastPublish = (clone $content())->where('status', 'complete')->max('updated_at');
            $days = $lastPublish !== null
                ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($lastPublish)->startOfDay())
                : null;

            return [
                'publishedLast90' => (clone $content())->where('status', 'complete')
                    ->where('updated_at', '>=', now()->subDays(90))->count(),
                'daysSinceLastPublish' => $days,
                'pendingContent' => (clone $content())->whereIn('status', ['pending', 'generating'])->count(),
                'fixedOpsCount' => (clone $content())->where('status', 'complete')->where('content_intent', 'fixed_ops')->count(),
                'variableCount' => (clone $content())->where('status', 'complete')->where('content_intent', 'variable')->count(),
                'complianceFlagged' => (clone $content())->whereIn('compliance_status', ['flagged', 'fail', 'pending'])->count(),
                'ideacloudsActive' => HriznIdeacloud::withoutTenantScope()
                    ->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')->count(),
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function contentFor(string $tenantType, string $tenantId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        return SystemContext::runAsTenant($tenantType, $tenantId, fn (): array => HriznContent::withoutTenantScope()
            ->where('tenant_type', $tenantType)->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'article_type', 'content_intent', 'status', 'hrizn_content_id', 'created_at'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'article_type' => $r->article_type,
                'content_intent' => $r->content_intent,
                'status' => $r->status,
                'hrizn_content_id' => $r->hrizn_content_id,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ])->all());
    }
}
```

Note: `withoutTenantScope()` is the model helper `BelongsToTenant` provides (the existing HRIZN tests and `InventoryDirectory` both use it); combined with the explicit tenant `where` under `runAsTenant`, this is the exact sanctioned Directory read pattern — no `TenantScope` class import needed.

- [ ] **Step 4: Bind the singleton in the provider**

In `src/HriznServiceProvider.php` `register()`, alongside the existing `app()->singleton(HriznClientFactory::class)`:

```php
app()->singleton(\Vctrs\Plugins\VbHrizn\HriznDirectory::class);
```

- [ ] **Step 5: Run the directory test to verify it passes**

Run: `bash scripts/test-in-app.sh tests/HriznDirectoryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Firewall audit + commit**

```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain   # MUST be empty
git add src/HriznDirectory.php src/HriznServiceProvider.php tests/HriznDirectoryTest.php
git commit -m "feat(hrizn): HriznDirectory content-health outbound seam (Expand T3)"
```

---

### Task 4: UI vehicle picker + badges + vehicle-search passthrough

**Files:**
- Create: `src/Http/Controllers/VehicleSearchController.php`
- Modify: `src/routes.php` (add the search route)
- Modify: `ui/entry.tsx` (vehicle field + badges)
- Test: `tests/HriznVehicleSearchTest.php` (create), `ui/__tests__/entry.test.tsx` (extend)

**Interfaces:**
- Consumes: `Vctrs\Plugins\InventoryHub\InventoryDirectory`, `HriznRelation` (not required here), the existing `ApiResponse` envelope + `session-api` group.
- Produces: `GET /api/v1/hrizn/vehicles/search?q=` → `{ data: PICKER_FIELDS[] }`.

- [ ] **Step 1: Write the failing passthrough test**

Create `tests/HriznVehicleSearchTest.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__.'/hz_bootstrap.php';

const HZ_INV_DIR_S = 'Vctrs\\Plugins\\InventoryHub\\InventoryDirectory';

beforeEach(function () {
    $this->user = hzFeatureUser(['+hrizn.content.write.rooftop']);
});

it('returns vehicles from the inventory directory for a query', function () {
    app()->instance(HZ_INV_DIR_S, new class
    {
        public function search(string $tt, string $tid, ?string $q = null, ?string $status = 'active', int $limit = 20): array
        {
            return [['vin' => 'VIN1', 'make' => 'Honda', 'model' => 'Accord']];
        }
    });

    $res = $this->actingAs($this->user)->getJson('/api/v1/hrizn/vehicles/search?q=hond')->assertOk()->json();
    expect($res['data'][0]['vin'])->toBe('VIN1');
});

it('returns an empty list when inventory-hub is not bound', function () {
    // Ensure the binding is absent.
    if (app()->bound(HZ_INV_DIR_S)) {
        app()->forgetInstance(HZ_INV_DIR_S);
    }
    $res = $this->actingAs($this->user)->getJson('/api/v1/hrizn/vehicles/search?q=x')->assertOk()->json();
    expect($res['data'])->toBe([]);
});

it('is gated by the content.write permission', function () {
    $viewer = pluginTestUser('rooftop_owner', ['-hrizn.content.write.rooftop']);
    $this->actingAs($viewer)->getJson('/api/v1/hrizn/vehicles/search?q=x')->assertForbidden();
});
```

Note: if `app()->bound(...)` cannot be un-bound cleanly in-process (inventory-hub booted globally), replace the second test's approach by binding a stub whose `search()` returns `[]`; the controller's contract (empty array when unavailable) is what's asserted. The implementer should pick whichever reliably exercises the empty path in this harness and note the choice.

- [ ] **Step 2: Run to verify it fails**

Run: `bash scripts/test-in-app.sh tests/HriznVehicleSearchTest.php`
Expected: FAIL (route 404 / controller missing).

- [ ] **Step 3: Create the controller**

Create `src/Http/Controllers/VehicleSearchController.php`:

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\InventoryHub\InventoryDirectory;

/**
 * Session-API passthrough to the inventory-hub vehicle picker (PICKER_FIELDS,
 * cost-safe, no invoice/msrp). Returns an empty list when inventory-hub is not
 * installed, so the HRIZN content UI degrades gracefully standalone.
 */
class VehicleSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $q = (string) $request->query('q', '');

        if (! app()->bound(InventoryDirectory::class)) {
            return ApiResponse::success([]);
        }

        $vehicles = app(InventoryDirectory::class)->search(
            $ctx->activeTenantType(), $ctx->activeTenantId(), $q !== '' ? $q : null, 'active', 20,
        );

        return ApiResponse::success($vehicles);
    }
}
```

(`App\Support\ApiResponse` is the FQCN the other HRIZN controllers import — matches `ContentController`.)

- [ ] **Step 4: Register the route**

`src/routes.php` uses the `Route::` facade inside `Route::middleware(['web','session-api'])->prefix('api/v1/hrizn')->name('hrizn.api.')->group(function () { ... })`. Add the import at the top with the other controller imports:

```php
use Vctrs\Plugins\VbHrizn\Http\Controllers\VehicleSearchController;
```

and add this route inside the group, near the content routes (after the `content` routes, before `intelligence`):

```php
Route::get('/vehicles/search', [VehicleSearchController::class, 'search'])->middleware('can:hrizn.content.write.rooftop')->name('vehicles.search');
```

- [ ] **Step 5: Run the passthrough test to verify it passes**

Run: `bash scripts/test-in-app.sh tests/HriznVehicleSearchTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Extend the UI (vehicle field + badges) and its Vitest test**

In `ui/entry.tsx` (host-injected React, `R.createElement`, no JSX):
- On the content-generation form, when the selected `articleType` is `modellanding` or `comparison`, render an optional "Vehicle (optional)" input. On input, `apiGet('/vehicles/search?q=' + encodeURIComponent(value))` (via the vendored client) and render up to ~8 suggestions (`{year} {make} {model} {trim} — {vin}`); selecting one sets `vehicleVin` in the generate payload. When the article type isn't vehicle-specific, don't render the field and don't send `vehicleVin`.
- On the content list/show: render a `Badge` "Ready to publish" when `item.status === 'complete'`; on the show view, when `data.linkedVehicles?.length`, render a chip per vehicle `🔗 {year} {make} {model}`.
- Keep all data access through the vendored `ui/plugin-ui/client.ts`; do NOT add a real `import` of React.

Extend `ui/__tests__/entry.test.tsx`:
- Mock the vehicle-search endpoint; assert the vehicle field renders for `modellanding` and NOT for `basic`.
- Assert the "Ready to publish" badge renders for a `complete` content row.
- Assert a `linkedVehicles` chip renders on the show view when present.

- [ ] **Step 7: Run the UI tests**

Run: `cd /home/carmelo/Work/VCTRS/vctrbase-plugins/vb-hrizn && npm run test` (or the repo's Vitest command — check `package.json` `scripts`; use whatever the existing `entry.test.tsx` runs under).
Expected: PASS (existing + new assertions). Then `npm run build` to confirm the ESM lib build still succeeds.

- [ ] **Step 8: Firewall audit + commit**

```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain   # MUST be empty
git add src/Http/Controllers/VehicleSearchController.php src/routes.php ui/entry.tsx ui/__tests__/entry.test.tsx tests/HriznVehicleSearchTest.php
git commit -m "feat(hrizn): vehicle picker + ready/linked badges + vehicle-search passthrough (Expand T4)"
```

---

### Final gate: version bump + CHANGELOG + full suite + whole-branch review

- [ ] **Step 1: Bump version**

In `manifest.json`, change `"version": "1.0.0"` → `"version": "1.1.0"` (author unchanged: `"Angus Fox"`).

- [ ] **Step 2: Prepend CHANGELOG entry**

In `CHANGELOG.md`, prepend:

```markdown
## [1.1.0] - 2026-08-05

### Added
- Content-lifecycle events: a feed event + an assigned "review & publish" task when the HRIZN platform finishes an article; a high-priority feed event on content-generation failure; a "research ready" feed event when an IdeaCloud completes.
- Content↔vehicle linking: `modellanding`/`comparison` articles can be linked to an inventory vehicle by VIN at generation time; linked vehicles surface on content detail (`linkedVehicles`).
- `HriznDirectory` — a PII-free per-rooftop content-health seam (`contentHealth`, `contentFor`) for cross-plugin/manager consumption.
- Content UI: an optional vehicle picker for vehicle-specific article types, "Ready to publish" and linked-vehicle badges, and a `GET /api/v1/hrizn/vehicles/search` passthrough.

### Notes
- All new cross-cutting effects are best-effort and degrade gracefully when inventory-hub is not installed. Zero core changes.
- Intelligence-recommendation events were evaluated and deferred: HRIZN has no recommendation webhook and no local mirror, so proactive rec-events would require added delta-detection state.
```

- [ ] **Step 3: Run the full suite**

Run: `bash scripts/test-in-app.sh`
Expected: all Pest tests green (existing + the 4 new files), including the signed-install/boot and signing-byte-compat tests. Wall-clock in the normal range (~30–60s) — a runaway indicates an accidental DDL/RLS deadlock (see the harness note in the spec).

- [ ] **Step 4: Run the UI suite + build**

Run: `cd /home/carmelo/Work/VCTRS/vctrbase-plugins/vb-hrizn && npm run test && npm run build`
Expected: Vitest green; ESM lib build succeeds.

- [ ] **Step 5: Firewall audit + commit the gate**

```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain   # MUST be empty
git add manifest.json CHANGELOG.md
git commit -m "chore(hrizn): 1.1.0 — Expand events/linking/directory (batched TP5 hold)"
```

- [ ] **Step 6: Whole-branch review**

Generate the review package for the full branch range (`git merge-base main HEAD`..HEAD) and dispatch the final Opus whole-branch reviewer. Address Critical/Important findings; record Minors in the ledger for owner triage. Do NOT merge until the review is READY TO MERGE and the owner authorizes the local merge.
