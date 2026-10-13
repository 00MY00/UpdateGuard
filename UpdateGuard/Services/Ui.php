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
    ];

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
}
