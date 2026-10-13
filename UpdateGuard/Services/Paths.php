<?php

namespace Modules\UpdateGuard\Services;

/** storage/updateguard/{staging,backups,locks,reports} */
class Paths
{
    public static function base($sub = '')
    {
        $base = storage_path((string) config('updateguard.storage', 'updateguard'));

        return $base.($sub !== '' ? '/'.$sub : '');
    }

    public static function staging($sub = '')
    {
        return self::base('staging'.($sub !== '' ? '/'.$sub : ''));
    }

    public static function backups($sub = '')
    {
        return self::base('backups'.($sub !== '' ? '/'.$sub : ''));
    }

    public static function locks()
    {
        return self::base('locks');
    }

    public static function reports()
    {
        return self::base('reports');
    }

    public static function modules($name = '')
    {
        return base_path('Modules'.($name !== '' ? '/'.$name : ''));
    }

    /** Module folder name: letters, digits, "_" and "-" only (never a path). */
    public static function isModuleName($name)
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{1,100}$/', (string) $name);
    }

    public static function ensure()
    {
        foreach (['staging', 'backups', 'locks', 'reports'] as $sub) {
            $dir = self::base($sub);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    public static function deleteDir($dir)
    {
        if (is_link($dir)) {
            @unlink($dir);
        } elseif (is_dir($dir)) {
            \File::deleteDirectory($dir);
        }
    }

    /** rename(), or copy + delete across file systems. */
    public static function move($from, $to)
    {
        if (!@rename($from, $to)) {
            if (!\File::copyDirectory($from, $to)) {
                throw new \RuntimeException('cannot move '.$from.' to '.$to);
            }
            \File::deleteDirectory($from);
        }
    }

    /** Copy of a module folder; symbolic links (e.g. a self-referencing Public link) are not followed. */
    public static function copyTree($from, $to)
    {
        if (!is_dir($to) && !@mkdir($to, 0775, true)) {
            throw new \RuntimeException('cannot create '.$to);
        }
        $from = rtrim($from, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $dest = $to.'/'.$relative;
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
                    throw new \RuntimeException('cannot create '.$dest);
                }
            } elseif (!@copy($item->getPathname(), $dest)) {
                throw new \RuntimeException('cannot copy '.$relative);
            }
        }
    }

    public static function dirSize($dir)
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /** Folders an update must write that this process cannot: path => owner. */
    public static function unwritable($moduleName)
    {
        $out = [];
        foreach ([self::modules(), self::modules($moduleName), public_path('modules'), base_path('bootstrap/cache'), self::base()] as $path) {
            if (file_exists($path) && !is_writable($path)) {
                $uid = @fileowner($path);
                $owner = $uid;
                if ($uid !== false && function_exists('posix_getpwuid')) {
                    $info = @posix_getpwuid($uid);
                    $owner = !empty($info['name']) ? $info['name'] : $uid;
                }
                $out[$path] = (string) $owner;
            }
        }

        return $out;
    }
}
