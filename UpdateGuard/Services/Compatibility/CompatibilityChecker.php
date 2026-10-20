<?php

namespace Modules\UpdateGuard\Services\Compatibility;

/**
 * Compatibility of a module version with the projects installed next to it (pure PHP). Never a hard dependency:
 * only what the module declares (Metadata) and UpdateGuard's emergency rules are checked, BEFORE installation
 * (health checks happen after it).
 *
 * States of a related project, then of the whole result:
 *   COMPATIBLE         fully supported combination
 *   DEGRADED           works, some optional integration unavailable (legacy compatibility mode)
 *   UNKNOWN            no compatibility information (or outside the declared range of an optional project)
 *   INCOMPATIBLE       installed project / version explicitly declared incompatible
 *   MISSING_REQUIRED   required dependency missing (or disabled)
 *   NOT_INSTALLED      optional project absent: shown "OPTIONAL / NOT INSTALLED", never blocks
 */
class CompatibilityChecker
{
    const COMPATIBLE = 'COMPATIBLE';
    const DEGRADED = 'DEGRADED';
    const UNKNOWN = 'UNKNOWN';
    const INCOMPATIBLE = 'INCOMPATIBLE';
    const MISSING_REQUIRED = 'MISSING_REQUIRED';
    const NOT_INSTALLED = 'NOT_INSTALLED';

    /** Severity of the overall result (the worst project wins). */
    const SEVERITY = [
        self::COMPATIBLE => 0, self::NOT_INSTALLED => 0, self::DEGRADED => 1, self::UNKNOWN => 2,
        self::INCOMPATIBLE => 3, self::MISSING_REQUIRED => 4,
    ];

    /**
     * @param array $target ['name' => 'RefreshGlobal', 'id' => 'refresh-global', 'version' => '1.20.0']
     * @param array|null $metadata Metadata::normalize() (null: nothing declared)
     * @param string $source where the metadata comes from (shown in reports)
     * @param array $installed ProjectRegistry::installed() rows
     * @param array $platform ['freescout' => '1.8.120', 'php' => '8.2.1']
     */
    public static function evaluate(array $target, $metadata, $source, array $installed, array $platform)
    {
        $result = [
            'module'   => (string) ($target['name'] ?? ''),
            'id'       => (string) ($target['id'] ?? ''),
            'version'  => (string) ($target['version'] ?? ''),
            'source'   => $metadata === null ? '' : (string) $source,
            'platform' => [],
            'projects' => [],
            'errors'   => $metadata['errors'] ?? [],
            'overall'  => self::UNKNOWN,
        ];
        foreach (['freescout' => 'FreeScout', 'php' => 'PHP'] as $key => $name) {
            $range = (string) ($metadata[$key] ?? '');
            $version = (string) ($platform[$key] ?? '');
            $result['platform'][] = [
                'id'        => $key,
                'name'      => $name,
                'installed' => $version,
                'required'  => $range,
                'result'    => $range === '' ? 'NOT DECLARED' : ($version === '' ? 'UNKNOWN' : (VersionRange::satisfies($version, $range) ? 'PASS' : 'FAIL')),
            ];
        }
        if ($metadata === null) {
            // nothing declared: platform not checked, projects unknown
            return $result;
        }
        foreach ($metadata['projects'] as $id => $project) {
            $result['projects'][] = self::project($result, $project, ProjectRegistry::find($installed, $project));
        }
        $worst = self::COMPATIBLE;
        foreach ($result['projects'] as $p) {
            if (self::SEVERITY[$p['state']] > self::SEVERITY[$worst]) {
                $worst = $p['state'];
            }
        }
        foreach ($result['platform'] as $p) {
            if ($p['result'] === 'FAIL' && self::SEVERITY[self::INCOMPATIBLE] > self::SEVERITY[$worst]) {
                $worst = self::INCOMPATIBLE;
            }
        }
        $result['overall'] = $worst;

        return $result;
    }

    /** One related project. */
    protected static function project(array $target, array $p, $installed)
    {
        $row = [
            'id'                 => $p['id'],
            'name'               => $installed && $installed['name'] !== '' ? $installed['name'] : $p['name'],
            'relationship'       => $p['relationship'],
            'required'           => $p['required'],
            'repository'         => $p['repository'] ?: ($installed['repository'] ?? ''),
            'installed'          => $installed !== null,
            'active'             => $installed ? $installed['active'] : false,
            'version'            => $installed ? $installed['version'] : '',
            'supported_versions' => $p['supported_versions'],
            'legacy_versions'    => $p['legacy']['supported_versions'],
            'conflicts'          => $p['conflicts'],
            'interop_required'   => $p['interop_protocol'],
            'interop_installed'  => $installed ? $installed['interop_protocol'] : '',
            'interop'            => '',
            'state'              => self::UNKNOWN,
            'reason'             => '',
            'builtin'            => false,
        ];
        $label = $target['module'].' '.$target['version'];
        $name = $row['name'];

        // ---- absent or disabled
        if (!$installed || !$installed['active']) {
            $why = $installed ? 'disabled' : 'not installed';
            if ($p['relationship'] === 'conflict') {
                return array_merge($row, ['state' => self::NOT_INSTALLED, 'reason' => $name.' '.$why.': no conflict']);
            }
            if ($p['required']) {
                return array_merge($row, ['state' => self::MISSING_REQUIRED, 'reason' => $label.' requires '.$name.' ('.$why.')']);
            }

            return array_merge($row, ['state' => self::NOT_INSTALLED, 'reason' => $p['relationship'] === 'recommended_companion'
                ? $name.' is recommended with '.$target['module'].' ('.$why.')'
                : $name.' is optional ('.$why.'): '.$target['module'].' works without it']);
        }
        $version = $installed['version'];
        if (!VersionRange::isVersion($version)) {
            return array_merge($row, ['reason' => 'version of '.$name.' unknown']);
        }

        // ---- declared conflicts (relationship "conflict": every version unless ranges are given)
        if ($p['relationship'] === 'conflict') {
            $ranges = $p['conflicts'] ?: ($p['supported_versions'] !== '' ? [$p['supported_versions']] : ['*']);
            foreach ($ranges as $range) {
                if (VersionRange::satisfies($version, $range)) {
                    return array_merge($row, ['state' => self::INCOMPATIBLE, 'reason' => $label.' declares a conflict with '.$name.($range !== '*' ? ' '.$range : '').' (installed: '.$version.')']);
                }
            }

            return array_merge($row, ['state' => self::COMPATIBLE, 'reason' => $name.' '.$version.' is outside the conflicting versions']);
        }
        foreach ($p['conflicts'] as $range) {
            if (VersionRange::satisfies($version, $range)) {
                return array_merge($row, ['state' => self::INCOMPATIBLE, 'reason' => $label.' declares '.$name.' '.$range.' as unsupported (installed: '.$version.')']);
            }
        }
        // ---- emergency rules of UpdateGuard
        foreach ($p['builtin'] as $rule) {
            if (VersionRange::satisfies($version, $rule['versions'])) {
                return array_merge($row, ['state' => $rule['state'], 'builtin' => true,
                    'reason' => 'UpdateGuard compatibility rule: '.$label.' with '.$name.' '.$rule['versions'].($rule['reason'] !== '' ? ' — '.$rule['reason'] : '')]);
            }
        }

        // ---- supported versions, then the interoperability protocol
        $interop = self::interop($p['interop_protocol'], $installed['interop_protocol']);
        $row['interop'] = $interop;
        if ($p['supported_versions'] !== '' && VersionRange::satisfies($version, $p['supported_versions'])) {
            if ($interop === 'FAIL') {
                if (self::interop($p['legacy']['interop_protocol'], $installed['interop_protocol']) === 'PASS') {
                    return array_merge($row, ['state' => self::DEGRADED, 'interop' => 'LEGACY', 'reason' => self::degradedMessage($target, $p, $name)]);
                }

                return array_merge($row, ['state' => self::INCOMPATIBLE, 'reason' => $name.' '.$version.' offers the interop protocol '
                    .$installed['interop_protocol'].', '.$label.' needs '.$p['interop_protocol']]);
            }

            return array_merge($row, ['state' => self::COMPATIBLE, 'reason' => $name.' '.$version.' is supported ('.$p['supported_versions'].')']);
        }
        if ($p['legacy']['supported_versions'] !== '' && VersionRange::satisfies($version, $p['legacy']['supported_versions'])) {
            return array_merge($row, ['state' => self::DEGRADED, 'interop' => $interop === 'PASS' ? 'PASS' : 'LEGACY', 'reason' => self::degradedMessage($target, $p, $name)]);
        }
        if ($p['supported_versions'] === '') {
            // no version range: the protocol alone can tell
            if ($interop === 'PASS') {
                return array_merge($row, ['state' => self::COMPATIBLE, 'reason' => $name.' offers a supported interop protocol ('.$installed['interop_protocol'].')']);
            }
            if ($interop === 'FAIL') {
                return array_merge($row, ['state' => self::INCOMPATIBLE, 'reason' => $name.' offers the interop protocol '.$installed['interop_protocol'].', '.$label.' needs '.$p['interop_protocol']]);
            }

            return array_merge($row, ['reason' => $label.' declares no supported versions of '.$name]);
        }
        if ($p['required']) {
            return array_merge($row, ['state' => self::INCOMPATIBLE, 'reason' => $label.' requires '.$name.' '.$p['supported_versions'].' (installed: '.$version.')']);
        }

        // optional / recommended / tested project outside the declared range: not declared incompatible
        return array_merge($row, ['reason' => $name.' '.$version.' is outside the versions declared by '.$label.' ('.$p['supported_versions'].')']);
    }

    /** PASS / FAIL when both sides declare a protocol, NOT DECLARED otherwise. */
    protected static function interop($required, $offered)
    {
        if ((string) $required === '' || (string) $offered === '') {
            return (string) $required === '' ? '' : 'NOT DECLARED';
        }

        return VersionRange::satisfies($offered, $required) ? 'PASS' : 'FAIL';
    }

    protected static function degradedMessage(array $target, array $p, $name)
    {
        return $p['legacy']['message'] !== ''
            ? $p['legacy']['message']
            : $target['module'].' will continue to work, but integration with '.$name.' will use legacy compatibility mode.';
    }

    /**
     * What UpdateGuard does with the result.
     * @param array $s settings: compat_block_incompatible, compat_block_unknown_auto, compat_allow_override
     * @return array ['decision' => 'allowed'|'blocked', 'overridable' => bool, 'overridden' => bool, 'reasons' => [...], 'label' => ...]
     */
    public static function decide(array $result, array $s, $automatic, $override = false)
    {
        $reasons = [];
        $platform = false;
        foreach ($result['platform'] as $p) {
            if ($p['result'] === 'FAIL') {
                $platform = true;
                $reasons[] = 'requires '.$p['name'].' '.$p['required'].' (installed: '.$p['installed'].')';
            }
        }
        $blocking = [];
        foreach ($result['projects'] as $p) {
            if ($p['state'] === self::INCOMPATIBLE || $p['state'] === self::MISSING_REQUIRED) {
                $blocking[] = $p['reason'];
            }
        }
        $decision = ['decision' => 'allowed', 'overridable' => false, 'overridden' => false, 'reasons' => [], 'label' => 'SAFE TO UPDATE', 'code' => 'safe'];
        if ($platform) {
            // FreeScout / PHP too old: the code would not even load; never overridable
            return array_merge($decision, ['decision' => 'blocked', 'reasons' => array_merge($reasons, $blocking), 'label' => 'BLOCKED', 'code' => 'blocked']);
        }
        if ($blocking && !empty($s['compat_block_incompatible'])) {
            $overridable = !$automatic && !empty($s['compat_allow_override']);
            if ($override && $overridable) {
                return array_merge($decision, ['overridable' => true, 'overridden' => true, 'reasons' => $blocking, 'label' => 'OVERRIDDEN', 'code' => 'overridden']);
            }

            return array_merge($decision, ['decision' => 'blocked', 'overridable' => $overridable, 'reasons' => $blocking, 'label' => 'BLOCKED', 'code' => 'blocked']);
        }
        if ($blocking) {
            $decision['reasons'] = $blocking;
            $decision['label'] = 'NOT BLOCKED (setting)';
            $decision['code'] = 'not_blocked';
        } elseif ($result['overall'] === self::UNKNOWN) {
            if ($automatic && !empty($s['compat_block_unknown_auto'])) {
                return array_merge($decision, ['decision' => 'blocked', 'reasons' => ['compatibility unknown: automatic update refused (setting)'], 'label' => 'BLOCKED', 'code' => 'blocked']);
            }
            $decision['label'] = 'UNKNOWN COMPATIBILITY';
            $decision['code'] = 'unknown';
        } elseif ($result['overall'] === self::DEGRADED) {
            $decision['label'] = 'SAFE TO UPDATE (DEGRADED)';
            $decision['code'] = 'degraded';
        }

        return $decision;
    }
}
