<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\ChecksumService;
use Modules\UpdateGuard\Services\DownloadService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\Security\LocalStaticScanner;
use Modules\UpdateGuard\Services\Security\Providers;
use Modules\UpdateGuard\Services\Security\SecurityGate;
use Modules\UpdateGuard\Services\Security\SecurityReports;
use Modules\UpdateGuard\Services\Settings;
use Modules\UpdateGuard\Services\ZipInspector;

/**
 * Security Gate without installing anything (automation, CI, checking a package before an update):
 *
 *   php artisan updateguard:scan Refresh                 latest published package of the module
 *   php artisan updateguard:scan Refresh --zip=Refresh.zip [--sums=SHA256SUMS]   a local package
 *   php artisan updateguard:scan Refresh --installed     the files currently installed (no integrity check)
 *   --json   JSON output        --upload   allow sending an unknown package to the external scanners
 *
 * Exit code: 0 = security verification passed, 1 = blocked / critical issue, 2 = scanner or configuration error.
 */
class ScanCommand extends Command
{
    protected $signature = 'updateguard:scan {module : module folder name}
        {--zip= : local package (ZIP) instead of the published one}
        {--sums= : SHA256SUMS file of the local package}
        {--installed : scan the installed files of the module}
        {--upload : allow uploading an unknown package to external scanners}
        {--json : JSON output}';

    protected $description = 'UpdateGuard: Security Gate on a module package without installing it (exit 0 pass, 1 blocked, 2 error)';

    public function handle()
    {
        $name = (string) $this->argument('module');
        if (!Paths::isModuleName($name)) {
            return $this->failWith('invalid module name');
        }
        $work = Paths::base('security/scan-'.getmypid().'-'.mt_rand());
        @mkdir($work, 0775, true);
        try {
            return $this->scan($name, $work);
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        } finally {
            LocalStaticScanner::removeTree($work);
        }
    }

    protected function scan($name, $work)
    {
        $module = ModuleDiscoveryService::find($name);
        $from = $module ? $module['version'] : '';
        $s = Settings::all();
        $ctx = ['module' => $name, 'from' => $from, 'to' => $from, 'source' => '', 'zip' => '', 'staged' => '', 'sha256' => '',
            'integrity' => 'NOT AVAILABLE', 'structure_errors' => []];

        if ($this->option('installed')) {
            if (!$module) {
                return $this->failWith('module not installed: '.$name);
            }
            $ctx['staged'] = $module['path'];
            $ctx['source'] = $module['path'];
            $ctx['sha256'] = ChecksumService::hashTree($module['path'], false);
        } else {
            $zip = $work.'/package.zip';
            $sums = '';
            $zipName = $name.'.zip';
            if ($this->option('zip')) {
                $source = (string) $this->option('zip');
                if (!is_file($source) || !@copy($source, $zip)) {
                    return $this->failWith('package not readable: '.$source);
                }
                $ctx['source'] = realpath($source) ?: $source;
                $zipName = basename($source);
                $sums = $this->option('sums') ? (string) @file_get_contents((string) $this->option('sums')) : '';
            } else {
                if (!$module) {
                    return $this->failWith('module not installed: '.$name.' (use --zip for a package)');
                }
                $zipUrl = (string) ($module['manifest']['latestVersionZipUrl'] ?? '');
                if ($zipUrl === '') {
                    return $this->failWith('the module publishes no latestVersionZipUrl (use --zip)');
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
                $manifest = ModuleDiscoveryService::manifest($ctx['staged']);
                $ctx['to'] = (string) ($manifest['version'] ?? '');
            } else {
                $ctx['structure_errors'] = $inspection['errors'];
            }
        }

        // nothing waits in a command: the result is passed / blocked; unknown packages are uploaded only with --upload
        $decisions = ['continue', $this->option('upload') ? 'upload' : 'skip_upload'];
        $report = SecurityGate::run($ctx, $s, Providers::external($s), Providers::cache($s), $decisions, false);
        if (!empty($s['sec_save_reports'])) {
            try {
                SecurityReports::save($report, '');
            } catch (\Throwable $e) {
            }
        }
        $this->line($this->option('json') ? SecurityGate::toJson($report) : rtrim(SecurityGate::toText($report)));

        return SecurityGate::exitCode($report);
    }

    protected function failWith($message)
    {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return 2;
    }
}
