<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Services\Security\ScanCache;
use Modules\UpdateGuard\Services\Security\ScanResult;
use Modules\UpdateGuard\Services\Security\SecurityScannerProviderInterface;

/**
 * UpdateGuard checks itself without updating itself (its updates go through FreeScout or install.sh):
 *
 *   1. integrity: every installed file against FILES.sha256, published with the release of the INSTALLED version
 *      (releases/download/v<version>/: tags are never moved): files modified, missing or added on the server;
 *   2. VirusTotal (when enabled): the SHA-256 of the official UpdateGuard.zip of that release (its SHA256SUMS), one
 *      lookup per version thanks to the cache; when the ZIP is unknown, the administrator can send it.
 *
 * No local static analysis of itself: its code contains the detection rules and would report itself. The integrity
 * check proves that the installed code is exactly the published code.
 * Limit: it protects against changes on the server, not against a compromised repository.
 */
class SelfCheck
{
    const RELEASES = 'https://github.com/00MY00/UpdateGuard/releases/download/v%s/';

    const ZIP = 'UpdateGuard.zip';

    /** Files listed per kind of difference (the counts are complete). */
    const MAX_LISTED = 20;

    /** verified | intact | modified | flagged | unavailable */
    const STATUSES = ['verified', 'intact', 'modified', 'flagged', 'unavailable'];

    public static function releaseUrl($version, $file)
    {
        return sprintf(self::RELEASES, rawurlencode((string) $version)).$file;
    }

    /** Lines "sha256  path" (sha256sum output) -> [path => sha256]; anything else is ignored. */
    public static function parseManifest($text)
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            if (!preg_match('/^([a-fA-F0-9]{64}) [ *](.+)$/', rtrim($line), $m)) {
                continue;
            }
            $path = str_replace('\\', '/', $m[2]);
            if (strpos($path, './') === 0) {
                $path = substr($path, 2);
            }
            if ($path === '' || $path[0] === '/' || strpos('/'.$path.'/', '/../') !== false) {
                continue;
            }
            $out[$path] = strtolower($m[1]);
        }

        return $out;
    }

    /** Installed files: [relative path => sha256]; a symbolic link is listed as "link" (never followed), .git left out. */
    public static function installedFiles($dir)
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                function ($item) {
                    return $item->getFilename() !== '.git';
                }
            )
        );
        foreach ($iterator as $item) {
            $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($dir) + 1);
            $out[$relative] = $item->isLink() ? 'link' : ChecksumService::hashFile($item->getPathname());
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return array ['modified' => [...], 'missing' => [...], 'added' => [...]] sorted paths */
    public static function compare(array $expected, array $actual)
    {
        $diff = ['modified' => [], 'missing' => [], 'added' => []];
        foreach ($expected as $path => $hash) {
            if (!array_key_exists($path, $actual)) {
                $diff['missing'][] = $path;
            } elseif (!hash_equals($hash, (string) $actual[$path])) {
                $diff['modified'][] = $path;
            }
        }
        foreach (array_keys($actual) as $path) {
            if (!array_key_exists($path, $expected)) {
                $diff['added'][] = (string) $path;
            }
        }
        foreach ($diff as $kind => $paths) {
            sort($paths, SORT_STRING);
            $diff[$kind] = $paths;
        }

        return $diff;
    }

    /**
     * Checks the installed folder $dir of version $version.
     *
     * @param SecurityScannerProviderInterface|null $vt VirusTotal (null when disabled)
     * @param bool $upload the administrator agreed to send the official ZIP when VirusTotal does not know it
     */
    public static function run($dir, $version, DownloadService $download, $vt = null, ?ScanCache $cache = null, $upload = false)
    {
        $r = [
            'status' => 'unavailable', 'version' => (string) $version, 'files' => 0,
            'modified' => [], 'missing' => [], 'added' => [], 'modified_count' => 0, 'missing_count' => 0, 'added_count' => 0,
            'zip_sha256' => '', 'vt' => null, 'error' => '', 'at' => date('c'),
        ];
        if (VersionService::normalize($version) === '') {
            $r['error'] = 'installed version unknown';

            return $r;
        }
        try {
            $manifest = self::parseManifest($download->text(self::releaseUrl($version, 'FILES.sha256')));
        } catch (\Throwable $e) {
            $r['error'] = 'FILES.sha256 of version '.$version.' not available: '.$e->getMessage();

            return $r;
        }
        if (!$manifest) {
            $r['error'] = 'FILES.sha256 of version '.$version.' lists no file';

            return $r;
        }
        $r['files'] = count($manifest);
        foreach (self::compare($manifest, self::installedFiles($dir)) as $kind => $paths) {
            $r[$kind] = array_slice($paths, 0, self::MAX_LISTED);
            $r[$kind.'_count'] = count($paths);
        }
        if ($r['modified_count'] + $r['missing_count'] + $r['added_count'] > 0) {
            // not the published code: no external opinion on the official package would say anything about it
            $r['status'] = 'modified';

            return $r;
        }
        $r['status'] = 'intact';
        if (!$vt || !$vt->isConfigured()) {
            return $r;
        }
        try {
            $r['zip_sha256'] = ChecksumService::expectedFor($download->text(self::releaseUrl($version, 'SHA256SUMS')), self::ZIP);
        } catch (\Throwable $e) {
            $r['error'] = 'SHA256SUMS of version '.$version.' not available: '.$e->getMessage();
        }
        if ($r['zip_sha256'] === '') {
            return $r;
        }
        $result = $cache ? $cache->get($vt->getName(), $r['zip_sha256']) : null;
        if (!$result || ($upload && $result->unknown)) {
            $result = $vt->lookupHash($r['zip_sha256']);
            if ($upload && $result->unknown && $vt->supportsUpload()) {
                $result = self::upload($version, $r['zip_sha256'], $download, $vt);
            }
            if ($cache) {
                $cache->put($vt->getName(), $r['zip_sha256'], $result);
            }
        }
        $r['vt'] = $result->toArray();
        if ($result->status === ScanResult::BLOCKED || $result->status === ScanResult::WARNING) {
            $r['status'] = 'flagged';
        } elseif ($result->status === ScanResult::PASS) {
            $r['status'] = 'verified';
        }

        return $r;
    }

    /** Sends the official ZIP of the release, after checking that it is the one of SHA256SUMS. */
    protected static function upload($version, $sha256, DownloadService $download, SecurityScannerProviderInterface $vt)
    {
        $zip = Paths::base('security/self-'.getmypid().'-'.mt_rand().'.zip');
        @mkdir(dirname($zip), 0775, true);
        try {
            $download->file(self::releaseUrl($version, self::ZIP), $zip);
            if (!ChecksumService::matches($sha256, ChecksumService::hashFile($zip))) {
                return ScanResult::make($vt->getName(), ScanResult::ERROR, 'the downloaded '.self::ZIP.' does not match SHA256SUMS: not sent');
            }

            return $vt->scanFile($zip);
        } catch (\Throwable $e) {
            return ScanResult::make($vt->getName(), ScanResult::ERROR, $e->getMessage());
        } finally {
            @unlink($zip);
        }
    }

    /** Checks the installed UpdateGuard with the settings (VirusTotal when enabled). */
    public static function forInstalled(array $module, DownloadService $download, $upload = false)
    {
        $s = Settings::all();
        $vt = !empty($s['vt_enabled']) ? Security\Providers::virusTotal($s) : null;

        return self::run($module['path'], $module['version'], $download, $vt, Security\Providers::cache($s), $upload);
    }
}
