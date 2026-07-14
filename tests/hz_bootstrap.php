<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the HRIZN test suite.
 *
 * The plugin is mounted read-only at env HZ_SRC (default /hz-src) inside the app
 * container; only the plugin's tests/ dir is synced into the worktree. So the
 * plugin's own classes are NOT on Composer's autoload map. This file:
 *
 *   - registers a PSR-4 autoloader mapping Vctrs\Plugins\VbHrizn\ → HZ_SRC/src
 *     (for unit / job tests that reference plugin classes without installing it);
 *   - provides hzRunMigrations() to create the plugin tables directly (unit tests);
 *   - provides hzBindTenant() to bind a TenantContext;
 *   - provides hzInstallSignedAndBoot() — the proven signed-install → refresh →
 *     explicit-migrate → bootProviders sequence used by feature tests.
 *
 * Every function is guarded so requiring this file from multiple test files is safe.
 */

use App\Plugins\ArtifactSigning;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Plugins\PluginMigrator;
use App\Support\TenantContext;
use Illuminate\Support\Str;

if (! function_exists('hzSrc')) {
    function hzSrc(): string
    {
        return getenv('HZ_SRC') ?: '/hz-src';
    }
}

// ── PSR-4 autoloader for the mounted plugin src (idempotent) ────────────────────
if (! defined('HZ_AUTOLOAD_REGISTERED')) {
    define('HZ_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Vctrs\\Plugins\\VbHrizn\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $rel = substr($class, strlen($prefix));
        $file = hzSrc().'/src/'.str_replace('\\', '/', $rel).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

if (! function_exists('hzRunMigrations')) {
    /**
     * Run the plugin's migration files directly (idempotent — each migration
     * short-circuits when its table already exists). Used by unit/job tests that
     * exercise plugin services against real tables without a full install.
     */
    function hzRunMigrations(): void
    {
        $dir = hzSrc().'/database/migrations';
        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $migration = require $path; // returns the anonymous Migration instance
            $migration->up();
        }
    }
}

if (! function_exists('hzBindTenant')) {
    /**
     * Bind (and return) a TenantContext for the given user in PLUGIN_TEST_TENANT.
     */
    function hzBindTenant(string $userId, string $tenantId = PLUGIN_TEST_TENANT, string $type = 'rooftop'): TenantContext
    {
        // Empty traceId (matching the in-plugin test convention): the AuditObserver
        // then mints a fresh uuid per write, so multiple writes to the same
        // resource in one test don't collide on the audit_events unique key
        // (trace_id, action, resource_type, resource_id).
        $ctx = new TenantContext($userId, $type, $tenantId, '');
        app()->instance(TenantContext::class, $ctx);

        return $ctx;
    }
}

if (! function_exists('hzZip')) {
    /**
     * Recursively zip the mounted plugin source (manifest + src/ + database/ +
     * dist/) and return the temp ZIP path. Mirrors the shipping artifact the
     * installer expects.
     */
    function hzZip(?string $srcDir = null): string
    {
        $srcDir = rtrim($srcDir ?? hzSrc(), '/');
        $zipPath = tempnam(sys_get_temp_dir(), 'hz-zip-').'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $file) {
            $rel = ltrim(str_replace($srcDir, '', $file->getPathname()), '/');
            if (! preg_match('#^(manifest\.json|src/|database/|dist/)#', $rel)) {
                continue; // ship only what install + runtime need
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $zip->close();

        return $zipPath;
    }
}

if (! function_exists('hzInstallSignedAndBoot')) {
    /**
     * Install the plugin from a freshly-signed ZIP (real VCTRS key from env),
     * then boot it so its routes/widgets/tables are live.
     *
     * CORE GAP (documented, not patched): PluginInstaller::installFromZip() runs
     * plugin migrations BEFORE PluginManager::refresh(), and PluginManager is a
     * boot-discovered singleton, so the uploaded plugin's migrations are silently
     * skipped at install time. We therefore refresh() the manager (so it
     * rediscovers the freshly-installed dir), then run the plugin migrations
     * explicitly via PluginMigrator, then bootProviders() to execute the plugin's
     * register() (routes) — this proves the plugin code itself is correct.
     */
    function hzInstallSignedAndBoot(TenantContext $ctx): void
    {
        $priv = getenv('HZ_PRIV');
        $pub = getenv('HZ_PUB');

        config()->set('plugins.registry_pubkey', $pub);
        config()->set('plugins.require_signature', true);

        // Each install extracts the plugin to storage/app/plugins/<slug> — a
        // NON-transactional on-disk write that survives the test's DB rollback.
        // Left in place, the next install's "already installed" guard (which
        // reads PluginManager::manifest() from the disk scan, not the DB) trips.
        // Remove any leftover dir and force a rescan so every test installs clean.
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/plugins/vb-hrizn'));
        app(PluginManager::class)->refresh();

        $zip = hzZip();
        $sig = ArtifactSigning::signBytes((string) file_get_contents($zip), (string) $priv);

        try {
            app(PluginInstaller::class)->installFromZip($zip, $ctx, $sig, null);
        } finally {
            @unlink($zip);
        }

        $mgr = app(PluginManager::class);
        $mgr->refresh();
        app(PluginMigrator::class)->migrate('vb-hrizn');
        $mgr->bootProviders();
    }
}

if (! function_exists('hzFeatureUser')) {
    /**
     * Feature-test setup: create a user with the given plugin permissions in
     * PLUGIN_TEST_TENANT, bind its context, and install+boot the signed plugin so
     * its routes/tables are live. Returns the user for actingAs().
     *
     * @param  list<string>  $overrides  membership permission overrides
     */
    function hzFeatureUser(array $overrides = ['+vb-hrizn.read.rooftop', '+vb-hrizn.write.rooftop']): \App\Models\User
    {
        $user = pluginTestUser('rooftop_owner', $overrides);
        $ctx = hzBindTenant($user->id);
        hzInstallSignedAndBoot($ctx);

        return $user;
    }
}
