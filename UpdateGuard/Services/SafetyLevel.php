<?php

namespace Modules\UpdateGuard\Services;

/**
 * Safety level of a module, from what it publishes. Pure PHP.
 *
 *   FULL         remote version + ZIP + SHA-256 + native health check (backup and rollback are always done)
 *   PROTECTED    remote version + ZIP, with only one of SHA-256 / native health check
 *   GENERIC      remote version + ZIP, no SHA-256 and no native health check: generic validation only
 *   UNSUPPORTED  no remote version or no ZIP, official (licensed) module, or UpdateGuard itself
 */
class SafetyLevel
{
    const FULL = 'full';
    const PROTECTED_LEVEL = 'protected';
    const GENERIC = 'generic';
    const UNSUPPORTED = 'unsupported';

    const RANK = [self::UNSUPPORTED => 0, self::GENERIC => 1, self::PROTECTED_LEVEL => 2, self::FULL => 3];

    /**
     * @param array $c capabilities: version_url, zip_url, sha256, native_health, official, self (booleans)
     */
    public static function compute(array $c)
    {
        if (!empty($c['self']) || !empty($c['official']) || empty($c['version_url']) || empty($c['zip_url'])) {
            return self::UNSUPPORTED;
        }
        $sha = !empty($c['sha256']);
        $health = !empty($c['native_health']);
        if ($sha && $health) {
            return self::FULL;
        }

        return ($sha || $health) ? self::PROTECTED_LEVEL : self::GENERIC;
    }

    /** Automatic updates: never GENERIC / UNSUPPORTED; PROTECTED only when the administrator allowed it. */
    public static function allowsAutomatic($level, $minimum)
    {
        $minimum = $minimum === self::PROTECTED_LEVEL ? self::PROTECTED_LEVEL : self::FULL;

        return self::RANK[$level] >= self::RANK[$minimum];
    }

    public static function label($level)
    {
        $labels = [
            self::FULL            => '✓ Full Safe Update',
            self::PROTECTED_LEVEL => '! Protected Update',
            self::GENERIC         => '! Generic Validation',
            self::UNSUPPORTED     => '× Unsupported Automatic Update',
        ];

        return isset($labels[$level]) ? $labels[$level] : $labels[self::UNSUPPORTED];
    }
}
