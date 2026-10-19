<?php

namespace Modules\UpdateGuard\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * One operation (table updateguard_jobs). States (Info.md § 35):
 * queued checking downloading verifying staging backing_up installing clearing_cache health_check observing
 * successful failed rolling_back rolled_back rollback_failed blocked
 */
class UpdateJob extends Model
{
    const ACTIVE = ['queued', 'checking', 'downloading', 'verifying', 'staging', 'scanning', 'backing_up', 'installing', 'clearing_cache', 'health_check', 'rolling_back'];
    const FINISHED = ['successful', 'failed', 'rolled_back', 'rollback_failed', 'blocked'];
    // paused by the Security Gate before any change: confirmation, override or upload consent of an administrator
    const WAITING = ['awaiting_decision'];

    protected $table = 'updateguard_jobs';

    protected $guarded = ['id'];

    protected $casts = [
        'automatic' => 'boolean',
        'force'     => 'boolean',
    ];

    protected $dates = ['installed_at', 'observe_until', 'started_at', 'finished_at'];

    public function isFinished()
    {
        return in_array($this->status, self::FINISHED, true);
    }

    public function isWaiting()
    {
        return in_array($this->status, self::WAITING, true);
    }

    /** Decisions already taken by an administrator for this package (continue, override, upload, skip_upload). */
    public function decisions()
    {
        return array_values(array_filter(explode(',', (string) $this->security_decision)));
    }

    /** A queued or running operation, or an update under observation, for this module. */
    public static function pendingFor($module)
    {
        return self::where('module', $module)->where('type', '!=', 'check')->whereIn('status', array_merge(self::ACTIVE, self::WAITING, ['observing']))->first();
    }
}
