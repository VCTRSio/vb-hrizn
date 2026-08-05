<?php

declare(strict_types=1);

require_once __DIR__.'/hz_bootstrap.php';

const HZ_INV_DIR_S = 'Vctrs\\Plugins\\InventoryHub\\InventoryDirectory';

beforeEach(function () {
    $this->user = hzFeatureUser(['+hrizn.content.write.rooftop']);
});

it('returns vehicles from the inventory directory for a query', function () {
    app()->instance(HZ_INV_DIR_S, new class
    {
        public function search(string $tt, string $tid, ?string $q = null, ?string $status = 'active', int $limit = 20): array
        {
            return [['vin' => 'VIN1', 'make' => 'Honda', 'model' => 'Accord']];
        }
    });

    $res = $this->actingAs($this->user)->getJson('/api/v1/hrizn/vehicles/search?q=hond')->assertOk()->json();
    expect($res['data'][0]['vin'])->toBe('VIN1');
});

it('returns an empty list when inventory-hub is not bound', function () {
    // Ensure the binding is absent.
    if (app()->bound(HZ_INV_DIR_S)) {
        app()->forgetInstance(HZ_INV_DIR_S);
    }
    $res = $this->actingAs($this->user)->getJson('/api/v1/hrizn/vehicles/search?q=x')->assertOk()->json();
    expect($res['data'])->toBe([]);
});

it('is gated by the content.write permission', function () {
    $viewer = pluginTestUser('rooftop_owner', ['-hrizn.content.write.rooftop']);
    $this->actingAs($viewer)->getJson('/api/v1/hrizn/vehicles/search?q=x')->assertForbidden();
});
