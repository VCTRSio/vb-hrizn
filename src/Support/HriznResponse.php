<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Vctrs\Plugins\VbHrizn\Services\HriznApiException;

/**
 * Maps HriznClient exceptions to the canonical ApiResponse error envelope. Ports core
 * router.ts callHrizn() (status → code) + getClient()'s PRECONDITION_FAILED, now emitting
 * {traceId,data:null,status:error,error} so the @vctrs/plugin-ui client kit can unwrap it.
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
            return ApiResponse::error($e->getMessage(), 412);
        } catch (HriznApiException $e) {
            return ApiResponse::error($e->getMessage(), self::STATUS_MAP[$e->status()] ?? 500);
        }
    }
}
