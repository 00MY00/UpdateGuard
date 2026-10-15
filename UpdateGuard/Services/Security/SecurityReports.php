<?php

namespace Modules\UpdateGuard\Services\Security;

use Modules\UpdateGuard\Entities\SecurityReport;

/** Storage of the Security Gate reports (page UpdateGuard › Security Reports) and their retention. */
class SecurityReports
{
    /** Status of the local scan for the list: PASS / WARNING / FAIL / DISABLED / NOT AVAILABLE. */
    public static function localStatus(array $r)
    {
        $statuses = [];
        foreach (SecurityGate::LOCAL_CHECKS as $check) {
            $statuses[] = $r['checks'][$check]['status'] ?? 'DISABLED';
        }
        foreach (['ERROR', 'FAIL', 'WARNING', 'PASS', 'NOT AVAILABLE'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'DISABLED';
    }

    public static function externalStatus(array $r)
    {
        if (!$r['external']) {
            return 'DISABLED';
        }
        $statuses = array_column($r['external'], 'status');
        foreach ([ScanResult::BLOCKED, ScanResult::ERROR, ScanResult::WARNING, ScanResult::NOT_AVAILABLE, ScanResult::PASS] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'DISABLED';
    }

    /** Creates (or replaces, for a re-run of the same update) the report row. */
    public static function save(array $r, $updateId, $existingId = null)
    {
        $row = $existingId ? SecurityReport::find($existingId) : null;
        $row = $row ?: new SecurityReport();
        $row->fill([
            'update_id'       => (string) $updateId,
            'module'          => $r['module'],
            'from_version'    => $r['from'],
            'to_version'      => $r['to'],
            'source'          => mb_substr($r['source'], 0, 500),
            'sha256'          => $r['sha256'],
            'integrity'       => (string) ($r['checks']['integrity']['status'] ?? ''),
            'local_status'    => self::localStatus($r),
            'external_status' => self::externalStatus($r),
            'score'           => (int) $r['score'],
            'level'           => (string) $r['level'],
            'decision'        => $r['overridden'] ? 'overridden' : (string) $r['decision'],
            'override_by'     => (string) ($r['override_by'] ?? ''),
            'report'          => SecurityGate::toJson($r),
        ]);
        $row->save();

        return $row;
    }

    /** Result of the update after the gate (UpdateRunner::finish): result, health check, rollback. */
    public static function complete($id, $result, $health, $rollback)
    {
        $row = $id ? SecurityReport::find($id) : null;
        if (!$row) {
            return;
        }
        $row->result = (string) $result;
        $row->health = (string) $health;
        $row->rollback = (string) $rollback;
        $data = $row->data();
        $data['result'] = ['status' => (string) $result, 'health' => (string) $health, 'rollback' => (string) $rollback];
        $row->report = SecurityGate::toJson($data);
        $row->save();
    }

    /** Retention: older than N days, then beyond the maximum count (0 = unlimited). Returns deleted count. */
    public static function prune($days, $max)
    {
        $deleted = 0;
        if ((int) $days > 0) {
            $deleted += SecurityReport::where('created_at', '<', now()->subDays((int) $days))->delete();
        }
        if ((int) $max > 0) {
            $keep = SecurityReport::orderBy('id', 'desc')->limit((int) $max)->pluck('id')->all();
            if ($keep) {
                $deleted += SecurityReport::whereNotIn('id', $keep)->delete();
            }
        }

        return $deleted;
    }
}
