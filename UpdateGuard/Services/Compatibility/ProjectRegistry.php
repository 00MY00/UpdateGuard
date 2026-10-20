<?php

namespace Modules\UpdateGuard\Services\Compatibility;

/**
 * Projects installed in FreeScout, as compatibility metadata names them: a stable id (declared in interop.json /
 * module.json "project", or the module name in kebab-case), plus the alias and the folder name so that modules that
 * declare nothing (third-party modules) can still be found.
 */
class ProjectRegistry
{
    /**
     * @param array $modules ModuleDiscoveryService::installed() rows (name, alias, path, version, enabled, manifest)
     * @return array rows: id, name, module, alias, version, active, repository, interop_protocol, keys
     */
    public static function fromModules(array $modules)
    {
        $out = [];
        foreach ($modules as $m) {
            $identity = Metadata::fromDir((string) ($m['path'] ?? ''), (array) ($m['manifest'] ?? []))['identity'];
            $keys = array_values(array_unique(array_filter([
                $identity['id'],
                strtolower((string) ($m['alias'] ?? '')),
                strtolower((string) ($m['name'] ?? '')),
                Metadata::kebab((string) ($m['name'] ?? '')),
            ])));
            $out[] = [
                'id'               => $identity['id'],
                'name'             => (string) ($m['name'] ?? ''),
                'module'           => (string) ($m['name'] ?? ''),
                'alias'            => strtolower((string) ($m['alias'] ?? '')),
                'version'          => (string) ($m['version'] ?? ''),
                'active'           => (bool) ($m['enabled'] ?? false),
                'repository'       => $identity['repository'],
                'interop_protocol' => $identity['interop_protocol'],
                'keys'             => $keys,
            ];
        }

        return $out;
    }

    /** The installed project a declaration designates ("module" first, then the id), or null. */
    public static function find(array $installed, array $project)
    {
        $module = strtolower((string) ($project['module'] ?? ''));
        if ($module !== '') {
            foreach ($installed as $row) {
                if ($module === strtolower($row['module']) || $module === $row['alias']) {
                    return $row;
                }
            }
        }
        foreach ($installed as $row) {
            if ($row['id'] === $project['id']) {
                return $row;
            }
        }
        foreach ($installed as $row) {
            if (in_array($project['id'], $row['keys'], true)) {
                return $row;
            }
        }

        return null;
    }
}
