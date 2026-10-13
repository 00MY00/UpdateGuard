<?php

namespace Modules\UpdateGuard\Services;

/**
 * Crash detection in FreeScout's Laravel log (storage/logs/laravel*.log). Pure PHP.
 *
 * Every uncaught exception of a web request (HTTP 500) and every fatal PHP error caught by Laravel is written there
 * as "[2026-10-13 18:01:12] production.ERROR: <message> {"exception":"[object] (<Class>(code: 0): ... at <file>:<line>)"
 * with the stack trace. An entry written after the installation that mentions the module's folder
 * (Modules/<Name>/) or its namespace (Modules\<Name>\) is attributed to the module.
 */
class LogScanner
{
    const FATAL_PATTERN = '/(FatalErrorException|FatalThrowableError|ParseError|Fatal error|Allowed memory size|Maximum execution time|Class \'[^\']+\' not found|Trait \'[^\']+\' not found|Call to undefined)/i';

    /**
     * @param string[] $files log files
     * @return array ['http500' => int, 'fatal' => int, 'samples' => string[]]
     */
    public static function scan(array $files, $moduleName, $since)
    {
        $result = ['http500' => 0, 'fatal' => 0, 'samples' => []];
        $since = (int) $since;
        $needles = ['Modules/'.$moduleName.'/', 'Modules\\'.$moduleName.'\\', 'Modules\\\\'.$moduleName.'\\\\'];
        foreach ($files as $file) {
            if (!is_file($file) || filemtime($file) < $since) {
                continue;
            }
            foreach (self::entries($file) as $entry) {
                if ($entry['time'] < $since || !in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                    continue;
                }
                $mentioned = false;
                foreach ($needles as $needle) {
                    if (strpos($entry['text'], $needle) !== false) {
                        $mentioned = true;
                        break;
                    }
                }
                if (!$mentioned) {
                    continue;
                }
                if (preg_match(self::FATAL_PATTERN, $entry['text'])) {
                    $result['fatal']++;
                } else {
                    $result['http500']++;
                }
                if (count($result['samples']) < 5) {
                    $result['samples'][] = mb_substr(strtok($entry['text'], "\n"), 0, 400);
                }
            }
        }

        return $result;
    }

    /** Entries of a Laravel log file: [time, level, text]. Only the last 5 MB are read. */
    public static function entries($file)
    {
        $size = (int) @filesize($file);
        $h = @fopen($file, 'rb');
        if (!$h) {
            return [];
        }
        if ($size > 5242880) {
            fseek($h, $size - 5242880);
        }
        $content = (string) stream_get_contents($h);
        fclose($h);
        $out = [];
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $content);
        foreach ($parts as $part) {
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})[^\]]*\]\s+[\w-]+\.([A-Z]+):/', $part, $m)) {
                continue;
            }
            $out[] = ['time' => (int) strtotime($m[1].' '.$m[2]), 'level' => $m[3], 'text' => $part];
        }

        return $out;
    }
}
