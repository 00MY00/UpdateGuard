<?php

namespace Modules\UpdateGuard\Services;

/**
 * Version strings of modules ("1.4.8", "v1.4.8", "1.5.0-beta.1"). Pure PHP.
 */
class VersionService
{
    /** "v1.4.8\n" -> "1.4.8"; anything that is not a version -> "" (a latestVersionUrl answering HTML, an error…). */
    public static function normalize($version)
    {
        $version = trim((string) $version);
        if (stripos($version, 'v') === 0) {
            $version = substr($version, 1);
        }

        return preg_match('/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.\-+]+)?$/', $version) ? $version : '';
    }

    /**
     * Version answered by a latestVersionUrl, as FreeScout's Modules page reads it: plain text ("1.4.8"),
     * or a module.json (its "version"), e.g. releases/latest/download/module.json. "" when there is none.
     */
    public static function fromResponse($body)
    {
        $body = trim((string) $body);
        $plain = self::normalize($body);
        if ($plain !== '') {
            return $plain;
        }
        $json = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $body), true);
        if (is_array($json) && isset($json['version']) && is_scalar($json['version'])) {
            return self::normalize($json['version']);
        }
        // malformed JSON: the same pattern as FreeScout (app/Http/Controllers/ModulesController.php)
        if (preg_match('#"version":[^"]*"([\d\.]+)"#', $body, $m)) {
            return self::normalize($m[1]);
        }

        return '';
    }

    /** -1, 0 or 1; an invalid version is lower than any valid one. */
    public static function compare($a, $b)
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') {
            return $a === $b ? 0 : ($a === '' ? -1 : 1);
        }

        return version_compare($a, $b);
    }

    public static function isNewer($candidate, $installed)
    {
        return self::normalize($candidate) !== '' && self::compare($candidate, $installed) > 0;
    }

    /** Safe for a folder name: digits, letters, dots, dashes. */
    public static function slug($version)
    {
        $slug = preg_replace('/[^0-9A-Za-z.\-]/', '_', (string) $version);

        return $slug !== '' ? $slug : 'unknown';
    }
}
