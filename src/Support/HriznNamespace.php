<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

use App\Models\PluginNamespace;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Per-tenant Hrizn secret store over App\Models\PluginNamespace.
 *
 * QOL DIVERGENCE: core (router.ts saveNamespace/getNamespace) stores apiKey +
 * webhookSecret as PLAINTEXT in pluginNamespaces.dataJson (README "Known issues"
 * T2B-18). Here the secret blob is encrypted with Crypt::encryptString before
 * write and decrypted on read, matching this codebase's house style
 * (SettingsAiController encrypts BYOK keys the same way; the GBP schema mandates
 * *_encrypted columns). The encrypted blob lives under data_json['secrets'] and
 * is kept separate from data_json['settings'] (owned by PluginSettings), so the
 * settings cascade and this secret store never collide.
 */
final class HriznNamespace
{
    private const SLUG = 'hrizn';

    /** @return array<string, mixed> */
    public static function get(string $tenantType, string $tenantId): array
    {
        $ns = PluginNamespace::query()->where('namespace', self::key($tenantId))->first();
        if ($ns === null) {
            return [];
        }

        $blob = $ns->data_json['secrets'] ?? null;
        if (! is_string($blob) || $blob === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($blob), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function patch(string $tenantType, string $tenantId, array $patch): void
    {
        $current = self::get($tenantType, $tenantId);
        self::write($tenantType, $tenantId, array_merge($current, $patch));
    }

    public static function clear(string $tenantType, string $tenantId): void
    {
        self::write($tenantType, $tenantId, []);
    }

    /**
     * @param  array<string, mixed>  $secrets
     */
    private static function write(string $tenantType, string $tenantId, array $secrets): void
    {
        $ns = PluginNamespace::firstOrNew(['namespace' => self::key($tenantId)]);
        $existing = $ns->getAttribute('data_json');
        $data = is_array($existing) ? $existing : [];
        $data['secrets'] = Crypt::encryptString((string) json_encode($secrets));

        $ns->fill([
            'plugin_slug' => self::SLUG,
            'tenant_type' => $tenantType,
            'tenant_id' => $tenantId,
            'data_json' => $data,
        ])->save();
    }

    private static function key(string $tenantId): string
    {
        return self::SLUG.':'.$tenantId;
    }
}
