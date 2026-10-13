<?php

namespace Modules\UpdateGuard\Services;

/**
 * Which backups of ONE module to delete. Pure PHP.
 *
 * "Keep last N versions AND delete backups older than D days": a backup is deleted when it is beyond the N most
 * recent OR older than D days (0 = unlimited for either rule). The last known-good backup is never deleted
 * automatically, nor a backup that is in use by a running operation.
 */
class RetentionPolicy
{
    /**
     * @param array $backups each: ['id' => mixed, 'created_at' => unix time, 'known_good' => bool, 'locked' => bool]
     * @return array ids to delete
     */
    public static function select(array $backups, $keep, $maxAgeDays, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $keep = max(0, (int) $keep);
        $maxAgeDays = max(0, (int) $maxAgeDays);
        usort($backups, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        // the most recent known-good backup is protected
        $protected = null;
        foreach ($backups as $b) {
            if (!empty($b['known_good'])) {
                $protected = $b['id'];
                break;
            }
        }
        $delete = [];
        foreach ($backups as $i => $b) {
            if ($b['id'] === $protected || !empty($b['locked'])) {
                continue;
            }
            $tooMany = $keep > 0 && $i >= $keep;
            $tooOld = $maxAgeDays > 0 && $b['created_at'] < $now - $maxAgeDays * 86400;
            if ($tooMany || $tooOld) {
                $delete[] = $b['id'];
            }
        }

        return $delete;
    }
}
