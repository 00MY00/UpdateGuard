<?php

namespace Modules\UpdateGuard\Services;

/**
 * "php artisan ..." in a NEW process (Symfony Process, array form: no shell). After the files of a module are
 * swapped, only a new process loads the new code: the bootstrap, the health checks and freescout:module-install
 * are always run this way.
 *
 * The child keeps the session driver (and cookie name) of this process. freescout:module-install rebuilds the
 * config cache, and a driver baked in from the surrounding environment is not the one FreeScout is running with.
 * On this install that rewrite selected "database" although the sessions table does not exist, so every page
 * returned 500 after a rollback that Update Guard had marked successful.
 */
class ArtisanRunner
{
    /** @return array ['code' => int, 'output' => string, 'timeout' => bool] */
    public static function run(array $args, $timeout = null)
    {
        $timeout = $timeout === null ? max(30, (int) Settings::get('process_timeout')) : (int) $timeout;
        $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find(false) ?: 'php';

        return self::process(array_merge([$php, base_path('artisan')], $args), $timeout);
    }

    /** php -l <file> */
    public static function lint($file)
    {
        $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find(false) ?: 'php';

        return self::process([$php, '-l', $file], 30);
    }

    protected static function process(array $command, $timeout)
    {
        $process = new \Symfony\Component\Process\Process($command, base_path(), self::environment(), null, $timeout);
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            return ['code' => 124, 'output' => $process->getOutput().$process->getErrorOutput(), 'timeout' => true];
        }

        return ['code' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput(), 'timeout' => false];
    }

    /**
     * Environment of the child. SESSION_DRIVER and SESSION_COOKIE stay as this process is serving them.
     * A database driver without a sessions table is replaced with file, which is FreeScout's own default.
     *
     * @return array<string, string>
     */
    public static function environment()
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $clean = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }
        $clean['SESSION_DRIVER'] = self::keptSessionDriver();
        $cookie = config('session.cookie');
        if (is_string($cookie) && $cookie !== '') {
            $clean['SESSION_COOKIE'] = $cookie;
        }

        return $clean;
    }

    /** @return string */
    public static function keptSessionDriver()
    {
        $driver = config('session.driver');
        if (!is_string($driver) || $driver === '') {
            return 'file';
        }
        // any other driver (including one registered by a module) is the one in service: kept as it is
        if ($driver !== 'database') {
            return $driver;
        }
        try {
            $table = config('session.table');
            if (!is_string($table) || $table === '') {
                $table = 'sessions';
            }
            if (!\Schema::hasTable($table)) {
                return 'file';
            }
        } catch (\Throwable $e) {
            return 'file';
        }

        return $driver;
    }

    /** First JSON object of an output (artisan may print warnings before it). */
    public static function json($output)
    {
        $start = strpos((string) $output, '{');
        if ($start === false) {
            return null;
        }
        $data = json_decode(substr($output, $start), true);
        if (is_array($data)) {
            return $data;
        }
        $end = strrpos($output, '}');
        $data = $end !== false ? json_decode(substr($output, $start, $end - $start + 1), true) : null;

        return is_array($data) ? $data : null;
    }
}
