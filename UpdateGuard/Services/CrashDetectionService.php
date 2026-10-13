<?php

namespace Modules\UpdateGuard\Services;

/**
 * Crash detection after an update (Info.md § 9):
 *   - bootstrap failure, ServiceProvider exception, class not found, route registration failure:
 *     generic validation in a new process (FallbackTestService::probe);
 *   - HTTP 500 and fatal PHP errors of real requests: FreeScout's Laravel log, entries written since the
 *     installation that point to the module (LogScanner), during the whole observation period.
 */
class CrashDetectionService
{
    public static function logFiles()
    {
        $files = glob(storage_path('logs').'/laravel*.log') ?: [];
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return array_slice($files, 0, 3);
    }

    /** @return array ['http500' => int, 'fatal' => int, 'samples' => string[]] */
    public static function scan($moduleName, $since)
    {
        return LogScanner::scan(self::logFiles(), $moduleName, $since);
    }

    /** Causes found in a scan, as setting names (rollback_http500, rollback_fatal). */
    public static function causes(array $scan)
    {
        $causes = [];
        if ($scan['fatal'] > 0) {
            $causes[] = 'fatal';
        }
        if ($scan['http500'] > 0) {
            $causes[] = 'http500';
        }

        return $causes;
    }
}
