<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Decision of the Security Gate from the scan results and the administrator's policy. Pure PHP.
 *
 *   allowed         the update may be installed
 *   blocked         a policy rule blocks it (overridable or not)
 *   confirm         a manual update waits for the administrator's "Continue update" (summary shown first)
 *   upload_consent  an external provider does not know the package: the administrator decides whether it is sent
 *
 * Decisions already taken by the administrator for this update: "continue", "override", "upload", "skip_upload".
 * Automatic updates never wait: they are allowed or blocked, and never upload anything without the setting.
 *
 * Not overridable: a checksum mismatch (the file is not the one the author published) and an unsafe archive (Zip
 * Slip, links...: it cannot be extracted safely). Those rules can only be switched off in the settings.
 */
class SecurityPolicy
{
    public static function decide(array $report, array $s, array $decisions = [], $automatic = false)
    {
        $out = ['decision' => 'allowed', 'reasons' => [], 'overridable' => false, 'overridden' => false];
        $hard = false;
        $blocked = false;

        if (($report['checks']['integrity']['status'] ?? '') === 'MISMATCH' && !empty($s['sec_block_checksum'])) {
            $blocked = $hard = true;
            $out['reasons'][] = 'checksum_mismatch';
        }
        if (($report['checks']['structure']['status'] ?? '') === 'FAIL' && !empty($s['sec_block_traversal'])) {
            $blocked = $hard = true;
            $out['reasons'][] = 'archive_unsafe';
        }
        if (($report['local_level'] ?? '') === Rules::CRITICAL && !empty($s['sec_block_critical_local'])) {
            $blocked = true;
            $out['reasons'][] = 'critical_local';
        }
        $threshold = (int) ($s['sec_score_threshold'] ?? 80);
        if (!empty($s['sec_block_score']) && (int) $report['score'] >= max(1, $threshold)) {
            $blocked = true;
            $out['reasons'][] = 'score';
        }
        $uploadCandidates = [];
        foreach ((array) ($report['external'] ?? []) as $name => $r) {
            if ($r['status'] === ScanResult::BLOCKED && !empty($s['sec_block_external'])) {
                $blocked = true;
                $out['reasons'][] = 'external_blocked:'.$name;
            }
            if ($r['status'] === ScanResult::ERROR && (!empty($s['sec_block_external_unavailable']) || !empty($r['block_on_error']))) {
                $blocked = true;
                $out['reasons'][] = 'external_unavailable:'.$name;
            }
            if (!empty($r['upload_candidate'])) {
                $uploadCandidates[] = $name;
            }
            // automatic update of a package the external scanner has never seen: not installed until it is checked
            // (the dashboard offers "Upload and scan"; the next automatic run tries again once it is known)
            if ($automatic && !empty($r['unknown']) && empty($r['skipped']) && !empty($s['sec_auto_require_known'])) {
                $blocked = true;
                $out['reasons'][] = 'unknown_to:'.$name;
            }
        }

        if ($blocked) {
            $out['overridable'] = !$hard && !empty($s['sec_allow_override']) && !$automatic;
            if ($out['overridable'] && in_array('override', $decisions, true)) {
                $out['decision'] = 'allowed';
                $out['overridden'] = true;
            } else {
                $out['decision'] = 'blocked';
            }

            return $out;
        }
        if ($automatic) {
            return $out;
        }
        if ($uploadCandidates && !array_intersect(['upload', 'skip_upload'], $decisions)) {
            $out['decision'] = 'upload_consent';
            $out['reasons'][] = 'unknown_to:'.implode(',', $uploadCandidates);

            return $out;
        }
        $mode = (string) ($s['sec_confirm'] ?? 'warnings');
        $warnings = Rules::ORDER[$report['level'] ?? ''] >= Rules::ORDER[Rules::MEDIUM];
        foreach ((array) ($report['external'] ?? []) as $r) {
            $warnings = $warnings || in_array($r['status'], [ScanResult::WARNING, ScanResult::ERROR], true);
        }
        if (!in_array('continue', $decisions, true) && ($mode === 'always' || ($mode === 'warnings' && $warnings))) {
            $out['decision'] = 'confirm';
        }

        return $out;
    }
}
