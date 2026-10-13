<?php

namespace Modules\UpdateGuard\Services;

/**
 * Generic validation for every module, with or without a native health check (Info.md § 7, § 43).
 *
 *   structure()  on a folder (staging before install, or installed): module.json, alias, providers, namespace,
 *                ServiceProvider files, views / routes files present when declared
 *   lint()       php -l on every PHP file (blade templates excluded: compiled and checked by the probe)
 *   probe()      NEW process "php artisan updateguard:probe <Module> --json": Laravel bootstrap with the installed
 *                code, core FreeScout routes, module ServiceProviders loaded, module routes resolved to existing
 *                controllers / methods, blade views compiled and linted, checks added by other modules through
 *                the "updateguard.health_check" filter. Nothing destructive, no HTTP request.
 *
 * Every check returns ['result' => PASS|FAIL|WARN|SKIPPED, 'detail' => string].
 */
class FallbackTestService
{
    const MAX_LINT_FILES = 3000;

    public static function pass($detail = '')
    {
        return ['result' => 'PASS', 'detail' => (string) $detail];
    }

    public static function fail($detail)
    {
        return ['result' => 'FAIL', 'detail' => (string) $detail];
    }

    public static function warn($detail)
    {
        return ['result' => 'WARN', 'detail' => (string) $detail];
    }

    /** Pure PHP: structure of a module folder. $expectedName/$expectedAlias: those of the installed module. */
    public static function structure($dir, $expectedName = null, $expectedAlias = null)
    {
        $dir = rtrim($dir, '/\\');
        $checks = [];
        $manifest = json_decode((string) @file_get_contents($dir.'/module.json'), true);
        if (!is_array($manifest)) {
            $checks['module.json'] = self::fail('missing or invalid JSON');

            return $checks;
        }
        $name = (string) ($manifest['name'] ?? '');
        $alias = (string) ($manifest['alias'] ?? '');
        $problems = [];
        if ($name === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            $problems[] = 'invalid "name"';
        }
        if ($alias === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $alias)) {
            $problems[] = 'invalid "alias"';
        }
        if ($expectedName !== null && $name !== $expectedName) {
            $problems[] = 'name "'.$name.'" instead of "'.$expectedName.'"';
        }
        if ($expectedAlias !== null && $alias !== $expectedAlias) {
            $problems[] = 'alias "'.$alias.'" instead of "'.$expectedAlias.'"';
        }
        if (empty($manifest['version']) || VersionService::normalize($manifest['version']) === '') {
            $problems[] = 'invalid "version"';
        }
        $checks['module.json'] = $problems ? self::fail(implode(', ', $problems)) : self::pass($name.' '.($manifest['version'] ?? ''));

        $providers = isset($manifest['providers']) && is_array($manifest['providers']) ? $manifest['providers'] : [];
        if (!$providers) {
            $checks['ServiceProvider'] = self::warn('no provider declared');
        } else {
            $missing = [];
            $namespace = [];
            foreach ($providers as $class) {
                $class = ltrim((string) $class, '\\');
                $prefix = 'Modules\\'.$name.'\\';
                if (strpos($class, $prefix) !== 0) {
                    $namespace[] = $class;
                    continue;
                }
                $file = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (!is_file($file)) {
                    $missing[] = $class;
                } elseif (!preg_match('/^\s*namespace\s+'.preg_quote(substr($class, 0, strrpos($class, '\\')), '/').'\s*;/m', (string) file_get_contents($file))) {
                    $namespace[] = $class.' (namespace line)';
                }
            }
            $checks['ServiceProvider'] = $missing ? self::fail('file not found: '.implode(', ', $missing)) : self::pass(count($providers).' provider(s)');
            $checks['namespace'] = $namespace ? self::fail('outside Modules\\'.$name.'\\: '.implode(', ', $namespace)) : self::pass('Modules\\'.$name.'\\');
        }
        $files = isset($manifest['files']) && is_array($manifest['files']) ? $manifest['files'] : [];
        $missingFiles = [];
        foreach ($files as $file) {
            if (ZipInspector::badName((string) $file) !== '' || !is_file($dir.'/'.$file)) {
                $missingFiles[] = (string) $file;
            }
        }
        $checks['autoload'] = $missingFiles ? self::fail('files listed in module.json not found: '.implode(', ', $missingFiles)) : self::pass();
        $checks['views'] = is_dir($dir.'/Resources/views') ? self::pass(count(self::bladeFiles($dir)).' template(s)') : self::pass('none');
        $routes = ['Http/routes.php', 'Routes/web.php', 'Routes/api.php'];
        $found = array_values(array_filter($routes, function ($r) use ($dir) {
            return is_file($dir.'/'.$r);
        }));
        $checks['routes'] = self::pass($found ? implode(', ', $found) : 'none');

        return $checks;
    }

    public static function phpFiles($dir)
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isFile() && !$item->isLink() && substr($path, -4) === '.php' && substr($path, -10) !== '.blade.php'
                && strpos(str_replace('\\', '/', $path), '/vendor/') === false) {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    public static function bladeFiles($dir)
    {
        $views = rtrim($dir, '/\\').'/Resources/views';
        $out = [];
        if (!is_dir($views)) {
            return $out;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isFile() && substr($item->getPathname(), -10) === '.blade.php') {
                $out[] = $item->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** php -l on every PHP file of the folder (separate processes). $linter: file => ['code','output'] (tests). */
    public static function lint($dir, ?callable $linter = null)
    {
        $linter = $linter ?: [ArtisanRunner::class, 'lint'];
        $files = self::phpFiles($dir);
        if (count($files) > self::MAX_LINT_FILES) {
            return self::warn(count($files).' PHP files: only the first '.self::MAX_LINT_FILES.' were checked');
        }
        $errors = [];
        foreach ($files as $file) {
            $r = call_user_func($linter, $file);
            if ($r['code'] !== 0) {
                $line = trim(preg_replace('/\s+/', ' ', (string) $r['output']));
                $errors[] = substr($file, strlen(rtrim($dir, '/\\')) + 1).': '.mb_substr($line, 0, 300);
                if (count($errors) >= 10) {
                    break;
                }
            }
        }

        return $errors ? self::fail(implode(' | ', $errors)) : self::pass(count($files).' file(s)');
    }

    /** Structure + lint (staging, before anything is installed). */
    public static function beforeInstall($dir, $expectedName, $expectedAlias, ?callable $linter = null)
    {
        $checks = self::structure($dir, $expectedName, $expectedAlias);
        if ($checks['module.json']['result'] !== 'FAIL') {
            $checks['php -l'] = self::lint($dir, $linter);
        }

        return $checks;
    }

    /** Probe in a new process (installed code). */
    public static function probe($moduleName)
    {
        $r = ArtisanRunner::run(['updateguard:probe', $moduleName, '--json']);
        $json = ArtisanRunner::json($r['output']);
        if ($r['timeout']) {
            return ['bootstrap' => ['result' => 'FAIL', 'detail' => 'timeout'], '_timeout' => true];
        }
        if (!is_array($json) || !isset($json['checks']) || !is_array($json['checks'])) {
            // the application did not even start: ServiceProvider exception, class not found, fatal error...
            $line = trim(preg_replace('/\s+/', ' ', mb_substr((string) $r['output'], 0, 600)));

            return ['bootstrap' => self::fail('Laravel bootstrap failed (exit '.$r['code'].'): '.$line)];
        }

        return $json['checks'];
    }

    public static function failed(array $checks)
    {
        foreach ($checks as $name => $check) {
            if (is_array($check) && ($check['result'] ?? '') === 'FAIL') {
                return true;
            }
        }

        return false;
    }

    public static function summary(array $checks)
    {
        $failed = [];
        foreach ($checks as $name => $check) {
            if (is_array($check) && ($check['result'] ?? '') === 'FAIL') {
                $failed[] = $name.': '.$check['detail'];
            }
        }

        return $failed ? 'FAIL — '.implode('; ', $failed) : 'PASS';
    }
}
