<?php

namespace App\Services;

use App\Models\Extension;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Installs add-on ZIPs (Instagram is the first).
 *
 * Deliberately NOT a fork of UpdaterService. An update REPLACES the app with a
 * newer build of itself; an add-on MERGES an extra feature into whatever build
 * is already here. Two different operations, two different failure modes — but
 * they share one thing, licence verification, and that is delegated rather
 * than duplicated so a token/endpoint change only has to happen once.
 *
 * ZIP CONTRACT
 * ------------
 * The ZIP must contain an `extension.json` at its root:
 *
 *   { "slug": "instagram", "name": "Instagram", "version": "1.0.0",
 *     "min_core": "1.4.0" }
 *
 * Everything alongside it is extracted into addon/<slug>/ (preserving paths),
 * so the add-on runs IN PLACE via App\Services\ModuleLoader — never spread into
 * the core tree. The manifest of what was written is stored on the extensions
 * row, and uninstall just removes that addon/<slug>/ folder.
 */
class ExtensionService
{
    private string $tempDir;

    /** Never let an add-on overwrite these — that is an update's job, not an add-on's. */
    private const PROTECTED_PATHS = [
        '.env', 'composer.json', 'composer.lock',
        // license.json carries the encrypted Envato token that license.php reads —
        // protect it alongside, or an add-on could replace the licence material.
        'config/license.php', 'config/license.json', 'config/version.php',
        'storage', 'vendor', 'node_modules',
    ];

    public function __construct(private UpdaterService $updater)
    {
        $this->tempDir = storage_path('app/extensions');
    }

    /**
     * Licence check. Delegated to the Updater so there is ONE definition of a
     * valid purchase code — the add-on is unlocked by the same WaDesk licence
     * the buyer already has.
     */
    public function verifyPurchase(string $code): array
    {
        return $this->updater->verifyPurchase($code);
    }

    public function saveUploadedZip($uploadedFile): string
    {
        File::ensureDirectoryExists($this->tempDir, 0755, true);
        $path = $this->tempDir . '/extension.zip';
        if (File::exists($path)) {
            File::delete($path);
        }
        $uploadedFile->move($this->tempDir, 'extension.zip');

        return $path;
    }

    /**
     * Read extension.json WITHOUT extracting. We refuse a malformed package before
     * a single file lands in the install — a half-merged add-on is far harder
     * to recover from than a rejected upload.
     *
     * @return array{ok:bool, message?:string, manifest?:array, root?:string}
     */
    public function inspect(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'That file is not a readable ZIP archive.'];
        }

        // Tolerate a single wrapper folder — most people zip the directory,
        // not its contents, and rejecting that is a pointless support ticket.
        $root = '';
        if ($zip->locateName('extension.json') === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (preg_match('~^([^/]+)/extension\.json$~', $n, $m)) {
                    $root = $m[1] . '/';
                    break;
                }
            }
        }

        $raw = $zip->getFromName($root . 'extension.json');
        $zip->close();

        if ($raw === false) {
            return ['ok' => false, 'message' => 'No extension.json found in the ZIP — this is not a WaDesk extension package.'];
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || empty($manifest['slug'])) {
            return ['ok' => false, 'message' => 'extension.json is malformed — it needs at least a "slug".'];
        }

        // Refuse an add-on built for a newer core than this install. Merging it
        // would half-work: files land, but they call methods that do not exist
        // yet, and the failure shows up later as an unexplained 500.
        $minCore = (string) ($manifest['min_core'] ?? '');
        $core    = (string) config('version.version', '0.0.0');
        if ($minCore !== '' && version_compare($core, $minCore, '<')) {
            return [
                'ok' => false,
                'message' => "This add-on needs WaDesk {$minCore} or newer — this install is {$core}. Update first, then add it.",
            ];
        }

        return [
            'ok'       => true,
            'manifest' => [
                'slug'     => (string) $manifest['slug'],
                'name'     => (string) ($manifest['name'] ?? $manifest['slug']),
                'version'  => (string) ($manifest['version'] ?? '1.0.0'),
                'min_core' => $minCore,
                // Contribution declarations — nav entries, plan feature flags,
                // plan limits. Carried through verbatim so ExtensionRegistry is
                // the single place that interprets them.
                'nav'           => (array) ($manifest['nav'] ?? []),
                'plan_features' => (array) ($manifest['plan_features'] ?? []),
                'plan_limits'   => (array) ($manifest['plan_limits'] ?? []),
                'routes'        => (array) ($manifest['routes'] ?? []),
                'migrate'       => (bool) ($manifest['migrate'] ?? true),
            ],
            'root' => $root,
        ];
    }

    /**
     * Extract into the install and record what was written.
     *
     * @return array{ok:bool, message?:string, files?:int}
     */
    public function install(string $zipPath, array $manifest, string $root, ?string $purchaseCode, ?int $userId): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'Could not reopen the ZIP for extraction.'];
        }

        $written = [];
        $skipped = [];

        // Everything installs UNDER addon/<slug>/ so the add-on runs IN PLACE
        // via App\Services\ModuleLoader (which scans addon/*/extension.json) —
        // nothing is spread into the core tree. Removing the add-on is then
        // just deleting this one folder, and the operator can see exactly what
        // an add-on added by looking at addon/<slug>/.
        $installRoot = 'addon/' . $manifest['slug'];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false) continue;

                // Strip the wrapper folder, if there was one.
                $rel = $root !== '' && str_starts_with($entry, $root)
                    ? substr($entry, strlen($root))
                    : $entry;

                if ($rel === '' || str_ends_with($rel, '/')) continue;

                // Zip-slip guard: a crafted entry like ../../.env would escape
                // the install root entirely. Refuse anything with a traversal
                // segment before it is ever joined to a path.
                if (str_contains($rel, '..')) {
                    $skipped[] = $rel;
                    continue;
                }
                // NOTE: extension.json is NOT skipped — ModuleLoader needs
                // addon/<slug>/extension.json on disk to discover and run the
                // module. It is written like any other payload file.
                if ($rel !== 'extension.json' && $this->isProtected($rel)) {
                    $skipped[] = $rel;
                    continue;
                }

                // Final on-disk path lives inside addon/<slug>/.
                $installRel = $installRoot . '/' . $rel;
                $target     = base_path($installRel);
                File::ensureDirectoryExists(dirname($target), 0755, true);

                $stream = $zip->getStream($entry);
                if (!$stream) { $skipped[] = $rel; continue; }
                file_put_contents($target, stream_get_contents($stream));
                fclose($stream);

                $written[] = $installRel;
            }
        } finally {
            $zip->close();
        }

        if (!$written) {
            return ['ok' => false, 'message' => 'The ZIP contained no installable files.'];
        }

        // firstOrNew, not create — re-installing an add-on is an UPGRADE of the
        // existing row, so the slug stays unique and the manifest reflects the
        // newest install rather than accumulating stale paths.
        $extension = Extension::firstOrNew(['slug' => $manifest['slug']]);
        $extension->fill([
            'name'         => $manifest['name'],
            'version'      => $manifest['version'],
            'status'       => Extension::STATUS_ACTIVE,
            'files'        => $written,
            'manifest'     => $manifest,
            'installed_at' => now(),
            'installed_by' => $userId,
        ]);
        if ($purchaseCode) {
            $extension->purchase_code = $purchaseCode;
        }
        $extension->save();

        Extension::forget($manifest['slug']);
        $this->clearCaches();

        // An extension that ships tables is useless until they exist. Run this
        // AFTER the files land (the migration files are part of the payload)
        // and AFTER the row is saved, so a migration failure leaves a visible
        // installed-but-broken extension the operator can remove, rather than
        // orphaned files with no row pointing at them.
        $migration = ($manifest['migrate'] ?? true)
            ? $this->runMigrations($manifest['slug'])
            : ['ran' => false, 'error' => null];

        Log::info('[EXTENSION] installed', [
            'slug' => $manifest['slug'], 'version' => $manifest['version'],
            'files' => count($written), 'skipped' => count($skipped),
            'migrated' => $migration['ran'],
        ]);

        return [
            'ok'               => true,
            'files'            => count($written),
            'skipped'          => count($skipped),
            'migrated'         => $migration['ran'],
            'migration_error'  => $migration['error'],
        ];
    }

    /**
     * Remove the files this add-on wrote, then the row.
     *
     * Only touches paths in the stored manifest — never a directory sweep, so
     * a core file that happens to sit beside an add-on file is safe.
     */
    public function uninstall(Extension $extension): array
    {
        $removed = 0;
        $dirs    = [];
        foreach ((array) $extension->files as $rel) {
            if (!is_string($rel) || str_contains($rel, '..') || $this->isProtected($rel)) {
                continue;
            }
            $p = base_path($rel);
            if (File::isFile($p)) {
                File::delete($p);
                $removed++;
                $dirs[dirname($p)] = true;
            }
        }

        $this->pruneEmptyDirs(array_keys($dirs));

        $slug = $extension->slug;
        $extension->delete();
        Extension::forget($slug);
        $this->clearCaches();

        Log::info('[EXTENSION] uninstalled', ['slug' => $slug, 'files_removed' => $removed]);

        return ['ok' => true, 'removed' => $removed];
    }

    /**
     * Delete directories the removed files left empty, walking upward.
     *
     * Without this an uninstall leaves a skeleton of empty folders behind that
     * looks like the extension is still half-installed. Stops the moment a
     * directory still has anything in it, and never climbs past base_path(),
     * so a shared parent like app/ or resources/views/ is never at risk.
     */
    private function pruneEmptyDirs(array $dirs): void
    {
        $root = rtrim(str_replace('\\', '/', base_path()), '/');

        foreach ($dirs as $dir) {
            $cur = rtrim(str_replace('\\', '/', $dir), '/');

            while ($cur !== $root && str_starts_with($cur, $root . '/')) {
                if (!File::isDirectory($cur)) break;
                // Anything left (including another extension's file) means stop.
                if (count(File::allFiles($cur)) > 0 || count(File::directories($cur)) > 0) break;

                File::deleteDirectory($cur);
                $cur = rtrim(str_replace('\\', '/', dirname($cur)), '/');
            }
        }
    }

    private function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::PROTECTED_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, rtrim($p, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run any migrations the extension shipped.
     *
     * `--force` because this always runs in a production context; there is no
     * interactive console to confirm at. Already-applied migrations are skipped
     * by Laravel's own ledger, so re-installing an extension over itself is
     * safe and does not re-run anything.
     *
     * A failure here is reported, never thrown: the files are already on disk,
     * and blowing up mid-install would leave the operator with no row to
     * uninstall and no message explaining why.
     *
     * @return array{ran:bool, error:?string}
     */
    private function runMigrations(string $slug): array
    {
        try {
            // The add-on's migrations live in addon/<slug>/database/migrations.
            // ModuleServiceProvider registers that path on a NORMAL boot, but on
            // THIS request the files only just landed — so point migrate straight
            // at the folder. Laravel's ledger still skips already-applied ones,
            // so re-installing over an existing add-on re-runs nothing.
            $migPath = 'addon/' . $slug . '/database/migrations';
            $args    = ['--force' => true];
            if (is_dir(base_path($migPath))) {
                $args['--path']     = $migPath;
                $args['--realpath'] = false;
            }
            \Artisan::call('migrate', $args);
            $output = trim(\Artisan::output());
            Log::info('[EXTENSION] migrations run', ['slug' => $slug, 'output' => $output]);

            return ['ran' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('[EXTENSION] migration failed', ['slug' => $slug, 'error' => $e->getMessage()]);

            return ['ran' => false, 'error' => $e->getMessage()];
        }
    }

    private function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear'] as $cmd) {
            try { \Artisan::call($cmd); } catch (\Throwable $e) { /* best effort */ }
        }
    }

    /**
     * Self-heal the "installed add-on 404s" case.
     *
     * In-place module routes are required inside withRouting(then:) — so when a
     * server runs `php artisan route:cache` (common in production), that compiled
     * table is a SNAPSHOT. Install an add-on afterwards and its pages 404, because
     * a cached app serves ONLY the cache and never re-runs the closure that loads
     * addon/<slug>/routes. The install DOES route:clear, but OPcache / a deploy
     * hook can re-cache a stale table.
     *
     * This detects exactly that: an ACTIVE in-place module whose own page URL is
     * missing from the compiled route table → drop the route cache so the next
     * request rebuilds routes from the closures (with the addon routes present).
     * Returns true if it cleared anything, so the caller can reload once.
     *
     * Costs nothing on a normal (uncached) app — it bails immediately.
     */
    public function healModuleRoutes(): bool
    {
        // A non-cached app always registers module routes at boot: nothing stale.
        if (!app()->routesAreCached()) {
            return false;
        }

        $manifests = \App\Services\ModuleLoader::manifests(); // live/active only
        if (empty($manifests)) {
            return false;
        }

        // Every URI currently in the compiled route table.
        $registered = [];
        foreach (\Route::getRoutes() as $r) {
            $registered['/' . ltrim($r->uri(), '/')] = true;
        }

        foreach ($manifests as $m) {
            if (empty($m['routes'])) {
                continue; // declares no routes → can't be missing any
            }
            // Use the module's own nav hrefs as canary URLs — the exact static
            // paths the operator hits (e.g. /admin/settings/instagram).
            foreach (['admin', 'user'] as $side) {
                foreach ((array) data_get($m, "nav.$side", []) as $item) {
                    $href = explode('?', '/' . ltrim((string) ($item['href'] ?? ''), '/'))[0];
                    if ($href === '/' || str_contains($href, '{')) {
                        continue;
                    }
                    if (!isset($registered[$href])) {
                        // An active module's page isn't in the compiled table →
                        // the cache predates it. Clear so the next request rebuilds.
                        try { \Artisan::call('route:clear'); } catch (\Throwable $e) {}
                        Log::info('[EXTENSION] stale route cache cleared for active module', [
                            'slug' => $m['slug'] ?? null, 'missing' => $href,
                        ]);
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
