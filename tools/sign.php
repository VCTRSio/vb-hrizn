<?php

declare(strict_types=1);

/**
 * sign.php — standalone Ed25519 signer for Prana Buzz plugin ZIP artifacts.
 *
 * Dependency-free (ext-sodium only). No Composer, no framework — so it can run
 * in any release/CI context that has PHP + sodium.
 *
 * ┌─ BYTE-COMPAT DRIFT NOTE (spec §11) ────────────────────────────────────────┐
 * │ This MUST stay byte-compatible with the app's authoritative implementation: │
 * │   vctrbase-php/app/Plugins/ArtifactSigning.php                              │
 * │ and its CLI wrapper vctrbase-php/app/Console/Commands/PluginSignCommand.php.│
 * │ Specifically:                                                               │
 * │   signature = base64_encode(sodium_crypto_sign_detached($bytes, $privRaw))  │
 * │   digest    = hash('sha256', $bytes)  // lowercase hex                       │
 * │   keys are base64-encoded RAW ed25519 bytes (NOT DER/PEM/PKCS8/SPKI).        │
 * │ If ArtifactSigning changes, update this file (and verify.php) in lockstep    │
 * │ and re-run tests/Feature/SigningByteCompatTest.php.                          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Usage:
 *   php tools/sign.php <zip> <privkey.b64-file-or-string>
 *
 * The private key argument may be EITHER a path to a file containing the
 * base64 key OR the raw base64 string itself (mirrors PluginSignCommand::resolveKey).
 *
 * On success:
 *   - writes "<zip>.sig" (base64 detached signature) next to the archive;
 *   - prints the sha256 hex digest of the archive bytes to stdout;
 *   - exits 0.
 * On any error prints a message to stderr and exits 1.
 */

function fail(string $message): never
{
    fwrite(STDERR, 'sign.php: '.$message.PHP_EOL);
    exit(1);
}

if (! extension_loaded('sodium')) {
    fail('the sodium extension is required');
}

$argvLocal = $argv ?? [];
if (count($argvLocal) !== 3) {
    fail('usage: php tools/sign.php <zip> <privkey.b64-file-or-string>');
}

$zipPath = $argvLocal[1];
$keyInput = $argvLocal[2];

if (! is_file($zipPath)) {
    fail('ZIP archive not found: '.$zipPath);
}

$bytes = file_get_contents($zipPath);
if ($bytes === false) {
    fail('could not read archive: '.$zipPath);
}

/**
 * Resolve the key from a raw base64 string or a file path containing one.
 * Mirrors App\Console\Commands\PluginSignCommand::resolveKey.
 */
function resolveKey(string $input): ?string
{
    $trimmed = trim($input);
    if ($trimmed === '') {
        return null;
    }
    if (is_file($trimmed)) {
        $contents = trim((string) file_get_contents($trimmed));

        return $contents !== '' ? $contents : null;
    }

    return $trimmed;
}

$privateKeyB64 = resolveKey($keyInput);
if ($privateKeyB64 === null) {
    fail('could not resolve private key from argument');
}

$privateKey = base64_decode($privateKeyB64, true);
if ($privateKey === false) {
    fail('private key is not valid base64');
}

if (strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fail('private key has wrong length (expected '.SODIUM_CRYPTO_SIGN_SECRETKEYBYTES.' raw bytes)');
}

try {
    $signature = base64_encode(sodium_crypto_sign_detached($bytes, $privateKey));
} catch (\Throwable $e) {
    fail('signing failed: '.$e->getMessage());
}

$digest = hash('sha256', $bytes);

$sigPath = $zipPath.'.sig';
if (file_put_contents($sigPath, $signature) === false) {
    fail('could not write signature file: '.$sigPath);
}

echo $digest.PHP_EOL;
exit(0);
