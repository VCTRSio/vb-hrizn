# HRIZN Expand — Events, Vehicle Linking & Content-Health Directory (Design)

**Date:** 2026-08-05
**Plugin:** `vb-hrizn` (8/8, final plugin of the marketplace-expansion Expand phase)
**Version target:** 1.0.0 → 1.1.0
**Owner-approved scope:** defer intelligence-recommendation events; vehicle-linking limited to the vehicle-specific article types.

## Goal

Turn HRIZN from a generate-and-forget content island into an **acted-on** loop: when the HRIZN platform finishes an article, the right person is told and given a task; content about a specific vehicle is *linked* to that vehicle; and a manager can see per-rooftop content health from outside HRIZN's own screen. All zero-core: the plugin consumes existing host seams only.

## Non-negotiable constraints (Core Change Firewall)

- **ZERO edits to the core tree** (`/home/carmelo/Work/VCTRS/vctrbase-php`). Every change lands in the `vb-hrizn` repo. Firewall audited (`git -C ../../vctrbase-php status --porcelain` empty) after every task.
- Consume host seams by class import + `app()` resolution / static call. **Never** import a sibling plugin's Eloquent models. Sanctioned seams used here:
  - `App\Support\EntityReferenceService::link($tt,$tid,$srcType,$srcId,$tgtType,$tgtId,$relation,$createdBy=null): string` (idempotent, unique 7-tuple) and `forSource(...)` for read-back.
  - `App\Events\FeedEventRequested(...)` and `App\Events\TaskRequested(...)` — both Outboxed fire-and-forget events; the `feed`/`tasks` plugins are the sole writers.
  - `App\Support\TenantContext::SYSTEM_ACTOR`, `->userId()`, `->activeTenantType()`, `->activeTenantId()`.
  - `App\Support\SystemContext::runAsTenant(...)` (already in use).
  - `InventoryDirectory` (bound singleton from `inventory-hub`) — `lookupByVin($tt,$tid,$vin): ?array` and `search($tt,$tid,$query,$status,$limit): array`. Both return `PICKER_FIELDS` (`vin,stock_number,year,make,model,trim,exterior_color,condition,status`) — **no `id`**, so vehicle linking keys on **VIN**, the identifier the Directory contract exposes. Resolved via `class_exists` / `app()->bound()` guard so HRIZN degrades gracefully if inventory-hub is not installed.
- Do not relax RLS/tenant isolation. All new reads stay tenant-scoped (explicit tenant `where` + `withoutGlobalScope`/`runAsTenant` where crossing scope), PII-free.
- **Best-effort discipline:** every new side effect (event dispatch, entity link) is wrapped `try/catch (\Throwable $e) { report($e); }` so a downstream failure never breaks the primary path (webhook ack, content generation).

## Current-state facts (from recon)

- Two local tables, both tenant-scoped + FORCE-RLS: `hrizn_ideaclouds`, `hrizn_content`. Intelligence recommendations are **not** mirrored locally (pure API proxy) — hence the owner decision to defer rec-events.
- Lifecycle sync is 100% webhook-driven via `WebhookController::dispatch()` (no jobs, no cron). `onContentCompleted()` currently does a bulk `update()` by external `hrizn_content_id`, returning the affected row count (the `dispatch()` `0 rows` warning depends on that `int`).
- `hrizn_content` carries `created_by` (the staff member who requested generation), `article_type` (7 types; vehicle-specific = `modellanding`, `comparison`), `content_intent` (`fixed_ops`/`variable`/`general`), `ideacloud_id` (local uuid FK-in-spirit → `hrizn_ideaclouds.id`, whose `keyword` names the topic).
- No `EntityReferenceService` usage, no relation-vocab file exist yet — introduced fresh here (same as the gbp Expand).
- `ContentController@generate` creates the local `HriznContent` row inside a `DB::transaction`; `@apiGet` is a pure API passthrough (returns `client->getContent($id)`).

## Architecture — four tasks + gate

### T1 — Relation vocab + content-lifecycle events

**New:** `src/Support/HriznRelation.php` — string consts (no core validation of relation strings, so a plugin-local vocab is the pattern):
- `PLUGIN_NAMESPACE = 'vb-hrizn'`
- `CONTENT_SOURCE_TYPE = 'vb-hrizn.content'`
- `VEHICLE_TARGET_TYPE = 'inventory_vehicle'`
- `COVERS = 'covers'`
- Feed event types: `FEED_CONTENT_READY = 'hrizn.content.ready'`, `FEED_CONTENT_FAILED = 'hrizn.content.failed'`, `FEED_RESEARCH_READY = 'hrizn.ideacloud.ready'`

**Modify** `WebhookController`: the three handlers below fetch the affected row(s) instead of bulk-updating, preserving the `int` return contract (return `0` when no local row matched, else the number updated), then fire events best-effort. All already run inside `SystemContext::runAsTenant` (tenant + audit context set).

- `onContentCompleted($data)`: fetch `$content = HriznContent::where('hrizn_content_id',$id)->first()`. If null → return 0. Else set `status=complete, progress_percent=100, progress_stage=finalizing`, save; then fire (best-effort):
  - `FeedEventRequested` — `eventType FEED_CONTENT_READY`, `sourceType CONTENT_SOURCE_TYPE`, `sourceId $content->id`, actor = system, summary `"New HRIZN article ready: {keyword} ({articleTypeLabel})"`, `detailPayload {article_type, content_intent}`.
  - `TaskRequested` — `pluginNamespace PLUGIN_NAMESPACE`, `requestedBy = $content->created_by ?: SYSTEM_ACTOR`, `assignedTo = $content->created_by` (nullable), `priority 'normal'`, title `"Review & publish HRIZN article: {keyword}"`, description names the article type/intent.
  - Keyword resolved best-effort via `$content->ideacloud?->keyword` (fallback `'content'`).
  - Return 1.
- `onContentFailed($data)`: same fetch-first refactor; on the failed row fire only a `FeedEventRequested` (`FEED_CONTENT_FAILED`, priority `high`, summary names the error) so failures stop being silent. No task. Return 1 (or 0 if not found).
- `setIdeacloudStatus($data,'complete')` (the `ideacloud.completed` arm only): fetch the ideacloud, on success fire a light `FeedEventRequested` (`FEED_RESEARCH_READY`, summary `"Keyword research ready: {keyword}"`, sourceType `'vb-hrizn.ideacloud'`, sourceId ideacloud id). `ideacloud.failed` unchanged (no event). Preserve return int.

**Tests (new `tests/HriznContentEventsTest.php`):** `Event::fake([...])`; drive each webhook type through `WebhookController::receive` (signed) or `dispatch` directly; assert the right events fire with the right fields; assert `content.completed` on an **unknown** id fires nothing and still returns 0 (warning path intact); assert idempotent re-delivery does not double-fire (already-`complete` row → still fires? decision: fire only on the completing transition — guard `if ($content->status !== 'complete')` before firing, so re-delivery is inert).

### T2 — Content → vehicle link at generate + read-back

**Modify** `ContentController@generate`:
- Add optional validation: `'vehicleVin' => ['sometimes','string','max:32']`.
- After the existing content-create transaction, if `vehicleVin` present AND `articleType ∈ {modellanding, comparison}` AND inventory-hub bound: resolve `InventoryDirectory::lookupByVin($tt,$tid,$vin)`; if non-null, `EntityReferenceService::link($tt,$tid, CONTENT_SOURCE_TYPE, $content->id, VEHICLE_TARGET_TYPE, strtoupper($vin), COVERS, $ctx->userId())`. Best-effort try/catch. Capture `$content` from the transaction (return it out) to get its id. If the VIN doesn't resolve, silently skip the link (do not fail generation) — the article is still created.
- Guard inventory-hub availability with `app()->bound(InventoryDirectory::class)` (or `class_exists`) so HRIZN degrades gracefully standalone.

**Modify** `ContentController@apiGet`: after the API fetch, resolve the local row by `hrizn_content_id` (best-effort), and if found, `EntityReferenceService::forSource($tt,$tid,CONTENT_SOURCE_TYPE,$localId)` filtered to `VEHICLE_TARGET_TYPE`; enrich each with `InventoryDirectory::lookupByVin` for `{vin,year,make,model,trim}` display. Attach as `linkedVehicles` array on the response payload. Never fail the passthrough on enrichment error.

**Tests (new `tests/HriznContentLinkTest.php`):** generate with a `vehicleVin` that exists → asserts an `entity_references` row (`covers`, target `inventory_vehicle`/VIN) created; generate with a non-vehicle `articleType` + a VIN → no link; generate with a non-existent VIN → no link, content still created; `apiGet` returns `linkedVehicles`. Inventory-hub is installed in these tests (the harness boots it) OR the link path is exercised against a seeded `entity_references` row if inventory-hub isn't available — spec'd in the plan.

### T3 — `HriznDirectory` (content-health outbound seam)

**New:** `src/HriznDirectory.php` — PII-free, singleton-bound in `HriznServiceProvider::register()`. Reads its own two tables only, with explicit tenant filter under `runAsTenant`/`withoutGlobalScope`.
- `contentHealth(string $tt, string $tid): array` → `{publishedLast90, daysSinceLastPublish, pendingContent, fixedOpsCount, variableCount, complianceFlagged, ideacloudsActive}`:
  - `publishedLast90` = count `hrizn_content` `status=complete`, `updated_at >= now-90d`, not deleted.
  - `daysSinceLastPublish` = whole days since `max(updated_at)` of completed content (null if none).
  - `pendingContent` = count `status in (pending, generating)`, not deleted.
  - `fixedOpsCount` / `variableCount` = count completed content by `content_intent`.
  - `complianceFlagged` = count where `compliance_status` in (`flagged`,`fail`,`pending`) — null-safe.
  - `ideacloudsActive` = count non-deleted ideaclouds.
- `contentFor(string $tt, string $tid, int $limit = 50): array` → list of `{id, article_type, content_intent, status, hrizn_content_id, created_at}` (PII-free), newest first.

**Tests (new `tests/HriznDirectoryTest.php`):** seed a mix of content/ideaclouds across statuses/intents/dates; assert every aggregate; assert cross-tenant rows are excluded (a second tenant's data is invisible).

### T4 — UI affordances

**Modify** `ui/entry.tsx` (client-fetch ESM, `R.createElement`):
- On the content-create flow (currently generation is triggered from the ideacloud/content screens), add an **optional vehicle field** shown only when the selected `articleType ∈ {modellanding, comparison}`: a text/typeahead input that calls a new lightweight session-API vehicle-search passthrough (see below) and, on select, sends `vehicleVin` to `POST /content`. If inventory-hub isn't available (search returns empty/errs), the field simply shows nothing to pick — no crash.
- On the content list/show, a small badge: **"Ready to publish"** when `status==='complete'`, and a **"🔗 {make} {model}"** linked-vehicle chip when `linkedVehicles` is present on the show payload.

**New session-API route** `GET /api/v1/hrizn/vehicles/search?q=` → `VehicleSearchController@search` (permission `hrizn.content.write.rooftop`), returns `InventoryDirectory::search($tt,$tid,$q,'active',20)` (PICKER_FIELDS) wrapped in `ApiResponse`; empty array when inventory-hub unbound. This keeps the picker on the same session-authed envelope the UI already uses.

**Tests:** `ui/__tests__/entry.test.tsx` additions (Vitest/jsdom) — vehicle field appears only for the two article types, badge/chip render; a new `tests/HriznVehicleSearchTest.php` for the passthrough route (permission gate + shape + empty-when-unbound).

### Final gate

- `manifest.json` 1.0.0 → 1.1.0 (author stays "Angus Fox").
- `CHANGELOG.md` — prepend `## [1.1.0] - 2026-08-05` (Added: content-lifecycle feed/task events, content↔vehicle linking, HriznDirectory content-health seam, vehicle picker + badges; Notes: intelligence-recommendation events deferred — no webhook/no local mirror).
- Full suite via `bash scripts/test-in-app.sh` (all files) — green, incl. signing/boot tests.
- Firewall audit clean. Whole-branch Opus review → READY TO MERGE.
- **Held at batched Touchpoint 5** — no build-zip/sign/publish; merged to local `main` only, awaiting the owner's batched release of all 8 plugins.

## Test harness note (carried from gbp)

`vb-hrizn/scripts/test-in-app.sh` does **not** pre-migrate the plugin's own tables — feature tests install+boot the signed plugin in-process (`hzInstallSignedAndBoot()`), and unit-style tests call `hzRunMigrations()` directly. This is a different harness shape than gbp's (gbp needed a committed pre-migrate to dodge a two-connection DDL deadlock). HRIZN has **no cron/job** firing on a separate `app_user` RLS connection during tests, so the deadlock that bit gbp does not apply here. **Do not** add a `beforeEach` migration hook that runs plugin DDL inside the owner test transaction; rely on the existing `hzInstallSignedAndBoot()`/`hzRunMigrations()` helpers exactly as the current tests do. Verify full-suite wall-clock stays in the normal range (~30–60s) as an early smoke that no deadlock was introduced.

## Deferred (recorded, not built this pass)

- **Intelligence-recommendation feed/task events** — no webhook and no local mirror; would require a seen-cursor in the encrypted namespace store and only fires on tab-open. Owner-deferred to the end-of-phase backlog.
- Durable compliance-verdict artifact (ownership work, separate bucket).
- content↔GBP-post mirroring (no GBP data source in this plugin).
- Batch inventory-gap content generation (data-entry-side feature).
