# HRIZN

**AI-powered automotive content generation for VCTRbase.** Keyword-driven
IdeaClouds and a generated-content library, backed by the HRIZN platform API —
with a public webhook receiver for lifecycle events and an encrypted per-tenant
secret store.

**Verified · by Carmelo Santana.** First-party, signed, PHP-native plugin.
Slug `vb-hrizn` · namespace `Vctrs\Plugins\VbHrizn`. Ships from outside the
monorepo and is autoloaded by `App\Plugins\RuntimeAutoloader`. Its release ZIP is
signed with the Carmelo Santana Ed25519 key (`keyId carmelo-ed25519-2026`), so it
installs as **Verified** and its server code boots under the `signed_first_party`
trust tier. **Touches no core files.**

## What it does (v1.0)

- **IdeaClouds** — keyword-set IdeaClouds submitted to the HRIZN platform, with
  create/list/show and an admin lifecycle (soft-delete/restore) (`HriznIdeacloud`,
  `IdeacloudController`, `IdeacloudAdminController`).
- **Content library** — the generated-content store with list/show and admin
  soft-delete/restore (`HriznContent`, `ContentController`, `ContentAdminController`).
- **Intelligence** — read-through intelligence endpoints against the HRIZN API
  (`IntelligenceController`).
- **Settings** — encrypted API-key store plus content-generation defaults and
  per-user notification preferences (`SettingsController`).
- **Overview & widgets** — an overview page and four dashboard widgets: total
  IdeaClouds, latest content, content by type, recent IdeaClouds.

## Install

HRIZN is distributed through the VCTRbase marketplace as a **signed release
artifact**. Install it from **Dashboard → Marketplace** (or the plugin admin at
`/dashboard/plugins`): pick HRIZN, and the platform downloads the release ZIP,
verifies its Ed25519 signature against the trusted keyring, and boots the server
code under the `signed_first_party` trust tier.

Manual/offline install of a specific release:

```bash
# download the release assets for the tag you want, e.g. v1.0.0
gh release download v1.0.0 -R carmelosantana/vb-hrizn \
  -p 'vb-hrizn-1.0.0.zip' -p 'vb-hrizn-1.0.0.zip.sig'
# upload the .zip (+ .sig) at /dashboard/plugins — the installer verifies the
# signature against keyId "carmelo-ed25519-2026" before enabling server code.
```

## Usage

Once enabled, the dashboard UI lives under `/dashboard/hrizn` and is tenant-scoped
and permission-gated. Programmatic endpoints live under `/api/v1/hrizn` (see
below).

Permissions: `hrizn.content.read.rooftop`, `hrizn.content.write.rooftop`,
`hrizn.ideacloud.read.rooftop`, `hrizn.ideacloud.write.rooftop`,
`hrizn.intelligence.read.rooftop`, `hrizn.settings.read.rooftop`,
`hrizn.settings.write.rooftop`, `hrizn.admin.manage.rooftop`.

## Notable divergences from the in-monorepo plugin

Extracting HRIZN out of the monorepo forces three deliberate divergences from the
in-tree `plugins/hrizn`. Each is faithful to the platform contract but must be
reproduced standalone.

1. **Migrations reproduce fail-closed tenant RLS.** As an extracted plugin, HRIZN
   owns its own schema. Its migrations recreate the platform's row-level-security
   posture: every table carries `tenant_type`/`tenant_id`, composite tenant FKs,
   and `FORCE ROW LEVEL SECURITY` with fail-closed tenant-isolation policies, so a
   query without a tenant GUC set returns nothing rather than everything.

2. **`/api/v1/hrizn/*` returns the canonical ApiResponse envelope, session-authed
   via the `session-api` group.** The browser-facing API surface is served through
   the host's `session-api` middleware group (Sanctum SPA-cookie + tenant context +
   the RLS tenant GUC), not Bearer tokens, and every response is wrapped in the
   canonical `ApiResponse` envelope so the shared `@vctrs/plugin-ui` client kit can
   consume it. Extracted plugins deliver their React via client-fetch only — they
   leave the core Vite build — so this API is the plugin's single data path.

3. **Public HMAC webhook receiver + encrypted secret store.** The HRIZN platform
   posts lifecycle events (IdeaCloud/content completion) to a **public** webhook
   endpoint (`WebhookController`) that is authenticated by an HMAC signature
   (`HriznWebhookSignature`) rather than a session. The plugin's API key and webhook
   secret are held in a per-tenant **encrypted** settings store, never in plaintext.

## Migrations & upgrades

The genesis migrations open with an adopt-existing guard
(`if (Schema::hasTable('<table>')) return;`) so first-install is idempotent and a
host that already owns the HRIZN tables (e.g. one that previously ran the in-monorepo
`plugins/hrizn`) **adopts** them — data preserved — rather than dropping and
recreating.

**Upgrade policy:** never mutate a genesis migration to evolve the schema — a host
that already has the table would skip the change entirely. Ship every future schema
change as a **new, additive, dated migration**, each independently idempotent.

## License

AGPLv3 with a plugin-API exception, mirroring the VCTRbase platform license — see
[`LICENSE`](LICENSE). Copyright (C) 2026 VCTRS LLC; author Carmelo Santana.
