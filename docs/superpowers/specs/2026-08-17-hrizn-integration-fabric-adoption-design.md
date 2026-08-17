# HRIZN Integration-Fabric Adoption — Design

**Date:** 2026-08-17
**Repo:** `vb-hrizn` (own git repo, released `main`==`origin` at tag `v1.1.1`, namespace
`Vctrs\Plugins\VbHrizn`, distribution=extracted).
**Host core baseline:** `vctrbase-php` master `f7ad1f7` (tag `v1.0.0-rc.4`).
**Process:** vctrbase-core-dev spine (recon → brainstorm/design → writing-plans →
subagent-driven-development). **Models:** fable 5 implementer / Opus 4.8 reviewer every task.
**Proposed version:** `1.1.1 → 1.2.0` (minor).

## Goal

Adopt the now-shipped core **Integration Fabric** in hrizn. hrizn is unusual: **no jobs, no
cron** — its lifecycle is 100% inbound-webhook-driven. So "Fabric adoption" here is
**event-shaped**, and it comes in two moves:

1. **Migrate the inbound webhook receiver fully onto the core inbound-webhook family**
   (`InboundWebhookManager` / `WebhookEndpoint` / `HmacSha256Verifier` / the core public
   ingress route + `InboundWebhookReceived` event), deleting hrizn's hand-rolled receiver,
   signature verifier, and public route. Owner decision (2026-08-17): full migration, nothing
   hand-rolled, prove the concept end-to-end. **No data migration** — dev / pre-v1 / RC, the DB
   can be re-provisioned fresh; nobody is running this.
2. **Record each inbound webhook's dispatch as an `IntegrationRun`** via
   `IntegrationRunRecorder` (start → succeed/fail), so failed webhook processing becomes a
   durable, tenant-scoped, queryable record instead of dying in a `Log` line.

Plus two hygiene items folded in.

## Grounding (verified against code at core `f7ad1f7`)

- **All Fabric tables are core-provisioned by the baseline schema**
  (`docker/postgres/init/01-schema.sql`): `integration_runs`, `webhook_endpoints`,
  `webhook_deliveries` each ship with `FORCE ROW LEVEL SECURITY` + a `*_tenant_isolation`
  policy + identity/overdue indexes. **hrizn adds no table, no migration, no plugin-side RLS.**
- **Core ships the whole inbound ingress**: `POST /api/webhooks/inbound/{slug}` →
  `App\Http\Controllers\Webhook\InboundWebhookController` → `InboundWebhookManager::handle(slug,
  rawBody, headers)`, which resolves the endpoint from the opaque slug (via `runUnscoped` +
  `withoutTenantScope`), then — inside `runAsTenant` — verifies the signature over the raw body,
  enforces the optional freshness window, dedupes against replay (`webhook_deliveries` unique
  `(endpoint_id, dedupe_key)` + `insertOrIgnore`), and fires `InboundWebhookReceived` on the
  first fresh delivery. The controller maps `Accepted`/`Duplicate` → 202, `WebhookException` →
  400, unexpected → 500.
- **`HmacSha256Verifier` is registered by default** in the `WebhookVerifierRegistry` singleton
  (`AppServiceProvider`), and its doc-comment states it accepts the signature "with or without a
  leading `sha256=` … the historical hrizn scheme." hrizn always sends `X-Webhook-Signature:
  sha256=<hex>`, so the switch is byte-for-byte equivalent on real traffic.
- **`WebhookEndpoint::provision($tt,$tid,$routingKey,$overrides=[])`** creates an active endpoint
  with a CSPRNG `slug` + CSPRNG `signing_secret`, `verifier='hmac-sha256'`,
  `signature_header='X-Webhook-Signature'`, `tolerance_seconds=300`, inside the tenant scope. The
  `signing_secret` lives encrypted in `secrets` jsonb (`EncryptedJsonKeys` cast) and is `$hidden`
  from serialization. `InboundWebhookManager` only resolves `status='active'` endpoints.
- **`InboundWebhookReceived`** carries `(endpointId, routingKey, tenantType, tenantId, payload)`;
  `payload` is the decoded JSON body. It is fired **inside the resolved tenant scope**, so a
  synchronous (non-`ShouldQueue`) listener runs already-scoped to the tenant — no queue needed,
  which fits hrizn's no-jobs nature.
- **`IntegrationRunRecorder`** is container-auto-resolved. Pattern (from core's own
  `2026-08-16-oauth-run-recording-design.md`): `$run = $recorder->start(type, targetRef,
  triggeredBy)` → `$run->succeed($stats, $cadence?)` / `$run->fail($error, $cadence?)`. A **null
  cadence leaves `expected_next_at` NULL → the run is excluded from `overdue()`**. `error_message`
  is truncated to 1000 chars; callers must pass no secret material.
- **`EntityRelation::COVERS = 'covers'`** exists in core and is byte-identical to
  `HriznRelation::COVERS`. hrizn uses `HriznRelation::COVERS` at exactly two sites
  (`ContentController::generate` link call; `HriznContentLinkTest` assertions).
- **hrizn has ZERO `StaffDirectory` references** in `src/` or `tests/` (verified by grep). The
  cross-plugin carry-note's "5 untouched plugins likely carry stale 3-arg `StaffDirectory::lookup`
  mocks" **does not apply to hrizn** — nothing to fix.
- **The SaaS owns the signing secret**: `HriznClient::createWebhook({url, events})` returns
  `{id, secret}` (SaaS-generated). This shapes the registration flow (below).

## Design

### 1. Delete the hand-rolled receiver

- Delete `src/Http/Controllers/WebhookController.php`.
- Delete `src/Support/HriznWebhookSignature.php`.
- Remove the public route block (`routes.php:50–54`, `POST /integrations/hrizn/webhook/{token}`)
  and the now-unused `WebhookController` import. The SaaS will POST to core's
  `/api/webhooks/inbound/{slug}` instead.

The six handlers (`onIdeacloudCompleted`, `setIdeacloudStatus`, `onContentProgress`,
`onContentCompleted` + `emitContentReady`, `onContentFailed`, `onComplianceCompleted`) and the
"0 rows updated" warning move into the new listener (below), essentially verbatim — the only
change is reading `$type`/`$data` from the event's `payload` instead of the request body.

### 2. New listener `src/Listeners/HandleInboundWebhook.php` (synchronous)

Registered in `HriznServiceProvider::register()`:
`Event::listen(InboundWebhookReceived::class, [HandleInboundWebhook::class, 'handle']);`
(not `ShouldQueue` — hrizn has no queue/jobs; the manager fires the event synchronously inside
the tenant scope, so the listener runs already-scoped).

```php
public function handle(InboundWebhookReceived $event): void
{
    if ($event->routingKey !== HriznRelation::PLUGIN_NAMESPACE) {
        return; // not ours
    }

    $payload = $event->payload;
    $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;
    if ($type === null) {
        return; // malformed — core accepted a JSON body without our envelope shape
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    // ── Integration Fabric: record this dispatch as a run (no cadence). ──
    $run = app(IntegrationRunRecorder::class)->start('hrizn_webhook', $type, 'webhook');
    try {
        $rows = $this->dispatch($type, $data);          // int|null; the 6 handlers
        $run->succeed(['type' => $type, 'rows' => (int) $rows]);
        if ($rows === 0) {
            $id = $data['article_id'] ?? $data['ideacloud_id'] ?? 'unknown';
            Log::warning("[hrizn] {$type}: 0 rows updated (id={$id})");
        }
    } catch (\Throwable $e) {
        $run->fail($e);   // null cadence, ≤1000 chars, no secret material
        report($e);
        // Swallow: core already deduped this delivery BEFORE firing the event, so a
        // re-thrown exception → 500 → SaaS retry would just be a Duplicate (no event,
        // handler never re-runs). The failed run IS the observable record.
    }
}
```

- **Run identity:** `integration_type = 'hrizn_webhook'`, `target_ref = <event type>`
  (e.g. `content.completed`), `triggered_by = 'webhook'`. **Null cadence** →
  `expected_next_at` NULL → excluded from `overdue()`/`overdueAcrossTenants()` sweeps. Correct:
  webhooks are legitimately sporadic (a dealer may generate nothing for weeks); silence detection
  would be false-alarm noise. The value is the **success/fail ledger**, not silence detection.
- **stats** carry only `type` + `rows` — never the payload, never a secret.
- The recorder auto-stamps the run to the tenant from the ambient `TenantContext` (set by the
  manager's `runAsTenant`), so runs are tenant-scoped and RLS-fenced.
- **hrizn only records deliveries that passed the manager's verify + dedupe AND match its
  routing_key** — i.e. the dispatch phase it owns. Signature failures / unknown slugs / replays
  are handled (and rejected) by core before the event ever fires; hrizn neither sees nor records
  them, and could not (no tenant is resolved for hrizn's code in those cases).

### 3. Registration flow — `SettingsController::registerWebhook`

The SaaS generates the signing secret, so provision the endpoint first (for its slug), then
persist the SaaS secret onto it:

1. **Fetch-or-provision** the tenant's endpoint: the active `WebhookEndpoint` with
   `routing_key='vb-hrizn'`; if none, `WebhookEndpoint::provision($tt,$tid,'vb-hrizn')`. Get its
   `slug`.
2. **Construct the callback URL** from the slug: `route('webhooks.inbound', ['slug'=>$slug],
   absolute: true)`. The existing `webhookCallbackUrl` cascaded setting becomes an **origin
   override** — when set, swap the scheme/host/port onto core's `/api/webhooks/inbound/{slug}`
   path (for reverse-proxy/tunnel setups) rather than replacing the whole URL, so the slug
   routing can't be broken. `OutboundUrl::assertSafe(...)` still guards the final URL.
3. **`createWebhook({url, events})`** → `{id, secret}` (7 event types, unchanged list).
4. **Persist**: write the SaaS secret onto the endpoint —
   `$endpoint->update(['secrets' => ['signing_secret' => $secret]])` (re-encrypted by the cast).
   Keep `webhookId` + `webhookRegisteredAt` in `HriznNamespace` (still needed for
   `deleteWebhook`/`testWebhook`). **Drop `webhookSecret` from the namespace** — the endpoint now
   owns it.
5. **Replace-existing** stays: if a prior `webhookId` exists, `deleteWebhook` best-effort first.

`testWebhook` and `settings.get` are unchanged (both key off `webhookId` in the namespace).

### 4. Disconnect — `SettingsController::removeApiKey`

In addition to clearing the namespace secret blob, **mark the tenant's `WebhookEndpoint`
`status='inactive'`** (owner decision, 2026-08-17). Core's manager resolves only `active`
endpoints, so a disconnected tenant immediately stops accepting inbound deliveries — no stale
endpoint lingers. A later `registerWebhook` fetch-or-provision reactivates/re-provisions.

### 5. Hygiene

- **`HriznRelation::COVERS` → `App\Support\EntityRelation::COVERS`** at both sites
  (`ContentController::generate` link call; `HriznContentLinkTest`). Delete the local `COVERS`
  const; update the `HriznRelation` doc-comment to note the relation verb now comes from core,
  while the class retains the source/target-type + feed-event + article-label vocab (which has
  **no** core equivalent). Byte-identical `'covers'` on disk — zero behavior change.
- **StaffDirectory mocks:** none exist in hrizn (verified). Recorded here as a deliberate no-op;
  no code change.

## Firewall (complete blast radius — all inside `vb-hrizn`)

- `src/Http/Controllers/WebhookController.php` *(deleted)*
- `src/Support/HriznWebhookSignature.php` *(deleted)*
- `src/routes.php` (remove public webhook route + import)
- `src/Listeners/HandleInboundWebhook.php` *(new)*
- `src/HriznServiceProvider.php` (register the listener)
- `src/Http/Controllers/SettingsController.php` (registerWebhook + removeApiKey)
- `src/Http/Controllers/ContentController.php` (EntityRelation::COVERS)
- `src/Support/HriznRelation.php` (drop COVERS const, doc-comment)
- tests (below)

**Zero `vctrbase-php` core edits.** After every task: `git -C
/home/carmelo/Work/VCTRS/vctrbase-php status --porcelain` MUST be empty. **No new tenant table**
→ `rls:coverage` posture unchanged; `integration_runs` / `webhook_endpoints` /
`webhook_deliveries` are all core-owned.

## Testing / gate

Harness: `bash scripts/test-in-app.sh <target>` (run target path inside the mounted worktree is
`tests/Feature/Plugins/VbHrizn/<File>.php`; files authored at plugin `tests/<File>.php`). Mounted
vctrbase-php worktree must be on core `f7ad1f7`. hrizn has no cron → no deadlock risk.

- **`HriznWebhookTest`** (rewrite): provision a `WebhookEndpoint`, POST signed bodies
  (`X-Webhook-Signature: sha256=<hmac>`) to `/api/webhooks/inbound/{slug}`; assert each of the 6
  handlers' dispatch effects (mirror-row updates, `FeedEventRequested`/`TaskRequested` emissions)
  **and** the recorded `IntegrationRun` (status, `integration_type='hrizn_webhook'`,
  `target_ref=<type>`, `expected_next_at` NULL, no secret in `stats`/`error_message`). Assert a
  duplicate delivery is deduped by core (no second run, no double effect). Assert a wrong-slug /
  bad-signature POST is rejected by core (400) and records no run.
- **New coverage** (in `HriznWebhookTest` or a sibling): a handler that throws records a `failed`
  run and the request still ACKs 202.
- **`HriznWebhookRlsTest`** (rewrite/shrink): the pre-tenant secret dance now lives in core;
  hrizn's test asserts tenant isolation of the *recorded runs* and dispatch effects — a delivery
  for tenant A produces rows/runs only under tenant A, invisible to tenant B.
- **`HriznSettingsTest`** (update): `registerWebhook` fetch-or-provisions a `WebhookEndpoint`
  (`routing_key='vb-hrizn'`, secret encrypted + `$hidden`), stores the SaaS secret on the
  endpoint (not the namespace), keeps `webhookId` in the namespace, and sends a callback URL
  containing the slug. `removeApiKey` marks the endpoint `inactive`.
- **`HriznContentLinkTest`** (update): assert on `EntityRelation::COVERS` (byte-identical).
- Gate (all green): full plugin Pest suite (incl. signing/byte-compat tests) · `pint --test` ·
  vitest · UI build · firewall audit == empty · `rls:coverage` posture unchanged.

## Non-goals / residuals

- **Freshness/timestamp header** (`timestamp_header`): the SaaS sends no timestamp header, so the
  endpoint leaves it null and freshness is skipped (core behavior). Not adopted.
- **Migrating the API key / other secrets** off `HriznNamespace`: out of scope. Only the webhook
  signing secret moves (to the endpoint it belongs on). The API key + site cache stay in the
  encrypted namespace blob.
- **Silence/overdue detection** for hrizn: intentionally not armed (null cadence) — sporadic
  inbound traffic makes it meaningless.

## Release gates (HALT AT READY-TO-RELEASE)

Do NOT merge/push/tag/publish. Release is double-gated on the owner: (a) Touchpoint-5 batched
release; (b) host core must DEPLOY rc4+ first (extracted plugins can't call new seams —
`InboundWebhookManager`, `WebhookEndpoint`, `IntegrationRunRecorder` — on an old host). Leave a
reviewed branch + `.superpowers/sdd/progress.md` ledger + handoff paragraph.
