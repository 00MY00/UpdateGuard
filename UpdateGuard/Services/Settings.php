<?php

namespace Modules\UpdateGuard\Services;

/**
 * Settings of UpdateGuard in FreeScout's options table (\App\Option), keys "updateguard.<name>",
 * defaults in Config/config.php. Read without Option's in-memory cache: the web server and the scheduler
 * (another process) both read and write them.
 */
class Settings
{
    const PREFIX = 'updateguard.';

    const CHOICES = [
        'check_frequency'       => ['hourly', 'daily'],
        'auto_update_min_level' => ['full', 'protected'],
        'observation_minutes'   => [0, 5, 10, 30, 60],
        'backups_per_module'    => [3, 5, 10, 20, 0],
        'backup_max_age_days'   => [30, 90, 180, 365, 0],
        'log_retention_days'    => [30, 90, 180, 365, 0],
        'report_retention_days' => [30, 90, 180, 365, 0],
        'sec_confirm'           => ['never', 'warnings', 'always'],
        'vt_on_failure'         => ['warn', 'block'],
    ];

    public static function defaults()
    {
        $defaults = config('updateguard.defaults');

        return is_array($defaults) ? $defaults : [];
    }

    public static function get($name)
    {
        $defaults = self::defaults();
        $default = array_key_exists($name, $defaults) ? $defaults[$name] : null;
        try {
            $value = \App\Option::get(self::PREFIX.$name, $default, true, false);
        } catch (\Throwable $e) {
            $value = $default;
        }
        if (is_bool($default)) {
            return (bool) $value;
        }
        if (is_int($default)) {
            return (int) $value;
        }

        return $value === null ? $default : (string) $value;
    }

    public static function all()
    {
        $out = [];
        foreach (array_keys(self::defaults()) as $name) {
            $out[$name] = self::get($name);
        }

        return $out;
    }

    /** Saves the known keys only, booleans from checkboxes, choices checked against CHOICES. */
    public static function save(array $input)
    {
        foreach (self::defaults() as $name => $default) {
            if (is_bool($default)) {
                $value = !empty($input[$name]) ? 1 : 0;
            } elseif (!array_key_exists($name, $input)) {
                continue;
            } elseif (isset(self::CHOICES[$name])) {
                $value = is_int($default) ? (int) $input[$name] : (string) $input[$name];
                if (!in_array($value, self::CHOICES[$name], true)) {
                    continue;
                }
            } elseif (is_int($default)) {
                $value = max(0, min(100000, (int) $input[$name]));
            } else {
                $value = (string) $input[$name];
                if ($name === 'check_time' && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value)) {
                    continue;
                }
            }
            \App\Option::set(self::PREFIX.$name, $value);
        }
    }

    /** Whether a crash cause triggers the automatic rollback. */
    public static function rollbackOn($cause)
    {
        return self::get('auto_rollback') && self::get('rollback_'.$cause);
    }
}
