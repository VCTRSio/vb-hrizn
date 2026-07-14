<?php

declare(strict_types=1);

/**
 * Spec §10 byte-compat acceptance.
 *
 * Proves the standalone release tools (VM_SRC/tools/sign.php + verify.php) are
 * byte-for-byte interoperable with the app's authoritative signing utility
 * App\Plugins\ArtifactSigning, in BOTH directions:
 *
 *   1. sign with tools/sign.php  → verify with ArtifactSigning::verifyBytes
 *   2. sign with ArtifactSigning → verify with tools/verify.php (exit 0)
 *
 * …plus a digest cross-check (sign.php stdout == ArtifactSigning::digestBytes)
 * and tamper checks in each direction. Keys are a throwaway keypair generated
 * in-test — the real VCTRS key is not needed here.
 *
 * The tools are shelled out (real subprocess) at VM_SRC/tools/*.php; VM_SRC is
 * the read-only plugin mount provided by scripts/test-in-app.sh.
 */

use App\Plugins\ArtifactSigning;
use Symfony\Component\Process\Process;

require_once __DIR__.'/hz_bootstrap.php';

/**
 * Run a plugin tool subprocess; returns [exitCode, stdout, stderr].
 *
 * @param  list<string>  $args
 * @return array{0:int,1:string,2:string}
 */
function hzRunTool(string $tool, array $args): array
{
    $proc = new Process([PHP_BINARY, hzSrc().'/tools/'.$tool, ...$args]);
    $proc->run();

    return [$proc->getExitCode(), $proc->getOutput(), $proc->getErrorOutput()];
}

it('is byte-compatible: tools/sign.php ↔ ArtifactSigning::verifyBytes', function () {
    expect(is_file(hzSrc().'/tools/sign.php'))->toBeTrue();

    $keys = ArtifactSigning::generateKeypair();

    $blob = random_bytes(4096);
    $fixture = tempnam(sys_get_temp_dir(), 'hz-compat-').'.bin';
    file_put_contents($fixture, $blob);

    try {
        // Sign with the standalone tool (key passed as a raw base64 string arg).
        [$code, $out, $err] = hzRunTool('sign.php', [$fixture, $keys['privateKey']]);
        expect($code)->toBe(0, "sign.php failed: $err");

        // Digest cross-check: sign.php stdout == ArtifactSigning::digestBytes.
        expect(trim($out))->toBe(ArtifactSigning::digestBytes($blob));

        // The tool wrote <fixture>.sig next to the archive.
        $sig = trim((string) file_get_contents($fixture.'.sig'));
        expect($sig)->not->toBe('');

        // The APP verifies the tool's signature.
        expect(ArtifactSigning::verifyBytes($blob, $sig, $keys['publicKey']))->toBeTrue();

        // Tampered payload must NOT verify.
        expect(ArtifactSigning::verifyBytes($blob.'x', $sig, $keys['publicKey']))->toBeFalse();
    } finally {
        @unlink($fixture);
        @unlink($fixture.'.sig');
    }
});

it('is byte-compatible: ArtifactSigning::signBytes ↔ tools/verify.php', function () {
    expect(is_file(hzSrc().'/tools/verify.php'))->toBeTrue();

    $keys = ArtifactSigning::generateKeypair();

    $blob = random_bytes(4096);
    $fixture = tempnam(sys_get_temp_dir(), 'hz-compat-').'.bin';
    file_put_contents($fixture, $blob);

    // The APP signs; the standalone tool verifies.
    $sig = ArtifactSigning::signBytes($blob, $keys['privateKey']);
    $sigFile = $fixture.'.sig';
    file_put_contents($sigFile, $sig);

    // Tampered copy for the negative case.
    $tampered = tempnam(sys_get_temp_dir(), 'hz-compat-').'.bin';
    file_put_contents($tampered, $blob.'x');

    try {
        // Valid signature → exit 0 (sig + pubkey passed as raw strings).
        [$code] = hzRunTool('verify.php', [$fixture, $sig, $keys['publicKey']]);
        expect($code)->toBe(0);

        // Also accepts sig/pubkey as file paths.
        $pubFile = $fixture.'.pub';
        file_put_contents($pubFile, $keys['publicKey']);
        [$codeFiles] = hzRunTool('verify.php', [$fixture, $sigFile, $pubFile]);
        expect($codeFiles)->toBe(0);
        @unlink($pubFile);

        // Tampered payload → exit 1.
        [$codeBad] = hzRunTool('verify.php', [$tampered, $sig, $keys['publicKey']]);
        expect($codeBad)->toBe(1);
    } finally {
        @unlink($fixture);
        @unlink($sigFile);
        @unlink($tampered);
    }
});
