<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\Security\PackageScan;
use Modules\UpdateGuard\Services\Security\SecurityGate;

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
        $mode = $this->option('zip') ? 'zip' : ($this->option('installed') ? 'installed' : 'published');
        try {
            $report = PackageScan::run((string) $this->argument('module'), $mode, [
                'zip'    => (string) $this->option('zip'),
                'sums'   => (string) $this->option('sums'),
                'upload' => (bool) $this->option('upload'),
            ]);
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return 2;
        }
        $this->line($this->option('json') ? SecurityGate::toJson($report) : rtrim(SecurityGate::toText($report)));

        return SecurityGate::exitCode($report);
    }
}
