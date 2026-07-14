<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

use RuntimeException;

/**
 * Thrown when a Hrizn API call is attempted without a configured API key.
 * Ports core's TRPCError PRECONDITION_FAILED — mapped to HTTP 412 by controllers.
 */
final class HriznPreconditionException extends RuntimeException {}
