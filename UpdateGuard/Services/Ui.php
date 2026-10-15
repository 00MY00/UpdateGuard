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
