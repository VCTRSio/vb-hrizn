# Changelog

All notable changes to HRIZN are documented here.

## [1.1.1] - 2026-08-05

### Changed
- `HriznDirectory::contentHealth` — renamed the `complianceFlagged` result key to
  `complianceNeedsAttention` to remove an ambiguity: the bucket counts rows needing
  a human look (`pending` awaiting verification OR `flagged`/`fail`), not strictly
  compliance failures. Added a doc-comment on the method spelling this out. This
  seam is new in 1.1.0 with no external consumers yet, so the key was renamed rather
  than left misleading.
- Strengthened the content↔vehicle link test to assert the persisted
  `entity_references` edge directly (`source_id` equals the local content id, plus
  `source_type`/`relation`/`target`), not only via the `linkedVehicles` round-trip.

## [1.1.0] - 2026-08-05

### Added
- Content-lifecycle events: when the HRIZN platform finishes an article, a feed
  event is raised and a "review & publish" task is created and assigned to the
  staff member who requested the article; a high-priority feed event is raised
  on content-generation failure; a "research ready" feed event is raised when an
  IdeaCloud completes.
- Content↔vehicle linking: `modellanding`/`comparison` articles can be linked to
  an inventory vehicle by VIN at generation time; linked vehicles surface on
  content detail (`linkedVehicles`).
- `HriznDirectory` — a PII-free, per-rooftop content-health seam (`contentHealth`,
  `contentFor`) for cross-plugin/manager consumption.
- Content UI: an optional vehicle picker for vehicle-specific article types,
  "Ready to publish" and linked-vehicle badges, and a
  `GET /api/v1/hrizn/vehicles/search` passthrough to the inventory directory.

### Notes
- All new cross-cutting effects are best-effort and degrade gracefully when
  inventory-hub is not installed. Zero core changes.
- Intelligence-recommendation events were evaluated and deferred: HRIZN has no
  recommendation webhook and no local mirror, so proactive rec-events would
  require added delta-detection state.

## [1.0.0] - 2026-07-14

### Added
- First-party PHP-native plugin scaffold extracted from the VCTRbase monorepo
  (`plugins/hrizn`), reshaped as a standalone signed release repo in the
  `Vctrs\Plugins\VbHrizn` namespace (kebab-cases to the install slug `vb-hrizn`,
  which the host's `RuntimeAutoloader` maps for autoloading).
- IdeaClouds — keyword-set IdeaClouds with create/list/show and admin
  soft-delete/restore (`HriznIdeacloud`, `IdeacloudController`,
  `IdeacloudAdminController`).
- Content library — generated-content store with list/show and admin
  soft-delete/restore (`HriznContent`, `ContentController`, `ContentAdminController`).
- Intelligence read endpoints against the HRIZN platform API
  (`IntelligenceController`, `HriznClient`).
- Settings — encrypted API-key store, content-generation defaults, and per-user
  notification preferences (`SettingsController`).
- Overview page and four dashboard widgets: total IdeaClouds, latest content,
  content by type, recent IdeaClouds.
- Release tooling copied from the vb-prana-buzz skeleton: signed release artifact
  via `tools/sign.php` + `tools/verify.php`.

### Divergences from the in-monorepo plugin
- Migrations reproduce the platform's fail-closed tenant row-level-security posture
  (per-table `tenant_type`/`tenant_id`, composite tenant FKs, `FORCE ROW LEVEL
  SECURITY`).
- `/api/v1/hrizn/*` returns the canonical `ApiResponse` envelope and is session-authed
  through the host `session-api` group (Sanctum SPA cookie + tenant context + RLS GUC),
  not Bearer tokens.
- Public HMAC webhook receiver (`WebhookController` + `HriznWebhookSignature`) for
  platform lifecycle events, backed by a per-tenant encrypted secret store.
- `HriznResponse::guard` now emits the canonical `ApiResponse` error envelope
  (`{traceId,data:null,status:error,error}`) so the vendored `@vctrs/plugin-ui`
  client kit can unwrap failures. The former `code` field returned on
  `HriznApiException` is dropped — `ApiResponse::error` carries only the `error`
  message (the code was unused by the reference frontend).
