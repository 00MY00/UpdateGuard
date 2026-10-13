<?php

namespace Modules\UpdateGuard\Services;

/**
 * SHA-256 of downloaded archives and of backups. Pure PHP.
 *
 * SHA256SUMS format (output of sha256sum): "<64 hex>  <file>" or "<64 hex> *<file>" (binary mode), one per line.
 */
class ChecksumService
{
    /** @return array file name => lower-case hash */
    public static function parseSums($text)
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            if (preg_match('/^\s*([a-fA-F0-9]{64})\s+\*?(.+?)\s*$/', $line, $m)) {
                $out[basename(str_replace('\\', '/', $m[2]))] = strtolower($m[1]);
            }
        }

        return $out;
    }

    /** Expected hash of $file in a SHA256SUMS text, or '' when the file is not listed. */
    public static function expectedFor($sumsText, $file)
    {
        $sums = self::parseSums($sumsText);

        return isset($sums[$file]) ? $sums[$file] : '';
    }

    public static function hashFile($path)
    {
        $hash = @hash_file('sha256', $path);

        return $hash ? strtolower($hash) : '';
    }

    /** Constant-time comparison; an empty expected hash never matches. */
    public static function matches($expected, $actual)
    {
        $expected = strtolower(trim((string) $expected));
        $actual = strtolower(trim((string) $actual));

        return strlen($expected) === 64 && hash_equals($expected, $actual);
    }

    /**
     * Fingerprint of a folder (backups): sha256 of the sorted lines "relative/path <sha256 of the file>".
     * Symbolic links are never followed: listed with their target, or left out with $links = false (backups do not
     * copy them).
     */
    public static function hashTree($dir, $links = true)
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir)) {
            return '';
        }
        $lines = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = str_replace('\\', '/', $item->getPathname());
            $relative = substr($path, strlen($dir) + 1);
            if ($item->isLink()) {
                if ($links) {
                    $lines[] = $relative.' -> '.readlink($path);
                }
            } elseif ($item->isFile()) {
                $lines[] = $relative.' '.self::hashFile($path);
            }
        }
        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }
}
