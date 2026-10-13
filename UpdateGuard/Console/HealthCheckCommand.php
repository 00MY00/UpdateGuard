<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\HealthCheckService;
use Modules\UpdateGuard\Services\LockService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\Settings;

/**
 *   updateguard:health [--json]            health of UpdateGuard itself (its own native health check, same protocol
 *                                          as the one it asks of other modules)
 *   updateguard:health <Module> [--json]   native health check + generic validation of a module
 *   updateguard:health --all               every installed module
 * Exit code 0 = healthy (warnings included), 1 = failure.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'updateguard:health {module? : module folder name} {--all : every installed module} {--json : JSON output}';

    protected $description = 'UpdateGuard: health check of a module (native + generic), of all modules, or of UpdateGuard itself';

    public function handle()
    {
        if ($this->option('all')) {
            $failed = 0;
            $out = [];
            foreach (ModuleDiscoveryService::installed() as $name => $module) {
                if ($name === ModuleDiscoveryService::SELF) {
                    continue;
                }
                $r = HealthCheckService::run($name);
                $out[$name] = $r['status'];
                $failed += $r['status'] === 'failed' ? 1 : 0;
                if (!$this->option('json')) {
                    $this->line(str_pad($name, 24).$this->symbol($r['status']).'  native: '.$r['native']['result']);
                }
            }
            if ($this->option('json')) {
                $this->line(json_encode(['status' => $failed ? 'fail' : 'ok', 'modules' => $out], JSON_UNESCAPED_SLASHES));
            }

            return $failed ? 1 : 0;
        }
        $module = (string) $this->argument('module');
        if ($module === '' || $module === ModuleDiscoveryService::SELF) {
            return $this->self();
        }
        if (!Paths::isModuleName($module) || !is_dir(Paths::modules($module))) {
            $this->error('Module not found: Modules/'.$module);

            return 1;
        }
        $r = HealthCheckService::run($module);
        if ($this->option('json')) {
            $this->line(json_encode([
                'status'  => $r['status'] === 'failed' ? 'fail' : 'ok',
                'module'  => $module,
                'health'  => $r['status'],
                'native'  => $r['native'],
                'generic' => $r['generic'],
                'causes'  => $r['causes'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line($module.': '.$this->symbol($r['status']));
            $this->line('  Native health check: '.$r['native']['result'].' ['.$r['native']['command'].'] '.$r['native']['detail']);
            $this->line('  Generic validation: '.($r['generic'] ? 'AVAILABLE' : 'SKIPPED'));
            foreach ($r['generic'] as $name => $check) {
                $this->line('    '.str_pad($name, 22).$check['result'].'  '.$check['detail']);
            }
        }

        return $r['status'] === 'failed' ? 1 : 0;
    }

    protected function symbol($status)
    {
        $map = ['healthy' => '✓ Healthy', 'warning' => '! Warning', 'failed' => '× Failed'];

        return isset($map[$status]) ? $map[$status] : $status;
    }

    /** UpdateGuard's own checks: tables, storage, scheduler, lock. */
    protected function self()
    {
        $checks = [];
        foreach (['updateguard_modules', 'updateguard_jobs', 'updateguard_backups', 'updateguard_logs'] as $table) {
            $ok = \Schema::hasTable($table);
            $checks['table '.$table] = $ok ? 'ok' : 'missing';
        }
        Paths::ensure();
        foreach (['staging', 'backups', 'locks', 'reports'] as $dir) {
            $checks['storage '.$dir] = is_writable(Paths::base($dir)) ? 'ok' : 'not writable';
        }
        $lastRun = (string) \App\Option::get('updateguard.last_run', '', true, false);
        $checks['scheduler'] = $lastRun !== '' && strtotime($lastRun) > time() - 600 ? 'ok' : 'warning: no run in the last 10 minutes (cron of FreeScout)';
        $lock = new LockService(Paths::locks(), Settings::get('lock_timeout_minutes'));
        $checks['lock'] = $lock->isAbandoned() ? 'warning: abandoned lock (removed by the next run)' : 'ok';
        $failed = count(array_filter($checks, function ($v) {
            return $v !== 'ok' && strpos($v, 'warning') !== 0;
        }));
        $manifest = ModuleDiscoveryService::manifest(Paths::modules(ModuleDiscoveryService::SELF));
        $result = ['status' => $failed ? 'fail' : 'ok', 'module' => 'UpdateGuard', 'version' => (string) ($manifest['version'] ?? ''), 'checks' => $checks];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $name => $value) {
                $this->line(str_pad($name, 30).$value);
            }
            $this->line('UpdateGuard '.$result['version'].': '.$result['status']);
        }

        return $failed ? 1 : 0;
    }
}
