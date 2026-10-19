<?php

namespace Modules\UpdateGuard\Services;

/** Small helpers of the views: states with a symbol + text (never colour only), sizes, Refresh detection. */
class Ui
{
    /** [symbol, translation key, css modifier] */
    const STATES = [
        'healthy'            => ['✓', 'state_healthy', 'ok'],
        'warning'            => ['!', 'state_warning', 'warn'],
        'failed'             => ['×', 'state_failed', 'fail'],
        'critical'           => ['×', 'state_critical', 'fail'],
        'unknown'            => ['?', 'state_unknown', 'muted'],
        'rolled_back'        => ['↺', 'state_rolled_back', 'warn'],
        'update'             => ['↑', 'state_update', 'info'],
        'checking'           => ['●', 'state_checking', 'info'],
        'pending_validation' => ['●', 'state_pending', 'info'],
        'blocked'            => ['×', 'state_blocked', 'fail'],
        'stable'             => ['✓', 'state_stable', 'ok'],
        'successful'         => ['✓', 'state_successful', 'ok'],
        'rollback_failed'    => ['×', 'state_rollback_failed', 'fail'],
        'observing'          => ['●', 'state_observing', 'info'],
        'queued'             => ['●', 'state_queued', 'info'],
        'awaiting_decision'  => ['!', 'state_awaiting', 'warn'],
        'cancelled'          => ['×', 'state_cancelled', 'muted'],
        'scan'               => ['🛡', 'state_scan', 'info'],
    ];

    /** Security statuses: [symbol, css modifier] (text = the status itself, translated) */
    const SEC = [
        'VERIFIED'      => ['✓', 'ok'],
        'PASS'          => ['✓', 'ok'],
        'WARNING'       => ['!', 'warn'],
        'FAIL'          => ['×', 'fail'],
        'MISMATCH'      => ['×', 'fail'],
        'BLOCKED'       => ['×', 'fail'],
        'ERROR'         => ['!', 'warn'],
        'NOT AVAILABLE' => ['–', 'muted'],
        'NOT_AVAILABLE' => ['–', 'muted'],
        'DISABLED'      => ['–', 'muted'],
    ];

    public static function secStatus($status)
    {
        $status = (string) $status;
        $s = isset(self::SEC[$status]) ? self::SEC[$status] : ['●', 'info'];
        $key = 'sec_status_'.strtolower(str_replace([' ', '_'], '', $status));
        $label = \Illuminate\Support\Facades\Lang::has('updateguard::ug.'.$key) ? __('updateguard::ug.'.$key) : $status;

        return '<span class="ug-badge ug-'.$s[1].'">'.e($s[0].' '.$label).'</span>';
    }

    public static function risk($score, $level)
    {
        $css = ['LOW' => 'ok', 'MEDIUM' => 'warn', 'HIGH' => 'fail', 'CRITICAL' => 'fail'];
        $symbol = ['LOW' => '✓', 'MEDIUM' => '!', 'HIGH' => '×', 'CRITICAL' => '×'];
        $level = isset($css[$level]) ? $level : 'LOW';

        return '<span class="ug-badge ug-'.$css[$level].'">'.e($symbol[$level].' '.(int) $score.' / 100 · '.__('updateguard::ug.sec_level_'.strtolower($level))).'</span>';
    }

    const LEVELS = [
        SafetyLevel::FULL            => ['✓', 'level_full', 'ok'],
        SafetyLevel::PROTECTED_LEVEL => ['!', 'level_protected', 'warn'],
        SafetyLevel::GENERIC         => ['!', 'level_generic', 'warn'],
        SafetyLevel::UNSUPPORTED     => ['×', 'level_unsupported', 'muted'],
    ];

    public static function badge($state)
    {
        $s = isset(self::STATES[$state]) ? self::STATES[$state] : ['●', null, 'info'];
        $label = $s[1] ? __('updateguard::ug.'.$s[1]) : str_replace('_', ' ', (string) $state);

        return '<span class="ug-badge ug-'.$s[2].'">'.e($s[0].' '.$label).'</span>';
    }

    public static function level($level)
    {
        $s = isset(self::LEVELS[$level]) ? self::LEVELS[$level] : self::LEVELS[SafetyLevel::UNSUPPORTED];

        return '<span class="ug-badge ug-'.$s[2].'">'.e($s[0].' '.__('updateguard::ug.'.$s[1])).'</span>';
    }

    /** [symbol, translation key, css modifier] of the SelfCheck statuses */
    const SELF_CHECK = [
        'verified'    => ['✓', 'self_verified', 'ok'],
        'intact'      => ['✓', 'self_intact', 'ok'],
        'modified'    => ['×', 'self_modified', 'fail'],
        'flagged'     => ['×', 'self_flagged', 'fail'],
        'unavailable' => ['?', 'self_unavailable', 'muted'],
    ];

    public static function isSelf($row)
    {
        return $row->name === ModuleDiscoveryService::SELF;
    }

    /** Result of the last SelfCheck of UpdateGuard (row of updateguard_modules), or null. */
    public static function selfCheck($row)
    {
        $check = $row->capability('self_check');

        return is_array($check) && isset(self::SELF_CHECK[$check['status'] ?? '']) ? $check : null;
    }

    /** Badge of UpdateGuard's own check (shown instead of the health state on its row). */
    public static function selfBadge($row)
    {
        $check = self::selfCheck($row);
        if (!$check) {
            return self::badge('unknown');
        }
        $s = self::SELF_CHECK[$check['status']];

        return '<span class="ug-badge ug-'.$s[2].'">'.e($s[0].' '.__('updateguard::ug.'.$s[1])).'</span>';
    }

    /** Neutral badge of the "Safety" column for UpdateGuard: updated by FreeScout, nothing to alert about. */
    public static function selfLevel()
    {
        return '<span class="ug-badge ug-muted">'.e('↻ '.__('updateguard::ug.level_self')).'</span>';
    }

    /** FreeScout's Modules page, where UpdateGuard itself is updated. */
    public static function freescoutModulesUrl()
    {
        try {
            if (\Route::has('modules')) {
                return route('modules');
            }
        } catch (\Throwable $e) {
        }

        return url('/modules/list');
    }

    /** State shown for a module row: an update available wins over "healthy". */
    public static function moduleState($row)
    {
        if ($row->state === 'critical' || $row->state === 'pending_validation' || $row->state === 'failed') {
            return $row->state;
        }
        if ($row->updateAvailable() && $row->blocked_version !== $row->latest_version) {
            return 'update';
        }

        return $row->health_status ?: 'unknown';
    }

    public static function size($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024).' KB';
    }

    public static function refreshActive()
    {
        try {
            return (bool) \App\Module::isActive('refresh');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function version()
    {
        $m = ModuleDiscoveryService::manifest(Paths::modules(ModuleDiscoveryService::SELF));

        return (string) ($m['version'] ?? '1');
    }

    /** Last failed operation not yet seen on the dashboard (setting notify_banner). */
    public static function alert()
    {
        if (!Settings::get('notify_banner')) {
            return null;
        }
        try {
            $seen = (int) \App\Option::get('updateguard.alert_seen', 0, true, false);

            return \Modules\UpdateGuard\Entities\UpdateJob::where('id', '>', $seen)
                ->whereIn('status', ['failed', 'rolled_back', 'rollback_failed'])
                ->whereIn('type', ['update', 'restore'])
                ->orderBy('id', 'desc')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Modules whose LAST security report says the package is unknown to an external scanner (VirusTotal) and was not
     * uploaded: the dashboard and the module page propose "Upload and scan". Only while VirusTotal is enabled.
     * @return \Illuminate\Support\Collection of SecurityReport
     */
    public static function unknownToScanners()
    {
        try {
            if (!Settings::get('vt_enabled')) {
                return collect();
            }
            $ids = \Modules\UpdateGuard\Entities\SecurityReport::selectRaw('max(id) as id')->groupBy('module')->pluck('id');

            return \Modules\UpdateGuard\Entities\SecurityReport::whereIn('id', $ids)->orderBy('module')->get()->filter(function ($row) {
                return self::isUnknownToScanners($row);
            })->values();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** The package of this report can be sent: unknown to a scanner that accepts uploads, not installed files. */
    public static function isUnknownToScanners($row)
    {
        $data = $row->data();
        // UpdateGuard itself: its official package is proposed by its own check (SelfCheck), not from old reports
        if ($row->module === ModuleDiscoveryService::SELF
            || ($data['scan_mode'] ?? '') === 'installed' || (string) $row->sha256 === '' || !is_dir(Paths::modules($row->module))) {
            return false;
        }
        foreach ((array) ($data['external'] ?? []) as $e) {
            if (!empty($e['unknown']) && empty($e['skipped'])) {
                return true;
            }
        }

        return false;
    }

    /** @var array ids of the operations whose progress bar is already on the page */
    protected static $progressShown = [];

    /**
     * true the first time a progress bar is asked for this operation in the request: the page frame (operation just
     * started) and the page itself (operation in progress for the module, running jobs...) may ask for the same one.
     */
    public static function firstProgress($jobId)
    {
        $jobId = (int) $jobId;
        if ($jobId <= 0 || isset(self::$progressShown[$jobId])) {
            return false;
        }
        self::$progressShown[$jobId] = true;

        return true;
    }

    /** End of the last check of every module ("Check all", daily / hourly task, automatic check), or null. */
    public static function lastFullCheck()
    {
        try {
            $job = \Modules\UpdateGuard\Entities\UpdateJob::where('type', 'check')->where('module', '*')
                ->where('status', 'successful')->orderBy('id', 'desc')->first();

            return $job ? ($job->finished_at ?: $job->updated_at) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Versions are checked in the background when the dashboard or the Modules page is opened and the last check is
     * more than an hour old (FreeScout's own Modules page checks on every visit): a queued "check" job, run by the
     * scheduler within a minute; the page refreshes when it is done. Returns the queued / running check job, or null.
     */
    public static function autoCheck()
    {
        try {
            if (!Settings::get('enabled')) {
                return null;
            }
            $running = \Modules\UpdateGuard\Entities\UpdateJob::where('type', 'check')->where('module', '*')
                ->whereIn('status', \Modules\UpdateGuard\Entities\UpdateJob::ACTIVE)->orderBy('id', 'desc')->first();
            if ($running) {
                return $running;
            }
            // any finished attempt counts (a failing check is not started again on every page load)
            $attempt = \Modules\UpdateGuard\Entities\UpdateJob::where('type', 'check')->where('module', '*')
                ->whereIn('status', \Modules\UpdateGuard\Entities\UpdateJob::FINISHED)->orderBy('id', 'desc')->first();
            $last = $attempt ? ($attempt->finished_at ?: $attempt->updated_at) : null;
            if ($last && $last->getTimestamp() > time() - 3600) {
                return null;
            }

            return UpdateRunner::queue('check', '*', ['triggered_by' => 'Automatic (page opened)']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Why the latest version of a module is unknown ('' when it is known). */
    public static function latestUnknownReason($row)
    {
        if ($row->latest_version !== '') {
            return '';
        }
        $caps = $row->capabilities();
        if (!empty($caps['official'])) {
            return __('updateguard::ug.latest_none_official');
        }
        if ($row->last_check_at === null) {
            return __('updateguard::ug.latest_not_checked');
        }
        if (empty($caps['version_url'])) {
            return __('updateguard::ug.latest_none_url');
        }

        return __('updateguard::ug.latest_check_error', ['error' => mb_substr((string) ($caps['error'] ?? ''), 0, 160)]);
    }

    /** Modules whose latest version was published again with another SHA-256 (RepublishGuard). */
    public static function republished()
    {
        try {
            return \Modules\UpdateGuard\Entities\ModuleHealth::orderBy('name')->get()->filter(function ($row) {
                return !empty($row->capability('republished'));
            })->values();
        } catch (\Throwable $e) {
            return collect();
        }
    }
}
