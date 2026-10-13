<?php

namespace Modules\UpdateGuard\Services;

/**
 * Puts a folder in place of Modules/<Name> and lets FreeScout install it (freescout:module-install: public link,
 * migrations, caches — what Manage › Modules does), always in a new process.
 */
class InstallService
{
    /**
     * Modules/<Name> -> $keepDir, then $newDir -> Modules/<Name>. The old folder is put back if the second move fails.
     */
    public static function swap($name, $newDir, $keepDir)
    {
        if (!Paths::isModuleName($name) || $name === ModuleDiscoveryService::SELF) {
            throw new \InvalidArgumentException('refused module name: '.$name);
        }
        $current = Paths::modules($name);
        if (file_exists($keepDir)) {
            throw new \RuntimeException('folder already exists: '.$keepDir);
        }
        if (!is_dir(dirname($keepDir))) {
            @mkdir(dirname($keepDir), 0775, true);
        }
        Paths::move($current, $keepDir);
        try {
            Paths::move($newDir, $current);
        } catch (\Throwable $e) {
            if (!file_exists($current)) {
                Paths::move($keepDir, $current);
            }
            throw $e;
        }
    }

    /** @return array ['ok' => bool, 'timeout' => bool, 'detail' => string] */
    public static function moduleInstall($alias)
    {
        $r = ArtisanRunner::run(['freescout:module-install', $alias]);
        $output = trim(preg_replace('/\s+/', ' ', (string) $r['output']));
        if ($r['timeout']) {
            return ['ok' => false, 'timeout' => true, 'detail' => 'timeout'];
        }
        // the command reports most errors in its output while still exiting with 0
        $error = $r['code'] !== 0 || preg_match('/(\w+Exception\b|\bfatal error\b|SQLSTATE\[)/i', $output);

        return ['ok' => !$error, 'timeout' => false, 'detail' => 'exit '.$r['code'].($error ? ': '.mb_substr($output, 0, 500) : '')];
    }

    public static function clearCache()
    {
        $r = ArtisanRunner::run(['freescout:clear-cache']);

        return ['ok' => $r['code'] === 0, 'timeout' => $r['timeout'], 'detail' => 'exit '.$r['code']];
    }
}
