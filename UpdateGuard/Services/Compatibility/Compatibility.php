<?php

namespace Modules\UpdateGuard\Services\Compatibility;

use Modules\UpdateGuard\Services\ChecksumService;
use Modules\UpdateGuard\Services\DownloadService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Settings;

/**
 * Project compatibility in FreeScout: where the metadata comes from, and the settings.
 *
 * Sources of a target version, by priority (the first one that declares something wins):
 *   1. interop.json of the target package (verified with SHA-256 before it is read)
 *   2. interop.json published next to the release ZIP (interopUrl, or the folder of latestVersionZipUrl)
 *   3. module.json of the target package (compatibility, requiredAppVersion, requiredPhpVersion, requires)
 *   4. metadata of the installed version
 *   5. nothing: UNKNOWN
 * UpdateGuard's emergency rules (Config/compatibility_rules.php) are added on top as known conflicts.
 */
class Compatibility
{
    public static function settings()
    {
        return [
            'compat_block_incompatible' => (bool) Settings::get('compat_block_incompatible'),
            'compat_block_unknown_auto' => (bool) Settings::get('compat_block_unknown_auto'),
            'compat_allow_override'     => (bool) Settings::get('compat_allow_override'),
        ];
    }

    /** Emergency rules shipped with UpdateGuard. */
    public static function rules()
    {
        $file = __DIR__.'/../../Config/compatibility_rules.php';
        $rules = is_file($file) ? include $file : [];

        return is_array($rules) ? $rules : [];
    }

    public static function platform()
    {
        return ['freescout' => (string) config('app.version'), 'php' => PHP_VERSION];
    }

    public static function installedProjects()
    {
        return ProjectRegistry::fromModules(ModuleDiscoveryService::installed());
    }

    /** interop.json of the latest release: "interopUrl" of module.json, or next to latestVersionZipUrl. */
    public static function releaseInteropUrl(array $manifest)
    {
        $url = Metadata::url($manifest['interopUrl'] ?? '');
        if ($url !== '') {
            return $url;
        }
        $zip = Metadata::url($manifest['latestVersionZipUrl'] ?? '');

        return $zip !== '' && strrpos($zip, '/') > 8 ? substr($zip, 0, strrpos($zip, '/') + 1).'interop.json' : '';
    }

    /** The release interop.json as an array (only the compatibility keys), or null. */
    public static function fetchReleaseInterop(array $manifest, DownloadService $download, $sums = '')
    {
        $url = self::releaseInteropUrl($manifest);
        if ($url === '') {
            return null;
        }
        try {
            $body = $download->text($url);
        } catch (\Throwable $e) {
            return null;
        }
        // listed in SHA256SUMS: must match, or it is not used
        $expected = $sums !== '' ? ChecksumService::expectedFor($sums, 'interop.json') : '';
        if ($expected !== '' && !ChecksumService::matches($expected, hash('sha256', $body))) {
            return null;
        }
        $doc = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $body), true);
        if (!is_array($doc) || !Metadata::declares($doc) && empty($doc['project'])) {
            return null;
        }

        return array_intersect_key($doc, array_flip(['project', 'compatibility', 'projects', 'interop_protocol']));
    }

    /**
     * @param array $sources Metadata::fromDir()/fromDocuments() results, in priority order, each with a 'source' label
     * @param array|null $installed ProjectRegistry rows (default: the installed modules)
     */
    public static function evaluate($moduleName, $version, array $sources, ?array $installed = null, ?array $rules = null, ?array $platform = null)
    {
        $chosen = null;
        $identity = null;
        foreach ($sources as $s) {
            $identity = $identity ?: ($s['identity'] ?? null);
            if (($s['metadata'] ?? null) !== null) {
                $chosen = $s;
                break;
            }
        }
        $identity = ($chosen['identity'] ?? null) ?: ($identity ?: Metadata::identity(['name' => $moduleName]));
        list($metadata, $applied) = Metadata::applyRules($chosen['metadata'] ?? null, $rules !== null ? $rules : self::rules(), $identity, $moduleName, $version);
        $source = $chosen ? $chosen['source'] : '';
        if ($applied) {
            $source = ($source !== '' ? $source.' + ' : '').'UpdateGuard rules';
        }
        $installed = $installed !== null ? $installed : self::installedProjects();
        // a module is not one of its own related projects
        $installed = array_values(array_filter($installed, function ($row) use ($moduleName) {
            return $row['module'] !== $moduleName;
        }));

        return CompatibilityChecker::evaluate(['name' => $moduleName, 'id' => $identity['id'], 'version' => $version], $metadata, $source,
            $installed, $platform !== null ? $platform : self::platform());
    }

    /** Sources of a downloaded and verified package (staging folder). */
    public static function packageSources(array $module, $staged, array $newManifest, $releaseInterop = null)
    {
        $package = Metadata::fromDir($staged, $newManifest);
        $sources = [];
        if ($package['source'] === 'interop.json') {
            $sources[] = array_merge($package, ['source' => 'package interop.json']);
        }
        if (is_array($releaseInterop)) {
            $sources[] = array_merge(Metadata::fromDocuments($newManifest, $releaseInterop), ['source' => 'release interop.json']);
        }
        if ($package['source'] === 'module.json') {
            $sources[] = array_merge($package, ['source' => 'package module.json']);
        }
        $installed = Metadata::fromDir($module['path'], $module['manifest']);
        $sources[] = array_merge($installed, ['source' => 'installed '.($installed['source'] ?: 'module.json').' ('.$module['version'].')']);

        return $sources;
    }

    /** Compatibility of the INSTALLED version with what is installed now (module page). */
    public static function forInstalled(array $module)
    {
        $own = Metadata::fromDir($module['path'], $module['manifest']);

        return self::evaluate($module['name'], $module['version'], [array_merge($own, ['source' => 'installed '.($own['source'] ?: 'module.json')])]);
    }

    /**
     * Before an update is queued (confirmation page): the latest version, from the release interop.json read by
     * the last check, else the installed metadata. The package is checked again after its download.
     */
    public static function preview(array $module, $row)
    {
        $caps = $row->capabilities();
        $version = (string) ($row->latest_version ?: $module['version']);
        $sources = [];
        if (!empty($caps['compat_release']) && is_array($caps['compat_release']) && ($caps['compat_release_version'] ?? '') === $version) {
            $sources[] = array_merge(Metadata::fromDocuments($module['manifest'], $caps['compat_release']), ['source' => 'release interop.json']);
        }
        $own = Metadata::fromDir($module['path'], $module['manifest']);
        $sources[] = array_merge($own, ['source' => 'installed '.($own['source'] ?: 'module.json').' ('.$module['version'].')']);

        return self::evaluate($module['name'], $version, $sources);
    }

    /** Installed modules that declare something about $name (its page lists them). */
    public static function declaredBy($name)
    {
        $installed = self::installedProjects();
        $target = null;
        foreach ($installed as $row) {
            if ($row['module'] === $name) {
                $target = $row;
            }
        }
        if (!$target) {
            return [];
        }
        $out = [];
        foreach (ModuleDiscoveryService::installed() as $module) {
            if ($module['name'] === $name) {
                continue;
            }
            $result = self::forInstalled($module);
            foreach ($result['projects'] as $p) {
                $found = ProjectRegistry::find([$target], ['id' => $p['id'], 'module' => '']);
                if ($found || $p['id'] === $target['id']) {
                    $out[] = ['module' => $module['name'], 'version' => $module['version'], 'project' => $p];
                }
            }
        }

        return $out;
    }
}
