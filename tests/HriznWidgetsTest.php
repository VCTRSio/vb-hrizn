<?php

use App\Plugins\PluginManifest;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\HriznServiceProvider;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

require_once __DIR__.'/hz_bootstrap.php';

// The core test discovered widgets via `new PluginManager(base_path('plugins'))`.
// vb-hrizn is an EXTERNAL uploaded plugin — it is NOT in base_path('plugins'); its
// manifest + src live at the read-only HZ_SRC mount. Build the provider directly
// from the mounted source (mirrors VendorWidgetsTest). Install + boot first so the
// plugin tables exist and the tenant is bound (the widget resolvers query the DB).
beforeEach(function () {
    hzInstallSignedAndBoot(hzBindTenant(pluginTestUser()->id));
});

function hriznProvider(): HriznServiceProvider
{
    $dir = hzSrc();
    $manifest = PluginManifest::fromArray(json_decode((string) file_get_contents($dir.'/manifest.json'), true));

    return new HriznServiceProvider($manifest, $dir);
}

function hriznWidgets(): array
{
    return hriznProvider()->widgets();
}

it('exposes the 4 core widgets and drops the stub contentThisMonth widget', function () {
    $keys = array_keys(hriznWidgets());
    expect($keys)->toContain('hrizn.totalIdeaclouds')
        ->toContain('hrizn.latestContent')
        ->toContain('hrizn.contentByType')
        ->toContain('hrizn.recentIdeaclouds')
        ->not->toContain('hrizn.contentThisMonth');
});

it('totalIdeaclouds returns a metric with a 30-day delta', function () {
    // core widgets.ts totalIdeaclouds (40-72): value = total, delta = last-30d count.
    $u = pluginTestUser();
    HriznIdeacloud::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'keyword' => 'k', 'status' => 'complete', 'hrizn_id' => (string) Str::uuid(), 'created_by' => $u->id]);

    [$perm, $fn] = hriznWidgets()['hrizn.totalIdeaclouds'];
    $w = $fn();
    expect($perm)->toBe('hrizn.content.read.rooftop')
        ->and($w['type'])->toBe('metric')
        ->and($w['payload']['value'])->toBe(1)
        ->and($w['payload']['delta'])->toBe(1)
        ->and($w['payload']['deltaLabel'])->toBe('last 30d');
});

it('latestContent returns the 5 most recent content rows shaped {label, sublabel, href}', function () {
    // core widgets.ts latestContent (78-110): 5 most recent, label = hriznContentId ?? id,
    // sublabel = articleType, href = /dashboard/hrizn/content/{id}.
    $u = pluginTestUser();
    $ic = HriznIdeacloud::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'keyword' => 'k', 'status' => 'complete', 'hrizn_id' => (string) Str::uuid(), 'created_by' => $u->id]);
    foreach (range(1, 6) as $i) {
        HriznContent::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
            'ideacloud_id' => $ic->id, 'article_type' => 'basic', 'status' => 'complete',
            'hrizn_content_id' => "hc_{$i}", 'created_by' => $u->id]);
    }

    [, $fn] = hriznWidgets()['hrizn.latestContent'];
    $w = $fn();
    expect($w['type'])->toBe('list')
        ->and($w['payload']['rows'])->toHaveCount(5);
    $first = $w['payload']['rows'][0];
    expect($first['sublabel'])->toBe('basic')
        ->and($first['href'])->toStartWith('/dashboard/hrizn/content/')
        ->and($first['label'])->toStartWith('hc_');
});

it('contentByType returns a donut chart grouped by article_type', function () {
    // core widgets.ts contentByType (116-140): grouped by articleType, friendly labels.
    $u = pluginTestUser();
    $ic = HriznIdeacloud::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'keyword' => 'k', 'status' => 'complete', 'hrizn_id' => (string) Str::uuid(), 'created_by' => $u->id]);
    foreach (['basic', 'basic', 'qa'] as $t) {
        HriznContent::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
            'ideacloud_id' => $ic->id, 'article_type' => $t, 'status' => 'complete', 'created_by' => $u->id]);
    }

    [, $fn] = hriznWidgets()['hrizn.contentByType'];
    $w = $fn();
    expect($w['type'])->toBe('chart');
    $byLabel = collect($w['payload']['data'])->pluck('value', 'label');
    expect($byLabel['Basic'])->toBe(2)->and($byLabel['Q&A'])->toBe(1);
});

it('recentIdeaclouds returns the 5 most recent ideaclouds keyed by keyword', function () {
    // core widgets.ts recentIdeaclouds (146-177): label = keyword || hriznId, sublabel = 'IdeaCloud'.
    $u = pluginTestUser();
    foreach (range(1, 6) as $i) {
        HriznIdeacloud::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
            'keyword' => "kw {$i}", 'status' => 'complete', 'hrizn_id' => (string) Str::uuid(), 'created_by' => $u->id]);
    }

    [, $fn] = hriznWidgets()['hrizn.recentIdeaclouds'];
    $w = $fn();
    expect($w['type'])->toBe('list')
        ->and($w['payload']['rows'])->toHaveCount(5);
    $first = $w['payload']['rows'][0];
    expect($first['sublabel'])->toBe('IdeaCloud')
        ->and($first['label'])->toStartWith('kw ')
        ->and($first['href'])->toStartWith('/dashboard/hrizn/ideaclouds/');
});

it('manifest exposes the 4 widget keys and the settings admin fields', function () {
    $manifest = hriznProvider()->manifest();

    expect(array_keys($manifest->settingsDefaults()))
        ->toContain('defaultArticleType')->toContain('defaultContentIntent')->toContain('webhookCallbackUrl');
    expect($manifest->settingsDefaults()['defaultArticleType'])->toBe('basic');
});
