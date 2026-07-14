<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

require_once __DIR__.'/hz_bootstrap.php';

// Install + boot the signed plugin so the admin routes are live and the models are
// registered for auditing. The admin routes moved from /dashboard/hrizn/.../admin
// to the session-authed /api/v1/hrizn/.../admin and now return the ApiResponse
// envelope (assertOk) instead of a back() redirect. DB-state assertions are
// unchanged — the AdminManageable trait behaviour is identical.
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser('rooftop_owner')->id));
});

function makeHriznIdeacloud2(array $overrides = []): HriznIdeacloud
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

function makeHriznContent2(array $overrides = []): HriznContent
{
    $ideacloud = $overrides['ideacloud_id'] ?? null;
    if (! $ideacloud) {
        $ideacloud = makeHriznIdeacloud2()->id;
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

// -----------------------------------------------------------------------
// Part A: HTTP admin CRUD via IdeacloudAdminController
// -----------------------------------------------------------------------

it('rooftop_owner can update an ideacloud via the admin route and it is audited', function () {
    $ideacloud = makeHriznIdeacloud2(['keyword' => 'Original keyword']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->putJson("/api/v1/hrizn/ideaclouds/{$ideacloud->id}/admin", ['keyword' => 'Edited keyword'])
        ->assertOk()->assertJsonPath('status', 'success');

    $ideacloud->refresh();
    expect($ideacloud->keyword)->toBe('Edited keyword')
        ->and($ideacloud->edit_count)->toBe(1);

    expect(DB::table('audit_events')
        ->where('resource_type', 'hrizn_ideaclouds')
        ->where('resource_id', $ideacloud->id)
        ->count())->toBeGreaterThan(0);
});

it('rooftop_owner can soft-delete and restore an ideacloud', function () {
    $ideacloud = makeHriznIdeacloud2(['keyword' => 'Delete Me']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson("/api/v1/hrizn/ideaclouds/{$ideacloud->id}/admin", ['reason' => 'dupe'])
        ->assertOk();

    $ideacloud->refresh();
    expect($ideacloud->deleted_at)->not->toBeNull()
        ->and($ideacloud->delete_reason)->toBe('dupe');

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/api/v1/hrizn/ideaclouds/{$ideacloud->id}/admin/restore")
        ->assertOk();

    $ideacloud->refresh();
    expect($ideacloud->deleted_at)->toBeNull();
});

it('billing_manager (no hrizn.admin.manage.rooftop) is forbidden from ideacloud admin actions', function () {
    $ideacloud = makeHriznIdeacloud2(['keyword' => 'Forbidden Ideacloud']);

    $this->actingAs(pluginTestUser('billing_manager'))
        ->putJson("/api/v1/hrizn/ideaclouds/{$ideacloud->id}/admin", ['keyword' => 'Hacked'])
        ->assertForbidden();
});

// -----------------------------------------------------------------------
// Part B: HTTP admin CRUD via ContentAdminController
// -----------------------------------------------------------------------

it('rooftop_owner can update a content item via the admin route and it is audited', function () {
    // Core admin-router.ts contentPatchSchema allows only articleType/contentIntent — not status.
    $content = makeHriznContent2(['status' => 'pending', 'article_type' => 'basic']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->putJson("/api/v1/hrizn/content/{$content->id}/admin", ['article_type' => 'qa'])
        ->assertOk();

    $content->refresh();
    expect($content->article_type)->toBe('qa')
        ->and($content->edit_count)->toBe(1);

    expect(DB::table('audit_events')
        ->where('resource_type', 'hrizn_content')
        ->where('resource_id', $content->id)
        ->count())->toBeGreaterThan(0);
});

it('content admin update rejects an article_type outside {basic, qa}', function () {
    // Core admin-router.ts contentPatchSchema: articleType ∈ {basic, qa} (admin-router.ts:25).
    // JSON request so the shared AdminController's $request->validate() surfaces
    // the rejection as a 422.
    $content = makeHriznContent2(['status' => 'pending', 'article_type' => 'basic']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->putJson("/api/v1/hrizn/content/{$content->id}/admin", ['article_type' => 'expert'])
        ->assertStatus(422);

    $content->refresh();
    expect($content->article_type)->toBe('basic'); // unchanged — the invalid patch never applied.
});

it('content admin update accepts a restricted articleType/contentIntent patch', function () {
    // Core admin-router.ts contentPatchSchema: contentIntent ∈ {fixed_ops, variable} (admin-router.ts:26).
    $content = makeHriznContent2(['status' => 'pending', 'article_type' => 'basic']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->putJson("/api/v1/hrizn/content/{$content->id}/admin", ['article_type' => 'qa', 'content_intent' => 'fixed_ops'])
        ->assertOk();

    $content->refresh();
    expect($content->article_type)->toBe('qa')->and($content->content_intent)->toBe('fixed_ops');
});

it('rooftop_owner can soft-delete and restore a content item', function () {
    $content = makeHriznContent2(['status' => 'pending']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson("/api/v1/hrizn/content/{$content->id}/admin", ['reason' => 'cancelled'])
        ->assertOk();

    $content->refresh();
    expect($content->deleted_at)->not->toBeNull()
        ->and($content->delete_reason)->toBe('cancelled');

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/api/v1/hrizn/content/{$content->id}/admin/restore")
        ->assertOk();

    $content->refresh();
    expect($content->deleted_at)->toBeNull();
});

it('billing_manager (no hrizn.admin.manage.rooftop) is forbidden from content admin actions', function () {
    $content = makeHriznContent2(['status' => 'pending']);

    $this->actingAs(pluginTestUser('billing_manager'))
        ->putJson("/api/v1/hrizn/content/{$content->id}/admin", ['article_type' => 'qa'])
        ->assertForbidden();
});

// -----------------------------------------------------------------------
// Part C: (removed) create → feed event
// -----------------------------------------------------------------------
//
// NOTE: The stub emitted a `hrizn.ideacloud.created` FeedEventRequested on create.
// Core router.ts ideaclouds.create (475-512) writes an AUDIT event and NO feed event.
// The create→audit path is asserted in HriznTest.php ("creates an ideacloud … audited").
