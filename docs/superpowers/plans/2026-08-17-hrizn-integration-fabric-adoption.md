# HRIZN Integration-Fabric Adoption — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate hrizn's hand-rolled inbound-webhook receiver fully onto the core inbound-webhook family and record each webhook dispatch as an `IntegrationRun`, plus fold in two hygiene items.

**Architecture:** hrizn has no cron/jobs; its lifecycle is 100% inbound-webhook-driven. The receiver becomes core-owned: the SaaS POSTs to core's public `POST /api/webhooks/inbound/{slug}` → `InboundWebhookManager` (resolve tenant from slug, verify HMAC, freshness, dedupe) → fires `InboundWebhookReceived` inside the tenant scope. hrizn attaches a synchronous listener keyed on `routing_key='vb-hrizn'` that records an `IntegrationRun` (start → succeed/fail, **null cadence**) around the six existing handlers. Registration provisions a per-tenant `WebhookEndpoint`; the SaaS-owned signing secret lives on that endpoint (not the plugin namespace).

**Tech Stack:** PHP 8 / Laravel, Pest (via `scripts/test-in-app.sh` Docker harness), Vite/vitest for the (unchanged) UI.

## Global Constraints

- **CORE FIREWALL: ZERO `vctrbase-php` core edits.** After every task, `git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` MUST be empty. All changes live inside `vb-hrizn`.
- **No new tenant table, no migration, no plugin-side RLS.** `integration_runs`, `webhook_endpoints`, `webhook_deliveries` are all core-provisioned by the baseline schema (`docker/postgres/init/01-schema.sql`, FORCE-RLS + policies + indexes). `rls:coverage` posture is unchanged.
- **Models per task:** fable 5 implementer / Opus 4.8 reviewer (never Sonnet).
- **Harness:** `bash scripts/test-in-app.sh <target>`. Files are authored at plugin `tests/<File>.php`; the run-target path inside the mounted worktree is `tests/Feature/Plugins/VbHrizn/<File>.php`. The mounted `vctrbase-php-hrizn-test` worktree MUST be on core `f7ad1f7`. hrizn has no cron → no deadlock risk. The harness restores the private test DB from `01-schema.sql`, so the baseline Fabric tables are present.
- **Namespace:** `Vctrs\Plugins\VbHrizn`. **Version bump:** `manifest.json` `1.1.1 → 1.2.0` (Task 4 only; `package.json` has no version field).
- **HALT AT READY-TO-RELEASE.** Do NOT merge/push/tag/publish.

---

## Pre-flight (once, before Task 1)

- [ ] **Confirm the core test worktree is on `f7ad1f7`:**

Run:
```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php-hrizn-test rev-parse HEAD 2>/dev/null || echo MISSING
```
Expected: `f7ad1f781ddae1550d39bdbcd7e9cc81f3410aeb`. If it prints anything else or `MISSING`, recreate it:
```bash
git -C /home/carmelo/Work/VCTRS/vctrbase-php worktree remove --force /home/carmelo/Work/VCTRS/vctrbase-php-hrizn-test 2>/dev/null || true
git -C /home/carmelo/Work/VCTRS/vctrbase-php worktree add --detach /home/carmelo/Work/VCTRS/vctrbase-php-hrizn-test f7ad1f7
```

- [ ] **Baseline the full suite is green before touching anything:**

Run: `bash scripts/test-in-app.sh` — Expected: all pass (this is the pre-change baseline). If anything is red pre-change, STOP and report — do not build on a red baseline.

---

### Task 1: Hygiene — swap `HriznRelation::COVERS` → core `EntityRelation::COVERS`

The relation verb `'covers'` now has a canonical core home (`App\Support\EntityRelation::COVERS`, byte-identical value). Source/target-type + feed-event + label vocab stays in `HriznRelation` (no core equivalent). Zero behavior change (same string on disk).

**Files:**
- Modify: `src/Http/Controllers/ContentController.php` (link call ~line 227)
- Modify: `src/Support/HriznRelation.php` (drop `COVERS` const + update doc-comment)
- Test: `tests/HriznContentLinkTest.php` (assert on `EntityRelation::COVERS`)

**Interfaces:**
- Consumes: `App\Support\EntityRelation::COVERS` (core, value `'covers'`).
- Produces: nothing new; `HriznRelation` retains `CONTENT_SOURCE_TYPE`, `IDEACLOUD_SOURCE_TYPE`, `VEHICLE_TARGET_TYPE`, `PLUGIN_NAMESPACE`, `FEED_*`, `articleLabel()`.

- [ ] **Step 1: Update the failing test first**

In `tests/HriznContentLinkTest.php`, add the import near the top (after the existing `use` lines):
```php
use App\Support\EntityRelation;
```
Then replace every `HriznRelation::COVERS` with `EntityRelation::COVERS` (the `where('relation', …)` filter and the two `->and($ref->relation)->toBe(…)` assertions). Leave `HriznRelation::CONTENT_SOURCE_TYPE` and `HriznRelation::VEHICLE_TARGET_TYPE` untouched.

- [ ] **Step 2: Run the test — it should FAIL to compile/resolve**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznContentLinkTest.php`
Expected: FAIL — the controller still writes via `HriznRelation::COVERS` (const still present) but that's fine; the real failure driver is Step 3. (If it PASSES here because both consts equal `'covers'`, that is acceptable — the swap is a value-preserving refactor; proceed to Step 3 to complete the source change.)

- [ ] **Step 3: Update the source**

In `src/Http/Controllers/ContentController.php`, add `use App\Support\EntityRelation;` alongside the existing `use App\Support\EntityReferenceService;`, and change the link call's relation argument from `HriznRelation::COVERS` to `EntityRelation::COVERS` (the line reads `HriznRelation::COVERS, $ctx->userId(),` → `EntityRelation::COVERS, $ctx->userId(),`).

In `src/Support/HriznRelation.php`, delete the `COVERS` const and its doc-comment block:
```php
    /** Relation verb: a content piece covers a specific vehicle. */
    public const COVERS = 'covers';
```
and update the class doc-comment to:
```php
/**
 * Plugin-local type & event vocabulary. The cross-plugin relation VERB now comes
 * from core (App\Support\EntityRelation::COVERS); what remains here is the
 * EntityReference source/target TYPE strings, the feed event types, and the article
 * labels — none of which have a core registry. Core does not validate the relation
 * string on EntityReferenceService::link(), so these stay plugin-owned.
 */
```

- [ ] **Step 4: Run the test — PASS**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznContentLinkTest.php`
Expected: PASS (all 4 link tests green).

- [ ] **Step 5: Firewall audit + commit**

Run: `git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` — Expected: empty.
```bash
git add src/Http/Controllers/ContentController.php src/Support/HriznRelation.php tests/HriznContentLinkTest.php
git commit -m "refactor(hrizn): use core EntityRelation::COVERS for content↔vehicle link (byte-identical)"
```

---

### Task 2: Migrate the inbound receiver onto core + record each dispatch as an IntegrationRun

Delete hrizn's hand-rolled receiver/verifier/route; move the six handlers into a synchronous `InboundWebhookReceived` listener wrapped in `IntegrationRunRecorder`.

**Files:**
- Create: `src/Listeners/HandleInboundWebhook.php`
- Modify: `src/HriznServiceProvider.php` (register the listener)
- Modify: `src/routes.php` (remove the public webhook route + import)
- Delete: `src/Http/Controllers/WebhookController.php`
- Delete: `src/Support/HriznWebhookSignature.php`
- Test (rewrite): `tests/HriznWebhookTest.php` (inbound-delivery section only; leave the two `registerWebhook` `it()`s at the top untouched — Task 3 rewrites those)
- Delete: `tests/HriznWebhookRlsTest.php` (guards a pre-tenant secret read that no longer exists in hrizn — core's manager owns it now; `HriznRlsIsolationTest` still covers the hrizn tables)

**Interfaces:**
- Consumes: `App\Events\InboundWebhookReceived` (`public readonly` fields `endpointId, routingKey, tenantType, tenantId, array $payload`); `App\Support\Integration\IntegrationRunRecorder::start(string $integrationType, ?string $targetRef, ?string $triggeredBy): IntegrationRun`; `App\Models\IntegrationRun::succeed(array $stats=[], ?int $cadenceSeconds=null)` / `->fail(string|Throwable $error, ?int $cadenceSeconds=null)`.
- Produces: `Vctrs\Plugins\VbHrizn\Listeners\HandleInboundWebhook` with `public function handle(InboundWebhookReceived $event): void` and `protected function dispatch(string $type, array $data): ?int` (protected so a test subclass can force a throw). Integration-run identity: `integration_type='hrizn_webhook'`, `target_ref=<event type>`, `triggered_by='webhook'`, **null cadence**.

- [ ] **Step 1: Write the new listener (full file)**

Create `src/Listeners/HandleInboundWebhook.php`:
```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Listeners;

use App\Events\FeedEventRequested;
use App\Events\InboundWebhookReceived;
use App\Events\TaskRequested;
use App\Support\Integration\IntegrationRunRecorder;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznRelation;

/**
 * Synchronous listener for the core inbound-webhook event. The core
 * InboundWebhookManager has already resolved the tenant from the opaque slug,
 * verified the HMAC over the raw body, enforced freshness, and deduped replays —
 * and fires this event INSIDE the resolved tenant scope. We only act on our own
 * deliveries (routing_key = 'vb-hrizn') and record each dispatch as an
 * IntegrationRun (start → succeed/fail, no cadence: hrizn's inbound traffic is
 * sporadic, so silence-detection is meaningless — the value is the fail/success
 * ledger). NOT ShouldQueue: hrizn has no queue/jobs.
 *
 * Ports the six handlers from the retired WebhookController::dispatch verbatim.
 */
class HandleInboundWebhook
{
    public function handle(InboundWebhookReceived $event): void
    {
        if ($event->routingKey !== HriznRelation::PLUGIN_NAMESPACE) {
            return; // not ours
        }

        $payload = $event->payload;
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;
        if ($type === null) {
            return; // core accepted a JSON body without our envelope shape
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $run = app(IntegrationRunRecorder::class)->start('hrizn_webhook', $type, 'webhook');
        try {
            $rows = $this->dispatch($type, $data);
            $run->succeed(['type' => $type, 'rows' => (int) $rows]);

            if ($rows === 0) {
                $id = $data['article_id'] ?? $data['ideacloud_id'] ?? 'unknown';
                Log::warning("[hrizn] {$type}: 0 rows updated (id={$id})");
            }
        } catch (\Throwable $e) {
            // Core deduped this delivery BEFORE firing, so a re-throw → 500 → sender
            // retry would just be a Duplicate (no event, handler never re-runs). The
            // failed run IS the observable record; swallow so the request still ACKs.
            $run->fail($e);
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function dispatch(string $type, array $data): ?int
    {
        return match ($type) {
            'ideacloud.completed' => $this->onIdeacloudCompleted($data),
            'ideacloud.failed' => $this->setIdeacloudStatus($data, 'failed'),
            'content.progress' => $this->onContentProgress($data),
            'content.completed' => $this->onContentCompleted($data),
            'content.failed' => $this->onContentFailed($data),
            'compliance.completed' => $this->onComplianceCompleted($data),
            default => null, // content_tools.completed + others: acknowledged, no local write
        };
    }

    /** @param array<string, mixed> $data */
    private function setIdeacloudStatus(array $data, string $status): int
    {
        $hriznId = $data['ideacloud_id'] ?? null;
        if (is_string($hriznId) && $hriznId !== '') {
            return HriznIdeacloud::query()->where('hrizn_id', $hriznId)->update(['status' => $status]);
        }

        return 0;
    }

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

    /** @param array<string, mixed> $data */
    private function onContentProgress(array $data): int
    {
        $id = $data['article_id'] ?? null;
        if (is_string($id) && $id !== '') {
            return HriznContent::query()->where('hrizn_content_id', $id)->update([
                'status' => 'generating',
                'progress_percent' => (int) ($data['progress_percent'] ?? 0),
                'progress_stage' => $data['stage'] ?? null,
            ]);
        }

        return 0;
    }

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

    /** @param array<string, mixed> $data */
    private function onComplianceCompleted(array $data): int
    {
        $id = $data['article_id'] ?? null;
        if (is_string($id) && $id !== '') {
            return HriznContent::query()->where('hrizn_content_id', $id)->update([
                'compliance_status' => $data['overall_status'] ?? null,
                'compliance_score' => isset($data['overall_score']) ? (int) $data['overall_score'] : null,
            ]);
        }

        return 0;
    }
}
```

- [ ] **Step 2: Register the listener; delete the old receiver, verifier, and route**

In `src/HriznServiceProvider.php`, add imports at the top:
```php
use App\Events\InboundWebhookReceived;
use Illuminate\Support\Facades\Event;
use Vctrs\Plugins\VbHrizn\Listeners\HandleInboundWebhook;
```
and inside `register()`, after the `AuditableRegistry::register(...)` lines, add:
```php
        Event::listen(InboundWebhookReceived::class, [HandleInboundWebhook::class, 'handle']);
```

In `src/routes.php`, delete the `use Vctrs\Plugins\VbHrizn\Http\Controllers\WebhookController;` import and the entire public-webhook block (the `// ── Public inbound Hrizn webhook receiver …` comment plus the `Route::middleware('api')->post('/integrations/hrizn/webhook/{token}', …)->name('hrizn.webhook.receive');` statement).

Delete the files:
```bash
git rm src/Http/Controllers/WebhookController.php src/Support/HriznWebhookSignature.php tests/HriznWebhookRlsTest.php
```

- [ ] **Step 3: Rewrite the inbound-delivery tests**

In `tests/HriznWebhookTest.php`: KEEP the top two `it('registerWebhook …')` tests and their imports untouched (Task 3 owns those). REMOVE the old helpers `seedHriznWebhookToken()` and `postHriznWebhook()` and every delivery `it()` below them (the bad-signature, unknown-token, ideacloud.completed, content multi-event, and 0-rows tests). Add these imports at the top if absent:
```php
use App\Models\IntegrationRun;
use App\Models\WebhookEndpoint;
use Vctrs\Plugins\VbHrizn\Listeners\HandleInboundWebhook;
use App\Events\InboundWebhookReceived;
```
Then append the new helpers + tests:
```php
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
```
Also update the file's top doc-comment block (lines ~14–18) that describes the old public receiver path — replace the mention of `/integrations/hrizn/webhook/{token}` and "RAW ({ok:true}/{message})" with a note that inbound deliveries now go through core's `/api/webhooks/inbound/{slug}` (202/400) and are handled by `HandleInboundWebhook`.

- [ ] **Step 4: Run the webhook suite — expect green**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznWebhookTest.php`
Expected: PASS — the two untouched `registerWebhook` tests plus all new inbound/run/dedupe/failed-run/routing tests. If the failed-run or routing unit tests error on tenant scope, ensure `hzBindTenant(...)` precedes `handle()` (the recorder stamps the tenant from the bound `TenantContext`).

- [ ] **Step 5: Run boot + full-suite smoke — expect green**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/SignedInstallBootTest.php` then `bash scripts/test-in-app.sh`
Expected: both PASS (deleting `WebhookController`/`HriznWebhookSignature` changes the signed byte content, but the signing tests hash the current `src/` at runtime, so they stay valid).

- [ ] **Step 6: Firewall audit + commit**

Run: `git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` — Expected: empty.
```bash
git add -A
git commit -m "feat(hrizn): migrate inbound webhook onto core InboundWebhookManager + record each dispatch as an IntegrationRun"
```

---

### Task 3: Registration flow — provision WebhookEndpoint, store SaaS secret on it, deactivate on disconnect

**Files:**
- Modify: `src/Http/Controllers/SettingsController.php` (`registerWebhook`, `removeApiKey`)
- Test: `tests/HriznWebhookTest.php` (rewrite the two top `registerWebhook` `it()`s)
- Test: `tests/HriznSettingsTest.php` (rewrite the `removeApiKey` test to also assert endpoint deactivation)

**Interfaces:**
- Consumes: `App\Models\WebhookEndpoint::provision(string $tenantType, string $tenantId, string $routingKey, array $overrides=[]): self` (creates active endpoint, CSPRNG slug + secret); the model's `secrets` (`EncryptedJsonKeys` cast) and `slug`/`status` attributes; the core route name `webhooks.inbound` (path `/api/webhooks/inbound/{slug}`); `App\Support\OutboundUrl::assertSafe`.
- Produces: `registerWebhook` persists the SaaS secret onto the tenant's `WebhookEndpoint` (`routing_key='vb-hrizn'`), keeps `webhookId`/`webhookRegisteredAt` in `HriznNamespace` (drops `webhookSecret`), and sends the SaaS a callback URL of the form `<origin>/api/webhooks/inbound/<slug>`. `removeApiKey` sets that endpoint `status='inactive'`.

- [ ] **Step 1: Rewrite the two `registerWebhook` tests (write the failing tests first)**

In `tests/HriznWebhookTest.php`, replace the two top `it('registerWebhook …')` tests with:
```php
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
```
(`WebhookEndpoint` and `TenantContext` are already imported from Task 2 / the existing file; confirm `use App\Support\TenantContext;` and `use App\Models\WebhookEndpoint;` are present.)

- [ ] **Step 2: Run — expect FAIL**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznWebhookTest.php "--filter=registerWebhook"`
Expected: FAIL — current `registerWebhook` still writes `webhookSecret` to the namespace and sends the operator's raw URL (no endpoint provisioned).

- [ ] **Step 3: Rewrite `SettingsController::registerWebhook`**

Add imports to `src/Http/Controllers/SettingsController.php`:
```php
use App\Models\WebhookEndpoint;
```
Replace the body of `registerWebhook` with (the method signature/route are unchanged):
```php
    public function registerWebhook(Request $request): JsonResponse
    {
        $request->validate(['callbackUrl' => ['sometimes', 'string', 'url']]);
        $ctx = app(TenantContext::class);
        $tt = $ctx->activeTenantType();
        $tid = $ctx->activeTenantId();

        // Optional origin override (reverse-proxy/tunnel): scheme+host[:port] only —
        // the path + slug always come from core's inbound route so routing can't break.
        $settings = app(PluginSettings::class)->resolve('vb-hrizn');
        $settingUrl = is_string($settings['webhookCallbackUrl'] ?? null) && $settings['webhookCallbackUrl'] !== ''
            ? $settings['webhookCallbackUrl'] : ($request->input('callbackUrl') ?: null);

        return HriznResponse::guard(function () use ($ctx, $tt, $tid, $settingUrl) {
            // Fetch-or-provision this tenant's inbound endpoint (one, keyed by routing_key).
            $endpoint = WebhookEndpoint::query()->where('routing_key', 'vb-hrizn')->where('status', 'active')->first()
                ?? WebhookEndpoint::provision($tt, $tid, 'vb-hrizn');

            $path = route('webhooks.inbound', ['slug' => $endpoint->slug], absolute: false);
            $origin = null;
            if ($settingUrl !== null) {
                $u = parse_url($settingUrl);
                if (isset($u['scheme'], $u['host'])) {
                    $origin = $u['scheme'].'://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '');
                }
            }
            $origin ??= rtrim((string) config('app.url'), '/');
            $callbackUrl = OutboundUrl::assertSafe($origin.$path);

            $client = $this->clients->for($tt, $tid);
            $ns = HriznNamespace::get($tt, $tid);

            // Replace an existing SaaS webhook (best-effort).
            if (is_string($ns['webhookId'] ?? null) && $ns['webhookId'] !== '') {
                try {
                    $client->deleteWebhook($ns['webhookId']);
                } catch (\Throwable) {
                    // ignore — registering a fresh one supersedes it
                }
            }

            $webhook = $client->createWebhook([
                'url' => $callbackUrl,
                'events' => [
                    'ideacloud.completed', 'ideacloud.failed', 'content.progress',
                    'content.completed', 'content.failed', 'compliance.completed', 'content_tools.completed',
                ],
            ]);

            // The SaaS owns the signing secret — persist it ONTO the endpoint (encrypted
            // by the cast), NOT in the plugin namespace. Keep webhookId for delete/test.
            $endpoint->update(['secrets' => ['signing_secret' => (string) ($webhook['secret'] ?? '')]]);
            HriznNamespace::patch($tt, $tid, [
                'webhookId' => $webhook['id'] ?? null,
                'webhookRegisteredAt' => now()->toIso8601String(),
            ]);

            return ApiResponse::success(['success' => true, 'webhookId' => $webhook['id'] ?? null]);
        });
    }
```
(Note: the old 412 "No webhook callback URL configured" path is gone — the URL is always derivable from the slug + app.url or the override; a non-routable origin surfaces via `OutboundUrl::assertSafe` through `HriznResponse::guard`.)

- [ ] **Step 4: Run — expect PASS**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznWebhookTest.php`
Expected: PASS (both registerWebhook variants + all Task 2 inbound tests).

- [ ] **Step 5: Add endpoint deactivation to `removeApiKey`; update its test**

In `SettingsController::removeApiKey`, change the transaction body to also deactivate the endpoint:
```php
    public function removeApiKey(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        DB::transaction(function () use ($ctx) {
            HriznNamespace::clear($ctx->activeTenantType(), $ctx->activeTenantId());
            WebhookEndpoint::query()->where('routing_key', 'vb-hrizn')->update(['status' => 'inactive']);
            AuditContext::tag('hrizn.settings.removeApiKey');
        });

        return ApiResponse::success(['success' => true]);
    }
```
In `tests/HriznSettingsTest.php`, add `use App\Models\WebhookEndpoint;` at the top and replace the `removeApiKey clears the stored key` test with:
```php
it('removeApiKey clears the key and deactivates the tenant webhook endpoint', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_a']);
    WebhookEndpoint::provision('rooftop', PLUGIN_TEST_TENANT, 'vb-hrizn');

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson('/api/v1/hrizn/settings/api-key')->assertOk()->assertJson(['data' => ['success' => true]]);

    expect(HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT))->toBe([]);
    expect(WebhookEndpoint::query()->where('routing_key', 'vb-hrizn')->firstOrFail()->status)->toBe('inactive');
});
```

- [ ] **Step 6: Run the settings suite — expect PASS**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbHrizn/HriznSettingsTest.php`
Expected: PASS (all settings tests incl. the updated removeApiKey).

- [ ] **Step 7: Firewall audit + commit**

Run: `git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` — Expected: empty.
```bash
git add -A
git commit -m "feat(hrizn): provision core WebhookEndpoint on register, hold SaaS secret on endpoint, deactivate on disconnect"
```

---

### Task 4: Version bump, CHANGELOG, full gate, ledger + handoff

**Files:**
- Modify: `manifest.json` (version)
- Modify: `CHANGELOG.md`
- Create: `.superpowers/sdd/progress.md`

- [ ] **Step 1: Bump the version**

In `manifest.json`, change `"version": "1.1.1"` → `"version": "1.2.0"`.

- [ ] **Step 2: Add the CHANGELOG entry**

Prepend to `CHANGELOG.md` (after the `# Changelog` header block), matching the existing style:
```markdown
## [1.2.0] - 2026-08-17

### Changed
- Inbound webhooks now run entirely on core's Integration Fabric. The hand-rolled
  receiver, signature verifier, and public route (`/integrations/hrizn/webhook/{token}`)
  are removed; the HRIZN platform now posts to core's public ingress
  (`/api/webhooks/inbound/{slug}` → `InboundWebhookManager`), which resolves the tenant
  from an opaque per-tenant `WebhookEndpoint` slug, verifies the HMAC (core
  `HmacSha256Verifier`, `sha256=` scheme unchanged), enforces freshness, and dedupes
  replays. A synchronous listener (`HandleInboundWebhook`) runs the six lifecycle
  handlers inside the resolved tenant scope. **Operators must re-register the webhook**
  (Settings → register) to obtain the new slug-based callback URL.
- Each inbound webhook dispatch is recorded as a core `IntegrationRun`
  (`integration_type='hrizn_webhook'`, no cadence), so failed processing is now a
  durable, tenant-scoped record instead of a log line only.
- The per-tenant webhook signing secret moved out of the encrypted plugin namespace
  onto the core `WebhookEndpoint` (`secrets.signing_secret`, encrypted at rest);
  `removeApiKey` deactivates the endpoint so a disconnected tenant stops accepting
  deliveries.
- The content↔vehicle link relation verb now comes from core
  (`App\Support\EntityRelation::COVERS`); byte-identical on disk.

### Notes
- Zero core changes. No new plugin tables/migrations — the Fabric tables
  (`integration_runs`, `webhook_endpoints`, `webhook_deliveries`) are core-provisioned.
```

- [ ] **Step 3: Run the FULL gate**

Run each; all must be green:
```bash
bash scripts/test-in-app.sh                 # full Pest suite (incl. signing/byte-compat)
```
Then the plugin's static + UI checks (from repo root):
```bash
./vendor/bin/pint --test        # if vendor present locally; otherwise run pint --test inside the harness container
npm ci && npm run build         # UI build (unchanged, must stay green)
npm run test --if-present       # vitest (unchanged)
```
Expected: Pest all-pass; `pint --test` clean; build succeeds; vitest passes. If `pint`/`npm` are not available on the host, run them via the same Docker `app` service used by `test-in-app.sh`. Record the exact commands + results in the ledger.

- [ ] **Step 4: Final firewall audit**

Run: `git -C /home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` — Expected: EMPTY. Paste the (empty) output into the ledger as firewall proof.

- [ ] **Step 5: Write the SDD ledger + handoff**

Create `.superpowers/sdd/progress.md` capturing: the four tasks and their commits; the firewall proof (empty core porcelain); the full-gate results; the proposed version bump (1.2.0); the two release gates (TP-5 batched release; host core must DEPLOY rc4+ first); and residuals (no data migration performed — dev/pre-v1, DB re-provisioned fresh; freshness/timestamp header not adopted; overdue detection intentionally not armed). End with a one-paragraph handoff.

- [ ] **Step 6: Commit (do NOT push/tag/merge)**
```bash
git add manifest.json CHANGELOG.md .superpowers/sdd/progress.md
git commit -m "chore(hrizn): 1.2.0 — Integration Fabric adoption (run recording + core inbound-webhook migration)"
```

**STOP.** Leave the reviewed branch + ledger + handoff. Do not merge, push, tag, or publish.

---

## Self-Review

**Spec coverage:**
- Full inbound-webhook migration (delete receiver/verifier/route; core ingress + endpoint + listener) → Task 2 + Task 3. ✓
- IntegrationRun recording (event-shaped, null cadence, no secrets, failed-run + ACK) → Task 2. ✓
- WebhookEndpoint provisioning + SaaS-secret-on-endpoint + origin-override callback URL → Task 3. ✓
- removeApiKey deactivation → Task 3. ✓
- HriznRelation::COVERS → EntityRelation::COVERS → Task 1. ✓
- StaffDirectory mocks: verified nonexistent — no task needed (recorded in spec + ledger). ✓
- No new table / migration / RLS; zero core edits; 1.2.0 → Global Constraints + Task 4. ✓
- HriznWebhookRlsTest deletion (guards retired code) → Task 2. ✓

**Placeholder scan:** No TBD/TODO; every code step shows full code; every run step shows the command + expected result. ✓

**Type consistency:** `HandleInboundWebhook::handle(InboundWebhookReceived)` / `protected dispatch(string,array): ?int`; `IntegrationRun::succeed(array,?int)`/`fail(string|Throwable,?int)`; `WebhookEndpoint::provision(string,string,string,array)`; run identity strings (`'hrizn_webhook'`, `target_ref=<type>`, `triggered_by='webhook'`) consistent across listener + tests. Endpoint `routing_key='vb-hrizn'` consistent across provision/query/deactivate. ✓
