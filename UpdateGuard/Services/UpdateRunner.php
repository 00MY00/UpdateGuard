<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Entities\ModuleHealth;
use Modules\UpdateGuard\Entities\UpdateBackup;
use Modules\UpdateGuard\Entities\UpdateJob;

/**
 * Executes the jobs of updateguard_jobs (always from the CLI: scheduler "updateguard:run" or the artisan commands,
 * never inside a web request).
 *
 *   update   preflight → download → SHA-256 → staging → generic tests → backup → install → clear cache →
 *            health check → observation → successful (version marked stable)
 *            any failure before the install: ABORT, nothing changed
 *            failure after the install: automatic rollback when allowed by the settings and the database state
 *   restore  lock → backup current → restore → clear cache → health check → report (failure: CRITICAL, no retry)
 *   health   health check + report
 *   check    remote versions of one module or all ('*')
 *
 * Only one job runs at a time (global lock). Each job has an update ID and a report.
 */
class UpdateRunner
{
    const PROGRESS = [
        'queued' => 0, 'checking' => 5, 'downloading' => 15, 'verifying' => 25, 'staging' => 35, 'backing_up' => 45,
        'installing' => 60, 'clearing_cache' => 70, 'health_check' => 80, 'rolling_back' => 85, 'observing' => 90,
        'scanning' => 40, 'awaiting_decision' => 42,
    ];

    /** @var callable|null */
    protected $out;

    /** @var UpdateJob */
    protected $job;

    /** @var Journal */
    protected $journal;

    /** @var array */
    protected $report;

    /** @var LockService */
    protected $lock;

    /** @var string SHA-256 of the archive installed by the current update */
    protected $zipHash = '';

    public function __construct(?callable $out = null)
    {
        $this->out = $out;
    }

    // --------------------------------------------------------------------------------------------------- queue

    /** Creates a job (UI, commands, scheduler) and gives it its update ID. */
    public static function queue($type, $module, array $attributes = [])
    {
        $job = UpdateJob::create(array_merge([
            'type'         => $type,
            'module'       => $module,
            'status'       => 'queued',
            'triggered_by' => 'System',
        ], $attributes));
        $job->update_id = ReportService::updateId($job->id);
        $job->save();

        return $job;
    }

    /** One pass of the scheduler: abandoned locks, observations, queued jobs, automatic updates. */
    public function tick($automatic = true)
    {
        Paths::ensure();
        $this->lock = new LockService(Paths::locks(), Settings::get('lock_timeout_minutes'));
        $stale = $this->lock->clearAbandoned();
        if ($stale) {
            (new Journal((string) ($stale['update_id'] ?? ''), (string) ($stale['module'] ?? ''), '', '', $this->out))
                ->warning('lock', 'Abandoned lock removed (pid '.($stale['pid'] ?? '?').', '.($stale['date'] ?? '?').')');
            $this->recoverInterrupted();
        }
        $this->recoverStale();
        $this->expireWaiting();
        foreach (UpdateJob::where('status', 'observing')->orderBy('id')->get() as $job) {
            $this->observe($job);
        }
        foreach (UpdateJob::where('status', 'queued')->orderBy('id')->get() as $job) {
            $this->process($job);
        }
        if ($automatic) {
            $this->scheduleAutomatic();
        }
    }

    /** Jobs left in an intermediate state by a killed process: reported, never resumed blindly. */
    protected function recoverInterrupted()
    {
        $states = array_diff(UpdateJob::ACTIVE, ['queued']);
        foreach (UpdateJob::whereIn('status', $states)->get() as $job) {
            $journal = new Journal($job->update_id, $job->module, $job->from_version, $job->to_version, $this->out);
            $installed = $job->installed_at !== null;
            $journal->critical('recovery', 'Operation interrupted in state "'.$job->status.'"'.($installed ? ': files were already replaced, check the module and restore a backup if needed' : ': nothing was installed'));
            $job->status = $installed ? 'rollback_failed' : 'failed';
            $job->message = 'interrupted ('.$job->status.')';
            $job->finished_at = now();
            $job->save();
            $row = ModuleHealth::named($job->module);
            if ($row && $installed) {
                $row->state = 'critical';
                $row->save();
            }
        }
    }

    /**
     * Operations that hold no lock (version check, security scan) or whose process died without leaving an abandoned
     * lock: an operation that has not moved for longer than the lock timeout is interrupted. Without this, a killed
     * "check" stayed "running" for ever and the automatic check was never started again.
     */
    protected function recoverStale()
    {
        $timeout = max(1, (int) Settings::get('lock_timeout_minutes'));
        $lock = $this->lock ? $this->lock->current() : null;
        $states = array_diff(UpdateJob::ACTIVE, ['queued']);
        foreach (UpdateJob::whereIn('status', $states)->where('updated_at', '<', now()->subMinutes($timeout))->get() as $job) {
            if ($lock && ($lock['update_id'] ?? '') === $job->update_id && !$this->lock->isAbandoned()) {
                continue; // still held by a live process
            }
            $installed = $job->installed_at !== null;
            (new Journal($job->update_id, $job->module, $job->from_version, $job->to_version, $this->out))->critical('recovery',
                'Operation without progress for '.$timeout.' min in state "'.$job->status.'": interrupted'.($installed ? '; files were already replaced, check the module and restore a backup if needed' : '; nothing was installed'));
            $job->status = $installed ? 'rollback_failed' : 'failed';
            $job->message = 'interrupted (no progress for '.$timeout.' min)';
            $job->finished_at = now();
            $job->save();
            $row = $installed ? ModuleHealth::named($job->module) : null;
            if ($row) {
                $row->state = 'critical';
                $row->save();
            }
        }
    }

    /** An update waiting for a decision is cancelled after 7 days: it no longer blocks the module. */
    protected function expireWaiting()
    {
        foreach (UpdateJob::whereIn('status', UpdateJob::WAITING)->where('updated_at', '<', now()->subDays(7))->get() as $job) {
            $job->status = 'failed';
            $job->message = 'no decision within 7 days: cancelled (nothing was installed)';
            $job->finished_at = now();
            $job->save();
            (new Journal($job->update_id, $job->module, $job->from_version, $job->to_version, $this->out))
                ->warning('security', 'Update waiting for a decision for 7 days: cancelled, nothing was installed');
        }
    }

    /** Automatic updates (setting on, module allowed, safety level high enough, no blocked version). */
    protected function scheduleAutomatic()
    {
        if (!Settings::get('enabled') || !Settings::get('auto_update')) {
            return;
        }
        foreach (ModuleHealth::where('auto_update', true)->get() as $row) {
            if (!$row->updateAvailable() || $row->blocked_version === $row->latest_version || !$row->enabled
                || !SafetyLevel::allowsAutomatic($row->safety_level, Settings::get('auto_update_min_level'))
                || UpdateJob::pendingFor($row->name) || in_array($row->state, ['critical', 'pending_validation'], true)) {
                continue;
            }
            if (($row->capability('republished') && Settings::get('sec_auto_block_republished')) || $this->alreadyTried($row->name, $row->latest_version)) {
                continue;
            }
            $job = self::queue('update', $row->name, [
                'from_version' => $row->installed_version,
                'to_version'   => $row->latest_version,
                'automatic'    => true,
                'triggered_by' => 'Scheduler',
            ]);
            $this->process($job);
        }
    }

    /**
     * Loop protection for automatic updates: a version is tried automatically once a day at most, and never again
     * after it was blocked or rolled back (an administrator can still start it by hand).
     */
    protected function alreadyTried($module, $version)
    {
        return UpdateJob::where('module', $module)->where('to_version', $version)->where('automatic', true)
            ->where(function ($q) {
                // ordinary attempts: once a day, never again after a block / rollback
                $q->where(function ($q2) {
                    $q2->whereRaw("COALESCE(message, '') NOT LIKE ?", ['%unknown_to:%'])
                        ->where(function ($q3) {
                            $q3->whereIn('status', ['blocked', 'rolled_back', 'rollback_failed'])
                                ->orWhere('created_at', '>', now()->subDay());
                        });
                // package not known yet by the external scanner: tried again every hour (no request: the "unknown"
                // answer is cached, replaced by the real result as soon as the package was uploaded and scanned)
                })->orWhere(function ($q2) {
                    $q2->whereRaw("COALESCE(message, '') LIKE ?", ['%unknown_to:%'])->where('created_at', '>', now()->subHour());
                });
            })->exists();
    }

    public function process(UpdateJob $job)
    {
        $job = $job->fresh();
        if (!$job || $job->status !== 'queued') {
            return $job;
        }
        Paths::ensure();
        $this->lock = $this->lock ?: new LockService(Paths::locks(), Settings::get('lock_timeout_minutes'));
        if (in_array($job->type, ['update', 'restore', 'health'], true)) {
            if (!$this->lock->acquire($job->module, $job->update_id)) {
                // another operation is running: stays queued for the next minute
                return $job;
            }
        }
        $this->job = $job;
        $this->journal = new Journal($job->update_id, $job->module, $job->from_version, $job->to_version, $this->out);
        $this->report = ReportService::make($job->update_id, $job->type, $job->module, $job->from_version, $job->to_version, $job->triggered_by, $job->automatic);
        $this->journal->report = &$this->report;
        $job->started_at = now();
        $job->save();
        try {
            switch ($job->type) {
                case 'update':
                    $this->runUpdate();
                    break;
                case 'restore':
                    $this->runRestore();
                    break;
                case 'health':
                    $this->runHealth();
                    break;
                case 'check':
                    $this->runCheck();
                    break;
                case 'scan':
                    $this->runScan();
                    break;
                default:
                    $this->finish('failed', 'unknown job type');
            }
        } catch (\Throwable $e) {
            $this->journal->error('exception', get_class($e).': '.$e->getMessage());
            $this->report['errors'][] = $e->getMessage();
            if ($this->job->type === 'update' && $this->job->installed_at !== null && !$this->job->isFinished()) {
                // crash of the validation itself, after the files were replaced
                $this->afterInstallFailure(['validation'], 'post-update validation crashed: '.$e->getMessage());
            } elseif (!$this->job->isFinished()) {
                $this->finish('failed', $e->getMessage());
            }
        } finally {
            $this->saveReport();
            $this->lock->release();
        }

        return $this->job;
    }

    // -------------------------------------------------------------------------------------------------- helpers

    protected function status($status, $message = null)
    {
        $this->job->status = $status;
        $this->job->progress = isset(self::PROGRESS[$status]) ? self::PROGRESS[$status] : 100;
        if ($message !== null) {
            $this->job->message = mb_substr((string) $message, 0, 5000);
        }
        $this->job->save();
        $this->saveReport();
    }

    protected function finish($status, $message, $finalStatus = null)
    {
        $this->job->status = $status;
        $this->job->progress = 100;
        $this->job->message = mb_substr((string) $message, 0, 5000);
        $this->job->finished_at = now();
        $this->job->save();
        $this->report['finished_at'] = date('c');
        $this->report['final_status'] = $finalStatus !== null ? $finalStatus : $status;
        if ($this->report['final_version'] === '') {
            $this->report['final_version'] = (string) (ModuleDiscoveryService::manifest(Paths::modules($this->job->module))['version'] ?? '');
        }
        if ($status !== 'successful' && $message !== '' && !in_array($message, $this->report['errors'], true)) {
            $this->report['errors'][] = $message;
        }
        $this->saveReport();
        if ($this->job->security_report_id) {
            // the security report also records what happened after the gate
            try {
                Security\SecurityReports::complete(
                    $this->job->security_report_id,
                    $status,
                    (string) ($this->report['steps']['native_health']['result'] ?? $this->report['steps']['generic_validation']['result'] ?? ''),
                    (string) ($this->report['steps']['rollback']['result'] ?? '')
                );
            } catch (\Throwable $e) {
            }
        }
    }

    protected function saveReport()
    {
        try {
            ReportService::save(Paths::reports(), $this->report);
        } catch (\Throwable $e) {
        }
    }

    protected function workDir($sub = '')
    {
        return Paths::staging($this->job->update_id.($sub !== '' ? '/'.$sub : ''));
    }

    protected function event($name)
    {
        try {
            \Eventy::action('updateguard.'.$name, $this->job, $this->report);
        } catch (\Throwable $e) {
            $this->journal->warning('hook', 'updateguard.'.$name.' listener failed: '.$e->getMessage());
        }
    }

    // --------------------------------------------------------------------------------------------------- update

    protected function runUpdate()
    {
        $job = $this->job;
        $j = $this->journal;
        $this->status('checking');
        if ($job->module === ModuleDiscoveryService::SELF) {
            $j->warning('preflight', 'UpdateGuard updates are handled by FreeScout.');

            return $this->finish('blocked', 'UpdateGuard updates are handled by FreeScout.');
        }
        $module = ModuleDiscoveryService::find($job->module);
        if (!$module) {
            return $this->finish('failed', 'module not installed: '.$job->module);
        }
        // offline update: a local ZIP (updateguard:update --zip=...), manual only, version read from the archive
        $localSource = (string) $job->source_path !== '';
        if ($localSource) {
            if ($job->automatic) {
                return $this->finish('blocked', 'a local archive is never installed automatically');
            }
            ModuleDiscoveryService::sync();
            $row = ModuleHealth::named($module['name']);
            $caps = $row->capabilities();
            $from = $module['version'];
            $job->from_version = $from;
            $job->save();
            $this->report['from'] = $from;
            $j->from = $from;
            $j->info('checking', 'Update from the local archive '.basename($job->source_path));

            return $this->updateFromPackage($module, $row, $caps, $from, '');
        }
        $row = ModuleDiscoveryService::check($module);
        $caps = $row->capabilities();
        $this->report['safety_level'] = $row->safety_level;
        if ($row->safety_level === SafetyLevel::UNSUPPORTED) {
            $j->warning('preflight', 'Unsupported automatic update: '.($caps['error'] ?: 'no latestVersionUrl / latestVersionZipUrl, or official module'));

            return $this->finish('blocked', 'unsupported module ('.($caps['error'] ?: 'no update information').')');
        }
        $from = $module['version'];
        $to = (string) $row->latest_version;
        $job->from_version = $from;
        $job->to_version = $to;
        $job->save();
        $this->report['from'] = $from;
        $this->report['to'] = $to;
        $j->from = $from;
        $j->to = $to;
        if ($to === '') {
            return $this->finish('failed', 'latest version unknown: '.($caps['error'] ?: 'no answer'));
        }
        if (!VersionService::isNewer($to, $from) && !$job->force) {
            $j->info('checking', $job->module.' '.$from.' is up to date.');
            $this->report['final_version'] = $from;

            return $this->finish('successful', 'already up to date', 'healthy');
        }
        $j->info('checking', 'Update detected: '.$from.' → '.$to);
        if ($row->blocked_version === $to && ($job->automatic || !$job->force)) {
            $j->warning('checking', 'Version '.$to.' is blocked ('.$row->blocked_reason.'); last known good: '.$row->known_good_version);

            return $this->finish('blocked', 'version '.$to.' blocked: '.$row->blocked_reason);
        }
        $republished = $row->capability('republished');
        if ($republished && ($republished['version'] ?? '') === $to) {
            if ($job->automatic && Settings::get('sec_auto_block_republished')) {
                return $this->finish('blocked', 'version '.$to.' was published again with a different SHA-256: automatic update refused');
            }
            $j->warning('checking', 'Version '.$to.' was published again with a different SHA-256 (before '.$republished['old'].', now '.$republished['new'].'): installed on the decision of the administrator');
        }
        if ($job->automatic && !SafetyLevel::allowsAutomatic($row->safety_level, Settings::get('auto_update_min_level'))) {
            return $this->finish('blocked', 'safety level '.$row->safety_level.' does not allow automatic updates');
        }

        return $this->updateFromPackage($module, $row, $caps, $from, $to);
    }

    /**
     * preflight → package (download or local archive) → SHA-256 → staging → generic tests → Security Gate → backup →
     * install → health check → observation. $to is '' for a local archive (read from its module.json).
     */
    protected function updateFromPackage(array $module, $row, array $caps, $from, $to)
    {
        $job = $this->job;
        $j = $this->journal;
        $localSource = (string) $job->source_path !== '';
        $gate = (bool) Settings::get('security_gate');

        // ------------------------------------------------------------------------------------------- preflight
        $problems = $this->preflight($module);
        if ($problems) {
            ReportService::step($this->report, 'preflight', 'FAIL', implode('; ', $problems));
            foreach ($problems as $p) {
                $j->error('preflight', $p);
            }

            return $this->finish('failed', 'ABORT (preflight): '.implode('; ', $problems));
        }
        ReportService::step($this->report, 'preflight', 'PASS');

        // ------------------------------------------------------------------------------------- download + SHA-256
        $this->status('downloading');
        $work = $this->workDir();
        @mkdir($work, 0775, true);
        $zip = $work.'/archive.zip';
        $download = new DownloadService();
        $source = $localSource ? $job->source_path : (string) ($caps['zip_url'] ?? '');
        if ($localSource) {
            if (!is_file($job->source_path) || !is_readable($job->source_path) || !@copy($job->source_path, $zip)) {
                $this->cleanWork();

                return $this->finish('failed', 'ABORT: local archive not readable: '.$job->source_path);
            }
            $size = (int) filesize($zip);
        } else {
            $size = $download->file($caps['zip_url'], $zip);
        }
        ReportService::step($this->report, 'download', 'PASS', round($size / 1024).' KB');
        $j->info('download', ($localSource ? 'Local archive copied' : 'Archive downloaded').' ('.round($size / 1024).' KB)');

        $this->status('verifying');
        $actual = ChecksumService::hashFile($zip);
        $this->zipHash = $actual;
        $expected = '';
        if ($localSource) {
            if ((string) $job->sums_path !== '') {
                $expected = ChecksumService::expectedFor((string) @file_get_contents($job->sums_path), basename($job->source_path));
                if ($expected === '') {
                    $expected = ChecksumService::expectedFor((string) @file_get_contents($job->sums_path), $module['name'].'.zip');
                }
            }
        } elseif (!empty($caps['sums_url'])) {
            try {
                $expected = ChecksumService::expectedFor($download->text($caps['sums_url']), DownloadService::zipName($module['manifest']));
            } catch (\Throwable $e) {
                $j->warning('verifying', 'SHA256SUMS not readable: '.$e->getMessage());
            }
        }
        // integrity state for the Security Gate: VERIFIED / MISMATCH / NOT AVAILABLE / DISABLED
        $integrity = $expected === '' ? 'NOT AVAILABLE' : (ChecksumService::matches($expected, $actual) ? 'VERIFIED' : 'MISMATCH');
        if ($gate && !Settings::get('sec_check_integrity')) {
            $integrity = 'DISABLED';
        }
        if ($integrity === 'MISMATCH') {
            ReportService::step($this->report, 'checksum', 'FAIL', 'expected '.$expected.', got '.$actual);
            $j->error('verifying', 'SHA-256 mismatch: expected '.$expected.', got '.$actual);
            if (!$gate) {
                $this->cleanWork();

                return $this->finish('failed', 'ABORT: SHA-256 mismatch');
            }
            // with the Security Gate, the policy decides (blocked by default, not overridable)
        } elseif ($integrity === 'DISABLED') {
            ReportService::step($this->report, 'checksum', 'SKIPPED', 'disabled in the Security Gate settings; sha256 '.$actual);
            $j->warning('verifying', 'SHA-256 verification disabled in the settings');
        } elseif ($integrity === 'VERIFIED') {
            ReportService::step($this->report, 'checksum', 'PASS', $actual);
            $j->info('verifying', 'SHA-256 verified');
        } elseif (!$localSource && !empty($caps['sha256']) && Settings::get('require_sha256')) {
            // the module publishes checksums but the file could not be read now: never install unverified
            ReportService::step($this->report, 'checksum', 'FAIL', 'SHA256SUMS announced but unavailable');
            $this->cleanWork();

            return $this->finish('failed', 'ABORT: SHA256SUMS unavailable (required)');
        } else {
            ReportService::step($this->report, 'checksum', 'NOT AVAILABLE', 'sha256 of the archive: '.$actual);
            $j->warning('verifying', 'No SHA256SUMS published: integrity not verified (sha256 '.$actual.')');
            if ($job->automatic && Settings::get('sec_auto_require_sha256')) {
                $this->cleanWork();

                return $this->finish('blocked', 'automatic update refused without SHA-256');
            }
        }

        // ---------------------------------------------------------------------------------------------- staging
        $this->status('staging');
        $inspection = ZipInspector::inspect($zip);
        if (!$inspection['ok']) {
            ReportService::step($this->report, 'staging', 'FAIL', implode('; ', $inspection['errors']));
            $j->error('staging', 'Archive refused: '.implode('; ', $inspection['errors']));
            if ($gate) {
                // security report of the refused package (never extracted)
                $this->securityGate($module, $from, $to ?: '?', $source, $zip, '', $actual, $integrity, $inspection['errors']);
            }
            $this->cleanWork();

            return $this->finish('failed', 'ABORT: archive refused ('.implode('; ', array_slice($inspection['errors'], 0, 3)).')');
        }
        $staged = $work.'/module';
        ZipInspector::extract($zip, $inspection['root'], $staged);
        $newManifest = ModuleDiscoveryService::manifest($staged);
        $stagedVersion = (string) ($newManifest['version'] ?? '');
        $structure = FallbackTestService::structure($staged, $module['name'], $module['alias']);
        if ($structure['module.json']['result'] === 'FAIL') {
            ReportService::step($this->report, 'staging', 'FAIL', $structure['module.json']['detail']);
            $this->cleanWork();

            return $this->finish('failed', 'ABORT: inconsistent structure ('.$structure['module.json']['detail'].')');
        }
        if (!VersionService::isNewer($stagedVersion, $from) && !$job->force) {
            $this->cleanWork();

            return $this->finish('failed', 'ABORT: the archive contains version '.$stagedVersion.', not newer than '.$from);
        }
        if ($stagedVersion !== $to) {
            $j->warning('staging', 'The archive contains '.$stagedVersion.' (latestVersionUrl said '.$to.')');
            $job->to_version = $to = $stagedVersion;
            $job->save();
            $this->report['to'] = $to;
            $j->to = $to;
        }
        $compat = $this->compatibility($newManifest);
        if ($compat) {
            ReportService::step($this->report, 'staging', 'FAIL', implode('; ', $compat));
            $this->cleanWork();

            return $this->finish('failed', 'ABORT: '.implode('; ', $compat));
        }
        $added = MigrationDiff::added($module['path'], $staged);
        $kind = MigrationDiff::rollbackSafety($added, $newManifest);
        $job->rollback_kind = $kind;
        $job->save();
        $this->report['rollback_kind'] = $kind;
        ReportService::step($this->report, 'staging', 'PASS', $inspection['entries'].' entries'.($added ? ', new migrations: '.implode(', ', $added) : ''));
        $j->info('staging', 'Archive extracted in staging and checked');
        if ($kind === 'unknown') {
            $j->warning('staging', 'New migrations ('.implode(', ', $added).'): database state unknown after a rollback, automatic rollback disabled for this update');
            if ($job->automatic && Settings::get('sec_auto_block_migrations')) {
                $this->cleanWork();

                return $this->finish('blocked', 'new migrations: automatic update disabled (database state unknown)');
            }
        }

        // ------------------------------------------------------------------------------------ generic tests
        if (Settings::get('fallback_validation')) {
            $checks = FallbackTestService::beforeInstall($staged, $module['name'], $module['alias']);
            $this->report['generic']['checks'] = $checks;
            if (FallbackTestService::failed($checks)) {
                ReportService::step($this->report, 'generic_tests', 'FAIL', FallbackTestService::summary($checks));
                $j->error('generic_tests', FallbackTestService::summary($checks));
                $this->cleanWork();
                $this->block($to, 'failed generic tests before install');

                return $this->finish('failed', 'ABORT: generic tests failed before install, nothing was changed');
            }
            ReportService::step($this->report, 'generic_tests', 'PASS');
        } else {
            ReportService::step($this->report, 'generic_tests', 'SKIPPED');
        }

        // ----------------------------------------------------------------------------------------- Security Gate
        // before anything is changed: SHA-256, archive, static analysis, external scanners, score, policy
        if ($gate) {
            $this->status('scanning');
            $sec = $this->securityGate($module, $from, $to, $source, $zip, $staged, $actual, $integrity, []);
            if ($sec['decision'] === 'blocked') {
                $this->cleanWork();

                return $this->finish('blocked', 'blocked by the security policy (risk '.$sec['score'].'/100 '.$sec['level'].': '.implode(', ', $sec['reasons']).')', 'blocked');
            }
            if ($sec['decision'] === 'confirm' || $sec['decision'] === 'upload_consent') {
                // waits for the administrator (Security Reports page or updateguard:update options); nothing changed
                $this->cleanWork();
                $job->security_sha256 = $actual;
                $job->save();
                $this->status('awaiting_decision', $sec['decision'] === 'upload_consent'
                    ? 'package unknown to an external scanner: upload or continue without it'
                    : 'security summary: confirmation required (risk '.$sec['score'].'/100 '.$sec['level'].')');
                $j->warning('security', $sec['decision'] === 'upload_consent'
                    ? 'Waiting for the administrator: upload the package to an external scanner or not'
                    : 'Waiting for the administrator to confirm the update after the security summary');

                return;
            }
            if ($integrity === 'MISMATCH') {
                $j->critical('security', 'SHA-256 mismatch accepted because the blocking rule is disabled in the settings');
            }
        } else {
            ReportService::step($this->report, 'security', 'DISABLED');
            if ($integrity === 'NOT AVAILABLE' && $localSource) {
                $j->warning('verifying', 'Local archive without SHA256SUMS: integrity not verified');
            }
        }

        // ------------------------------------------------------------------------------------------------ backup
        $this->status('backing_up');
        $backup = BackupService::create($module['name'], 'before_update', $job->update_id, $source);
        $job->backup_id = $backup->id;
        $job->save();
        ReportService::step($this->report, 'backup', 'PASS', $backup->path);
        $j->info('backing_up', 'Backup created ('.$backup->path.')');
        $this->event('before_update');

        // ----------------------------------------------------------------------------------------------- install
        $this->status('installing');
        InstallService::swap($module['name'], $staged, $this->workDir('previous'));
        $job->installed_at = now();
        $job->save();
        $row->state = 'pending_validation';
        $row->save();
        $j->info('installing', 'Files installed');
        $install = InstallService::moduleInstall($module['alias']);
        ReportService::step($this->report, 'install', $install['ok'] ? 'PASS' : 'FAIL', $install['detail']);
        if (!$install['ok']) {
            $j->error('installing', 'freescout:module-install failed: '.$install['detail']);

            return $this->afterInstallFailure([$install['timeout'] ? 'timeout' : 'bootstrap'], 'install failed: '.$install['detail']);
        }

        $this->status('clearing_cache');
        $cache = InstallService::clearCache();
        ReportService::step($this->report, 'clear_cache', $cache['ok'] ? 'PASS' : 'FAIL', $cache['detail']);

        // ----------------------------------------------------------------------------------------- health check
        $this->status('health_check');
        $causes = $this->healthCheck();
        if ($causes) {
            return $this->afterInstallFailure($causes, 'health check failed ('.implode(', ', $causes).')');
        }

        $minutes = (int) Settings::get('observation_minutes');
        if ($minutes > 0) {
            $job->observe_until = now()->addMinutes($minutes);
            $this->status('observing', 'observation until '.$job->observe_until->format('H:i'));
            ReportService::step($this->report, 'observation', 'RUNNING', $minutes.' min');
            $j->info('observing', 'Observation for '.$minutes.' minutes (version pending validation)');

            return;
        }
        ReportService::step($this->report, 'observation', 'SKIPPED');
        $this->success();
    }

    /**
     * Runs the Security Gate on the package, saves its report, records it in the update report and the log.
     * Decisions of the administrator apply only to the package they saw (same SHA-256).
     */
    protected function securityGate(array $module, $from, $to, $source, $zip, $staged, $sha256, $integrity, array $structureErrors)
    {
        $job = $this->job;
        $j = $this->journal;
        $s = Settings::all();
        // decisions of the web page apply to the package the administrator saw (same SHA-256); decisions given in
        // advance on the command line (no SHA-256 recorded) apply to the package of this run
        $decisions = in_array((string) $job->security_sha256, ['', $sha256], true) ? $job->decisions() : [];
        $sec = Security\SecurityGate::run([
            'module'           => $module['name'],
            'from'             => $from,
            'to'               => $to,
            'source'           => $source,
            'zip'              => $zip,
            'staged'           => $staged,
            'sha256'           => $sha256,
            'integrity'        => $integrity,
            'structure_errors' => $structureErrors,
            'trusted'          => Security\Trusts::keysFor($module['name']),
        ], $s, Security\Providers::external($s), Security\Providers::cache($s), $decisions, (bool) $job->automatic);
        $sec['update_id'] = $job->update_id;
        $sec['override_by'] = $sec['overridden'] ? (string) $job->triggered_by : '';
        if ($sec['overridden']) {
            $j->critical('security', 'OVERRIDE: update classified '.$sec['level'].' ('.$sec['score'].'/100: '.implode(', ', $sec['reasons']).') installed anyway on the decision of '.$job->triggered_by);
        }
        foreach ($sec['external'] as $name => $e) {
            if ($e['status'] === Security\ScanResult::ERROR) {
                $j->warning('security', $name.' unavailable: '.$e['message']);
            }
        }
        $label = ['allowed' => 'PASS', 'blocked' => 'BLOCKED', 'confirm' => 'WAITING', 'upload_consent' => 'WAITING'];
        ReportService::step($this->report, 'security', ($sec['overridden'] ? 'OVERRIDDEN' : $label[$sec['decision']]),
            'risk '.$sec['score'].'/100 '.$sec['level'].($sec['reasons'] ? ', '.implode(', ', $sec['reasons']) : ''));
        $this->report['security'] = [
            'score' => $sec['score'], 'level' => $sec['level'], 'decision' => $sec['decision'], 'overridden' => $sec['overridden'],
            'checks' => array_map(function ($c) {
                return $c['status'];
            }, $sec['checks']),
            'external' => array_map(function ($e) {
                return $e['status'];
            }, $sec['external']),
        ];
        $level = $sec['decision'] === 'blocked' ? 'error' : (Security\Rules::ORDER[$sec['level']] >= Security\Rules::ORDER['MEDIUM'] ? 'warning' : 'info');
        $j->{$level}('security', 'Security Gate: risk '.$sec['score'].'/100 '.$sec['level'].', decision '.$sec['decision'].($sec['reasons'] ? ' ('.implode(', ', $sec['reasons']).')' : ''));
        if (Settings::get('sec_save_reports')) {
            try {
                $row = Security\SecurityReports::save($sec, $job->update_id, $job->security_report_id);
                $job->security_report_id = $row->id;
                $job->save();
            } catch (\Throwable $e) {
                $j->warning('security', 'Security report not saved: '.$e->getMessage());
            }
        }

        return $sec;
    }

    /** @return string[] problems that stop the update before any change */
    protected function preflight(array $module)
    {
        $problems = [];
        foreach (Paths::unwritable($module['name']) as $path => $owner) {
            $problems[] = 'not writable by '.(function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?').': '.$path.' (owner '.$owner.')';
        }
        $free = @disk_free_space(Paths::base());
        $needed = Settings::get('min_free_mb') * 1048576 + 3 * Paths::dirSize($module['path']);
        if ($free !== false && $free < $needed) {
            $problems[] = 'disk space: '.round($free / 1048576).' MB free, '.round($needed / 1048576).' MB needed';
        }
        if (!class_exists('ZipArchive')) {
            $problems[] = 'PHP extension zip missing';
        }
        if (!is_writable(Paths::backups()) || !is_writable(Paths::staging())) {
            $problems[] = 'storage/updateguard not writable (backup impossible)';
        }
        if (!is_file($module['path'].'/module.json')) {
            $problems[] = 'installed module.json not found';
        }

        return $problems;
    }

    /** FreeScout / PHP version and dependencies required by the new version. */
    protected function compatibility(array $manifest)
    {
        $problems = [];
        $app = (string) config('app.version');
        $required = (string) ($manifest['requiredAppVersion'] ?? '');
        if ($required !== '' && $app !== '' && version_compare($app, $required, '<')) {
            $problems[] = 'requires FreeScout '.$required.' (installed: '.$app.')';
        }
        $php = (string) ($manifest['requiredPhpVersion'] ?? '');
        if ($php !== '' && version_compare(PHP_VERSION, $php, '<')) {
            $problems[] = 'requires PHP '.$php.' (installed: '.PHP_VERSION.')';
        }
        foreach ((array) ($manifest['requires'] ?? []) as $alias) {
            if (is_string($alias) && $alias !== '' && !ModuleDiscoveryService::isActive($alias)) {
                $problems[] = 'requires the module "'.$alias.'" (not active)';
            }
        }

        return $problems;
    }

    /** Native health check + generic validation; fills the report; returns the failure causes. */
    protected function healthCheck()
    {
        $j = $this->journal;
        $health = HealthCheckService::run($this->job->module);
        $native = $health['native'];
        $this->report['native_health'] = ['available' => $native['available'], 'command' => $native['command'], 'result' => $native['result'], 'json' => $native['json']];
        $this->report['generic']['checks'] = array_merge($this->report['generic']['checks'], $health['generic']);
        $this->report['generic']['result'] = $health['generic'] ? FallbackTestService::summary($health['generic']) : 'SKIPPED';
        ReportService::step($this->report, 'native_health', $native['result'], $native['detail']);
        ReportService::step($this->report, 'generic_validation', $health['generic'] ? (FallbackTestService::failed($health['generic']) ? 'FAIL' : 'PASS') : 'SKIPPED');
        if ($native['result'] === 'FAIL') {
            $j->error('health_check', 'Native health check failed ('.$native['command'].'): '.$native['detail']);
        } elseif ($native['result'] === 'PASS') {
            $j->info('health_check', 'Native health check passed ('.$native['command'].')');
        } else {
            $j->info('health_check', 'Native health check: NOT AVAILABLE — generic validation used');
        }
        if (FallbackTestService::failed($health['generic'])) {
            $j->error('health_check', 'Generic validation: '.FallbackTestService::summary($health['generic']));
        }
        try {
            \Eventy::action('updateguard.health_check', $this->job->module, $health);
        } catch (\Throwable $e) {
        }

        return $health['causes'];
    }

    /** Update under observation (scheduler, every minute): crash detection, then validation at the end. */
    public function observe(UpdateJob $job)
    {
        $this->job = $job;
        $this->journal = new Journal($job->update_id, $job->module, $job->from_version, $job->to_version, $this->out);
        $this->report = ReportService::load(Paths::reports(), $job->update_id)
            ?: ReportService::make($job->update_id, 'update', $job->module, $job->from_version, $job->to_version, $job->triggered_by, $job->automatic);
        $this->journal->report = &$this->report;
        $scan = CrashDetectionService::scan($job->module, $job->installed_at ? $job->installed_at->getTimestamp() : time());
        $causes = CrashDetectionService::causes($scan);
        $done = $job->observe_until === null || $job->observe_until->isPast();
        if (!$causes && !$done) {
            return;
        }
        $this->lock = $this->lock ?: new LockService(Paths::locks(), Settings::get('lock_timeout_minutes'));
        if (!$this->lock->acquire($job->module, $job->update_id)) {
            return;
        }
        try {
            if ($causes) {
                $this->report['crash'] = $scan;
                ReportService::step($this->report, 'observation', 'FAIL', 'HTTP 500: '.$scan['http500'].', fatal: '.$scan['fatal']);
                $this->journal->error('observing', 'Errors of the module in FreeScout\'s log since the update: HTTP 500 '.$scan['http500'].', fatal '.$scan['fatal']);
                $this->afterInstallFailure($causes, 'crash detected during observation');
            } else {
                $this->status('health_check');
                $causes = $this->healthCheck();
                if ($causes) {
                    $this->afterInstallFailure($causes, 'final health check failed ('.implode(', ', $causes).')');
                } else {
                    ReportService::step($this->report, 'observation', 'PASS', 'no error');
                    $this->success();
                }
            }
        } catch (\Throwable $e) {
            $this->journal->error('observing', $e->getMessage());
            $this->afterInstallFailure(['validation'], 'post-update validation crashed: '.$e->getMessage());
        } finally {
            $this->saveReport();
            $this->lock->release();
        }
    }

    protected function success()
    {
        $job = $this->job;
        $row = ModuleHealth::named($job->module);
        if ($row) {
            $row->installed_version = $job->to_version;
            $row->known_good_version = $job->to_version;
            $row->state = 'stable';
            if ($row->blocked_version === $job->to_version) {
                $row->blocked_version = '';
                $row->blocked_reason = '';
            }
            $row->last_update_at = now();
            $row->last_error = null;
            // the content just installed (and validated) becomes the reference of this version
            $caps = $row->capabilities();
            $caps['known_hashes'] = RepublishGuard::accept(isset($caps['known_hashes']) && is_array($caps['known_hashes']) ? $caps['known_hashes'] : [], $job->to_version, $this->zipHash);
            if (($caps['republished']['version'] ?? '') === $job->to_version) {
                $caps['republished'] = null;
            }
            $row->capabilities = json_encode($caps, JSON_UNESCAPED_SLASHES);
            $row->save();
        }
        $this->report['final_version'] = $job->to_version;
        $this->journal->info('successful', $job->module.' '.$job->from_version.' → '.$job->to_version.': version marked stable');
        $this->cleanWork();
        $this->finish('successful', 'updated to '.$job->to_version, 'healthy');
        $this->event('after_update');
        CleanupService::retention($job->module);
    }

    /**
     * Failure after the files were replaced: automatic rollback when one of the causes is enabled in the settings
     * and the database state allows it; otherwise the module stays as it is and the version is blocked.
     */
    protected function afterInstallFailure(array $causes, $reason)
    {
        $job = $this->job->fresh();
        $this->job = $job;
        $this->report['errors'][] = $reason;
        $this->event('update_failed');
        $enabled = array_values(array_filter($causes, function ($c) {
            return Settings::rollbackOn($c);
        }));
        if ($job->rollback_kind === 'unknown') {
            $this->journal->critical('rollback', 'Automatic rollback disabled: the new version ran migrations (database state unknown). Restore a backup manually after checking the database.');
            ReportService::step($this->report, 'rollback', 'DISABLED', 'database state unknown');
        } elseif (!$enabled) {
            $this->journal->error('rollback', 'Automatic rollback not enabled for: '.implode(', ', $causes));
            ReportService::step($this->report, 'rollback', 'DISABLED', 'settings');
        } else {
            return $this->rollback($reason);
        }
        $this->block($job->to_version, $reason);
        $row = ModuleHealth::named($job->module);
        if ($row) {
            $row->state = 'failed';
            $row->last_error = $reason;
            $row->save();
        }
        $this->cleanWork(false);
        $this->finish('failed', $reason, 'failed');
    }

    protected function rollback($reason)
    {
        $job = $this->job;
        $j = $this->journal;
        $this->status('rolling_back');
        $j->warning('rolling_back', 'Automatic rollback started ('.$reason.')');
        $this->event('before_rollback');
        $previous = $this->workDir('previous');
        $source = is_file($previous.'/module.json') ? $previous : null;
        if ($source === null && $job->backup_id) {
            $backup = UpdateBackup::find($job->backup_id);
            if ($backup && BackupService::verify($backup)) {
                $source = $this->workDir('restore-copy');
                Paths::copyTree($backup->filesDir(), $source);
            }
        }
        if ($source === null) {
            ReportService::step($this->report, 'rollback', 'FAIL', 'no previous version available');

            return $this->rollbackFailed('no previous version available');
        }
        $failed = $this->workDir('failed-'.VersionService::slug($job->to_version));
        Paths::deleteDir($failed);
        InstallService::swap($job->module, $source, $failed);
        $alias = (string) (ModuleDiscoveryService::manifest(Paths::modules($job->module))['alias'] ?? strtolower($job->module));
        $install = InstallService::moduleInstall($alias);
        InstallService::clearCache();
        $j->info('rolling_back', 'Version '.$job->from_version.' restored (module-install: '.$install['detail'].')');
        $health = HealthCheckService::run($job->module);
        if ($health['status'] === 'failed') {
            ReportService::step($this->report, 'rollback', 'FAIL', 'health check after rollback: '.implode(', ', $health['causes']));

            return $this->rollbackFailed('health check after rollback failed ('.implode(', ', $health['causes']).')');
        }
        ReportService::step($this->report, 'rollback', 'SUCCESS', $job->from_version.' restored');
        $j->info('rolled_back', 'Health check passed: known-good version '.$job->from_version.' confirmed');
        $this->block($job->to_version, $reason);
        $row = ModuleHealth::named($job->module);
        if ($row) {
            $row->installed_version = $job->from_version;
            $row->state = 'stable';
            $row->last_error = $reason;
            $row->save();
        }
        $this->report['final_version'] = $job->from_version;
        $this->cleanWork();
        $this->finish('rolled_back', $reason, 'healthy');
        $this->event('after_rollback');
    }

    protected function rollbackFailed($reason)
    {
        $this->journal->critical('rollback_failed', 'CRITICAL: rollback failed: '.$reason.'. Staging kept in '.$this->workDir().'; use Restorations or restore a backup by hand.');
        $row = ModuleHealth::named($this->job->module);
        if ($row) {
            $row->state = 'critical';
            $row->last_error = $reason;
            $row->save();
        }
        $this->block($this->job->to_version, $reason);
        $this->finish('rollback_failed', 'CRITICAL: '.$reason, 'critical');
        $this->event('after_rollback');
    }

    /** Loop protection: the version is not retried automatically (a newer version is evaluated again). */
    protected function block($version, $reason)
    {
        $row = ModuleHealth::named($this->job->module);
        if ($row && $version !== '') {
            $row->blocked_version = $version;
            $row->blocked_reason = mb_substr((string) $reason, 0, 250);
            $row->save();
        }
    }

    protected function cleanWork($all = true)
    {
        $work = $this->workDir();
        if (!is_dir($work)) {
            return;
        }
        if ($all) {
            Paths::deleteDir($work);
        } else {
            // keep the previous version (manual recovery), drop the archive
            @unlink($work.'/archive.zip');
            Paths::deleteDir($work.'/module');
        }
    }

    // -------------------------------------------------------------------------------------------------- restore

    protected function runRestore()
    {
        $job = $this->job;
        $j = $this->journal;
        $backup = $job->backup_id ? UpdateBackup::find($job->backup_id) : null;
        if (!$backup || $backup->module !== $job->module) {
            return $this->finish('failed', 'backup not found');
        }
        $module = ModuleDiscoveryService::find($job->module);
        if (!$module || $module['name'] === ModuleDiscoveryService::SELF) {
            return $this->finish('failed', 'module not installed or not restorable');
        }
        $job->from_version = $module['version'];
        $job->to_version = $backup->version;
        $job->save();
        $this->report['from'] = $module['version'];
        $this->report['to'] = $backup->version;
        $j->from = $module['version'];
        $j->to = $backup->version;
        $this->status('checking');
        if (!BackupService::verify($backup)) {
            ReportService::step($this->report, 'restore', 'FAIL', 'backup checksum mismatch or files missing');

            return $this->finish('failed', 'ABORT: the backup is incomplete or was modified (checksum)');
        }
        $problems = $this->preflight($module);
        if ($problems) {
            ReportService::step($this->report, 'preflight', 'FAIL', implode('; ', $problems));

            return $this->finish('failed', 'ABORT (preflight): '.implode('; ', $problems));
        }
        ReportService::step($this->report, 'preflight', 'PASS');
        $this->status('backing_up');
        $current = BackupService::create($module['name'], 'before_restore', $job->update_id, 'restore of '.$backup->path);
        ReportService::step($this->report, 'backup', 'PASS', $current->path);
        $j->info('backing_up', 'Backup of the current version '.$module['version'].' created');
        $this->event('before_rollback');

        $this->status('installing');
        $copy = $this->workDir('restore-copy');
        Paths::copyTree($backup->filesDir(), $copy);
        InstallService::swap($module['name'], $copy, $this->workDir('previous'));
        $job->installed_at = now();
        $job->save();
        $install = InstallService::moduleInstall($module['alias']);
        ReportService::step($this->report, 'restore', $install['ok'] ? 'PASS' : 'FAIL', $install['detail']);
        $this->status('clearing_cache');
        InstallService::clearCache();
        $this->status('health_check');
        $causes = $this->healthCheck();
        $row = ModuleHealth::named($module['name']);
        $this->report['final_version'] = $backup->version;
        if ($causes || !$install['ok']) {
            $reason = !$install['ok'] ? 'install failed: '.$install['detail'] : 'health check failed ('.implode(', ', $causes).')';
            // no automatic second restore (Info.md § 15)
            $j->critical('restore', 'CRITICAL: restore of '.$backup->version.' failed: '.$reason.'. The version before the restore is in backup '.$current->path);
            if ($row) {
                $row->installed_version = $backup->version;
                $row->state = 'critical';
                $row->last_error = $reason;
                $row->save();
            }
            $this->cleanWork(false);
            $this->finish('rollback_failed', 'CRITICAL: '.$reason, 'critical');
            $this->event('after_rollback');

            return;
        }
        if ($row) {
            $row->installed_version = $backup->version;
            $row->known_good_version = $backup->version;
            $row->state = 'stable';
            $row->last_error = null;
            $row->save();
        }
        $backup->known_good = true;
        $backup->health_status = 'healthy';
        $backup->save();
        $j->info('restore', $module['name'].' '.$backup->version.' restored, health check passed');
        $this->cleanWork();
        $this->finish('successful', 'restored '.$backup->version, 'healthy');
        $this->event('after_rollback');
    }

    // ------------------------------------------------------------------------------------------- health / check

    protected function runHealth()
    {
        $this->status('health_check');
        $module = ModuleDiscoveryService::find($this->job->module);
        if (!$module) {
            return $this->finish('failed', 'module not installed');
        }
        $this->report['from'] = $this->report['final_version'] = $module['version'];
        $causes = $this->healthCheck();
        $row = ModuleHealth::named($module['name']);
        $status = $row ? $row->health_status : ($causes ? 'failed' : 'healthy');
        $this->finish($causes ? 'failed' : 'successful', $causes ? 'health check failed ('.implode(', ', $causes).')' : 'health: '.$status, $status);
    }

    /**
     * Security scan without installing (buttons "Security scan" / "Scan all modules"): the published package when
     * the module publishes one, the installed files otherwise. One security report per module, linked to this job.
     */
    protected function runScan()
    {
        $this->status('verifying');
        $names = $this->job->module === '*' ? array_keys(ModuleDiscoveryService::installed()) : [$this->job->module];
        $upload = in_array('upload', $this->job->decisions(), true);
        $results = [];
        $blocked = 0;
        foreach ($names as $name) {
            $this->journal->module = $name;
            try {
                $r = Security\PackageScan::run($name, 'auto', ['upload' => $upload], $this->job->update_id);
                $results[] = $name.' '.$r['score'].'/100 '.$r['level'];
                $blocked += $r['decision'] === 'blocked' ? 1 : 0;
                $level = $r['decision'] === 'blocked' ? 'error' : (Security\Rules::ORDER[$r['level']] >= Security\Rules::ORDER['MEDIUM'] ? 'warning' : 'info');
                $this->journal->{$level}('security', 'Security scan ('.$r['scan_mode'].'): risk '.$r['score'].'/100 '.$r['level'].', decision '.$r['decision']);
            } catch (\Throwable $e) {
                $results[] = $name.' error';
                $this->journal->warning('security', 'Security scan not possible: '.$e->getMessage());
            }
        }
        $this->journal->module = $this->job->module;
        $this->report['final_version'] = '';
        $this->finish('successful', count($names).' module(s) scanned'.($blocked ? ', '.$blocked.' would be blocked' : '').': '.implode(', ', $results), $blocked ? 'warning' : 'healthy');
    }

    protected function runCheck()
    {
        $this->status('checking');
        $modules = $this->job->module === '*' ? ModuleDiscoveryService::sync() : array_filter([ModuleDiscoveryService::find($this->job->module)]);
        $available = [];
        $download = new DownloadService(min(30, (int) Settings::get('download_timeout')));
        foreach ($modules as $module) {
            $row = ModuleDiscoveryService::check($module, $download);
            if ($row->updateAvailable()) {
                $available[] = $row->name.' '.$row->installed_version.' → '.$row->latest_version;
                $this->journal->module = $row->name;
                $this->journal->info('checking', 'Update available: '.$row->installed_version.' → '.$row->latest_version);
            }
        }
        $this->journal->module = $this->job->module;
        $this->finish('successful', count($modules).' module(s) checked, '.count($available).' update(s)'.($available ? ': '.implode(', ', $available) : ''), 'healthy');
    }
}
