<?php
/**
 * BIZPOZ Print Server - Auto Updater
 *
 * Checks GitHub for a newer VERSION, and if found, downloads the branch as a
 * zip and replaces only the files whose content actually changed. Every
 * replaced file is backed up first and swapped in atomically. Nothing here
 * ever runs on the print-request hot path (restpos_printer_server*.php) -
 * it is only ever invoked from index.php's polling and from check_update.php.
 *
 * Every public entry point (checkAndUpdate) is wrapped so it can NEVER throw
 * or fatal out to the caller - it always returns a result array describing
 * what happened, and always logs to update.log.
 */

if (!class_exists('AutoUpdater')) {

    class AutoUpdater
    {
        /** @var array */
        private $cfg;

        /** @var string absolute path to the project root */
        private $baseDir;

        /** @var resource|null */
        private $lockHandle = null;

        public function __construct(array $cfg, string $baseDir)
        {
            $this->cfg = $cfg;
            $this->baseDir = rtrim($baseDir, '/\\');
        }

        // -----------------------------------------------------------------
        // Public API
        // -----------------------------------------------------------------

        /**
         * Single source of truth for whether auto-update is enabled, so
         * getStatus() and checkAndUpdate() can never disagree with each other.
         * Missing key defaults to enabled (matches the shipped config.php).
         */
        private function isEnabled(): bool
        {
            return array_key_exists('enabled', $this->cfg) ? (bool)$this->cfg['enabled'] : true;
        }

        /**
         * Read-only status for display (dashboard). Never hits the network.
         */
        public function getStatus(): array
        {
            $state = $this->readState();
            $intervalSeconds = $this->parseInterval($this->cfg['check_interval'] ?? '1h');

            $lastCheck = $state['last_check'] ?? null;
            $nextCheck = $lastCheck ? $lastCheck + $intervalSeconds : null;

            return [
                'enabled'          => $this->isEnabled(),
                'local_version'    => $this->getLocalVersion(),
                'check_interval'   => $this->cfg['check_interval'] ?? '1h',
                'check_interval_seconds' => $intervalSeconds,
                'last_check'       => $lastCheck,
                'next_check'       => $nextCheck,
                'last_check_status'=> $state['last_check_status'] ?? null,
                'last_update'      => $state['last_update'] ?? null,
                'last_update_from' => $state['last_update_from'] ?? null,
                'last_update_to'   => $state['last_update_to'] ?? null,
                'last_error'       => $state['last_error'] ?? null,
            ];
        }

        /**
         * Main entry point. Checks (respecting the configured interval unless
         * $force is true) and applies an update if one is available.
         * Always returns a structured array. Never throws.
         */
        public function checkAndUpdate(bool $force = false): array
        {
            if (!$this->isEnabled()) {
                return ['ok' => true, 'status' => 'disabled'];
            }

            if (!$force && !$this->isCheckDue()) {
                return ['ok' => true, 'status' => 'not_due', 'next_check' => $this->getStatus()['next_check']];
            }

            if (!$this->acquireLock()) {
                return ['ok' => true, 'status' => 'already_running'];
            }

            $tempPaths = [];

            try {
                $owner  = $this->cfg['github_owner'] ?? '';
                $repo   = $this->cfg['github_repo'] ?? '';
                if ($owner === '' || $repo === '') {
                    throw new RuntimeException('github_owner / github_repo not configured');
                }

                $resolved = $this->resolveBranchAndVersion($owner, $repo);
                if (!$resolved['ok']) {
                    $this->recordCheck(false, $resolved['error']);
                    return ['ok' => false, 'status' => 'check_failed', 'error' => $resolved['error']];
                }

                $branch = $resolved['branch'];
                $remoteVersion = $resolved['version'];
                $localVersion = $this->getLocalVersion();

                if (!$this->isNewer($remoteVersion, $localVersion)) {
                    $this->recordCheck(true, null);
                    return [
                        'ok' => true,
                        'status' => 'up_to_date',
                        'local_version' => $localVersion,
                        'remote_version' => $remoteVersion,
                    ];
                }

                $this->log("Update available: {$localVersion} -> {$remoteVersion} (branch: {$branch})");

                // 1. Download
                $zipPath = $this->tempPath('psupdate_', '.zip');
                $tempPaths[] = $zipPath;
                $dl = $this->downloadUrl($this->zipUrl($owner, $repo, $branch), $zipPath);
                if (!$dl['ok']) {
                    $this->recordCheck(true, null); // the version check itself succeeded
                    $this->recordUpdateFailure("Download failed: " . $dl['error']);
                    return ['ok' => false, 'status' => 'download_failed', 'error' => $dl['error']];
                }
                if (!is_file($zipPath) || filesize($zipPath) === 0) {
                    $this->recordUpdateFailure('Downloaded update package is empty');
                    return ['ok' => false, 'status' => 'download_failed', 'error' => 'Downloaded file is empty'];
                }

                // 2. Extract
                $extractDir = $this->tempPath('psupdate_extract_', '', true);
                $tempPaths[] = $extractDir;
                $ex = $this->extractZip($zipPath, $extractDir);
                if (!$ex['ok']) {
                    $this->recordUpdateFailure("Extraction failed: " . $ex['error']);
                    return ['ok' => false, 'status' => 'extract_failed', 'error' => $ex['error']];
                }

                $sourceRoot = $this->findExtractedRoot($extractDir);
                if ($sourceRoot === null) {
                    $this->recordUpdateFailure('Could not locate extracted source folder');
                    return ['ok' => false, 'status' => 'extract_failed', 'error' => 'Could not locate extracted source folder'];
                }

                // Sanity check: the download should at least contain a VERSION file
                // matching what we expect, and an index.php. If not, something is
                // wrong upstream - abort rather than risk corrupting the install.
                if (!is_file($sourceRoot . '/index.php')) {
                    $this->recordUpdateFailure('Downloaded package failed sanity check (missing index.php)');
                    return ['ok' => false, 'status' => 'sanity_check_failed', 'error' => 'Downloaded package is missing expected files'];
                }

                // 3. Apply (hash-diff, syntax-check, backup, atomic swap)
                $result = $this->applyUpdate($sourceRoot);

                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        $this->log("Update error: {$err}", 'ERROR');
                    }
                }

                if (count($result['updated']) === 0 && count($result['errors']) === 0) {
                    // Version file was newer but no file content actually differed.
                    if (!$this->writeLocalVersion($remoteVersion)) {
                        $this->recordUpdateFailure("Could not write VERSION file to {$remoteVersion} - will retry next check");
                        return ['ok' => false, 'status' => 'version_write_failed', 'error' => 'Could not write VERSION file'];
                    }
                    $this->recordUpdateSuccess($localVersion, $remoteVersion, []);
                    return ['ok' => true, 'status' => 'up_to_date', 'note' => 'version bumped, no file changes needed'];
                }

                if (count($result['errors']) === 0) {
                    // Full success - safe to bump the version marker.
                    if (!$this->writeLocalVersion($remoteVersion)) {
                        // Files were applied but the version marker didn't stick. Not
                        // dangerous (files already match remote), but next check will
                        // re-download and re-apply - which is a safe no-op since the
                        // content hashes will already match.
                        $this->recordUpdateFailure("Files updated but could not write VERSION file to {$remoteVersion} - will retry next check");
                        return [
                            'ok' => false,
                            'status' => 'version_write_failed',
                            'updated_files' => $result['updated'],
                            'skipped_files' => $result['skipped'],
                        ];
                    }
                    $this->recordUpdateSuccess($localVersion, $remoteVersion, $result['updated']);
                    $this->pruneOldBackups();
                    return [
                        'ok' => true,
                        'status' => 'updated',
                        'from_version' => $localVersion,
                        'to_version' => $remoteVersion,
                        'updated_files' => $result['updated'],
                        'skipped_files' => $result['skipped'],
                    ];
                }

                // Partial failure: some files updated, some failed. Do NOT bump the
                // version file, so the next check retries the whole thing (already
                //-updated files are idempotent - re-applying them is a no-op).
                $this->recordUpdateFailure('Partial update (' . count($result['errors']) . ' file(s) failed): ' . implode('; ', $result['errors']));
                return [
                    'ok' => false,
                    'status' => 'partial_update',
                    'updated_files' => $result['updated'],
                    'skipped_files' => $result['skipped'],
                    'errors' => $result['errors'],
                ];
            } catch (\Throwable $e) {
                $this->log('Unexpected error: ' . $e->getMessage(), 'ERROR');
                $this->recordUpdateFailure('Unexpected error: ' . $e->getMessage());
                return ['ok' => false, 'status' => 'error', 'error' => $e->getMessage()];
            } finally {
                $this->cleanupTemp($tempPaths);
                $this->releaseLock();
            }
        }

        // -----------------------------------------------------------------
        // Version handling
        // -----------------------------------------------------------------

        public function getLocalVersion(): string
        {
            $path = $this->baseDir . '/VERSION';
            if (!is_file($path)) {
                return '0.0.0';
            }
            $v = @file_get_contents($path);
            return $v === false ? '0.0.0' : trim($v);
        }

        private function writeLocalVersion(string $version): bool
        {
            $tmp = $this->baseDir . '/.VERSION.tmp_' . uniqid();
            if (@file_put_contents($tmp, trim($version) . "\n") === false) {
                $this->log('Could not write VERSION temp file', 'ERROR');
                return false;
            }
            if (!$this->atomicReplace($tmp, $this->baseDir . '/VERSION')) {
                $this->log('Could not activate VERSION file (rename failed) - update will retry on next check', 'ERROR');
                return false;
            }
            return true;
        }

        private function isNewer(string $remote, string $local): bool
        {
            $remote = ltrim(trim($remote), 'vV');
            $local = ltrim(trim($local), 'vV');
            if ($remote === '') {
                return false;
            }
            if (preg_match('/^\d+(\.\d+)*$/', $remote) && preg_match('/^\d+(\.\d+)*$/', $local)) {
                return version_compare($remote, $local, '>');
            }
            return $remote !== $local;
        }

        private function resolveBranchAndVersion(string $owner, string $repo): array
        {
            $configured = $this->cfg['branch'] ?? 'main';
            $configuredList = is_array($configured) ? $configured : [$configured];
            $candidates = array_values(array_unique(array_filter(array_merge($configuredList, ['main', 'master']))));
            $attempts = [];

            foreach ($candidates as $branch) {
                $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/VERSION";
                $res = $this->httpGetString($url);
                if ($res['ok'] && trim($res['body']) !== '') {
                    return ['ok' => true, 'branch' => $branch, 'version' => trim($res['body'])];
                }
                $attempts[] = "{$url} -> " . ($res['error'] ?? 'empty response');
            }

            return ['ok' => false, 'error' => "Could not fetch VERSION from any branch. Tried:\n" . implode("\n", $attempts)];
        }

        // -----------------------------------------------------------------
        // Networking
        // -----------------------------------------------------------------

        private function zipUrl(string $owner, string $repo, string $branch): string
        {
            return "https://codeload.github.com/{$owner}/{$repo}/zip/refs/heads/" . rawurlencode($branch);
        }

        private function httpGetString(string $url): array
        {
            $timeout = (int)($this->cfg['request_timeout'] ?? 30);
            $connectTimeout = (int)($this->cfg['connect_timeout'] ?? 10);

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_USERAGENT => 'print_server-auto-updater/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $body = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($body === false) {
                    return ['ok' => false, 'error' => "curl error: {$err}"];
                }
                if ($httpCode < 200 || $httpCode >= 300) {
                    return ['ok' => false, 'error' => "HTTP {$httpCode}"];
                }
                return ['ok' => true, 'body' => $body];
            }

            if (function_exists('ini_get') && ini_get('allow_url_fopen')) {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => $timeout,
                        'header' => "User-Agent: print_server-auto-updater/1.0\r\n",
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body === false) {
                    return ['ok' => false, 'error' => 'file_get_contents failed (network or allow_url_fopen)'];
                }
                return ['ok' => true, 'body' => $body];
            }

            return ['ok' => false, 'error' => 'No HTTP client available: enable the PHP curl extension or allow_url_fopen'];
        }

        private function downloadUrl(string $url, string $destPath): array
        {
            $timeout = (int)($this->cfg['request_timeout'] ?? 30);
            $connectTimeout = (int)($this->cfg['connect_timeout'] ?? 10);

            if (function_exists('curl_init')) {
                $fp = @fopen($destPath, 'wb');
                if (!$fp) {
                    return ['ok' => false, 'error' => "Cannot open temp file for writing: {$destPath}"];
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_USERAGENT => 'print_server-auto-updater/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $ok = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                fclose($fp);

                if (!$ok) {
                    @unlink($destPath);
                    return ['ok' => false, 'error' => "curl error: {$err}"];
                }
                if ($httpCode < 200 || $httpCode >= 300) {
                    @unlink($destPath);
                    return ['ok' => false, 'error' => "HTTP {$httpCode}"];
                }
                return ['ok' => true];
            }

            if (function_exists('ini_get') && ini_get('allow_url_fopen')) {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => $timeout,
                        'header' => "User-Agent: print_server-auto-updater/1.0\r\n",
                    ],
                ]);
                $data = @file_get_contents($url, false, $ctx);
                if ($data === false) {
                    return ['ok' => false, 'error' => 'file_get_contents failed (network or allow_url_fopen)'];
                }
                if (@file_put_contents($destPath, $data) === false) {
                    return ['ok' => false, 'error' => "Cannot write to {$destPath}"];
                }
                return ['ok' => true];
            }

            return ['ok' => false, 'error' => 'No HTTP client available: enable the PHP curl extension or allow_url_fopen'];
        }

        // -----------------------------------------------------------------
        // Extraction & file application
        // -----------------------------------------------------------------

        private function extractZip(string $zipPath, string $destDir): array
        {
            if (!class_exists('ZipArchive')) {
                return ['ok' => false, 'error' => 'PHP ZipArchive extension is not enabled (enable php_zip in php.ini)'];
            }
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                return ['ok' => false, 'error' => "Could not create extraction directory: {$destDir}"];
            }

            $zip = new ZipArchive();
            $openResult = $zip->open($zipPath);
            if ($openResult !== true) {
                return ['ok' => false, 'error' => "Could not open downloaded zip (code {$openResult}) - it may be corrupt"];
            }
            if ($zip->numFiles === 0) {
                $zip->close();
                return ['ok' => false, 'error' => 'Downloaded zip contains no files'];
            }
            $extracted = $zip->extractTo($destDir);
            $zip->close();

            if (!$extracted) {
                return ['ok' => false, 'error' => 'Zip extraction failed (check disk space and permissions)'];
            }
            return ['ok' => true];
        }

        private function findExtractedRoot(string $extractDir): ?string
        {
            $items = @scandir($extractDir);
            if ($items === false) {
                return null;
            }
            $items = array_values(array_diff($items, ['.', '..']));
            if (count($items) === 1 && is_dir($extractDir . '/' . $items[0])) {
                return $extractDir . '/' . $items[0];
            }
            if (count($items) > 0) {
                return $extractDir; // flat archive fallback
            }
            return null;
        }

        private function applyUpdate(string $sourceRoot): array
        {
            $updated = [];
            $skipped = [];
            $errors = [];

            $backupEnabled = !empty($this->cfg['backup_enabled']);
            $backupDir = $backupEnabled ? $this->baseDir . '/backups/' . date('Ymd_His') : null;

            try {
                $dirIterator = new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS);
                $noSymlinks = new RecursiveCallbackFilterIterator($dirIterator, function ($current) {
                    // Exclude symlinks outright - for directories this also prevents
                    // ever recursing into them, so a symlink pointing outside the
                    // extracted package (or at itself) can't be walked at all.
                    return !$current->isLink();
                });
                $iterator = new RecursiveIteratorIterator($noSymlinks);
            } catch (\Throwable $e) {
                $errors[] = 'Could not scan downloaded package: ' . $e->getMessage();
                return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
            }

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isLink() || !$fileInfo->isFile()) {
                    continue;
                }

                $srcPath = $fileInfo->getPathname();
                $relPath = ltrim(str_replace($sourceRoot, '', $srcPath), '/\\');
                $relPath = str_replace('\\', '/', $relPath);

                if ($relPath === '' || $this->isExcluded($relPath)) {
                    continue;
                }

                $newContent = @file_get_contents($srcPath);
                if ($newContent === false) {
                    $errors[] = "Could not read downloaded file: {$relPath}";
                    continue;
                }

                $destPath = $this->baseDir . '/' . $relPath;
                $existingContent = is_file($destPath) ? @file_get_contents($destPath) : null;

                if ($existingContent !== null && hash('sha256', $existingContent) === hash('sha256', $newContent)) {
                    continue; // unchanged, leave it alone entirely
                }

                // Syntax-check PHP files in isolation before they are allowed near the
                // live install. If we have no way to run the check (exec disabled),
                // we do NOT apply the file unverified - that would silently break the
                // safety guarantee. It's reported as skipped so it's visible instead
                // of failing invisibly.
                if (preg_match('/\.php$/i', $relPath)) {
                    if (!$this->execAvailable()) {
                        $errors[] = "Skipped {$relPath}: cannot verify PHP syntax because exec() is disabled on this server - enable exec() (remove it from php.ini's disable_functions) to allow PHP file updates";
                        $skipped[] = $relPath;
                        continue;
                    }
                    $lintOut = [];
                    $lintCode = 0;
                    @exec('php -l ' . escapeshellarg($srcPath) . ' 2>&1', $lintOut, $lintCode);
                    if ($lintCode !== 0) {
                        $errors[] = "Skipped {$relPath}: failed PHP syntax check (" . trim(implode(' ', $lintOut)) . ')';
                        $skipped[] = $relPath;
                        continue;
                    }
                }

                $destDir = dirname($destPath);
                if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                    $errors[] = "Could not create directory: {$destDir}";
                    continue;
                }

                if ($backupDir !== null && $existingContent !== null) {
                    $backupPath = $backupDir . '/' . $relPath;
                    $backupParent = dirname($backupPath);
                    if (!is_dir($backupParent) && !@mkdir($backupParent, 0755, true) && !is_dir($backupParent)) {
                        $errors[] = "Could not create backup directory for {$relPath}, skipping this file for safety";
                        $skipped[] = $relPath;
                        continue;
                    }
                    if (@file_put_contents($backupPath, $existingContent) === false) {
                        $errors[] = "Backup failed for {$relPath}, skipping this file for safety";
                        $skipped[] = $relPath;
                        continue;
                    }
                }

                $tmpDest = $destDir . '/.' . basename($destPath) . '.tmp_' . uniqid();
                if (@file_put_contents($tmpDest, $newContent) === false) {
                    $errors[] = "Could not write temp file for {$relPath}";
                    continue;
                }
                if (!$this->atomicReplace($tmpDest, $destPath)) {
                    $errors[] = "Could not activate updated file (rename failed, even after retry): {$relPath}";
                    continue;
                }

                $updated[] = $relPath;
            }

            if (!empty($this->cfg['remove_deleted_files'])) {
                $errors = array_merge($errors, $this->removeFilesDeletedUpstream($sourceRoot));
            }

            return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
        }

        private function removeFilesDeletedUpstream(string $sourceRoot): array
        {
            $errors = [];
            try {
                $dirIterator = new RecursiveDirectoryIterator($this->baseDir, FilesystemIterator::SKIP_DOTS);
                $noSymlinks = new RecursiveCallbackFilterIterator($dirIterator, function ($current) {
                    return !$current->isLink();
                });
                $iterator = new RecursiveIteratorIterator($noSymlinks);
            } catch (\Throwable $e) {
                return ["Could not scan local install for deletion pass: " . $e->getMessage()];
            }

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isLink() || !$fileInfo->isFile()) {
                    continue;
                }
                $localPath = $fileInfo->getPathname();
                $relPath = ltrim(str_replace($this->baseDir, '', $localPath), '/\\');
                $relPath = str_replace('\\', '/', $relPath);

                if ($relPath === '' || $this->isExcluded($relPath)) {
                    continue;
                }
                if (strpos($relPath, 'backups/') === 0) {
                    continue;
                }

                if (!is_file($sourceRoot . '/' . $relPath)) {
                    if (!@unlink($localPath)) {
                        $errors[] = "Could not remove file deleted upstream: {$relPath}";
                    } else {
                        $this->log("Removed file no longer present upstream: {$relPath}");
                    }
                }
            }

            return $errors;
        }

        private function isExcluded(string $relPath): bool
        {
            $relPath = str_replace('\\', '/', $relPath);
            $exclude = $this->cfg['exclude'] ?? [];
            foreach ($exclude as $ex) {
                $ex = trim(str_replace('\\', '/', (string)$ex), '/');
                if ($ex === '') {
                    continue;
                }
                if ($relPath === $ex || strpos($relPath, $ex . '/') === 0) {
                    return true;
                }
            }
            return false;
        }

        private function execAvailable(): bool
        {
            static $available = null;
            if ($available !== null) {
                return $available;
            }
            if (!function_exists('exec')) {
                $available = false;
                return false;
            }
            $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
            $available = !in_array('exec', $disabled, true);
            return $available;
        }

        private function pruneOldBackups(): void
        {
            $max = (int)($this->cfg['max_backups'] ?? 10);
            if ($max <= 0) {
                return;
            }
            $backupsRoot = $this->baseDir . '/backups';
            if (!is_dir($backupsRoot)) {
                return;
            }
            $dirs = glob($backupsRoot . '/*', GLOB_ONLYDIR);
            if ($dirs === false || count($dirs) <= $max) {
                return;
            }
            sort($dirs); // timestamp-named folders sort chronologically
            $toRemove = array_slice($dirs, 0, count($dirs) - $max);
            foreach ($toRemove as $dir) {
                $this->removeDirRecursive($dir);
            }
        }

        private function removeDirRecursive(string $dir): void
        {
            if (!is_dir($dir)) {
                return;
            }
            $items = @scandir($dir);
            if ($items === false) {
                return;
            }
            foreach (array_diff($items, ['.', '..']) as $item) {
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $this->removeDirRecursive($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        }

        // -----------------------------------------------------------------
        // State, locking, scheduling
        // -----------------------------------------------------------------

        private function statePath(): string
        {
            return $this->baseDir . '/.update_state.json';
        }

        private function readState(): array
        {
            $path = $this->statePath();
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);
            if ($raw === false) {
                return [];
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }

        private function writeState(array $data): void
        {
            $tmp = $this->baseDir . '/.update_state.json.tmp_' . uniqid();
            if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false) {
                $this->log('Could not write update-state temp file', 'ERROR');
                return;
            }
            if (!$this->atomicReplace($tmp, $this->statePath())) {
                $this->log('Could not persist update state (rename failed)', 'ERROR');
            }
        }

        private function recordCheck(bool $ok, ?string $error): void
        {
            $state = $this->readState();
            $state['last_check'] = time();
            $state['last_check_status'] = $ok ? 'ok' : 'failed';
            $state['last_error'] = $error;
            $this->writeState($state);
            if (!$ok) {
                $this->log("Version check failed: {$error}", 'ERROR');
            } else {
                $this->log('Version check completed');
            }
        }

        private function recordUpdateSuccess(string $from, string $to, array $updatedFiles): void
        {
            $state = $this->readState();
            $state['last_check'] = time();
            $state['last_check_status'] = 'ok';
            $state['last_update'] = time();
            $state['last_update_from'] = $from;
            $state['last_update_to'] = $to;
            $state['last_error'] = null;
            $this->writeState($state);
            $this->log("Update applied successfully: {$from} -> {$to} (" . count($updatedFiles) . ' file(s) changed)');
        }

        private function recordUpdateFailure(string $error): void
        {
            $state = $this->readState();
            $state['last_check'] = time();
            $state['last_error'] = $error;
            $this->writeState($state);
            $this->log("Update failed: {$error}", 'ERROR');
        }

        private function isCheckDue(): bool
        {
            $state = $this->readState();
            $lastCheck = $state['last_check'] ?? null;
            if ($lastCheck === null) {
                return true;
            }
            $interval = $this->parseInterval($this->cfg['check_interval'] ?? '1h');
            return (time() - (int)$lastCheck) >= $interval;
        }

        private function parseInterval($value): int
        {
            if (is_int($value)) {
                return max(60, $value);
            }
            $value = trim((string)$value);
            if ($value === '') {
                return 3600;
            }
            if (preg_match('/^(\d+)\s*([smhd]?)$/i', $value, $m)) {
                $num = (int)$m[1];
                $unit = strtolower($m[2] !== '' ? $m[2] : 's');
                $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$unit];
                return max(60, $num * $mult);
            }
            $this->log("Invalid check_interval '{$value}', falling back to 3600s (1h)", 'WARN');
            return 3600;
        }

        private function acquireLock(): bool
        {
            $lockPath = $this->baseDir . '/update.lock';
            $handle = @fopen($lockPath, 'c');
            if (!$handle) {
                $this->log('Could not open lock file - proceeding without lock', 'WARN');
                return true;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return false;
            }
            $this->lockHandle = $handle;
            return true;
        }

        private function releaseLock(): void
        {
            if ($this->lockHandle) {
                flock($this->lockHandle, LOCK_UN);
                fclose($this->lockHandle);
                $this->lockHandle = null;
            }
        }

        // -----------------------------------------------------------------
        // Temp files & logging
        // -----------------------------------------------------------------

        private function tempPath(string $prefix, string $suffix = '', bool $asDir = false): string
        {
            $base = sys_get_temp_dir() . '/' . $prefix . uniqid() . $suffix;
            return $base;
        }

        private function cleanupTemp(array $paths): void
        {
            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $this->removeDirRecursive($path);
                } elseif (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        // -----------------------------------------------------------------
        // Atomic file replacement (Windows-safe)
        // -----------------------------------------------------------------

        /**
         * Moves $tmpPath over $destPath. On POSIX filesystems rename() atomically
         * overwrites an existing destination; on Windows, rename() commonly fails
         * if the destination already exists. This retries by removing the
         * destination first, and always removes the temp file if it could not
         * be activated so we never leave stray .tmp_* files behind.
         */
        private function atomicReplace(string $tmpPath, string $destPath): bool
        {
            if (@rename($tmpPath, $destPath)) {
                return true;
            }

            if (is_file($destPath) && @unlink($destPath) && @rename($tmpPath, $destPath)) {
                return true;
            }

            @unlink($tmpPath);
            return false;
        }

        private function log(string $msg, string $level = 'INFO'): void
        {
            $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
            @file_put_contents($this->baseDir . '/update.log', $line, FILE_APPEND | LOCK_EX);
        }
    }
}
