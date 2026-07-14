<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

use Vctrs\Plugins\VbHrizn\Services\HriznClient;

/**
 * Resolves a per-tenant HriznClient from the encrypted namespace. Centralises
 * the "no API key configured" precondition (core router.ts getClient()).
 */
final class HriznClientFactory
{
    public function for(string $tenantType, string $tenantId): HriznClient
    {
        $ns = HriznNamespace::get($tenantType, $tenantId);
        $apiKey = $ns['apiKey'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            throw new HriznPreconditionException('No Hrizn API key configured. Go to Settings → Hrizn Content.');
        }

        return new HriznClient($apiKey);
    }
}
