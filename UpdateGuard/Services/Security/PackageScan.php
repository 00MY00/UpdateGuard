<?php

namespace Modules\UpdateGuard\Services\Security;

use Modules\UpdateGuard\Services\ChecksumService;
use Modules\UpdateGuard\Services\DownloadService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\Settings;
use Modules\UpdateGuard\Services\ZipInspector;

/**
 * Security Gate on a module WITHOUT installing anything (button "Security scan", "Scan all modules",
 * php artisan updateguard:scan). What is scanned:
 *
 *   zip        a local package (--zip, optional --sums)
 *   installed  the files currently installed (local analysis only: there is no package to look up or upload)
 *   published  the latest package published by the module (latestVersionZipUrl + SHA256SUMS), local analysis and
 *              the enabled external scanners
 *   auto       published when the module publishes a package, installed otherwise (buttons)
 *
 * Unknown packages are uploaded to external scanners only with the decision "upload" (administrator's click, or
 * --upload) or the explicit setting. Returns the Security Gate report; it is saved when reports are enabled.
 */
class PackageScan
{
    public static function run($name, $mode = 'auto', array $options = [], $updateId = '')
    {
        if (!Paths::isModuleName($name)) {
            throw new \InvalidArgumentException('invalid module name');
        }
        $module = ModuleDiscoveryService::find($name);
        $s = Settings::all();
        if ($mode === 'auto') {
            $mode = $module && !empty($module['manifest']['latestVersionZipUrl']) ? 'published' : 'installed';
        }
        $from = $module ? $module['version'] : '';
        $ctx = ['module' => $name, 'from' => $from, 'to' => $from, 'source' => '', 'zip' => '', 'staged' => '', 'sha256' => '',
            'integrity' => 'NOT AVAILABLE', 'structure_errors' => [], 'trusted' => Trusts::keysFor($name)];
        $providers = Providers::external($s);
        $work = Paths::base('security/scan-'.getmypid().'-'.mt_rand());
        @mkdir($work, 0775, true);
        try {
            if ($mode === 'installed') {
                if (!$module) {
                    throw new \RuntimeException('module not installed: '.$name);
                }
                $ctx['staged'] = $module['path'];
                $ctx['source'] = 'installed files';
                $ctx['sha256'] = ChecksumService::hashTree($module['path'], false);
                // no package: nothing to look up or upload
                $providers = [];
            } else {
                $zip = $work.'/package.zip';
                $sums = '';
                if ($mode === 'zip') {
                    $source = (string) ($options['zip'] ?? '');
                    if (!is_file($source) || !@copy($source, $zip)) {
                        throw new \RuntimeException('package not readable: '.$source);
                    }
                    $ctx['source'] = realpath($source) ?: $source;
                    $zipName = basename($source);
                    $sums = !empty($options['sums']) ? (string) @file_get_contents((string) $options['sums']) : '';
                } else {
                    $zipUrl = $module ? (string) ($module['manifest']['latestVersionZipUrl'] ?? '') : '';
                    if ($zipUrl === '') {
                        throw new \RuntimeException('the module publishes no latestVersionZipUrl');
                    }
                    $download = new DownloadService();
                    $download->file($zipUrl, $zip);
                    $ctx['source'] = $zipUrl;
                    $zipName = DownloadService::zipName($module['manifest']);
                    $sumsUrl = DownloadService::sumsUrl($module['manifest']);
                    if ($sumsUrl !== '') {
                        try {
                            $sums = $download->text($sumsUrl);
                        } catch (\Throwable $e) {
                            $sums = '';
                        }
                    }
                }
                $ctx['zip'] = $zip;
                $ctx['sha256'] = ChecksumService::hashFile($zip);
                $expected = $sums !== '' ? ChecksumService::expectedFor($sums, $zipName) : '';
                $ctx['integrity'] = $expected === '' ? 'NOT AVAILABLE' : (ChecksumService::matches($expected, $ctx['sha256']) ? 'VERIFIED' : 'MISMATCH');
                $inspection = ZipInspector::inspect($zip);
                if ($inspection['ok']) {
                    ZipInspector::extract($zip, $inspection['root'], $work.'/module');
                    $ctx['staged'] = $work.'/module';
                    $ctx['to'] = (string) (ModuleDiscoveryService::manifest($ctx['staged'])['version'] ?? '');
                } else {
                    $ctx['structure_errors'] = $inspection['errors'];
                }
            }
            // a scan never waits: passed or blocked; upload only with consent or the setting
            $decisions = ['continue', !empty($options['upload']) ? 'upload' : 'skip_upload'];
            $report = SecurityGate::run($ctx, $s, $providers, Providers::cache($s), $decisions, false);
            $report['scan_mode'] = $mode;
            $report['update_id'] = (string) $updateId;
            if (!empty($s['sec_save_reports'])) {
                try {
                    $row = SecurityReports::save($report, $updateId);
                    $row->result = 'scan'; // a scan, not an update: nothing was installed
                    $row->save();
                    $report['report_id'] = $row->id;
                } catch (\Throwable $e) {
                }
            }

            return $report;
        } finally {
            LocalStaticScanner::removeTree($work);
        }
    }
}
