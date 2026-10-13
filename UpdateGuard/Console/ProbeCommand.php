<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\ArtisanRunner;
use Modules\UpdateGuard\Services\FallbackTestService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Paths;

/**
 * Generic validation run in a NEW process by UpdateGuard after an install / restore (FallbackTestService::probe).
 * Reaching handle() already proves that Laravel and every module ServiceProvider booted. Read-only: no HTTP request,
 * no controller action is executed, no data is written.
 */
class ProbeCommand extends Command
{
    protected $signature = 'updateguard:probe {module : module folder name} {--json : JSON output}';

    protected $description = 'UpdateGuard (internal): bootstrap, routes, providers and views of a module, read-only';

    protected $hidden = true;

    public function handle()
    {
        $name = (string) $this->argument('module');
        $checks = ['bootstrap' => FallbackTestService::pass('Laravel '.app()->version())];
        if (!Paths::isModuleName($name) || !is_dir(Paths::modules($name))) {
            $checks['module'] = FallbackTestService::fail('Modules/'.$name.' not found');

            return $this->result($checks);
        }
        $dir = Paths::modules($name);
        $manifest = ModuleDiscoveryService::manifest($dir);
        $alias = (string) ($manifest['alias'] ?? '');
        $active = ModuleDiscoveryService::isActive($alias);

        // core FreeScout routes still registered
        $missing = [];
        foreach ((array) config('updateguard.core_routes', []) as $route) {
            if (!\Route::has($route)) {
                $missing[] = $route;
            }
        }
        $checks['core routes'] = $missing ? FallbackTestService::fail('missing: '.implode(', ', $missing)) : FallbackTestService::pass(count((array) config('updateguard.core_routes')).' route(s)');

        // providers of the module loaded
        if (!$active) {
            $checks['providers'] = FallbackTestService::warn('module disabled: providers not loaded');
        } else {
            $problems = [];
            foreach ((array) ($manifest['providers'] ?? []) as $class) {
                $class = ltrim((string) $class, '\\');
                if (!class_exists($class)) {
                    $problems[] = 'class not found: '.$class;
                } elseif (method_exists(app(), 'getProvider') && !app()->getProvider($class)) {
                    $problems[] = 'not registered: '.$class;
                }
            }
            $checks['providers'] = $problems ? FallbackTestService::fail(implode(', ', $problems)) : FallbackTestService::pass();
        }

        // routes of the module resolve to existing controllers and methods
        $prefix = 'Modules\\'.$name.'\\';
        $count = 0;
        $broken = [];
        foreach (\Route::getRoutes() as $route) {
            $action = $route->getAction();
            $uses = isset($action['uses']) && is_string($action['uses']) ? ltrim($action['uses'], '\\') : '';
            if ($uses === '' || strpos($uses, $prefix) !== 0) {
                continue;
            }
            $count++;
            list($class, $method) = array_pad(explode('@', $uses, 2), 2, '__invoke');
            if (!class_exists($class)) {
                $broken[] = $route->uri().' → class not found '.$class;
            } elseif (!method_exists($class, $method)) {
                $broken[] = $route->uri().' → method not found '.$class.'@'.$method;
            }
            if (count($broken) >= 10) {
                break;
            }
        }
        if (!$active) {
            $checks['module routes'] = FallbackTestService::warn('module disabled');
        } else {
            $checks['module routes'] = $broken ? FallbackTestService::fail(implode('; ', $broken)) : FallbackTestService::pass($count.' route(s)');
        }

        // blade templates compile and the compiled PHP is valid
        $checks['module views'] = $this->views($dir);

        // checks added by other modules (no hard dependency)
        try {
            $extra = \Eventy::filter('updateguard.health_check', [], $name, $alias);
            foreach ((array) $extra as $label => $check) {
                if (is_array($check) && isset($check['result'])) {
                    $checks[(string) $label] = ['result' => (string) $check['result'], 'detail' => (string) ($check['detail'] ?? '')];
                }
            }
        } catch (\Throwable $e) {
            $checks['extension checks'] = FallbackTestService::fail('updateguard.health_check listener: '.$e->getMessage());
        }

        return $this->result($checks);
    }

    protected function views($dir)
    {
        $files = FallbackTestService::bladeFiles($dir);
        if (!$files) {
            return FallbackTestService::pass('none');
        }
        $compiler = app('blade.compiler');
        $errors = [];
        $tmp = tempnam(sys_get_temp_dir(), 'ugv');
        foreach ($files as $file) {
            try {
                file_put_contents($tmp, $compiler->compileString((string) file_get_contents($file)));
                $r = ArtisanRunner::lint($tmp);
                if ($r['code'] !== 0) {
                    $errors[] = substr($file, strlen($dir) + 1).': '.trim(preg_replace('/\s+/', ' ', str_replace($tmp, 'compiled', $r['output'])));
                }
            } catch (\Throwable $e) {
                $errors[] = substr($file, strlen($dir) + 1).': '.$e->getMessage();
            }
            if (count($errors) >= 10) {
                break;
            }
        }
        @unlink($tmp);

        return $errors ? FallbackTestService::fail(implode(' | ', $errors)) : FallbackTestService::pass(count($files).' template(s)');
    }

    protected function result(array $checks)
    {
        $failed = FallbackTestService::failed($checks);
        if ($this->option('json')) {
            $this->line(json_encode(['status' => $failed ? 'fail' : 'ok', 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $name => $check) {
                $this->line(str_pad($name, 20).$check['result'].'  '.$check['detail']);
            }
        }

        return $failed ? 1 : 0;
    }
}
