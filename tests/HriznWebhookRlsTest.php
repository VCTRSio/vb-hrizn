<?php

declare(strict_types=1);

/**
 * Regression guard for the LIVE-INSTALL webhook-secret bug.
 *
 * The public inbound webhook receiver (WebhookController::receive) resolves the
 * per-tenant webhook secret from `plugin_namespaces` — a FORCE ROW LEVEL SECURITY
 * tenant table — at a PRE-TENANT point in the flow: no app.tenant_* GUC is set yet
 * (the tenant is being resolved FROM this very lookup). Under the real runtime
 * (non-superuser app_user, fail-closed FORCE RLS) a BARE `HriznNamespace::get()`
 * therefore sees ZERO rows → the secret comes back NULL → the receiver 404s
 * "Webhook not configured" and NO webhook is ever processed.
 *
 * The fix wraps that read in SystemContext::runUnscoped (the sanctioned #6 bypass),
 * matching the token lookup directly above it. This test encodes both halves:
 *   - a bare get() on the app_user connection does NOT see the secret (the bug), and
 *   - the same get() under runUnscoped DOES (the fix).
 *
 * WHY A COMMITTED CLONE CONNECTION (pgsql_ddl)
 * --------------------------------------------
 * Mirrors HriznRlsIsolationTest: DatabaseTransactions wraps only the DEFAULT
 * (pgsql, superuser) connection, so a row written there is invisible to the
 * SEPARATE app_user session (uncommitted). We seed the namespace row on the
 * committed, unwrapped `pgsql_ddl` clone (superuser bypasses RLS) so it is really
 * there and visible to app_user; then we read it on `pgsql_app` (the real posture)
 * to prove RLS hides it pre-tenant and runUnscoped reveals it. plugin_namespaces
 * is a core table already present in the migrated schema — hzRunMigrations() only
 * adds the hrizn_* tables — so no DDL/lock juggling is needed for it.
 */

use App\Support\Rls\TenantGuc;
use App\Support\SystemContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

afterEach(fn () => TenantGuc::clear());

it('reads the pre-tenant webhook secret only under runUnscoped on app_user FORCE RLS', function () {
    // Committed, unwrapped owner (superuser) connection for the namespace seed.
    config(['database.connections.pgsql_ddl' => config('database.connections.pgsql')]);
    DB::purge('pgsql_ddl');

    $tenantId = (string) Str::uuid();
    $secret = 'whsec_'.Str::random(12);
    $key = 'vb-hrizn:'.$tenantId;

    $priorDefault = config('database.default');

    try {
        // Ensure the hrizn_* tables exist with RLS (idempotent). plugin_namespaces
        // already exists in the migrated schema — this is belt-and-braces so the
        // plugin's own tables carry FORCE RLS when the file runs standalone.
        config(['database.default' => 'pgsql_ddl']);
        DB::purge('pgsql_ddl');
        hzRunMigrations();

        // Write the webhook secret via the plugin's own store, on the committed
        // superuser connection (bypasses RLS) so the encrypted row is visible to
        // the separate app_user session below.
        HriznNamespace::patch('rooftop', $tenantId, ['webhookSecret' => $secret]);

        // Sanity / anti-false-pass: the row is genuinely committed and readable when
        // RLS is not enforcing (superuser owner) — so the app_user assertions below
        // cannot pass trivially just because the row is missing.
        $committed = DB::connection('pgsql_ddl')->table('plugin_namespaces')
            ->where('namespace', $key)->count();
        expect($committed)->toBe(1);

        // Switch to the real runtime posture: non-superuser app_user, FORCE RLS,
        // and NO tenant GUC set — exactly the pre-tenant state of the webhook flow.
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');
        TenantGuc::clear();

        // THE BUG: a bare get() at this pre-tenant point is invisible under FORCE RLS.
        $bare = HriznNamespace::get('rooftop', $tenantId);
        expect($bare['webhookSecret'] ?? null)->toBeNull();

        // THE FIX: the sanctioned #6 bypass makes the row visible, yielding the secret.
        $viaBypass = SystemContext::runUnscoped(
            fn () => HriznNamespace::get('rooftop', $tenantId)
        );
        expect($viaBypass['webhookSecret'] ?? null)->toBe($secret);
    } finally {
        TenantGuc::clear();
        // Remove the committed seed row (owner bypasses RLS) so nothing leaks.
        DB::connection('pgsql_ddl')->table('plugin_namespaces')->where('namespace', $key)->delete();
        config(['database.default' => $priorDefault]);
        DB::purge('pgsql_app');
        DB::purge('pgsql_ddl');
    }
});
