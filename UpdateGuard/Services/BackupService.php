<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Entities\ModuleHealth;
use Modules\UpdateGuard\Entities\UpdateBackup;

/**
 * Backup of a module folder before any change:
 *
 *   storage/updateguard/backups/<Module>/<version>-<YYYYmmddHHMMSS>/
 *       files/          copy of Modules/<Module> (symbolic links not followed)
 *       metadata.json   module, version, date, checksum (tree hash), source, health status, update ID, reason
 */
class BackupService
{
    public static function create($moduleName, $reason, $updateId, $source = '')
    {
        if (!Paths::isModuleName($moduleName)) {
            throw new \InvalidArgumentException('invalid module name');
        }
        $moduleDir = Paths::modules($moduleName);
        $manifest = ModuleDiscoveryService::manifest($moduleDir);
        if (!$manifest) {
            throw new \RuntimeException('Modules/'.$moduleName.'/module.json not found: nothing to back up');
        }
        $version = (string) ($manifest['version'] ?? '');
        $relative = $moduleName.'/'.VersionService::slug($version).'-'.date('YmdHis');
        $dir = Paths::backups($relative);
        for ($i = 2; file_exists($dir); $i++) {
            $dir = Paths::backups($relative.'-'.$i);
        }
        $relative = substr($dir, strlen(Paths::backups()) + 1);
        Paths::copyTree($moduleDir, $dir.'/files');
        $checksum = ChecksumService::hashTree($dir.'/files');
        if ($checksum !== ChecksumService::hashTree($moduleDir, false)) {
            Paths::deleteDir($dir);
            throw new \RuntimeException('backup incomplete: the copy differs from Modules/'.$moduleName);
        }
        $row = ModuleHealth::named($moduleName);
        $health = $row ? (string) $row->health_status : 'unknown';
        $knownGood = $row && $row->known_good_version === $version && $row->state !== 'pending_validation';
        $metadata = [
            'module'        => $moduleName,
            'version'       => $version,
            'date'          => date('c'),
            'checksum'      => $checksum,
            'source'        => (string) $source,
            'health_status' => $health,
            'update_id'     => (string) $updateId,
            'reason'        => (string) $reason,
            'known_good'    => $knownGood,
        ];
        file_put_contents($dir.'/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $backup = UpdateBackup::create([
            'module'        => $moduleName,
            'version'       => $version,
            'path'          => str_replace('\\', '/', $relative),
            'reason'        => (string) $reason,
            'update_id'     => (string) $updateId,
            'size'          => Paths::dirSize($dir),
            'checksum'      => $checksum,
            'source'        => mb_substr((string) $source, 0, 500),
            'health_status' => $health,
            'known_good'    => $knownGood,
        ]);
        if ($row) {
            $row->last_backup_at = now();
            $row->save();
        }
        try {
            \Eventy::action('updateguard.backup_created', $backup);
        } catch (\Throwable $e) {
        }

        return $backup;
    }

    /** The backup is complete and unchanged since it was made. */
    public static function verify(UpdateBackup $backup)
    {
        return $backup->exists() && ($backup->checksum === '' || ChecksumService::hashTree($backup->filesDir()) === $backup->checksum);
    }

    /** Most recent known-good backup of a module other than $exceptVersion. */
    public static function lastKnownGood($moduleName, $exceptVersion = null)
    {
        $query = UpdateBackup::where('module', $moduleName)->where('known_good', true)->orderBy('id', 'desc');
        if ($exceptVersion !== null) {
            $query->where('version', '!=', $exceptVersion);
        }

        return $query->first();
    }

    public static function delete(UpdateBackup $backup)
    {
        Paths::deleteDir($backup->dir());
        $backup->delete();
    }

    /** ZIP of a backup for download (temporary file, deleted after the response). */
    public static function zip(UpdateBackup $backup)
    {
        $file = tempnam(sys_get_temp_dir(), 'ug').'.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('cannot create the archive');
        }
        $base = $backup->filesDir();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
                $zip->addFile($item->getPathname(), $backup->module.'/'.$relative);
            }
        }
        if (is_file($backup->dir().'/metadata.json')) {
            $zip->addFile($backup->dir().'/metadata.json', $backup->module.'/updateguard-metadata.json');
        }
        $zip->close();

        return $file;
    }
}
