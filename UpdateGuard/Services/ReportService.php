<?php

namespace Modules\UpdateGuard\Services;

/**
 * Report of one operation (update, rollback, restore, health check): built step by step by the runner, saved as
 * JSON in storage/updateguard/reports/<update id>.json, exported as TXT, JSON or HTML. Pure PHP except save()/load().
 */
class ReportService
{
    /** Order and labels of the steps in the TXT export. */
    const STEPS = [
        'preflight'          => 'Preflight',
        'download'           => 'Download',
        'checksum'           => 'Checksum',
        'staging'            => 'Staging',
        'compatibility'      => 'Project compatibility',
        'generic_tests'      => 'Generic tests (staging)',
        'security'           => 'Security Gate',
        'backup'             => 'Backup',
        'install'            => 'Install',
        'clear_cache'        => 'Clear cache',
        'native_health'      => 'Native health check',
        'generic_validation' => 'Generic validation',
        'observation'        => 'Observation',
        'rollback'           => 'Rollback',
        'restore'            => 'Restore',
    ];

    public static function make($updateId, $type, $module, $from, $to, $triggeredBy, $automatic)
    {
        return [
            'format'        => 'updateguard-report/1',
            'update_id'     => (string) $updateId,
            'type'          => (string) $type,
            'module'        => (string) $module,
            'from'          => (string) $from,
            'to'            => (string) $to,
            'triggered_by'  => (string) $triggeredBy,
            'automatic'     => (bool) $automatic,
            'started_at'    => date('c'),
            'finished_at'   => '',
            'safety_level'  => '',
            'rollback_kind' => '',
            'steps'         => [],
            'native_health' => ['available' => false, 'command' => '', 'result' => 'NOT AVAILABLE', 'json' => null],
            'generic'       => ['available' => true, 'result' => 'NOT RUN', 'checks' => []],
            'crash'         => null,
            'compatibility' => null,
            'final_version' => '',
            'final_status'  => '',
            'errors'        => [],
            'log'           => [],
        ];
    }

    public static function step(array &$report, $step, $result, $detail = '')
    {
        $report['steps'][$step] = ['result' => (string) $result, 'detail' => (string) $detail];
    }

    public static function toJson(array $report)
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function toText(array $r)
    {
        $titles = ['update' => 'Update Report', 'rollback' => 'Rollback Report', 'restore' => 'Restore Report', 'health' => 'Health Check Report'];
        $lines = [];
        $lines[] = isset($titles[$r['type']]) ? $titles[$r['type']] : 'UpdateGuard Report';
        $lines[] = str_repeat('=', 40);
        $lines[] = '';
        $pairs = [
            'Update ID'    => $r['update_id'],
            'Module'       => $r['module'],
            'Update'       => $r['from'].($r['to'] !== '' ? ' → '.$r['to'] : ''),
            'Triggered by' => $r['triggered_by'].($r['automatic'] ? ' (automatic)' : ' (manual)'),
            'Started'      => $r['started_at'],
            'Finished'     => $r['finished_at'],
        ];
        if ($r['safety_level'] !== '') {
            $pairs['Safety level'] = strtoupper($r['safety_level']);
        }
        if ($r['rollback_kind'] !== '') {
            $pairs['Rollback'] = strtoupper($r['rollback_kind']);
        }
        foreach ($pairs as $label => $value) {
            $lines[] = str_pad($label.':', 16).$value;
        }
        $lines[] = '';
        $lines[] = 'Steps';
        $lines[] = str_repeat('-', 40);
        foreach (self::STEPS as $key => $label) {
            if (isset($r['steps'][$key])) {
                $s = $r['steps'][$key];
                $lines[] = str_pad($label, 26).$s['result'].($s['detail'] !== '' ? '  ('.$s['detail'].')' : '');
            }
        }
        if (!empty($r['compatibility'])) {
            $lines[] = '';
            $lines = array_merge($lines, self::compatibilityLines($r['compatibility']));
        }
        $lines[] = '';
        $lines[] = str_pad('Native health check:', 26).$r['native_health']['result'].($r['native_health']['command'] !== '' ? '  ['.$r['native_health']['command'].']' : '');
        $lines[] = str_pad('Generic validation:', 26).($r['generic']['available'] ? 'AVAILABLE — '.$r['generic']['result'] : 'NOT AVAILABLE');
        foreach ($r['generic']['checks'] as $name => $check) {
            $lines[] = '    '.str_pad($name, 22).$check['result'].($check['detail'] !== '' ? '  '.$check['detail'] : '');
        }
        if (!empty($r['crash'])) {
            $lines[] = str_pad('Crash detection:', 26).'HTTP 500: '.$r['crash']['http500'].', fatal: '.$r['crash']['fatal'];
            foreach ($r['crash']['samples'] as $sample) {
                $lines[] = '    '.$sample;
            }
        }
        $lines[] = '';
        $lines[] = str_pad('Final version:', 26).$r['final_version'];
        $lines[] = str_pad('Final status:', 26).strtoupper($r['final_status']);
        if ($r['errors']) {
            $lines[] = '';
            $lines[] = 'Errors';
            $lines[] = str_repeat('-', 40);
            foreach ($r['errors'] as $error) {
                $lines[] = '- '.$error;
            }
        }
        if ($r['log']) {
            $lines[] = '';
            $lines[] = 'Log';
            $lines[] = str_repeat('-', 40);
            foreach ($r['log'] as $l) {
                $lines[] = $l['time'].' '.str_pad($l['level'], 9).$l['message'];
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** "Compatibility checks" block (reports, updateguard:update output). */
    public static function compatibilityLines(array $c)
    {
        $dots = function ($label, $value) {
            return str_pad($label.' ', 22, '.').' '.$value;
        };
        $lines = ['Compatibility checks'.($c['source'] !== '' ? ' (source: '.$c['source'].')' : ' (no compatibility metadata)'), str_repeat('-', 40)];
        foreach ($c['platform'] as $p) {
            $lines[] = $dots($p['name'], $p['result']).($p['required'] !== '' ? '  (requires '.$p['required'].', installed '.$p['installed'].')' : '');
        }
        foreach ($c['projects'] as $p) {
            $lines[] = $dots($p['name'], $p['state'] === 'NOT_INSTALLED' ? ($p['relationship'] === 'conflict' ? 'NOT INSTALLED' : 'OPTIONAL / NOT INSTALLED') : $p['state']);
            $lines[] = '    Relationship: '.str_replace('_', ' ', $p['relationship']).' · Required: '.($p['required'] ? 'Yes' : 'No')
                .($p['repository'] !== '' ? ' · '.$p['repository'] : '');
            if ($p['installed']) {
                $lines[] = '    Installed: '.$p['version'].($p['active'] ? '' : ' (disabled)')
                    .($p['supported_versions'] !== '' ? ' · Supported: '.$p['supported_versions'] : '')
                    .($p['legacy_versions'] !== '' ? ' · Legacy: '.$p['legacy_versions'] : '')
                    .($p['conflicts'] ? ' · Known conflicts: '.implode(', ', $p['conflicts']) : '')
                    .($p['interop'] !== '' ? ' · Interop: '.$p['interop'].($p['interop_installed'] !== '' ? ' ('.$p['interop_installed'].')' : '') : '');
            }
            if ($p['reason'] !== '') {
                $lines[] = '    '.$p['reason'];
            }
        }
        foreach ((array) ($c['errors'] ?? []) as $error) {
            $lines[] = '    ! metadata: '.$error;
        }
        $lines[] = str_pad('Overall compatibility:', 26).$c['overall'];
        if (!empty($c['decision'])) {
            $lines[] = str_pad('Result:', 26).$c['decision']['label'];
        }

        return $lines;
    }


    public static function toHtml(array $r)
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($r['update_id'])
            .'</title></head><body><pre>'.htmlspecialchars(self::toText($r)).'</pre></body></html>';
    }

    /** Update id format: UG-YYYYMMDD-NNNNN (NNNNN = id of the job row). */
    public static function updateId($number, $time = null)
    {
        return 'UG-'.date('Ymd', $time === null ? time() : $time).'-'.str_pad((string) (int) $number, 5, '0', STR_PAD_LEFT);
    }

    public static function isUpdateId($id)
    {
        return (bool) preg_match('/^UG-\d{8}-\d{5,}$/', (string) $id);
    }

    public static function save($dir, array $report)
    {
        if (!self::isUpdateId($report['update_id'])) {
            throw new \InvalidArgumentException('invalid update id');
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/'.$report['update_id'].'.json', self::toJson($report));
    }

    public static function load($dir, $updateId)
    {
        if (!self::isUpdateId($updateId)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($dir.'/'.$updateId.'.json'), true);

        return is_array($data) ? $data : null;
    }
}
