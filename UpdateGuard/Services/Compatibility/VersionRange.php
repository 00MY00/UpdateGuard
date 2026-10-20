<?php

namespace Modules\UpdateGuard\Services\Compatibility;

use Modules\UpdateGuard\Services\VersionService;

/**
 * Semantic version ranges of the compatibility metadata (pure PHP), the usual npm / Composer forms:
 *
 *   ">=1.7.0 <2.0.0"    all comparators must match (>=, >, <=, <, =, !=)
 *   "1.9.0 - 1.9.2"     inclusive range
 *   "^1.2.0" "~1.2.3"   caret (same major; 0.x: same minor) and tilde (same minor)
 *   "1.9.x" "1.x" "*"   wildcards; "1.9" alone means 1.9.x
 *   "1.5.0"             exactly this version
 *   "a || b"            one of the alternatives
 *
 * Versions are compared on major.minor.patch (missing parts are 0), then the pre-release ("1.0.0-beta" < "1.0.0").
 * An invalid range never matches and is reported by isValid().
 */
class VersionRange
{
    /** -1, 0 or 1; "1.9" equals "1.9.0". */
    public static function compare($a, $b)
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        if ($pa === null || $pb === null) {
            return VersionService::compare($a, $b);
        }
        for ($i = 0; $i < 3; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] < $pb[$i] ? -1 : 1;
            }
        }
        if ($pa[3] === $pb[3]) {
            return 0;
        }
        // a pre-release is lower than the release
        if ($pa[3] === '' || $pb[3] === '') {
            return $pa[3] === '' ? 1 : -1;
        }

        return version_compare($pa[3], $pb[3]) <=> 0;
    }

    /** [major, minor, patch, pre-release] or null; build metadata ("+...") is ignored. */
    protected static function parts($version)
    {
        $version = VersionService::normalize($version);
        if ($version === '' || !preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?(?:-([0-9A-Za-z.\-]+))?(?:\+.*)?$/', $version, $m)) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (string) ($m[4] ?? '')];
    }

    public static function isVersion($version)
    {
        return self::parts($version) !== null;
    }

    public static function isValid($range)
    {
        return self::compile($range) !== null;
    }

    /** Whether $version is in $range ('' or invalid: false). */
    public static function satisfies($version, $range)
    {
        if (self::parts($version) === null) {
            return false;
        }
        $alternatives = self::compile($range);
        if ($alternatives === null) {
            return false;
        }
        foreach ($alternatives as $comparators) {
            $ok = true;
            foreach ($comparators as $c) {
                $cmp = self::compare($version, $c[1]);
                $ok = $ok && (
                    ($c[0] === '>=' && $cmp >= 0) || ($c[0] === '>' && $cmp > 0) || ($c[0] === '<=' && $cmp <= 0)
                    || ($c[0] === '<' && $cmp < 0) || ($c[0] === '=' && $cmp === 0) || ($c[0] === '!=' && $cmp !== 0)
                );
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** @return array|null alternatives, each a list of [operator, version] */
    protected static function compile($range)
    {
        $range = trim((string) $range);
        if ($range === '') {
            return null;
        }
        $out = [];
        foreach (explode('||', $range) as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '') {
                return null;
            }
            // "1.9.0 - 1.9.2"
            if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $alternative, $m)) {
                if (self::parts($m[1]) === null || self::parts($m[2]) === null) {
                    return null;
                }
                $out[] = [['>=', $m[1]], ['<=', $m[2]]];
                continue;
            }
            $comparators = [];
            // ">= 1.7.0" is accepted too
            $alternative = preg_replace('/(>=|<=|!=|>|<|=|\^|~)\s+/', '$1', $alternative);
            foreach (preg_split('/[\s,]+/', $alternative) as $token) {
                $c = self::token($token);
                if ($c === null) {
                    return null;
                }
                $comparators = array_merge($comparators, $c);
            }
            $out[] = $comparators;
        }

        return $out;
    }

    /** One token -> comparators, or null when invalid. */
    protected static function token($token)
    {
        if ($token === '*' || strtolower($token) === 'x') {
            return [['>=', '0.0.0']];
        }
        if (!preg_match('/^(>=|<=|!=|>|<|=|\^|~)?v?(.+)$/', $token, $m)) {
            return null;
        }
        $op = $m[1];
        $version = $m[2];
        // wildcards and partial versions: 1.9.x, 1.x, 1.9 (only without operator, or with "=")
        if (($op === '' || $op === '=') && preg_match('/^(\d+)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?$/i', $version, $w)) {
            $major = (int) $w[1];
            if (!isset($w[2]) || !ctype_digit($w[2])) {
                return [['>=', $major.'.0.0'], ['<', ($major + 1).'.0.0']];
            }
            if (!isset($w[3]) || !ctype_digit($w[3])) {
                return [['>=', $major.'.'.$w[2].'.0'], ['<', $major.'.'.((int) $w[2] + 1).'.0']];
            }
        }
        $p = self::parts($version);
        if ($p === null) {
            return null;
        }
        if ($op === '^') {
            $upper = $p[0] > 0 ? ($p[0] + 1).'.0.0' : ($p[1] > 0 ? '0.'.($p[1] + 1).'.0' : '0.0.'.($p[2] + 1));

            return [['>=', $version], ['<', $upper]];
        }
        if ($op === '~') {
            return [['>=', $version], ['<', $p[0].'.'.($p[1] + 1).'.0']];
        }

        return [[$op === '' ? '=' : $op, $version]];
    }
}
