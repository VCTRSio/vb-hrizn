# Changelog

All notable changes to HRIZN are documented here.

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
