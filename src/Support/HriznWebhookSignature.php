<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

/**
 * Verify an inbound Hrizn webhook signature. Ports core client.ts
 * verifyHriznWebhookSignature: header format is "sha256=<hex hmac_sha256(rawBody,
 * secret)>", compared in constant time with hash_equals.
 */
final class HriznWebhookSignature
{
    public static function verify(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
