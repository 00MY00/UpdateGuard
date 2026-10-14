<?php

namespace Modules\UpdateGuard\Services;

/**
 * A version number must always mean the same content. UpdateGuard remembers the SHA-256 of the ZIP of each version it
 * has seen (from the module's SHA256SUMS) and reports a version published AGAIN with a different SHA-256: release
 * modified after publication, or a compromised download source. Pure PHP.
 *
 * Nothing is installed because of it: automatic updates of the module are paused and the administrator decides
 * (Update to this version / keep the installed one).
 */
class RepublishGuard
{
    const KEEP = 20;

    /**
     * @param array  $known   version => sha256 already seen
     * @param string $version version published now
     * @param string $hash    sha256 of its ZIP in SHA256SUMS ('' when not published)
     * @return array [array $known (updated), array|null $republished ['version','old','new']]
     */
    public static function track(array $known, $version, $hash)
    {
        $version = (string) $version;
        $hash = strtolower((string) $hash);
        if ($version === '' || strlen($hash) !== 64) {
            return [$known, null];
        }
        if (isset($known[$version]) && $known[$version] !== $hash) {
            // the first hash stays the reference until the administrator accepts the new one
            return [$known, ['version' => $version, 'old' => $known[$version], 'new' => $hash]];
        }
        $known[$version] = $hash;
        if (count($known) > self::KEEP) {
            $known = array_slice($known, -self::KEEP, null, true);
        }

        return [$known, null];
    }

    /** The administrator trusts the new publication (after installing it): it becomes the reference. */
    public static function accept(array $known, $version, $hash)
    {
        if ((string) $version !== '' && strlen((string) $hash) === 64) {
            $known[(string) $version] = strtolower((string) $hash);
        }

        return $known;
    }

    public static function short($hash)
    {
        return substr((string) $hash, 0, 12).'…';
    }
}
