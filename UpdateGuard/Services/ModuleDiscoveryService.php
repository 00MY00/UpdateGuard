<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Entities\ModuleHealth;

/**
 * Installed modules and what they publish (FreeScout's module system: nwidart \Module facade, \App\Module).
 *
 * Read from each module.json:
 *   latestVersionUrl      plain-text latest version (same convention as freescout:module-update,
 *                         app/Console/Commands/ModuleUpdate.php: the body, trimmed, is the version)
 *   latestVersionZipUrl   ZIP of that version (same as App\Module::updateModule())
 *   sha256sumsUrl         optional; default: SHA256SUMS next to the ZIP
 *   healthCommand         optional artisan command; default convention: "<alias>:health"
 *   updateGuard           optional: {"migrations": "backward-compatible"}
 *
 * The health command must already be registered in Artisan by the INSTALLED code: nothing downloaded is executed
 * to find it.
 */
class ModuleDiscoveryService
{
    const SELF = 'UpdateGuard';

    /** @return array name => ['name','alias','path','version','enabled','manifest'] */
    public static function installed()
    {
        $out = [];
        foreach (\Module::all() as $module) {
            $name = $module->getName();
            if (!Paths::isModuleName($name)) {
                continue;
            }
            $path = $module->getPath();
            $manifest = self::manifest($path);
            $alias = (string) ($manifest['alias'] ?? $module->getAlias());
            $out[$name] = [
                'name'     => $name,
                'alias'    => $alias,
                'path'     => $path,
                'version'  => (string) ($manifest['version'] ?? ''),
                'enabled'  => self::isActive($alias),
                'manifest' => $manifest,
            ];
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    public static function find($name)
    {
        $all = self::installed();

        return isset($all[$name]) ? $all[$name] : null;
    }

    public static function manifest($dir)
    {
        $data = json_decode((string) @file_get_contents(rtrim($dir, '/\\').'/module.json'), true);

        return is_array($data) ? $data : [];
    }

    public static function isActive($alias)
    {
        try {
            return (bool) \App\Module::isActive($alias);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isOfficial(array $manifest)
    {
        try {
            return !empty($manifest['authorUrl']) && (bool) \App\Module::isOfficial($manifest['authorUrl']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Artisan command name of the native health check (declared or by convention). A declared command must be in
     * the module's own namespace ("<alias>:..."): a module.json can never make UpdateGuard run "migrate:fresh",
     * "db:wipe" or the command of another module.
     */
    public static function healthCommandName(array $manifest, $alias)
    {
        $alias = strtolower((string) $alias);
        $declared = isset($manifest['healthCommand']) ? strtolower((string) $manifest['healthCommand']) : '';
        if ($declared !== '' && strpos($declared, $alias.':') === 0 && preg_match('/^[a-z0-9_\-]+(:[a-z0-9_\-]+)+$/', $declared)) {
            return $declared;
        }

        return $alias.':health';
    }

    /**
     * The command is registered in Artisan (by the installed modules) — ['available' => bool, 'json' => bool].
     */
    public static function healthCommand(array $manifest, $alias)
    {
        $name = self::healthCommandName($manifest, $alias);
        try {
            $all = \Artisan::all();
        } catch (\Throwable $e) {
            $all = [];
        }
        if (!isset($all[$name])) {
            return ['name' => $name, 'available' => false, 'json' => false];
        }
        $json = false;
        try {
            $json = $all[$name]->getDefinition()->hasOption('json');
        } catch (\Throwable $e) {
        }

        return ['name' => $name, 'available' => true, 'json' => $json];
    }

    /**
     * Checks one module: remote version, SHA256SUMS, health command; saves the row of updateguard_modules.
     * Network errors are recorded on the row, never thrown.
     */
    public static function check(array $module, ?DownloadService $download = null)
    {
        $download = $download ?: new DownloadService();
        $m = $module['manifest'];
        $versionUrl = (string) ($m['latestVersionUrl'] ?? '');
        $zipUrl = (string) ($m['latestVersionZipUrl'] ?? '');
        $self = $module['name'] === self::SELF;
        $official = self::isOfficial($m);
        $health = self::healthCommand($m, $module['alias']);
        $caps = [
            'version_url'      => $versionUrl,
            'zip_url'          => $zipUrl,
            'sums_url'         => '',
            'sha256'           => false,
            'native_health'    => $health['available'],
            'health_command'   => $health['name'],
            'health_json'      => $health['json'],
            'official'         => $official,
            'self'             => $self,
            'service_provider' => !empty($m['providers']),
            'has_migrations'   => (bool) MigrationDiff::files($module['path']),
            'error'            => '',
        ];
        $latest = '';
        if (!$self && !$official && $versionUrl !== '') {
            try {
                $latest = VersionService::normalize($download->text($versionUrl));
                if ($latest === '') {
                    $caps['error'] = 'latestVersionUrl does not answer a version number';
                }
            } catch (\Throwable $e) {
                $caps['error'] = 'latestVersionUrl: '.$e->getMessage();
            }
            $sumsUrl = DownloadService::sumsUrl($m);
            if ($sumsUrl !== '' && $zipUrl !== '') {
                $caps['sums_url'] = $sumsUrl;
                try {
                    $caps['sha256'] = ChecksumService::expectedFor($download->text($sumsUrl), DownloadService::zipName($m)) !== '';
                } catch (\Throwable $e) {
                    // no SHA256SUMS published: level without checksum
                }
            }
        }
        $level = SafetyLevel::compute([
            'version_url'   => $versionUrl !== '' && DownloadService::isAllowedUrl($versionUrl),
            'zip_url'       => $zipUrl !== '' && DownloadService::isAllowedUrl($zipUrl),
            'sha256'        => $caps['sha256'],
            'native_health' => $caps['native_health'],
            'official'      => $official,
            'self'          => $self,
        ]);

        $row = ModuleHealth::firstOrNew(['name' => $module['name']]);
        $row->alias = $module['alias'];
        $row->installed_version = $module['version'];
        if ($latest !== '' || $caps['error'] === '') {
            $row->latest_version = $latest;
        }
        $row->enabled = $module['enabled'];
        $row->safety_level = $level;
        $row->capabilities = json_encode($caps, JSON_UNESCAPED_SLASHES);
        $row->last_check_at = now();
        if (!$row->exists) {
            $row->known_good_version = $module['version'];
            $row->auto_update = true;
        }
        $row->save();

        return $row;
    }

    /** Rows for every installed module (without network); removed modules are dropped. */
    public static function sync()
    {
        $installed = self::installed();
        foreach ($installed as $module) {
            $row = ModuleHealth::firstOrNew(['name' => $module['name']]);
            if (!$row->exists) {
                $row->alias = $module['alias'];
                $row->known_good_version = $module['version'];
                $row->safety_level = SafetyLevel::UNSUPPORTED;
                $row->auto_update = true;
            }
            $row->installed_version = $module['version'];
            $row->enabled = $module['enabled'];
            $row->save();
        }
        ModuleHealth::whereNotIn('name', array_keys($installed) ?: ['-'])->delete();

        return $installed;
    }
}
