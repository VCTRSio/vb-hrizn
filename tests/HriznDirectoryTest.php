<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\HriznDirectory;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

require_once __DIR__.'/hz_bootstrap.php';

beforeEach(function () {
    hzBindTenant(pluginTestUser('rooftop_owner')->id);
    hzRunMigrations();
});

function hzDirContent(array $attrs): HriznContent
{
    return HriznContent::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => (string) Str::uuid(), 'hrizn_content_id' => (string) Str::uuid(),
        'article_type' => 'basic', 'content_intent' => 'variable', 'status' => 'complete',
        'created_by' => (string) Str::uuid(),
    ], $attrs));
}

it('aggregates content health for the tenant', function () {
    hzDirContent(['status' => 'complete', 'content_intent' => 'fixed_ops']);
    hzDirContent(['status' => 'complete', 'content_intent' => 'variable']);
    hzDirContent(['status' => 'generating']);
    hzDirContent(['status' => 'complete', 'content_intent' => 'general', 'compliance_status' => 'flagged']);
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'complete', 'hrizn_id' => 'ic1', 'created_by' => (string) Str::uuid(),
    ]);

    $h = app(HriznDirectory::class)->contentHealth('rooftop', PLUGIN_TEST_TENANT);

    expect($h['publishedLast90'])->toBe(3)          // 3 complete (fixed_ops, variable, flagged)
        ->and($h['pendingContent'])->toBe(1)
        ->and($h['fixedOpsCount'])->toBe(1)
        ->and($h['variableCount'])->toBe(1)
        ->and($h['complianceFlagged'])->toBe(1)
        ->and($h['ideacloudsActive'])->toBe(1)
        ->and($h['daysSinceLastPublish'])->toBe(0);
});

it('lists content PII-free, newest first', function () {
    hzDirContent(['article_type' => 'qa']);
    $rows = app(HriznDirectory::class)->contentFor('rooftop', PLUGIN_TEST_TENANT);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys(['id', 'article_type', 'content_intent', 'status', 'hrizn_content_id', 'created_at'])
        ->and($rows[0])->not->toHaveKey('created_by');
});

it('excludes another tenant\'s rows', function () {
    hzDirContent(['tenant_id' => (string) Str::uuid(), 'status' => 'complete']); // other tenant
    $h = app(HriznDirectory::class)->contentHealth('rooftop', PLUGIN_TEST_TENANT);
    expect($h['publishedLast90'])->toBe(0);
});
