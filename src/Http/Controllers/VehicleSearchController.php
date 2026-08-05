<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\InventoryHub\InventoryDirectory;

/**
 * Session-API passthrough to the inventory-hub vehicle picker (PICKER_FIELDS,
 * cost-safe, no invoice/msrp). Returns an empty list when inventory-hub is not
 * installed, so the HRIZN content UI degrades gracefully standalone.
 */
class VehicleSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $q = (string) $request->query('q', '');

        if (! app()->bound(InventoryDirectory::class)) {
            return ApiResponse::success([]);
        }

        $vehicles = app(InventoryDirectory::class)->search(
            $ctx->activeTenantType(), $ctx->activeTenantId(), $q !== '' ? $q : null, 'active', 20,
        );

        return ApiResponse::success($vehicles);
    }
}
