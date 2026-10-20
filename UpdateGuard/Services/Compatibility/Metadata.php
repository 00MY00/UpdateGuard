<?php

namespace Modules\UpdateGuard\Services\Compatibility;

/**
 * Compatibility metadata of a module (pure PHP): what it declares about other projects, and who it is.
 *
 * interop.json at the root of the module (preferred), or the same keys in its module.json:
 *
 *   {
 *     "project": {                                  identity of THIS module (optional)
 *       "id": "refresh-global",                     stable id; default: the module name in kebab-case
 *       "name": "RefreshGlobal",
 *       "repository": "https://github.com/00MY00/RefreshGlobal",
 *       "interop_protocol": "1.2.0"                 version of the API it offers to other modules
 *     },
 *     "compatibility": {
 *       "freescout": ">=1.8.0",                     optional (default: requiredAppVersion)
 *       "php": ">=7.1",                             optional (default: requiredPhpVersion)
 *       "projects": {
 *         "refresh": {
 *           "relationship": "optional_integration", required_dependency | optional_integration |
 *                                                   recommended_companion | conflict | compatibility_tested
 *           "required": false,
 *           "name": "Refresh", "module": "Refresh",  display name; FreeScout module folder or alias (optional)
 *           "repository": "https://github.com/altmenorg/freescout-refresh",
 *           "supported_versions": ">=1.7.0 <2.0.0",
 *           "interop_protocol": ">=1.0.0 <2.0.0",
 *           "legacy": {"supported_versions": ">=1.5.0 <1.7.0", "mode": "degraded", "message": "..."},
 *           "conflicts": [">=1.9.0 <=1.9.2"]
 *         }
 *       }
 *     }
 *   }
 *
 * The old module.json keys still count: requiredAppVersion, requiredPhpVersion, requires (required modules).
 */
class Metadata
{
    const RELATIONSHIPS = ['required_dependency', 'optional_integration', 'recommended_companion', 'conflict', 'compatibility_tested'];

    const ALIASES = [
        'required' => 'required_dependency', 'dependency' => 'required_dependency', 'requires' => 'required_dependency',
        'optional' => 'optional_integration', 'integration' => 'optional_integration',
        'recommended' => 'recommended_companion', 'companion' => 'recommended_companion',
        'conflicts' => 'conflict', 'incompatible' => 'conflict',
        'tested' => 'compatibility_tested',
    ];

    /** "RefreshGlobal" -> "refresh-global" */
    public static function kebab($name)
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', (string) $name);
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $name);

        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    /** Stable project id: lowercase letters, digits, "-", "_", "." */
    public static function isId($id)
    {
        return is_string($id) && preg_match('/^[a-z0-9][a-z0-9._\-]{0,63}$/', $id) === 1;
    }

    public static function readJson($file)
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) @file_get_contents($file)), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Declarations of a module folder: interop.json first, then module.json.
     * @return array ['metadata' => normalized|null, 'source' => 'interop.json'|'module.json'|'', 'identity' => [...]]
     */
    public static function fromDir($dir, ?array $manifest = null)
    {
        $dir = rtrim((string) $dir, '/\\');
        $manifest = $manifest !== null ? $manifest : (self::readJson($dir.'/module.json') ?: []);
        $interop = self::readJson($dir.'/interop.json');

        return self::fromDocuments($manifest, $interop, $interop !== null ? 'interop.json' : '');
    }

    /** Same from the documents themselves (an interop.json published next to the release, tests). */
    public static function fromDocuments(array $manifest, $interop = null, $interopSource = 'interop.json')
    {
        $identity = self::identity($manifest, is_array($interop) ? $interop : []);
        if (is_array($interop) && self::declares($interop)) {
            return ['metadata' => self::normalize($interop, $manifest), 'source' => $interopSource ?: 'interop.json', 'identity' => $identity];
        }
        if (self::declares($manifest)) {
            return ['metadata' => self::normalize($manifest, $manifest), 'source' => 'module.json', 'identity' => $identity];
        }

        return ['metadata' => null, 'source' => '', 'identity' => $identity];
    }

    /** The document says something about compatibility (new or old keys). */
    public static function declares(array $doc)
    {
        return !empty($doc['compatibility']) || !empty($doc['projects']) || !empty($doc['requiredAppVersion'])
            || !empty($doc['requiredPhpVersion']) || !empty($doc['requires']);
    }

    /** Identity of a module: id, name, repository, interop protocol. */
    public static function identity(array $manifest, array $interop = [])
    {
        $p = isset($interop['project']) && is_array($interop['project']) ? $interop['project']
            : (isset($manifest['project']) && is_array($manifest['project']) ? $manifest['project'] : []);
        $name = (string) ($p['name'] ?? ($manifest['name'] ?? ''));
        $id = strtolower((string) ($p['id'] ?? ''));

        return [
            'id'               => self::isId($id) ? $id : self::kebab((string) ($manifest['name'] ?? $name)),
            'name'             => $name,
            'repository'       => self::url($p['repository'] ?? ($manifest['detailsUrl'] ?? '')),
            'interop_protocol' => (string) ($p['interop_protocol'] ?? ($interop['interop_protocol'] ?? '')),
        ];
    }

    /** https:// links only (shown on the pages). */
    public static function url($url)
    {
        $url = trim((string) $url);

        return preg_match('#^https://[^\s"<>]+$#i', $url) ? $url : '';
    }

    /**
     * ['freescout' => range, 'php' => range, 'projects' => [id => project], 'errors' => [...]]
     * $doc: interop.json or module.json; $manifest: module.json (old keys requiredAppVersion / requiredPhpVersion / requires).
     */
    public static function normalize(array $doc, array $manifest = [])
    {
        $c = isset($doc['compatibility']) && is_array($doc['compatibility']) ? $doc['compatibility'] : $doc;
        $out = ['freescout' => '', 'php' => '', 'projects' => [], 'errors' => []];
        foreach (['freescout' => 'requiredAppVersion', 'php' => 'requiredPhpVersion'] as $key => $old) {
            $range = trim((string) ($c[$key] ?? ''));
            if ($range === '' && !empty($manifest[$old]) && is_scalar($manifest[$old])) {
                $range = '>='.trim((string) $manifest[$old]);
            }
            if ($range !== '' && !VersionRange::isValid($range)) {
                $out['errors'][] = $key.': invalid range "'.$range.'"';
                $range = '';
            }
            $out[$key] = $range;
        }
        foreach ((isset($c['projects']) && is_array($c['projects']) ? $c['projects'] : []) as $id => $p) {
            $id = strtolower((string) $id);
            if (!self::isId($id) || !is_array($p)) {
                $out['errors'][] = 'project "'.$id.'": invalid id or declaration';
                continue;
            }
            $out['projects'][$id] = self::project($id, $p, $out['errors']);
        }
        // old "requires": aliases of required modules
        foreach ((array) ($manifest['requires'] ?? []) as $alias) {
            $alias = strtolower(is_string($alias) ? trim($alias) : '');
            if (self::isId($alias) && !isset($out['projects'][$alias])) {
                $out['projects'][$alias] = self::project($alias, ['relationship' => 'required_dependency', 'module' => $alias], $out['errors']);
            }
        }

        return $out;
    }

    protected static function project($id, array $p, array &$errors)
    {
        $relationship = strtolower(trim((string) ($p['relationship'] ?? ($p['type'] ?? ''))));
        $relationship = self::ALIASES[$relationship] ?? $relationship;
        if (!in_array($relationship, self::RELATIONSHIPS, true)) {
            if ($relationship !== '') {
                $errors[] = $id.': unknown relationship "'.$relationship.'"';
            }
            $relationship = !empty($p['required']) ? 'required_dependency' : 'optional_integration';
        }
        $range = function ($value, $what) use ($id, &$errors) {
            $value = trim(is_scalar($value) ? (string) $value : '');
            if ($value !== '' && !VersionRange::isValid($value)) {
                $errors[] = $id.': invalid '.$what.' "'.$value.'"';

                return '';
            }

            return $value;
        };
        $legacy = isset($p['legacy']) && is_array($p['legacy']) ? $p['legacy'] : [];
        $conflicts = [];
        foreach (is_array($p['conflicts'] ?? null) ? $p['conflicts'] : (isset($p['conflicts']) ? [$p['conflicts']] : []) as $conflict) {
            $conflict = $range($conflict, 'conflict range');
            if ($conflict !== '') {
                $conflicts[] = $conflict;
            }
        }

        return [
            'id'                 => $id,
            'name'               => trim((string) ($p['name'] ?? '')) ?: $id,
            'module'             => preg_match('/^[A-Za-z0-9_\-]+$/', (string) ($p['module'] ?? '')) ? (string) $p['module'] : '',
            'relationship'       => $relationship,
            'required'           => $relationship === 'required_dependency' || !empty($p['required']),
            'repository'         => self::url($p['repository'] ?? ''),
            'supported_versions' => $range($p['supported_versions'] ?? ($p['versions'] ?? ''), 'supported_versions'),
            'interop_protocol'   => $range($p['interop_protocol'] ?? '', 'interop_protocol'),
            'legacy'             => [
                'supported_versions' => $range($legacy['supported_versions'] ?? '', 'legacy supported_versions'),
                'interop_protocol'   => $range($legacy['interop_protocol'] ?? '', 'legacy interop_protocol'),
                'mode'               => (string) ($legacy['mode'] ?? 'degraded'),
                'message'            => mb_substr(trim((string) ($legacy['message'] ?? '')), 0, 300),
            ],
            'conflicts'          => $conflicts,
            'builtin'            => [],
        ];
    }

    /**
     * Emergency rules of UpdateGuard (Config/compatibility_rules.php) that apply to this module version: added as
     * known conflicts on top of the module's own metadata (a published release cannot be changed any more).
     */
    public static function applyRules($metadata, array $rules, array $identity, $moduleName, $version)
    {
        $applied = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['project']) || !self::isId(strtolower((string) $rule['project']))) {
                continue;
            }
            $module = (string) ($rule['module'] ?? '');
            if ($module !== $moduleName && strtolower($module) !== $identity['id']) {
                continue;
            }
            if (!empty($rule['versions']) && !VersionRange::satisfies($version, (string) $rule['versions'])) {
                continue;
            }
            $projectVersions = trim((string) ($rule['project_versions'] ?? ''));
            if ($projectVersions === '' || !VersionRange::isValid($projectVersions)) {
                continue;
            }
            if ($metadata === null) {
                $metadata = ['freescout' => '', 'php' => '', 'projects' => [], 'errors' => []];
            }
            $id = strtolower((string) $rule['project']);
            if (!isset($metadata['projects'][$id])) {
                $errors = [];
                $metadata['projects'][$id] = self::project($id, ['relationship' => 'compatibility_tested', 'name' => $rule['name'] ?? $id], $errors);
            }
            $metadata['projects'][$id]['builtin'][] = [
                'versions' => $projectVersions,
                'state'    => strtoupper((string) ($rule['state'] ?? 'INCOMPATIBLE')) === 'DEGRADED' ? 'DEGRADED' : 'INCOMPATIBLE',
                'reason'   => mb_substr((string) ($rule['reason'] ?? ''), 0, 300),
            ];
            $applied++;
        }

        return [$metadata, $applied];
    }
}
