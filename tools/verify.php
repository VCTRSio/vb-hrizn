<?php

declare(strict_types=1);

/**
 * verify.php — standalone Ed25519 verifier for Prana Buzz plugin ZIP artifacts.
 *
 * Dependency-free (ext-sodium only). Mirror of the app's authoritative
 * verifier so a release/CI context can independently confirm a signature
 * WITHOUT booting the framework.
 *
 * ┌─ BYTE-COMPAT DRIFT NOTE (spec §11) ────────────────────────────────────────┐
 * │ This MUST stay byte-compatible with:                                        │
 * │   vctrbase-php/app/Plugins/ArtifactSigning.php  (::verifyBytes)             │
 * │ Same defensive checks: strict base64 decode, signature length ==            │
 * │ SODIUM_CRYPTO_SIGN_BYTES, pubkey length == SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,│
 * │ and a try/catch around sodium_crypto_sign_verify_detached. Any drift here    │
 * │ must be mirrored in ArtifactSigning (and sign.php) and re-tested via         │
 * │ tests/Feature/SigningByteCompatTest.php.                                     │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Usage:
 *   php tools/verify.php <zip> <sig-file-or-string> <pubkey.b64-file-or-string>
 *
 * Both the signature and the public key arguments may be EITHER a path to a
 * file containing the value OR the raw string itself.
 *
 * Exit 0 if the signature is valid, 1 otherwise (or on any error).
 */

function verifyDie(string $message): never
{
    fwrite(STDERR, 'verify.php: '.$message.PHP_EOL);
    exit(1);
}

if (! extension_loaded('sodium')) {
    verifyDie('the sodium extension is required');
}

$argvLocal = $argv ?? [];
if (count($argvLocal) !== 4) {
    verifyDie('usage: php tools/verify.php <zip> <sig-file-or-string> <pubkey.b64-file-or-string>');
}

$zipPath = $argvLocal[1];

if (! is_file($zipPath)) {
    verifyDie('ZIP archive not found: '.$zipPath);
}

$bytes = file_get_contents($zipPath);
if ($bytes === false) {
    verifyDie('could not read archive: '.$zipPath);
}

/**
 * Resolve a value from a raw string or a file path containing one.
 */
function resolveValue(string $input): string
{
    $trimmed = trim($input);
    if ($trimmed !== '' && is_file($trimmed)) {
        return trim((string) file_get_contents($trimmed));
    }

    return $trimmed;
}

$signatureB64 = resolveValue($argvLocal[2]);
$publicKeyB64 = resolveValue($argvLocal[3]);

/**
 * Mirror of App\Plugins\ArtifactSigning::verifyBytes — returns false on any error.
 */
function verifyBytes(string $bytes, string $signatureB64, string $publicKeyB64): bool
{
    $signature = base64_decode($signatureB64, true);
    $publicKey = base64_decode($publicKeyB64, true);

    if ($signature === false || $publicKey === false) {
        return false;
    }

    if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return false;
    }

    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return false;
    }

    try {
        return sodium_crypto_sign_verify_detached($signature, $bytes, $publicKey);
    } catch (\Throwable) {
        return false;
    }
}

exit(verifyBytes($bytes, $signatureB64, $publicKeyB64) ? 0 : 1);
