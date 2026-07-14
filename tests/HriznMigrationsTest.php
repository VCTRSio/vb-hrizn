<?php

use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/hz_bootstrap.php';

it('creates both hrizn tables with expected columns', function () {
    hzRunMigrations();
    expect(Schema::hasTable('hrizn_ideaclouds'))->toBeTrue();
    expect(Schema::hasTable('hrizn_content'))->toBeTrue();
    expect(Schema::hasColumns('hrizn_content', [
        'id', 'tenant_type', 'tenant_id', 'ideacloud_id', 'hrizn_content_id', 'article_type',
        'content_intent', 'auto_compliance', 'status', 'progress_percent', 'created_by',
        'deleted_at', 'edit_count',
    ]))->toBeTrue();
});

it('applies the fail-closed tenant_isolation policy + FORCE on both tables', function () {
    hzRunMigrations();
    foreach (['hrizn_content', 'hrizn_ideaclouds'] as $t) {
        $pol = \DB::selectOne('SELECT polname FROM pg_policy WHERE polrelid = ?::regclass', ["public.{$t}"]);
        expect($pol?->polname)->toBe("{$t}_tenant_isolation");
        $forced = \DB::selectOne('SELECT relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', ["public.{$t}"]);
        expect($forced?->relforcerowsecurity)->toBeTrue();
    }
});
