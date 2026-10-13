<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Entities\ModuleHealth;

/**
 * Health of an installed module = native health check (if the module has one) + generic validation.
 *
 * Native protocol (README, "For module developers"):
 *   php artisan <alias>:health [--json]     exit 0 = healthy, anything else = failure;
 *   with --json, a JSON object {"status": "ok", "module": "...", "version": "...", "checks": {...}} kept in the report.
 *
 * Always run in a NEW process. The command name comes from the INSTALLED module.json and must be in the module's
 * own namespace (ModuleDiscoveryService::healthCommandName).
 */
class HealthCheckService
{
    /** @return array ['available','command','result' => PASS|FAIL|NOT AVAILABLE,'detail','json','timeout'] */
    public static function native($moduleName)
    {
        $dir = Paths::modules($moduleName);
        $manifest = ModuleDiscoveryService::manifest($dir);
        $alias = (string) ($manifest['alias'] ?? strtolower($moduleName));
        $command = ModuleDiscoveryService::healthCommandName($manifest, $alias);
        $out = ['available' => false, 'command' => $command, 'result' => 'NOT AVAILABLE', 'detail' => '', 'json' => null, 'timeout' => false];

        // registered in Artisan by the installed code? (asked to a new process: the new version's commands)
        $list = ArtisanRunner::run(['list', '--format=json'], 120);
        $commands = ArtisanRunner::json($list['output']);
        $definition = null;
        foreach ((array) ($commands['commands'] ?? []) as $c) {
            if (($c['name'] ?? '') === $command) {
                $definition = $c;
                break;
            }
        }
        if ($definition === null) {
            return $out;
        }
        $out['available'] = true;
        $args = [$command];
        if (isset($definition['definition']['options']['json'])) {
            $args[] = '--json';
        }
        $r = ArtisanRunner::run($args);
        $out['timeout'] = $r['timeout'];
        $out['json'] = in_array('--json', $args, true) ? ArtisanRunner::json($r['output']) : null;
        $out['result'] = $r['code'] === 0 ? 'PASS' : 'FAIL';
        $out['detail'] = $r['timeout'] ? 'timeout' : 'exit '.$r['code'].($r['code'] !== 0 ? ': '.mb_substr(trim(preg_replace('/\s+/', ' ', $r['output'])), 0, 400) : '');

        return $out;
    }

    /**
     * Full health check of an installed module (Dashboard "Health Check", updateguard:health, after install/restore).
     * Saves the health status on the module row.
     *
     * @return array ['status' => healthy|warning|failed, 'native' => array, 'generic' => array, 'causes' => string[]]
     */
    public static function run($moduleName, $saveStatus = true)
    {
        $native = self::native($moduleName);
        // always: bootstrap, routes and providers of the installed code are the crash detection itself
        // (the "fallback_validation" setting only drives the tests of the staging folder before the install)
        $generic = array_merge(FallbackTestService::structure(Paths::modules($moduleName)), FallbackTestService::probe($moduleName));
        $timeout = !empty($generic['_timeout']) || $native['timeout'];
        unset($generic['_timeout']);
        $causes = [];
        if (isset($generic['bootstrap']) && $generic['bootstrap']['result'] === 'FAIL') {
            $causes[] = 'bootstrap';
        } elseif (FallbackTestService::failed($generic)) {
            $causes[] = 'generic';
        }
        if ($native['result'] === 'FAIL') {
            $causes[] = 'native';
        }
        if ($timeout) {
            $causes[] = 'timeout';
        }
        $warnings = !$native['available'];
        foreach ($generic as $check) {
            if (($check['result'] ?? '') === 'WARN') {
                $warnings = true;
            }
        }
        $status = $causes ? 'failed' : ($warnings ? 'warning' : 'healthy');
        // modules may add their own checks or veto (filter, no hard dependency)
        try {
            $status = (string) \Eventy::filter('updateguard.health_status', $status, $moduleName, $native, $generic);
        } catch (\Throwable $e) {
        }
        if ($saveStatus) {
            $row = ModuleHealth::named($moduleName);
            if ($row) {
                $row->health_status = $status;
                $row->health_checked_at = now();
                $caps = $row->capabilities();
                $caps['native_health'] = $native['available'];
                $caps['health_command'] = $native['command'];
                $row->capabilities = json_encode($caps, JSON_UNESCAPED_SLASHES);
                $row->save();
            }
        }

        return ['status' => $status, 'native' => $native, 'generic' => $generic, 'causes' => $causes];
    }
}
