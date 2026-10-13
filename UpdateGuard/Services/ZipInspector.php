<?php

namespace Modules\UpdateGuard\Services;

/**
 * Checks a module archive BEFORE anything is extracted, then extracts it entry by entry into the staging folder.
 * Pure PHP (ZipArchive).
 *
 * Refused: "../", absolute paths ("/x", "C:\x", "\\server\x"), NUL bytes, symbolic links (Unix mode in the
 * external attributes), entries outside the single top-level folder, an archive without module.json.
 *
 * Accepted layouts (the same as FreeScout's own updater, app/Module.php::updateFromUrl):
 *   ModuleName/module.json                 release archive
 *   Repo-main/module.json                  GitHub archive of a branch whose root is the module
 *   Repo-main/ModuleName/module.json       GitHub archive of a repository that holds the module in a sub-folder
 */
class ZipInspector
{
    const MAX_ENTRIES = 20000;
    const MAX_UNCOMPRESSED = 524288000; // 500 MB

    /**
     * @return array ['ok' => bool, 'errors' => string[], 'root' => 'Top/' or 'Top/Sub/', 'entries' => int]
     */
    public static function inspect($zipPath)
    {
        $result = ['ok' => false, 'errors' => [], 'root' => '', 'entries' => 0];
        if (!class_exists('ZipArchive')) {
            $result['errors'][] = 'PHP extension zip is missing';

            return $result;
        }
        $zip = new \ZipArchive();
        $code = $zip->open($zipPath, defined('ZipArchive::CHECKCONS') ? \ZipArchive::CHECKCONS : 0);
        if ($code !== true) {
            $result['errors'][] = 'corrupt archive (ZipArchive code '.$code.')';

            return $result;
        }
        $result['entries'] = $zip->numFiles;
        if ($zip->numFiles === 0 || $zip->numFiles > self::MAX_ENTRIES) {
            $result['errors'][] = 'unexpected number of entries: '.$zip->numFiles;
        }
        $tops = [];
        $names = [];
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat ? (string) $stat['name'] : '';
            $size += $stat ? (int) $stat['size'] : 0;
            $error = self::badName($name);
            if ($error !== '') {
                $result['errors'][] = $error.': '.self::printable($name);
                continue;
            }
            if (self::isSymlink($zip, $i)) {
                $result['errors'][] = 'symbolic link refused: '.self::printable($name);
                continue;
            }
            $name = str_replace('\\', '/', $name);
            $names[] = $name;
            $parts = explode('/', $name);
            if (count($parts) === 1) {
                // file at the root of the archive (outside any module folder)
                $result['errors'][] = 'file outside the module folder: '.self::printable($name);
                continue;
            }
            $tops[$parts[0]] = true;
        }
        if ($size > self::MAX_UNCOMPRESSED) {
            $result['errors'][] = 'archive too large once extracted ('.round($size / 1048576).' MB)';
        }
        $zip->close();
        if (count($tops) !== 1) {
            $result['errors'][] = 'unexpected top-level directories: '.implode(', ', array_map([self::class, 'printable'], array_keys($tops)));
        }
        if ($result['errors']) {
            return $result;
        }
        $top = array_keys($tops)[0].'/';
        if (in_array($top.'module.json', $names, true)) {
            $result['root'] = $top;
        } else {
            $candidates = [];
            foreach ($names as $name) {
                if (preg_match('#^'.preg_quote($top, '#').'([^/]+)/module\.json$#', $name, $m)) {
                    $candidates[] = $top.$m[1].'/';
                }
            }
            if (count($candidates) === 1) {
                $result['root'] = $candidates[0];
            } else {
                $result['errors'][] = count($candidates) ? 'several module.json files: inconsistent structure' : 'module.json not found';

                return $result;
            }
        }
        $result['ok'] = true;

        return $result;
    }

    /** '' when the name is safe, otherwise the reason. */
    public static function badName($name)
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            return 'invalid entry name';
        }
        $name = str_replace('\\', '/', $name);
        if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name) || strpos($name, '//') === 0) {
            return 'absolute path refused';
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return 'path traversal refused';
            }
        }

        return '';
    }

    /** Unix file type in the upper 16 bits of the external attributes (zip made on Unix): 0120000 = symlink. */
    protected static function isSymlink(\ZipArchive $zip, $index)
    {
        $opsys = 0;
        $attr = 0;
        if (!method_exists($zip, 'getExternalAttributesIndex') || !$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === (defined('ZipArchive::OPSYS_UNIX') ? \ZipArchive::OPSYS_UNIX : 3) && ((($attr >> 16) & 0170000) === 0120000);
    }

    /**
     * Extracts only the module folder ($root) into $target (which must not exist), entry by entry, after inspect().
     * Every destination path is checked again to be inside $target.
     */
    public static function extract($zipPath, $root, $target)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('cannot open the archive');
        }
        if (file_exists($target)) {
            throw new \RuntimeException('staging folder already exists: '.$target);
        }
        if (!@mkdir($target, 0775, true)) {
            throw new \RuntimeException('cannot create the staging folder');
        }
        $base = rtrim(str_replace('\\', '/', realpath($target)), '/').'/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (self::badName($name) !== '' || self::isSymlink($zip, $i)) {
                throw new \RuntimeException('unsafe entry: '.self::printable($name));
            }
            if (strpos($name, $root) !== 0) {
                continue;
            }
            $relative = substr($name, strlen($root));
            if ($relative === '' || $relative === false) {
                continue;
            }
            $dest = $base.$relative;
            if (substr($name, -1) === '/') {
                if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
                    throw new \RuntimeException('cannot create '.$relative);
                }
                continue;
            }
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new \RuntimeException('cannot create '.dirname($relative));
            }
            $real = rtrim(str_replace('\\', '/', realpath($dir)), '/').'/';
            if (strpos($real, $base) !== 0) {
                throw new \RuntimeException('entry outside the staging folder: '.self::printable($name));
            }
            $in = $zip->getStream($name);
            if (!$in) {
                throw new \RuntimeException('cannot read '.self::printable($name));
            }
            $out = fopen($dest, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
        $zip->close();
    }

    public static function printable($name)
    {
        return preg_replace('/[^\x20-\x7E]/', '?', mb_substr((string) $name, 0, 200));
    }
}
