<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hrizn_ideaclouds — matches docker/postgres/init/01-schema.sql column-for-column.
 * Sanctioned divergence: tenant_type native pg enum → string (the RLS predicate casts
 * (tenant_type)::text, so it is enum/string agnostic). Fail-closed tenant RLS is
 * reproduced verbatim from core's enforce_real_rls (a clean external install is never
 * swept by that core migration, so skipping RLS would leave the table with no isolation).
 */
return new class extends Migration
{
    private const T = 'hrizn_ideaclouds';

    public function up(): void
    {
        if (! Schema::hasTable(self::T)) {
            Schema::create(self::T, function (Blueprint $t) {
                $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $t->string('tenant_type');
                $t->uuid('tenant_id');
                $t->text('keyword');
                $t->text('status')->default('researching');
                $t->text('hrizn_id');
                $t->uuid('created_by');
                $t->timestamps(); // created_at/updated_at default now() supplied by Eloquent
                $t->timestampTz('deleted_at')->nullable();
                $t->uuid('deleted_by_id')->nullable();
                $t->text('delete_reason')->nullable();
                $t->timestampTz('edited_at')->nullable();
                $t->uuid('edited_by_id')->nullable();
                $t->integer('edit_count')->default(0);
            });
            DB::statement('CREATE INDEX idx_hrizn_ideaclouds_active ON public.hrizn_ideaclouds USING btree (tenant_type, tenant_id) WHERE deleted_at IS NULL');
        }
        $this->applyRls();
    }

    private function applyRls(): void
    {
        $t = self::T;
        $predicate = <<<'SQL'
            current_setting('app.bypass_rls', true) = '1'
            OR ( (tenant_type)::text = current_setting('app.tenant_type', true)
                 AND (tenant_id)::text = NULLIF(current_setting('app.tenant_id', true), '') )
        SQL;
        DB::unprepared("ALTER TABLE public.{$t} ENABLE ROW LEVEL SECURITY;");
        DB::unprepared("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t};");
        DB::unprepared("CREATE POLICY {$t}_tenant_isolation ON public.{$t} USING ({$predicate});");
        DB::unprepared("ALTER TABLE public.{$t} FORCE ROW LEVEL SECURITY;");
        DB::unprepared(<<<SQL
            DO \$\$ BEGIN
              IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
                EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON public.{$t} TO app_user';
                EXECUTE 'GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO app_user';
              END IF;
            END \$\$;
        SQL);
    }

    public function down(): void
    {
        $t = self::T;
        DB::unprepared("ALTER TABLE public.{$t} NO FORCE ROW LEVEL SECURITY;");
        DB::unprepared("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t};");
        Schema::dropIfExists(self::T);
    }
};
