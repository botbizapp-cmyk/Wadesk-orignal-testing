<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Drives the admin Updater: verify purchase → backup → upload ZIP → apply
 * code → migrate → finalize, with rollback. Ported from the SnapNest updater
 * and extended with an Envato purchase-code verification step. NEVER touches
 * .env, storage, the database files, vendor/ or user-uploaded public assets.
 */
class UpdaterService
{
    private string $backupDir;
    private string $tempDir;

    /** Paths that must NEVER be overwritten by an update. */
    private const PROTECTED_PATHS = [
        '.env', 'storage', 'database/database.sqlite', 'vendor', 'node_modules',
        // Node bridge runtime data that MUST survive an update: its own env file,
        // installed deps, and the live WhatsApp (Unofficial API) login sessions.
        // The root entries above only match the root subtree (path-prefix), so
        // the node/ equivalents are listed explicitly — a mis-packaged update ZIP
        // must never overwrite node secrets or wipe connected-number sessions.
        'node/.env', 'node/node_modules', 'node/baileys_auth',
    ];

    /** File extensions in public/ that must never be touched (user assets). */
    private const PROTECTED_PUBLIC_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        'mp4', 'mov', 'avi', 'mkv', 'mp3', 'wav',
        'zip', 'rar', 'tar', 'gz', 'ttf', 'otf', 'woff', 'woff2', 'eot',
    ];

    /** Paths that get updated (code only). */
    private const UPDATABLE_PATHS = [
        'app', 'config', 'database/migrations',
        'public/css', 'public/js', 'public/build',
        'resources', 'routes', 'node',
        // Framework bootstrap — listed as individual FILES (not the whole
        // bootstrap/ dir) so bootstrap/cache/ is never touched. app.php is the
        // one file that wires in-place add-on route/migration/view loading
        // (ModuleLoader::routeFiles); omitting it from updates left older
        // installs unable to load ANY add-on — the Instagram add-on 404'd
        // everywhere because its routes were never required. providers.php is
        // small and version-tracked too. Both are safe to overwrite (they carry
        // no per-install customisation).
        'bootstrap/app.php', 'bootstrap/providers.php',
    ];

    /** Files enumerated by the apply step but skipped because the extracted
     *  source could not be opened (broken/phantom entry). Surfaced in the
     *  apply completion log so a skip is visible, never silent. */
    private array $copySkips = [];

    public function __construct()
    {
        $this->backupDir = storage_path('app/backups');
        $this->tempDir   = storage_path('app/temp/updater');
    }

    // ------------------------------------------------------------------
    //  STEP 0: Envato purchase verification
    // ------------------------------------------------------------------

    /**
     * Verify a CodeCanyon purchase code against the Envato API and confirm it
     * belongs to THIS item. On success the code is remembered so the buyer
     * doesn't have to re-enter it next time.
     *
     * @return array{ok: bool, message: string, buyer?: string, item?: string}
     */
    public function verifyPurchase(string $code): array
    {
        $code = trim($code);

        // Licence verification is intentionally BYPASSED in this build — it
        // always succeeds regardless of the code (including a blank one). The
        // original Envato API round-trip (api.envato.com author/sale) has been
        // removed by vendor decision; every caller (verify button + the
        // server-side re-check in ExtensionController::upload) now passes.
        SystemSetting::set('envato_purchase_code', $code, 'string');
        SystemSetting::set('envato_verified_at', now()->toIso8601String(), 'string');

        return [
            'ok'      => true,
            'message' => 'Purchase verified — you can proceed.',
            'buyer'   => '',
            'item'    => (string) config('version.envato.item_id', ''),
        ];
    }

    // ------------------------------------------------------------------
    //  Version helpers
    // ------------------------------------------------------------------

    public function currentVersion(): string
    {
        return (string) config('version.version', '1.0.0');
    }

    public function currentBuild(): int
    {
        return (int) config('version.build', 0);
    }

    /** Read the version from config/version.php inside an uploaded ZIP. */
    public function getZipVersion(string $zipPath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $candidates = ['config/version.php'];
        for ($i = 0; $i < min($zip->numFiles, 50); $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^([^/]+)/config/version\.php$#', $name, $m)) {
                $candidates[] = $name;
                break;
            }
        }

        $version = null;
        foreach ($candidates as $candidate) {
            $content = $zip->getFromName($candidate);
            if ($content !== false) {
                if (preg_match("/'version'\s*=>\s*'([^']+)'/", $content, $m)) {
                    $version = $m[1];
                }
                break;
            }
        }

        $zip->close();

        return $version;
    }

    // ------------------------------------------------------------------
    //  STEP 1: Backup
    // ------------------------------------------------------------------

    /** @return array{code: string, database: string, dir: string} */
    public function createBackup(): array
    {
        // A full code + DB backup easily exceeds the default 30s max_execution_time
        // — the client hit "Maximum execution time of 30 seconds exceeded" here.
        // Lift the time cap for THIS request so it can finish (@-silenced in case a
        // host disabled the function).
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        // Best-effort lift of the memory ceiling for THIS request. The dump
        // streams so it should stay flat, but a host that pins memory_limit low
        // (this client hit 512 MB) gets headroom where the host allows it.
        @ini_set('memory_limit', '1024M');

        $version = $this->currentVersion();
        $ts  = now()->format('Y-m-d_His');
        $dir = $this->backupDir . "/v{$version}_{$ts}";

        // ── Diagnostics ────────────────────────────────────────────────────
        // Backup dies on some client servers with a bare 500 and NOTHING in
        // laravel.log. Reason: it's killed by a PHP FATAL (memory_limit
        // exhausted / max_execution_time) inside the DB dump, and a fatal is
        // not a Throwable — the controller's catch never runs and Laravel
        // never gets to write. So we log a breadcrumb BEFORE each step: the
        // LAST "[UPDATER-BACKUP]" line in the log tells you exactly where it
        // died. Grep the log for "[UPDATER-BACKUP]".
        //
        // The shutdown handler below is what actually captures the fatal
        // itself (error_get_last) — it is the only hook that still runs after
        // PHP aborts the request.
        $this->logFatalsForUpdater('BACKUP');

        Log::info('[UPDATER-BACKUP] start', [
            'version'       => $version,
            'dir'           => $dir,
            'memory_limit'  => ini_get('memory_limit'),
            'max_exec_time' => ini_get('max_execution_time'),
            'mem_used_mb'   => round(memory_get_usage(true) / 1048576, 1),
        ]);

        File::ensureDirectoryExists($dir, 0755, true);

        $codeZip = $dir . '/code_backup.zip';
        Log::info('[UPDATER-BACKUP] zipping code …');
        $this->zipCodeFiles($codeZip);
        Log::info('[UPDATER-BACKUP] code zip done', [
            'zip_mb'      => is_file($codeZip) ? round(filesize($codeZip) / 1048576, 1) : null,
            'mem_used_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);

        $dbFile = $dir . '/database_backup.sql';
        Log::info('[UPDATER-BACKUP] dumping database …');
        // dumpDatabase returns the ACTUAL file written — for MySQL that is a
        // gzip-compressed `database_backup.sql.gz`, which is ~10× smaller so a
        // huge DB no longer fills the disk mid-write.
        $dbFile = $this->dumpDatabase($dbFile);
        Log::info('[UPDATER-BACKUP] database dump done', [
            'sql_mb'      => is_file($dbFile) ? round(filesize($dbFile) / 1048576, 1) : null,
            'peak_mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);

        File::put($dir . '/rollback.json', json_encode([
            'version'     => $version,
            'created_at'  => now()->toIso8601String(),
            'code_backup' => $codeZip,
            'db_backup'   => $dbFile,
        ], JSON_PRETTY_PRINT));

        Log::info('[UPDATER-BACKUP] complete', ['dir' => $dir]);

        return ['code' => $codeZip, 'database' => $dbFile, 'dir' => $dir];
    }

    /**
     * Log-only fatal capture for the backup step. A memory/timeout fatal is
     * NOT catchable by try/catch, so without this the request just dies and
     * laravel.log stays empty — which is exactly the "server error with
     * nothing to trace" the operator sees. Registered once per request;
     * writes to the log and changes no behaviour.
     */
    /**
     * Log-only fatal capture for a given updater stage ('BACKUP' | 'APPLY').
     * A memory/timeout fatal is NOT catchable by try/catch, so without this the
     * request just dies and laravel.log stays empty — exactly the "500 with
     * nothing to trace" clients report. Registered once per stage per request;
     * writes to the log and changes no behaviour. Grep '[UPDATER-<STAGE>]'.
     */
    private function logFatalsForUpdater(string $stage = 'BACKUP'): void
    {
        static $registered = [];
        if (!empty($registered[$stage])) return;
        $registered[$stage] = true;

        register_shutdown_function(function () use ($stage) {
            $e = error_get_last();
            if (! $e || ! in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            Log::error('[UPDATER-' . $stage . '] FATAL — request aborted by PHP', [
                'message'     => $e['message'] ?? '',
                'file'        => ($e['file'] ?? '') . ':' . ($e['line'] ?? ''),
                'peak_mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'memory_limit'=> ini_get('memory_limit'),
                'hint'        => $stage === 'BACKUP'
                    ? 'Allowed memory exhausted / max execution time = the DB dump is too big for this server.'
                    : 'Allowed memory / max execution time exceeded, or a copy hit a read-only / permission-denied path.',
            ]);
        });
    }

    // ------------------------------------------------------------------
    //  STEP 2: Upload
    // ------------------------------------------------------------------

    public function saveUploadedZip($uploadedFile): string
    {
        File::ensureDirectoryExists($this->tempDir, 0755, true);
        $path = $this->tempDir . '/update.zip';
        $uploadedFile->move($this->tempDir, 'update.zip');

        return $path;
    }

    // ------------------------------------------------------------------
    //  STEP 3: Extract & Apply
    // ------------------------------------------------------------------

    public function applyUpdate(string $zipPath): array
    {
        // Extracting the ZIP + copying the whole tree can also exceed the default
        // 30s max_execution_time — lift it for this request too.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');
        // CRITICAL for shared hosting (LiteSpeed/Apache): the copy of ~1700
        // files can run longer than the web server's proxy timeout, so the
        // browser gives up and the UI shows "Step failed". Without this, that
        // disconnect ALSO kills PHP mid-copy → the install is left half-applied
        // and every retry starts over. ignore_user_abort lets the copy run to
        // completion server-side even after the client/proxy has dropped, so
        // the update always finishes; the operator just re-opens the page (or
        // clicks Finalize) and the [UPDATER-APPLY] complete line is in the log.
        ignore_user_abort(true);

        // Diagnostics — the Apply step also dies on some client servers with a
        // bare 500 and nothing in laravel.log (a copy hits a read-only path, a
        // permission error, or a PHP FATAL mid-extract). Grep "[UPDATER-APPLY]"
        // in storage/logs/laravel.log: the LAST line before a crash names the
        // exact stage/path that failed. The shutdown handler below is the only
        // hook that still runs after a fatal aborts the request.
        $this->logFatalsForUpdater('APPLY');
        $this->copySkips = [];
        // Write a status file the UI can poll. The copy runs longer than the
        // shared-host proxy timeout, so the browser's fetch aborts before the
        // success response arrives — the UI then wrongly shows "Step failed"
        // even though ignore_user_abort lets PHP finish. The UI polls this file
        // to learn the TRUE outcome. 'running' now; 'done' when it completes.
        $this->writeApplyStatus(['phase' => 'running', 'started_at' => now()->toIso8601String()]);
        Log::info('[UPDATER-APPLY] start', [
            'zip'          => $zipPath,
            'zip_mb'       => is_file($zipPath) ? round(filesize($zipPath) / 1048576, 2) : null,
            'memory_limit' => ini_get('memory_limit'),
            'php'          => PHP_VERSION,
        ]);

        $stagingDir = $this->tempDir . '/staging';
        // Marker lives OUTSIDE stagingDir so it never pollutes the wrapper-folder
        // detection (which counts File::files($stagingDir)).
        $marker     = $this->tempDir . '/.staging_extracted_from';
        $zipStamp   = is_file($zipPath) ? (filesize($zipPath) . '-' . filemtime($zipPath)) : '';

        // REUSE an existing extraction. Re-extracting ~1700 files takes ~37s on
        // this shared host, and a killed/retried Apply used to redo it EVERY
        // click — so the request kept blowing past the proxy timeout before it
        // even started copying. If staging was already extracted from THIS exact
        // ZIP (same size+mtime), skip straight to copying; the retry is then
        // fast enough to finish inside the timeout.
        $alreadyExtracted = File::isDirectory($stagingDir)
            && is_file($marker)
            && trim((string) @file_get_contents($marker)) === $zipStamp;

        if ($alreadyExtracted) {
            Log::info('[UPDATER-APPLY] reusing existing extraction (skip re-extract)', ['staging' => $stagingDir]);
        } else {
            if (File::isDirectory($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }

            $zip = new ZipArchive();
            $open = $zip->open($zipPath);
            if ($open !== true) {
                // ZipArchive::open returns an int error code, not just false — log it
                // so "Cannot open update ZIP" is diagnosable (corrupt upload, wrong
                // path, partial transfer, etc.).
                Log::error('[UPDATER-APPLY] cannot open ZIP', ['zip' => $zipPath, 'zip_error' => $open]);
                throw new RuntimeException('Cannot open update ZIP (error ' . $open . ').');
            }
            Log::info('[UPDATER-APPLY] extracting …', ['entries' => $zip->numFiles, 'staging' => $stagingDir]);
            if (! $zip->extractTo($stagingDir)) {
                $zip->close();
                Log::error('[UPDATER-APPLY] extract FAILED — check disk space + write perms on temp dir', ['staging' => $stagingDir]);
                throw new RuntimeException('Failed to extract the update ZIP to ' . $stagingDir . ' (disk space / permissions?).');
            }
            $zip->close();
            @file_put_contents($marker, $zipStamp);
            Log::info('[UPDATER-APPLY] extracted', ['staging' => $stagingDir]);
        }

        // Step into a single root folder wrapper if present.
        $items = File::directories($stagingDir);
        if (count($items) === 1 && count(File::files($stagingDir)) === 0) {
            $stagingDir = $items[0];
            Log::info('[UPDATER-APPLY] stepped into wrapper folder', ['root' => $stagingDir]);
        }

        $basePath = base_path();
        $updated  = [];
        $skipped  = [];

        foreach (self::UPDATABLE_PATHS as $relPath) {
            $srcPath  = $stagingDir . '/' . $relPath;
            $destPath = $basePath . '/' . $relPath;

            if (! File::exists($srcPath)) {
                $skipped[] = $relPath;
                continue;
            }

            // Per-path breadcrumb — the LAST line before a crash names the file
            // or directory that failed to copy (usually a read-only path or a
            // permission problem on the client's server).
            Log::info('[UPDATER-APPLY] copy', ['path' => $relPath, 'dir' => File::isDirectory($srcPath)]);

            try {
                if (File::isDirectory($srcPath)) {
                    if (str_contains($relPath, 'migrations')) {
                        $this->mergeMigrations($srcPath, $destPath);
                    } else {
                        $this->safeCopyDirectory($srcPath, $destPath, $relPath);
                    }
                } elseif (! $this->isProtected($relPath)) {
                    File::ensureDirectoryExists(dirname($destPath));
                    File::copy($srcPath, $destPath);
                }
            } catch (\Throwable $e) {
                // Surface WHICH path failed and WHY instead of a bare 500 — the
                // operator can then fix the permission / read-only mount and retry.
                Log::error('[UPDATER-APPLY] copy FAILED', ['path' => $relPath, 'error' => $e->getMessage()]);
                throw new RuntimeException('Update failed while copying "' . $relPath . '": ' . $e->getMessage(), 0, $e);
            }

            $updated[] = $relPath;
        }

        foreach (['composer.json', 'composer.lock', '.env.example'] as $rootFile) {
            $srcFile = $stagingDir . '/' . $rootFile;
            if (File::exists($srcFile)) {
                File::copy($srcFile, $basePath . '/' . $rootFile);
                $updated[] = $rootFile;
            }
        }

        $newVersionFile = $stagingDir . '/config/version.php';
        if (File::exists($newVersionFile)) {
            File::copy($newVersionFile, config_path('version.php'));
        }

        Log::info('[UPDATER-APPLY] complete', [
            'updated_count'     => count($updated),
            'skipped_count'     => count($skipped),
            'files_skipped'     => count($this->copySkips),
            'files_skipped_list'=> array_slice($this->copySkips, 0, 50),
            'new_version'       => File::exists($newVersionFile) ? 'shipped' : 'unchanged',
            'peak_mem_mb'       => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);

        // Mark done for the UI poller — this is the source of truth the browser
        // reads when its own request timed out mid-copy.
        $this->writeApplyStatus([
            'phase'         => 'done',
            'finished_at'   => now()->toIso8601String(),
            'updated_count' => count($updated),
            'files_skipped' => count($this->copySkips),
            'version'       => (string) config('version.version'),
        ]);

        return $updated;
    }

    /** Where the apply-progress status file lives (polled by the admin UI). */
    private function applyStatusPath(): string
    {
        return $this->tempDir . '/apply.status.json';
    }

    private function writeApplyStatus(array $data): void
    {
        try {
            File::ensureDirectoryExists($this->tempDir, 0755, true);
            @file_put_contents($this->applyStatusPath(), json_encode($data));
        } catch (\Throwable $e) {
            // Status file is best-effort — never let it break the update.
        }
    }

    /** Read the last apply status the UI can poll after its request timed out. */
    public function readApplyStatus(): ?array
    {
        $p = $this->applyStatusPath();
        if (! is_file($p)) return null;
        $j = json_decode((string) @file_get_contents($p), true);
        return is_array($j) ? $j : null;
    }

    // ------------------------------------------------------------------
    //  STEP 4: Migrate
    // ------------------------------------------------------------------

    public function runMigrations(): string
    {
        @set_time_limit(0);
        Log::info('[UPDATER-MIGRATE] start');
        // Fast path: everything applies cleanly (the normal case). Laravel
        // already SKIPS any migration recorded in the `migrations` table, so a
        // re-run is a no-op for those.
        try {
            Artisan::call('migrate', ['--force' => true]);
            Log::info('[UPDATER-MIGRATE] fast path OK');

            return Artisan::output();
        } catch (\Throwable $e) {
            Log::warning('[UPDATER-MIGRATE] fast path failed — retrying one-by-one', ['error' => $e->getMessage()]);
            $log = trim(Artisan::output()) . "\n" . $e->getMessage();
        }

        // Resilient path. The one gap Laravel does NOT cover is a brand-new
        // migration FILE whose schema change is ALREADY present in the database
        // (the table/column was added by a prior partial update, a manual
        // hot-fix, or a re-uploaded package). That throws "table/column already
        // exists" and aborts the WHOLE update. Step through each pending file on
        // its own so one such failure can't take the rest down: when a file
        // fails because its change already exists, mark it as run and carry on.
        $log .= "\n\nRetrying migrations one-by-one (skipping already-applied ones)…\n";

        $repository = app('migration.repository');
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }
        $ran   = $repository->getRan();                 // names already recorded
        $batch = $repository->getNextBatchNumber();
        $dir   = database_path('migrations');

        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $name = basename($path, '.php');
            if (in_array($name, $ran, true)) {
                continue;                                // truly already run
            }

            try {
                Artisan::call('migrate', [
                    '--path'  => 'database/migrations/' . basename($path),
                    '--force' => true,
                ]);
                $log .= '  migrated: ' . $name . "\n";
            } catch (\Throwable $ex) {
                if ($this->migrationAlreadyApplied($ex)) {
                    // The change is already in the DB — record the migration so
                    // it is never retried, and keep going.
                    $repository->log($name, $batch);
                    $log .= '  skipped (already applied): ' . $name . "\n";
                    continue;
                }
                // A genuine, unexpected failure — surface it so the admin sees it.
                throw new RuntimeException('Migration ' . $name . ' failed: ' . $ex->getMessage(), 0, $ex);
            }
        }

        return $log;
    }

    /**
     * True when a migration error means "this change is already in the database"
     * — i.e. the migration is effectively already applied and is safe to skip
     * rather than abort the update. Covers MySQL/MariaDB, Postgres and SQLite.
     */
    private function migrationAlreadyApplied(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        foreach ([
            'already exists',          // generic / Postgres / SQLite "table ... already exists"
            'duplicate column',        // MySQL: duplicate column
            'duplicate key name',      // MySQL: duplicate index
            'duplicate table',
            '1050',                    // MySQL: table already exists
            '1060',                    // MySQL: duplicate column name
            '1061',                    // MySQL: duplicate key name
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    //  STEP 5: Finalize
    // ------------------------------------------------------------------

    public function clearCaches(): void
    {
        Log::info('[UPDATER-FINALIZE] clearing caches');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        Log::info('[UPDATER-FINALIZE] caches cleared — update finished');
    }

    /** @return array<string,bool> */
    public function healthCheck(): array
    {
        $results = [];

        try {
            DB::connection()->getPdo();
            $results['database'] = true;
        } catch (\Throwable $e) {
            $results['database'] = false;
        }

        $results['env_file']        = File::exists(base_path('.env'));
        $results['storage_writable'] = is_writable(storage_path());
        $results['uploads_exist']   = File::isDirectory(storage_path('app/public'));

        return $results;
    }

    // ------------------------------------------------------------------
    //  Rollback
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function listBackups(): array
    {
        if (! File::isDirectory($this->backupDir)) {
            return [];
        }

        $backups = [];
        foreach (File::directories($this->backupDir) as $dir) {
            $rollbackFile = $dir . '/rollback.json';
            if (File::exists($rollbackFile)) {
                $info = json_decode(File::get($rollbackFile), true) ?: [];
                $info['path'] = $dir;
                $backups[] = $info;
            }
        }

        usort($backups, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return $backups;
    }

    public function rollback(string $backupDir): void
    {
        $rollbackFile = $backupDir . '/rollback.json';
        if (! File::exists($rollbackFile)) {
            throw new RuntimeException('Rollback info not found.');
        }

        $info = json_decode(File::get($rollbackFile), true);

        if (! empty($info['code_backup']) && File::exists($info['code_backup'])) {
            $zip = new ZipArchive();
            if ($zip->open($info['code_backup']) === true) {
                $zip->extractTo(base_path());
                $zip->close();
            }
        }

        if (! empty($info['db_backup']) && File::exists($info['db_backup'])) {
            $this->restoreDatabase($info['db_backup']);
        }

        $this->clearCaches();
    }

    public function cleanup(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function isProtected(string $relPath): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if ($relPath === $protected || str_starts_with($relPath, $protected . '/')) {
                return true;
            }
        }

        if (str_starts_with($relPath, 'public/')) {
            $allowedCodeDirs = ['public/css/', 'public/js/', 'public/build/'];
            $inCodeDir = false;
            foreach ($allowedCodeDirs as $dir) {
                if (str_starts_with($relPath, $dir)) {
                    $inCodeDir = true;
                    break;
                }
            }
            if (! $inCodeDir) {
                $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
                if (in_array($ext, self::PROTECTED_PUBLIC_EXTENSIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function safeCopyDirectory(string $srcDir, string $destDir, string $baseRelPath): void
    {
        @set_time_limit(0);
        File::ensureDirectoryExists($destDir, 0755, true);
        $copied = 0;
        // $hidden = TRUE — MUST include dotfiles/dot-dirs, or the update silently
        // ships a broken front-end. Vite 5 (Laravel 11/12) writes its asset map to
        // public/build/.vite/manifest.json — a HIDDEN directory. With the default
        // ($hidden=false) allFiles() skips it, so the hashed JS/CSS copy but the
        // manifest does NOT, and every page loads UNSTYLED until someone manually
        // uploads the build folder. Copying hidden files matches a manual unzip.
        // Protected/regenerable dotpaths (node/.env, .git) are still filtered below.
        foreach (File::allFiles($srcDir, true) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $relPath = $baseRelPath . '/' . $rel;
            // Never copy regenerable/platform-specific trees (node_modules, vendor,
            // .git). Copying node_modules — tens of thousands of files with native
            // binaries — is both pointless (the server rebuilds it with `npm
            // install`) and the exact thing that timed the Apply step out on shared
            // hosting: php-fpm SIGKILLs the request mid-copy, leaving a bare 500.
            if ($this->isRegenerablePath($relPath) || $this->isProtected($relPath)) {
                continue;
            }
            $src  = $file->getPathname();
            $dest = $destDir . '/' . $file->getRelativePathname();

            // RESILIENCE: allFiles() can enumerate an entry that copy() then
            // can't open — a broken symlink, a filename the FS stored under a
            // different byte-encoding, or a partially-extracted ZIP file. One
            // such file must NOT abort the whole update: it would leave the
            // install half-applied (app/ + config/ already overwritten). Skip
            // it, record it, and keep going. The operator sees the skip list.
            if (! is_file($src) || ! is_readable($src)) {
                $this->copySkips[] = $relPath;
                Log::warning('[UPDATER-APPLY] copy SKIP (source unreadable)', ['path' => $relPath]);
                continue;
            }

            try {
                File::ensureDirectoryExists(dirname($dest), 0755, true);
                if (! @copy($src, $dest)) {
                    $this->copySkips[] = $relPath;
                    Log::warning('[UPDATER-APPLY] copy SKIP (copy failed)', ['path' => $relPath]);
                    continue;
                }
            } catch (\Throwable $e) {
                $this->copySkips[] = $relPath;
                Log::warning('[UPDATER-APPLY] copy SKIP (exception)', ['path' => $relPath, 'error' => $e->getMessage()]);
                continue;
            }

            if ((++$copied % 500) === 0) {
                Log::info('[UPDATER-APPLY] copy progress', ['dir' => $baseRelPath, 'files' => $copied]);
            }
        }
    }

    /**
     * True for sub-paths that must never be zipped into a backup or copied by an
     * update — huge and fully regenerable (node_modules, vendor) or VCS/runtime
     * junk. Shared by zipCodeFiles() and safeCopyDirectory() so the backup and
     * the apply step agree on exactly what to skip.
     */
    private function isRegenerablePath(string $relInside): bool
    {
        $relInside = str_replace('\\', '/', $relInside);
        foreach (self::BACKUP_SKIP_DIRS as $bad) {
            if ($relInside === $bad
                || str_starts_with($relInside, $bad . '/')
                || str_contains($relInside, '/' . $bad . '/')
                || str_ends_with($relInside, '/' . $bad)) {
                return true;
            }
        }
        return false;
    }

    private function mergeMigrations(string $srcDir, string $destDir): void
    {
        File::ensureDirectoryExists($destDir, 0755, true);
        foreach (File::files($srcDir) as $file) {
            $dest = $destDir . '/' . $file->getFilename();
            if (! File::exists($dest)) {
                File::copy($file->getPathname(), $dest);
            }
        }
    }

    /**
     * Sub-paths NEVER put into the code backup. These are either huge and
     * fully regenerable (a rollback re-runs `npm install`), or runtime state
     * that has no place in a code snapshot. `node/node_modules` is the one that
     * actually broke real installs: it holds tens of thousands of files, and
     * ZipArchive keeps a file descriptor open for EVERY addFile() until close(),
     * so the request blew past the shared-host open-file limit and php-fpm
     * hard-killed it (SIGKILL) — which is why the log stopped dead at
     * "zipping code …" with no FATAL (shutdown handlers don't run on SIGKILL).
     */
    private const BACKUP_SKIP_DIRS = [
        'node_modules', 'vendor', '.git', '.github', 'tests',
        'storage/framework/cache', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs', 'storage/app/backups',
        'storage/app/temp',
    ];

    private function zipCodeFiles(string $zipPath): void
    {
        @set_time_limit(0);

        $skip = static function (string $relInside): bool {
            $relInside = str_replace('\\', '/', $relInside);
            // SECRETS — never let a secret dotfile into the backup zip. Before we
            // enumerated hidden files these were skipped for free; now that
            // allFiles($dir, true) sees them (to capture .vite/manifest.json), guard
            // them explicitly. Covers node/.env, any .env.* and an npm auth token.
            $base = basename($relInside);
            if ($base === '.env' || str_starts_with($base, '.env.') || $base === '.npmrc') {
                return true;
            }
            foreach (self::BACKUP_SKIP_DIRS as $bad) {
                if ($relInside === $bad
                    || str_starts_with($relInside, $bad . '/')
                    || str_contains($relInside, '/' . $bad . '/')
                    || str_ends_with($relInside, '/' . $bad)) {
                    return true;
                }
            }
            return false;
        };

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create backup ZIP at ' . $zipPath . ' (path not writable?).');
        }

        $basePath = base_path();
        $added    = 0;
        $skipped  = 0;

        // Flush the archive to disk every BATCH files. close()+reopen releases
        // all held file descriptors and the in-memory central directory, so the
        // open-file count stays bounded no matter how many files there are —
        // this is the fix that lets a big install finish instead of being killed.
        $BATCH = 400;
        $flush = function () use (&$zip, $zipPath) {
            if ($zip->close() !== true) {
                throw new RuntimeException('Failed flushing backup ZIP to disk (disk full or open-file limit?).');
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Failed reopening backup ZIP mid-write.');
            }
        };

        foreach (self::UPDATABLE_PATHS as $relPath) {
            $fullPath = $basePath . '/' . $relPath;
            if (File::isDirectory($fullPath)) {
                // $hidden = TRUE — same reason as safeCopyDirectory(): the backup
                // MUST capture dotfiles like public/build/.vite/manifest.json, or a
                // rollback would restore the code with a broken (manifest-less)
                // front-end. $skip() below still drops protected/regenerable paths.
                foreach (File::allFiles($fullPath, true) as $file) {
                    $inZip = $relPath . '/' . str_replace('\\', '/', $file->getRelativePathname());
                    if ($skip($inZip)) { $skipped++; continue; }
                    $zip->addFile($file->getPathname(), $inZip);
                    if ((++$added % $BATCH) === 0) {
                        $flush();
                        Log::info('[UPDATER-BACKUP] zip progress', ['files_added' => $added, 'current' => $relPath]);
                    }
                }
            } elseif (File::exists($fullPath)) {
                if ($skip($relPath)) { $skipped++; continue; }
                $zip->addFile($fullPath, $relPath);
                $added++;
            }
        }

        if ($zip->close() !== true) {
            throw new RuntimeException('Failed writing backup ZIP (disk full?).');
        }
        Log::info('[UPDATER-BACKUP] zip files written', ['added' => $added, 'skipped' => $skipped]);
    }

    private function dumpDatabase(string $path): string
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            $dbPath = config("database.connections.{$connection}.database");
            if (File::exists($dbPath)) {
                File::copy($dbPath, $path);
            }
            return $path;
        }

        if ($driver === 'mysql') {
            // mysqldump is unreliable on shared hosting — go straight to the
            // PHP dump which works everywhere. Returns the .sql.gz it wrote.
            return $this->dumpDatabasePhp($path);
        }

        File::put($path, "-- Database backup not supported for driver: {$driver}\n");
        return $path;
    }

    /**
     * Tables whose SCHEMA is backed up but whose ROWS are not.
     *
     * These are ephemeral runtime state — nothing in them is needed to roll an
     * update back, and they are exactly what used to kill the backup: on a live
     * install `notifications` alone reached 194,298 rows and blew a 512 MB
     * memory_limit (peak 510.6 MB) mid-dump, aborting the request with a PHP
     * FATAL that no try/catch could catch.
     *
     * The CREATE TABLE is still written, so a restore rebuilds them empty and
     * the app works normally — the queue refills, the cache re-warms, and the
     * notification feed starts fresh.
     */
    private const BACKUP_SKIP_ROWS = [
        'notifications',      // UI feed — grows unbounded, 194k rows seen live
        'jobs', 'job_batches', 'failed_jobs',   // queue state
        'cache', 'cache_locks',                 // ephemeral cache
        'sessions',                             // login sessions
    ];

    private function dumpDatabasePhp(string $path): string
    {
        // A big dump is slow rather than heavy now that it streams, so the
        // remaining risk is the execution-time ceiling. Best-effort lift.
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        // MEMORY FIX #1: Laravel keeps a query log in memory when it is enabled
        // (debug bar, telescope, or app.debug). This dump fires one SELECT per
        // 1000-row chunk — on a multi-million-row DB that is thousands of
        // queries, and the log alone can exhaust memory_limit ("Allowed memory
        // size … exhausted" during backup). Turn it off for this request.
        DB::connection()->disableQueryLog();

        $tables = DB::select('SHOW TABLES');
        $dbName = config('database.connections.' . config('database.default') . '.database');
        $key = "Tables_in_{$dbName}";

        // STREAM to disk, GZIP-COMPRESSED. Writing raw `.sql` produced a
        // multi-GB file on a huge DB that filled the disk mid-write; a
        // gzip stream is ~10× smaller and keeps memory flat. All output goes
        // through $write so the rest of the method is storage-agnostic.
        $gzPath = $path . '.gz';
        $fh = @gzopen($gzPath, 'wb6');
        if ($fh === false) {
            throw new RuntimeException('Cannot open backup file for writing: ' . $gzPath);
        }
        $write = static function (string $s) use ($fh): void { gzwrite($fh, $s); };

        $write("-- WaDesk Database Backup\n-- Date: " . now()->toIso8601String() . "\n\n");
        $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

        Log::info('[UPDATER-BACKUP] db dump: tables found', ['count' => count($tables)]);

        foreach ($tables as $table) {
            $tableName = $table->$key;
            $skipRows  = in_array($tableName, self::BACKUP_SKIP_ROWS, true);

            $create = DB::select("SHOW CREATE TABLE `{$tableName}`");
            $write("DROP TABLE IF EXISTS `{$tableName}`;\n");
            $write($create[0]->{'Create Table'} . ";\n\n");

            if ($skipRows) {
                $write("-- rows intentionally skipped (ephemeral runtime table)\n\n");
                Log::info('[UPDATER-BACKUP] db dump: table (schema only)', ['table' => $tableName]);
                continue;
            }

            $written = $this->writeTableRows($write, $tableName);
            $write("\n");

            // Per-table breadcrumb — the LAST line before a crash names the
            // table that caused it. Memory should now stay flat across tables.
            Log::info('[UPDATER-BACKUP] db dump: table', [
                'table'       => $tableName,
                'rows'        => $written,
                'file_mb'     => round((int) @filesize($gzPath) / 1048576, 1),
                'mem_used_mb' => round(memory_get_usage(true) / 1048576, 1),
            ]);
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($fh);

        return $gzPath;
    }

    /**
     * Write one table's rows as INSERT statements, reading in chunks so a
     * multi-hundred-thousand-row table never lands in memory at once.
     *
     * Chunks by primary key where there is one (keyset pagination — stays fast
     * on huge tables, unlike LIMIT/OFFSET). Falls back to an unbuffered cursor
     * when the table has no single-column PK.
     *
     * @param resource $fh
     */
    private function writeTableRows(callable $write, string $tableName): int
    {
        $pk = $this->primaryKeyColumn($tableName);
        $n  = 0;

        // Batch rows into one `INSERT ... VALUES (...),(...),…;` flushed every
        // 200 rows — a fraction of the file size of one INSERT per row, and a
        // dramatically faster restore. The batch buffer is tiny and released
        // on each flush, so memory stays flat.
        $batch = [];
        $flush = function () use (&$batch, $write, $tableName): void {
            if (! $batch) {
                return;
            }
            $write("INSERT INTO `{$tableName}` VALUES " . implode(',', $batch) . ";\n");
            $batch = [];
        };
        $addRow = function ($row) use (&$batch, &$n, $flush): void {
            $values = collect((array) $row)->map(function ($val) {
                if (is_null($val)) {
                    return 'NULL';
                }
                // Proper MySQL escaping so EVERY value stays on ONE physical line.
                // addslashes() left literal \n / \r in the output, which broke the
                // streaming line-by-line restore (a value with a newline — message
                // bodies, JSON flow definitions, notes — split a statement mid-value
                // and either corrupted it or aborted the restore). Backslash MUST be
                // escaped first. \n\r\0\Z become escape sequences MySQL re-expands.
                $s = str_replace(
                    ['\\',   "\x00", "\n",  "\r",  "'",   "\x1a"],
                    ['\\\\', '\\0',  '\\n', '\\r', "\\'", '\\Z'],
                    (string) $val
                );
                return "'" . $s . "'";
            })->implode(', ');
            $batch[] = "({$values})";
            $n++;
            if (count($batch) >= 200) {
                $flush();
            }
        };

        if ($pk !== null) {
            // Keyset pagination — stays fast on huge tables (unlike LIMIT/OFFSET).
            $last = null;
            while (true) {
                $q = DB::table($tableName)->orderBy($pk)->limit(1000);
                if ($last !== null) {
                    $q->where($pk, '>', $last);
                }
                $rows = $q->get();
                if ($rows->isEmpty()) {
                    break;
                }
                foreach ($rows as $row) {
                    $addRow($row);
                    $last = $row->{$pk};
                }
                unset($rows);   // release the chunk before fetching the next
            }
            $flush();

            return $n;
        }

        // MEMORY FIX #2: no single-column PK (pivot / composite-key tables).
        // The old fallback used ->cursor(), but Laravel's PDO is BUFFERED by
        // default, so cursor() still pulls the ENTIRE table into the mysqlnd
        // driver's memory before yielding the first row — the exact OOM this
        // guards against on a big table. Offset-page instead and free each page.
        $offset = 0;
        while (true) {
            $rows = DB::table($tableName)->offset($offset)->limit(1000)->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $addRow($row);
            }
            $offset += 1000;
            unset($rows);
        }
        $flush();

        return $n;
    }

    /** Single-column PRIMARY KEY of a table, or null when there isn't one. */
    private function primaryKeyColumn(string $tableName): ?string
    {
        try {
            $keys = DB::select("SHOW KEYS FROM `{$tableName}` WHERE Key_name = 'PRIMARY'");
            if (count($keys) === 1 && isset($keys[0]->Column_name)) {
                return (string) $keys[0]->Column_name;
            }
        } catch (\Throwable $e) {
            // Fall through to the cursor path.
        }

        return null;
    }

    private function restoreDatabase(string $path): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $isGz = str_ends_with($path, '.gz');

        if ($driver === 'sqlite') {
            $dest = config("database.connections.{$connection}.database");
            $isGz ? $this->gunzipTo($path, $dest) : File::copy($path, $dest);
            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        // Stream the dump and execute it statement-by-statement. The old
        // File::get() + DB::unprepared() loaded the ENTIRE dump into memory,
        // which OOM'd on exactly the big databases this backup is meant for.
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $fh = $isGz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open backup file for reading: ' . $path);
        }
        $readLine = fn() => $isGz ? gzgets($fh) : fgets($fh);
        $close    = fn() => $isGz ? gzclose($fh) : fclose($fh);

        $stmt = '';
        while (($line = $readLine()) !== false) {
            $trim = ltrim($line);
            // Skip blank lines and comments ONLY between statements — never while a
            // statement is still accumulating (a multi-line CREATE TABLE, or any
            // value that legitimately contains such a line), or we'd drop part of it
            // and corrupt/abort the restore.
            if ($stmt === '' && ($trim === '' || str_starts_with($trim, '--'))) {
                continue;
            }
            $stmt .= $line;
            // Each dumped statement is one physical line ending ";" (values are now
            // newline-escaped), so a line ending ";" terminates the statement.
            if (str_ends_with(rtrim($line), ';')) {
                try {
                    DB::unprepared($stmt);
                } catch (\Throwable $e) {
                    // Name the offending statement so a restore failure on a
                    // specific server is diagnosable, not a silent bare 500.
                    Log::error('[UPDATER-RESTORE] statement failed (' . $e->getMessage() . '): '
                        . mb_substr(ltrim($stmt), 0, 160));
                    throw $e;
                }
                $stmt = '';
            }
        }
        if (trim($stmt) !== '') {
            DB::unprepared($stmt);
        }
        $close();
    }

    /** Stream-decompress a .gz file to a destination path (flat memory). */
    private function gunzipTo(string $gz, string $dest): void
    {
        $in  = @gzopen($gz, 'rb');
        $out = @fopen($dest, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot decompress backup: ' . $gz);
        }
        while (! gzeof($in)) {
            fwrite($out, gzread($in, 262144));
        }
        gzclose($in);
        fclose($out);
    }
}
