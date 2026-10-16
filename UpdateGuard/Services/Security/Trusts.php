<?php

namespace Modules\UpdateGuard\Services\Security;

use Modules\UpdateGuard\Entities\SecurityReport;
use Modules\UpdateGuard\Entities\Trust;
use Modules\UpdateGuard\Services\Journal;
use Modules\UpdateGuard\Services\Settings;

/**
 * "Trust" of reported behaviours: an administrator who checked a finding (e.g. a shell_exec() the module really
 * needs, VirusTotal reporting nothing) accepts it for this module. The trust is per finding (rule + file + detail),
 * so a new behaviour in a later version is reported again. Never for integrity, archive or external results.
 */
class Trusts
{
    /** @return string[] keys of the active trusts of a module (passed to SecurityGate::run as ctx "trusted") */
    public static function keysFor($module)
    {
        try {
            if (!Settings::get('sec_allow_trust')) {
                return [];
            }

            return Trust::active()->where('module', (string) $module)->pluck('finding_key')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Trusts every trustable finding of a report (from the security report page). Logged as a WARNING.
     * @return int number of new trusts
     */
    public static function trustReport(SecurityReport $row, $user, $reason)
    {
        $data = $row->data();
        $count = 0;
        $existing = array_flip(self::keysFor($row->module));
        foreach (SecurityGate::trustable((array) ($data['findings'] ?? [])) as $f) {
            $key = $f['key'] ?? Rules::findingKey($f);
            if (isset($existing[$key])) {
                continue;
            }
            Trust::create([
                'module'             => $row->module,
                'finding_key'        => $key,
                'rule'               => $f['rule'],
                'file'               => mb_substr((string) $f['file'], 0, 500),
                'message'            => (string) $f['message'],
                'severity'           => (string) $f['severity'],
                'version'            => (string) $row->to_version,
                'sha256'             => (string) $row->sha256,
                'security_report_id' => $row->id,
                'reason'             => mb_substr((string) $reason, 0, 2000),
                'user_id'            => $user ? $user->id : null,
                'user_name'          => $user ? (string) $user->getFullName() : '',
            ]);
            $existing[$key] = true;
            $count++;
        }
        if ($count) {
            (new Journal('', $row->module, $row->from_version, $row->to_version))->warning('security',
                $count.' finding(s) trusted by '.($user ? $user->getFullName() : '?').' (report #'.$row->id.', score '.$row->score.'/100): '.$reason);
        }

        return $count;
    }

    public static function revoke(Trust $trust, $user)
    {
        $trust->revoked_at = now();
        $trust->revoked_by = $user ? (string) $user->getFullName() : '';
        $trust->save();
        (new Journal('', $trust->module, $trust->version))->warning('security',
            'Trust removed by '.$trust->revoked_by.': '.$trust->message.($trust->file !== '' ? ' ('.$trust->file.')' : ''));
    }
}
