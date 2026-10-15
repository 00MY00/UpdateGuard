<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Security Gate: runs BEFORE a module update is installed, on the downloaded package (ZIP) and its staging copy.
 * Pure PHP: settings, providers and cache are passed in (UpdateRunner and updateguard:scan build them).
 *
 *   integrity (SHA-256) → archive structure → local static analysis → enabled external providers →
 *   risk score → security policy → decision (allowed / blocked / confirm / upload_consent)
 *
 * The health checks stay AFTER installation (HealthCheckService): different responsibility.
 * Every check can be switched off; with the gate off, UpdateGuard keeps its former behaviour.
 */
class SecurityGate
{
    const LOCAL_CHECKS = ['php', 'obfuscation', 'executables', 'filesystem', 'network', 'installer'];

    /**
     * @param array $ctx       module, from, to, source, zip (path), staged (dir or ''), sha256, integrity
     *                         (VERIFIED|MISMATCH|NOT AVAILABLE), structure_errors (ZipInspector errors)
     * @param array $s         settings (Settings::all())
     * @param array $providers external SecurityScannerProviderInterface instances (enabled ones)
     * @param array $decisions decisions already taken by the administrator for this package
     */
    public static function run(array $ctx, array $s, array $providers = [], ?ScanCache $cache = null, array $decisions = [], $automatic = false)
    {
        $report = [
            'format'    => 'updateguard-security/1',
            'module'    => (string) ($ctx['module'] ?? ''),
            'from'      => (string) ($ctx['from'] ?? ''),
            'to'        => (string) ($ctx['to'] ?? ''),
            'source'    => (string) ($ctx['source'] ?? ''),
            'sha256'    => (string) ($ctx['sha256'] ?? ''),
            'date'      => date('c'),
            'automatic' => (bool) $automatic,
            'enabled'   => [],
            'checks'    => [],
            'external'  => [],
            'findings'  => [],
            'decisions' => array_values($decisions),
        ];
        $on = function ($check) use ($s) {
            return !empty($s['sec_check_'.$check]);
        };

        // integrity
        if ($on('integrity')) {
            $report['enabled'][] = 'integrity';
            $state = (string) ($ctx['integrity'] ?? 'NOT AVAILABLE');
            $report['checks']['integrity'] = ['status' => $state, 'detail' => $report['sha256']];
            if ($state === 'MISMATCH') {
                $report['findings'][] = Rules::finding('checksum_mismatch', '', 0, (string) ($ctx['integrity_detail'] ?? ''));
            }
        } else {
            $report['checks']['integrity'] = ['status' => 'DISABLED', 'detail' => ''];
        }

        // archive structure (Zip Slip, links, duplicates, zip bomb...)
        $errors = (array) ($ctx['structure_errors'] ?? []);
        if ($on('structure')) {
            $report['enabled'][] = 'structure';
            foreach ($errors as $error) {
                $report['findings'][] = Rules::finding(self::structureRule($error), '', 0, $error);
            }
            $report['checks']['structure'] = ['status' => $errors ? 'FAIL' : 'PASS', 'detail' => $errors ? implode('; ', array_slice($errors, 0, 3)) : ''];
        } else {
            $report['checks']['structure'] = ['status' => 'DISABLED', 'detail' => ''];
        }

        // local static analysis of the staging copy
        $enabled = [];
        foreach (self::LOCAL_CHECKS as $check) {
            $enabled[$check] = $on($check);
            if ($enabled[$check]) {
                $report['enabled'][] = $check;
            }
        }
        $staged = (string) ($ctx['staged'] ?? '');
        $local = null;
        if (array_filter($enabled) && $staged !== '' && is_dir($staged)) {
            $local = (new LocalStaticScanner($report['module'], $enabled))->scanFile($staged);
            $report['findings'] = array_merge($report['findings'], $local->findings);
        }
        foreach (self::LOCAL_CHECKS as $check) {
            if (!$enabled[$check]) {
                $report['checks'][$check] = ['status' => 'DISABLED', 'detail' => ''];
            } elseif ($local === null) {
                $report['checks'][$check] = ['status' => 'NOT AVAILABLE', 'detail' => $errors ? 'archive not extracted' : ''];
            } elseif ($local->status === ScanResult::ERROR) {
                $report['checks'][$check] = ['status' => 'ERROR', 'detail' => $local->message];
            } else {
                $mine = array_values(array_filter($local->findings, function ($f) use ($check) {
                    return $f['check'] === $check;
                }));
                $max = RiskScore::maxSeverity($mine);
                $report['checks'][$check] = [
                    'status' => !$mine ? 'PASS' : (Rules::ORDER[$max] >= Rules::ORDER[Rules::HIGH] ? 'FAIL' : 'WARNING'),
                    'detail' => count($mine).' finding(s)',
                    'count'  => count($mine),
                ];
            }
        }
        // level of the local static analysis alone (integrity and archive have their own policy rules)
        $report['local_level'] = $local && $local->findings ? RiskScore::compute($local->findings, self::thresholds($s))['level'] : '';

        // external providers (hash first, upload only with consent or the explicit setting)
        // already blocked by the local checks (and not overridable, or no override asked): external scanners are not
        // asked — limited free APIs (VirusTotal: 4 / min, 500 / day) are kept for packages that could be installed
        $localOnly = array_merge($report, ['score' => RiskScore::compute($report['findings'], self::thresholds($s))['score'], 'external' => []]);
        $localPolicy = SecurityPolicy::decide($localOnly, $s, $decisions, $automatic);
        $skipExternal = $localPolicy['decision'] === 'blocked';
        foreach ($providers as $provider) {
            if ($skipExternal) {
                $report['external'][$provider->getName()] = array_merge(
                    ScanResult::make($provider->getName(), ScanResult::NOT_AVAILABLE, 'not asked: already blocked by the local checks (quota saved)')->toArray(),
                    ['block_on_error' => false, 'upload_candidate' => false, 'skipped' => true]
                );
                $report['enabled'][] = $provider->getName();
                continue;
            }
            $report['external'][$provider->getName()] = self::external($provider, $report['sha256'], (string) ($ctx['zip'] ?? ''), $s, $cache, $decisions, $automatic);
            $report['enabled'][] = $provider->getName();
            $level = $report['external'][$provider->getName()]['level'] ?? '';
            $map = [Rules::MEDIUM => 'external_warning', Rules::HIGH => 'external_high', Rules::CRITICAL => 'external_critical'];
            if (isset($map[$level])) {
                $report['findings'][] = Rules::finding($map[$level], '', 0, $provider->getName().' '.$report['external'][$provider->getName()]['message']);
            }
        }

        $score = RiskScore::compute($report['findings'], self::thresholds($s));
        $report['score'] = $score['score'];
        $report['level'] = $score['level'];
        $report['by_check'] = $score['by_check'];
        $policy = SecurityPolicy::decide($report, $s, $decisions, $automatic);

        return array_merge($report, $policy);
    }

    public static function thresholds(array $s)
    {
        return [
            'medium'   => (int) ($s['sec_level_medium'] ?? 21),
            'high'     => (int) ($s['sec_level_high'] ?? 50),
            'critical' => (int) ($s['sec_level_critical'] ?? 80),
        ];
    }

    protected static function external(SecurityScannerProviderInterface $provider, $sha256, $zip, array $s, ?ScanCache $cache, array $decisions, $automatic)
    {
        $name = $provider->getName();
        $blockOnError = $name === 'VirusTotal' && ($s['vt_on_failure'] ?? 'warn') === 'block';
        try {
            if (!$provider->isConfigured()) {
                $r = ScanResult::make($name, ScanResult::ERROR, 'not configured (API key missing)');
            } else {
                // 1. cache (SHA-256 + provider): no request for a package already looked up
                $r = $cache ? $cache->get($name, $sha256) : null;
                // 2. hash lookup
                if (!$r) {
                    $hashFirst = $name !== 'VirusTotal' || !empty($s['vt_hash_first']);
                    $r = $hashFirst ? $provider->lookupHash($sha256) : ScanResult::make($name, ScanResult::NOT_AVAILABLE, 'hash lookup disabled', ['unknown' => true]);
                    if ($cache && $hashFirst) {
                        $cache->put($name, $sha256, $r);
                    }
                }
                // 3. upload, only with the administrator's consent or the explicit setting
                if ($r->unknown && $provider->supportsUpload()) {
                    $auto = $name === 'VirusTotal' ? !empty($s['vt_auto_upload']) : false;
                    if (($auto || in_array('upload', $decisions, true)) && $zip !== '' && is_file($zip)) {
                        $r = $provider->scanFile($zip);
                        if ($cache) {
                            $cache->put($name, $sha256, $r);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $r = ScanResult::make($name, ScanResult::ERROR, $e->getMessage());
        }
        $a = $r->toArray();
        $a['block_on_error'] = $blockOnError;
        // unknown and still not uploaded: the administrator may be asked (never for automatic updates)
        $askable = $name !== 'VirusTotal' || (!empty($s['vt_ask_upload']) && empty($s['vt_auto_upload']));
        $a['upload_candidate'] = $r->unknown && $provider->supportsUpload() && $askable && !$automatic
            && !in_array('skip_upload', $decisions, true) && !in_array('upload', $decisions, true);

        return $a;
    }

    public static function structureRule($error)
    {
        $e = strtolower((string) $error);
        if (strpos($e, 'traversal') !== false || strpos($e, 'absolute') !== false || strpos($e, 'outside') !== false || strpos($e, 'invalid entry') !== false) {
            return 'archive_traversal';
        }
        if (strpos($e, 'symbolic') !== false) {
            return 'archive_symlink';
        }
        if (strpos($e, 'duplicate') !== false) {
            return 'archive_duplicate';
        }
        if (strpos($e, 'zip bomb') !== false || strpos($e, 'too large') !== false) {
            return 'archive_bomb';
        }

        return 'archive_invalid';
    }

    // ------------------------------------------------------------------------------------------------ outputs

    const LABELS = [
        'integrity'   => 'SHA256',
        'structure'   => 'Archive validation',
        'php'         => 'Static analysis',
        'obfuscation' => 'Code obfuscation',
        'executables' => 'Executable files',
        'filesystem'  => 'Filesystem behavior',
        'network'     => 'Network behavior',
        'installer'   => 'Installer scripts',
    ];

    /** Text for "php artisan updateguard:scan". */
    public static function toText(array $r)
    {
        $lines = ['Scanning '.$r['module'].' '.$r['to'].'...', ''];
        foreach (self::LABELS as $check => $label) {
            $status = $r['checks'][$check]['status'] ?? 'DISABLED';
            $lines[] = str_pad($label.' ', 25, '.').' '.($status === 'VERIFIED' ? 'PASS' : $status);
        }
        foreach ($r['external'] as $name => $e) {
            $lines[] = str_pad($name.' ', 25, '.').' '.$e['status'].($e['message'] !== '' ? ' ('.$e['message'].')' : '');
        }
        $lines[] = '';
        $lines[] = 'Risk score: '.$r['score'].' / 100 ('.$r['level'].')';
        $results = ['allowed' => 'PASS', 'blocked' => 'BLOCKED', 'confirm' => 'PASS (warnings: confirmation asked before a manual update)', 'upload_consent' => 'PASS (package unknown to an external scanner)'];
        $lines[] = 'Result: '.($results[$r['decision']] ?? $r['decision']).($r['reasons'] ? ' — '.implode(', ', $r['reasons']) : '');
        foreach (array_slice($r['findings'], 0, 40) as $f) {
            $lines[] = '  ['.$f['severity'].'] '.$f['message'].($f['file'] !== '' ? ' — '.$f['file'].($f['line'] ? ':'.$f['line'] : '') : '');
        }

        return implode("\n", $lines)."\n";
    }

    public static function toJson(array $r)
    {
        return json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** 0 = passed, 1 = blocked / critical, 2 = scanner or configuration error. */
    public static function exitCode(array $r)
    {
        if ($r['decision'] === 'blocked') {
            return 1;
        }
        foreach ($r['external'] as $e) {
            if ($e['status'] === ScanResult::ERROR) {
                return 2;
            }
        }
        foreach ($r['checks'] as $c) {
            if (($c['status'] ?? '') === 'ERROR') {
                return 2;
            }
        }

        return 0;
    }
}
