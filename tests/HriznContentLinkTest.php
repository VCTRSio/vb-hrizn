<?php

declare(strict_types=1);

use App\Models\EntityReference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

const HZ_INV_DIR = 'Vctrs\\Plugins\\InventoryHub\\InventoryDirectory';

function hzBindInventoryStub(array $byVin): void
{
    app()->instance(HZ_INV_DIR, new class($byVin)
    {
        /** @param array<string, array<string, mixed>> $byVin */
        public function __construct(private array $byVin) {}

        public function lookupByVin(string $tt, string $tid, string $vin): ?array
        {
            return $this->byVin[strtoupper($vin)] ?? null;
        }

        /** @return array<int, array<string, mixed>> */
        public function search(string $tt, string $tid, ?string $q = null, ?string $status = 'active', int $limit = 20): array
        {
            return array_values($this->byVin);
        }
    });
}

beforeEach(function () {
    $user = hzFeatureUser(['+hrizn.content.read.rooftop', '+hrizn.content.write.rooftop', '+hrizn.ideacloud.write.rooftop']);
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_ok']);
    // Seed the local ideacloud that generate() resolves so it stores a valid uuid
    // ideacloud_id (the column is uuid-typed) and the content row is actually created.
    HriznIdeacloud::withoutTenantScope()->create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'keyword' => 'k',
        'status' => 'complete', 'hrizn_id' => 'ic-uuid', 'created_by' => (string) Str::uuid(),
    ]);
    $this->user = $user;
});

it('links content to a vehicle by VIN for a modellanding article', function () {
    hzBindInventoryStub(['1HGCM82633A004352' => ['vin' => '1HGCM82633A004352', 'year' => 2022, 'make' => 'Honda', 'model' => 'Accord', 'trim' => 'EX']]);
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response(['data' => ['id' => 'art_link']])]);

    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'modellanding', 'vehicleVin' => '1hgcm82633a004352',
    ])->assertOk();

    $ref = EntityReference::query()
        ->where('source_type', 'vb-hrizn.content')
        ->where('target_type', 'inventory_vehicle')
        ->where('target_id', '1HGCM82633A004352')
        ->where('relation', 'covers')->first();
    expect($ref)->not->toBeNull();
});

it('does not link when the article type is not vehicle-specific', function () {
    hzBindInventoryStub(['1HGCM82633A004352' => ['vin' => '1HGCM82633A004352', 'make' => 'Honda', 'model' => 'Accord']]);
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response(['data' => ['id' => 'art_basic']])]);

    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'basic', 'vehicleVin' => '1HGCM82633A004352',
    ])->assertOk();

    expect(EntityReference::query()->where('source_type', 'vb-hrizn.content')->count())->toBe(0);
});

it('does not link (but still creates content) when the VIN does not resolve', function () {
    hzBindInventoryStub([]); // no vehicles
    Http::fake(['api.app.hrizn.io/v1/public/content' => Http::response(['data' => ['id' => 'art_novin']])]);

    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'comparison', 'vehicleVin' => 'ZZZZZZZZZZZZZZZZZ',
    ])->assertOk();

    expect(EntityReference::query()->where('source_type', 'vb-hrizn.content')->count())->toBe(0);
});

it('exposes linkedVehicles on apiGet', function () {
    hzBindInventoryStub(['1HGCM82633A004352' => ['vin' => '1HGCM82633A004352', 'year' => 2022, 'make' => 'Honda', 'model' => 'Accord', 'trim' => 'EX']]);
    Http::fake([
        'api.app.hrizn.io/v1/public/content*' => Http::response(['data' => ['id' => 'art_get', 'title' => 'X']]),
    ]);
    // Generate first (creates local row + link).
    $this->actingAs($this->user)->postJson('/api/v1/hrizn/content', [
        'ideacloudId' => 'ic-uuid', 'articleType' => 'modellanding', 'vehicleVin' => '1HGCM82633A004352',
    ])->assertOk();

    $res = $this->actingAs($this->user)->getJson('/api/v1/hrizn/content/art_get')->assertOk()->json();
    expect($res['data']['linkedVehicles'][0]['vin'])->toBe('1HGCM82633A004352')
        ->and($res['data']['linkedVehicles'][0]['make'])->toBe('Honda');
});
