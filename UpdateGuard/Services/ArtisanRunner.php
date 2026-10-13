<?php

namespace Modules\UpdateGuard\Services;

/**
 * "php artisan ..." in a NEW process (Symfony Process, array form: no shell). After the files of a module are
 * swapped, only a new process loads the new code: the bootstrap, the health checks and freescout:module-install
 * are always run this way.
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
        $process = new \Symfony\Component\Process\Process($command, base_path(), null, null, $timeout);
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            return ['code' => 124, 'output' => $process->getOutput().$process->getErrorOutput(), 'timeout' => true];
        }

        return ['code' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput(), 'timeout' => false];
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
