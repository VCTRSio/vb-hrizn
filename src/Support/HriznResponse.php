<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

use Closure;
use Illuminate\Http\JsonResponse;
use Vctrs\Plugins\VbHrizn\Services\HriznApiException;

/**
 * Maps HriznClient exceptions to HTTP JSON responses. Ports core router.ts
 * callHrizn() (status → tRPC code) + getClient()'s PRECONDITION_FAILED.
 */
final class HriznResponse
{
    /** @var array<int, int> API status → HTTP status (identity for the mapped ones). */
    private const STATUS_MAP = [400 => 400, 401 => 401, 403 => 403, 404 => 404, 429 => 429];

    /**
     * @param  Closure(): JsonResponse  $fn
     */
    public static function guard(Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (HriznPreconditionException $e) {
            return response()->json(['message' => $e->getMessage()], 412);
        } catch (HriznApiException $e) {
            $http = self::STATUS_MAP[$e->status()] ?? 500;

            return response()->json(['message' => $e->getMessage(), 'code' => $e->code()], $http);
        }
    }
}
