<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Services;

use RuntimeException;

/**
 * Ports core client.ts HriznApiError. Carries the HTTP status, the API error
 * `code`, and the optional `request_id` returned by the Hrizn API so the router
 * layer can map status → HTTP response codes.
 */
final class HriznApiException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
