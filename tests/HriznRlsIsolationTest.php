<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation proof on the plugin's OWN table, enforced by Postgres
 * FORCE ROW LEVEL SECURITY as the non-superuser app_user (pgsql_app) — NOT by the
 * Eloquent tenant scope.
 *
 * WHY THE DDL RUNS ON A COMMITTED CLONE CONNECTION
 * ------------------------------------------------
 * The suite runs under DatabaseTransactions, which wraps only the DEFAULT (pgsql,
 * superuser) connection in an open transaction. If the plugin's table DDL ran
 * there, two things break: (a) the CREATE/INSERT are invisible to the SEPARATE
 * app_user session (uncommitted), so the read would see zero rows regardless of
 * RLS — a meaningless test; and (b) applyRls()'s ALTER TABLE holds an ACCESS
 * EXCLUSIVE lock for the whole test, so a cross-connection insert deadlocks/hangs.
 *
 * So the DDL + seed run on `pgsql_ddl` — a clone of the pgsql (superuser) config
 * that DatabaseTransactions never wraps, hence autocommits. The rows are then
 * committed and visible to app_user, and no DDL lock is held. The superuser
 * bypasses RLS, so it can seed both tenants directly. Cleanup drops the committed
 * table in finally.
 *
 * The read strips TenantScope (withoutGlobalScope) so the ONLY thing that can hide
 * tenant B's row is the database policy keyed off app.tenant_id. A committed
 * bypass-sanity count of 2 first proves both rows genuinely exist and WOULD be
 * returned if RLS were not enforcing — so the isolation assertion cannot pass
 * trivially.
 */

use App\Support\Rls\TenantGuc;
use App\Support\Scopes\TenantScope;
use App\Support\SystemContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;

require_once __DIR__.'/hz_bootstrap.php';

afterEach(fn () => TenantGuc::clear());

it('enforces cross-tenant isolation on hrizn_content under app_user FORCE RLS', function () {
    // A committed, unwrapped owner (superuser) connection for the plugin DDL + seed.
    config(['database.connections.pgsql_ddl' => config('database.connections.pgsql')]);
    DB::purge('pgsql_ddl');

    $a = (string) Str::uuid();
    $b = (string) Str::uuid();

    $seedRow = fn (string $tid): array => [
        'id' => (string) Str::uuid(),
        'tenant_type' => 'rooftop',
        'tenant_id' => $tid,
        'ideacloud_id' => (string) Str::uuid(),
        'article_type' => 'basic',
        'status' => 'complete',
        'progress_percent' => 100,
        'created_by' => (string) Str::uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $priorDefault = config('database.default');

    try {
        // Run the plugin migrations on the committed clone: creates hrizn_content +
        // hrizn_ideaclouds with the fail-closed policy, FORCE RLS, and app_user grants.
        config(['database.default' => 'pgsql_ddl']);
        DB::purge('pgsql_ddl');
        hzRunMigrations();

        // Seed one row per tenant (superuser bypasses RLS) — committed, so the
        // separate app_user session can see them.
        DB::connection('pgsql_ddl')->table('hrizn_content')->insert([$seedRow($a), $seedRow($b)]);

        // Sanity / anti-false-pass: both rows are really there and visible absent RLS.
        $committed = DB::connection('pgsql_ddl')->table('hrizn_content')
            ->whereIn('tenant_id', [$a, $b])->pluck('tenant_id')->all();
        expect($committed)->toHaveCount(2);

        // Read as tenant A on the app_user connection. TenantScope is removed, so the
        // ONLY surviving filter is Postgres FORCE RLS keyed off the app.tenant_id GUC.
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');

        $seen = SystemContext::runAsTenant('rooftop', $a, fn () => HriznContent::on('pgsql_app')
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', [$a, $b])
            ->pluck('tenant_id')->all());

        expect($seen)->toHaveCount(1);   // exactly one row visible…
        expect($seen)->each->toBe($a);   // …and it is tenant A — B invisible under app_user RLS
    } finally {
        TenantGuc::clear();
        DB::connection('pgsql_ddl')->statement('DROP TABLE IF EXISTS hrizn_content CASCADE');
        DB::connection('pgsql_ddl')->statement('DROP TABLE IF EXISTS hrizn_ideaclouds CASCADE');
        config(['database.default' => $priorDefault]);
        DB::purge('pgsql_app');
        DB::purge('pgsql_ddl');
    }
});
