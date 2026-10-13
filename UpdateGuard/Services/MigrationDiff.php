<?php

namespace Modules\UpdateGuard\Services;

/**
 * Migrations brought by a new version (files in Database/Migrations that the installed version does not have).
 * Pure PHP.
 *
 * Rolling back the files does not roll back the database. In version 1, when a new version brings migrations,
 * the automatic rollback is disabled for that update (database state unknown) and the update is never automatic,
 * unless the module declares in its module.json:
 *
 *   "updateGuard": { "migrations": "backward-compatible" }
 *
 * meaning: the previous version of the module keeps working with the new tables / columns.
 */
class MigrationDiff
{
    public static function files($moduleDir)
    {
        $dir = rtrim($moduleDir, '/\\').'/Database/Migrations';
        $out = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $out[] = basename($file);
        }
        sort($out);

        return $out;
    }

    /** @return string[] migration files of $newDir that $oldDir does not have */
    public static function added($oldDir, $newDir)
    {
        return array_values(array_diff(self::files($newDir), self::files($oldDir)));
    }

    /** file-safe | database-safe | unknown */
    public static function rollbackSafety(array $added, array $manifest)
    {
        if (!$added) {
            return 'file-safe';
        }
        $declared = isset($manifest['updateGuard']['migrations']) ? (string) $manifest['updateGuard']['migrations'] : '';

        return $declared === 'backward-compatible' ? 'database-safe' : 'unknown';
    }
}
