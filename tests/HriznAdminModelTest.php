<?php

use App\Audit\AuditableRegistry;
use App\Plugins\Contracts\AdminManageableModel;
use App\Plugins\PluginModel;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so its tables exist and its models are registered
// in AuditableRegistry (the ServiceProvider::register() runs). The AdminManageable
// trait behaviour is core code, exercised here 1:1.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser()->id));
});

function makeHriznIdeacloud(array $overrides = []): HriznIdeacloud
{
    return HriznIdeacloud::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'keyword' => 'Test keyword research',
        'status' => 'researching',
        'hrizn_id' => (string) Str::uuid(),
        'created_by' => (string) Str::uuid(),
    ], $overrides));
}

function makeHriznContent(array $overrides = []): HriznContent
{
    $ideacloud = $overrides['ideacloud_id'] ?? null;
    if (! $ideacloud) {
        $ideacloud = makeHriznIdeacloud()->id;
    }

    return HriznContent::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'ideacloud_id' => $ideacloud,
        'article_type' => 'basic',
        'status' => 'pending',
        'created_by' => (string) Str::uuid(),
    ], $overrides));
}

function hriznActorId(): string
{
    return (string) Str::uuid();
}

it('is a PluginModel and an AdminManageableModel: Ideacloud', function () {
    expect(new HriznIdeacloud)->toBeInstanceOf(PluginModel::class)
        ->and(new HriznIdeacloud)->toBeInstanceOf(AdminManageableModel::class);
});

it('is a PluginModel and an AdminManageableModel: Content', function () {
    expect(new HriznContent)->toBeInstanceOf(PluginModel::class)
        ->and(new HriznContent)->toBeInstanceOf(AdminManageableModel::class);
});

it('registers the hrizn_ideaclouds table for auditing', function () {
    expect(AuditableRegistry::hasTable('hrizn_ideaclouds'))->toBeTrue();
});

it('registers the hrizn_content table for auditing', function () {
    expect(AuditableRegistry::hasTable('hrizn_content'))->toBeTrue();
});

it('applyAdminEdit updates fields, stamps editor, increments edit_count: Ideacloud', function () {
    $actorId = hriznActorId();
    $ideacloud = makeHriznIdeacloud();
    $ideacloud->applyAdminEdit(['keyword' => 'Renamed keyword'], $actorId);
    $ideacloud->refresh();
    expect($ideacloud->keyword)->toBe('Renamed keyword')
        ->and($ideacloud->edited_by_id)->toBe($actorId)
        ->and($ideacloud->edit_count)->toBe(1)
        ->and($ideacloud->edited_at)->not->toBeNull();
});

it('softDeleteWithReason then scopeActive hides it, restore brings it back: Ideacloud', function () {
    $actorId = hriznActorId();
    $ideacloud = makeHriznIdeacloud();
    $ideacloud->softDeleteWithReason('spam', $actorId);
    expect(HriznIdeacloud::withoutTenantScope()->active()->whereKey($ideacloud->id)->exists())->toBeFalse();
    $ideacloud->refresh();
    expect($ideacloud->deleted_by_id)->toBe($actorId)->and($ideacloud->delete_reason)->toBe('spam');
    $ideacloud->restoreSoftDeleted();
    expect(HriznIdeacloud::withoutTenantScope()->active()->whereKey($ideacloud->id)->exists())->toBeTrue();
});

it('applyAdminEdit updates fields, stamps editor, increments edit_count: Content', function () {
    $actorId = hriznActorId();
    $content = makeHriznContent();
    $content->applyAdminEdit(['status' => 'complete'], $actorId);
    $content->refresh();
    expect($content->status)->toBe('complete')
        ->and($content->edited_by_id)->toBe($actorId)
        ->and($content->edit_count)->toBe(1)
        ->and($content->edited_at)->not->toBeNull();
});

it('softDeleteWithReason then scopeActive hides it, restore brings it back: Content', function () {
    $actorId = hriznActorId();
    $content = makeHriznContent();
    $content->softDeleteWithReason('cancelled', $actorId);
    expect(HriznContent::withoutTenantScope()->active()->whereKey($content->id)->exists())->toBeFalse();
    $content->refresh();
    expect($content->deleted_by_id)->toBe($actorId)->and($content->delete_reason)->toBe('cancelled');
    $content->restoreSoftDeleted();
    expect(HriznContent::withoutTenantScope()->active()->whereKey($content->id)->exists())->toBeTrue();
});

// NOTE (dropped): the core file's final test hit the server-rendered show route
// GET /dashboard/hrizn/ideaclouds/{id} and asserted a soft-deleted row 404s. That
// Inertia surface is retired in the extracted plugin (there is no local-uuid show
// route; /api/v1/hrizn/ideaclouds/{id} proxies the external API keyed on hrizn_id).
// The "soft-deleted rows are hidden" behaviour is proven by scopeActive above and
// by the list-fallback tests in HriznIdeacloudsTest / HriznContentTest (T2B-17).
