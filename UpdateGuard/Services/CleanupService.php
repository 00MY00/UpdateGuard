<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Entities\UpdateBackup;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Entities\UpdateLog;

/**
 * Retention (Settings › Retention): backups per module and by age (never the last known-good one), log lines,
 * reports, leftovers of finished jobs in staging. Run daily by updateguard:cleanup and after each update.
 */
class CleanupService
{
    /** @return int backups deleted */
    public static function retention($module = null)
    {
        $keep = (int) Settings::get('backups_per_module');
        $age = (int) Settings::get('backup_max_age_days');
        $inUse = UpdateJob::whereNotNull('backup_id')->whereIn('status', array_merge(UpdateJob::ACTIVE, ['observing']))->pluck('backup_id')->all();
        $modules = $module !== null ? [$module] : UpdateBackup::distinct()->pluck('module')->all();
        $deleted = 0;
        foreach ($modules as $name) {
            $rows = UpdateBackup::where('module', $name)->get();
            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'id'         => $row->id,
                    'created_at' => $row->created_at ? $row->created_at->getTimestamp() : 0,
                    'known_good' => (bool) $row->known_good,
                    'locked'     => in_array($row->id, $inUse),
                ];
            }
            foreach (RetentionPolicy::select($list, $keep, $age) as $id) {
                $backup = $rows->where('id', $id)->first();
                if ($backup) {
                    BackupService::delete($backup);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /** @return array counts */
    public static function run()
    {
        Paths::ensure();
        $out = ['backups' => self::retention(), 'logs' => 0, 'reports' => 0, 'staging' => 0, 'security' => 0];
        // Security Gate reports (days + maximum count) and expired external scan results
        try {
            $out['security'] = Security\SecurityReports::prune(Settings::get('sec_report_retention_days'), Settings::get('sec_report_max'));
            Security\Providers::cache(Settings::all())->prune();
        } catch (\Throwable $e) {
        }
        $logDays = (int) Settings::get('log_retention_days');
        if ($logDays > 0) {
            $out['logs'] = UpdateLog::where('created_at', '<', now()->subDays($logDays))->delete();
        }
        $reportDays = (int) Settings::get('report_retention_days');
        if ($reportDays > 0) {
            foreach (glob(Paths::reports().'/UG-*.json') ?: [] as $file) {
                if (filemtime($file) < time() - $reportDays * 86400) {
                    @unlink($file);
                    $out['reports']++;
                }
            }
        }
        // staging folders of finished jobs, kept 7 days for a manual recovery after a failure
        foreach (glob(Paths::staging().'/UG-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $job = UpdateJob::where('update_id', basename($dir))->first();
            if ((!$job || $job->isFinished()) && filemtime($dir) < time() - 7 * 86400) {
                Paths::deleteDir($dir);
                $out['staging']++;
            }
        }

        return $out;
    }
}
