<?php

use Modules\UpdateGuard\Services\Compatibility\CompatibilityChecker as CC;
use Modules\UpdateGuard\Services\Compatibility\Metadata;
use Modules\UpdateGuard\Services\Compatibility\ProjectRegistry;
use Modules\UpdateGuard\Services\Compatibility\VersionRange;

/*
 * Project relationships / compatibility: ranges, metadata, states of each related project, overall result and
 * decision. Pure PHP: installed projects and platform are given.
 */

/** An installed project as ProjectRegistry returns it. */
function ug_proj($name, $version, array $extra = [])
{
    return array_merge([
        'id' => Metadata::kebab($name), 'name' => $name, 'module' => $name, 'alias' => strtolower($name), 'version' => $version,
        'active' => true, 'repository' => '', 'interop_protocol' => '', 'keys' => [Metadata::kebab($name), strtolower($name)],
    ], $extra);
}

/** RefreshGlobal 1.20.0 declaring Refresh (optional integration). */
function ug_rg_doc(array $refresh = [], array $others = [])
{
    return ['project' => ['id' => 'refresh-global', 'name' => 'RefreshGlobal', 'repository' => 'https://github.com/00MY00/RefreshGlobal'],
        'compatibility' => ['freescout' => '>=1.8.0', 'php' => '>=7.1', 'projects' => array_merge(['refresh' => array_merge([
            'required' => false, 'relationship' => 'optional_integration', 'name' => 'Refresh', 'repository' => 'https://github.com/altmenorg/freescout-refresh',
            'supported_versions' => '>=1.7.0 <2.0.0', 'interop_protocol' => '>=1.0.0 <2.0.0',
            'legacy' => ['supported_versions' => '>=1.5.0 <1.7.0', 'mode' => 'degraded'],
            'conflicts' => ['>=1.9.0 <=1.9.2'],
        ], $refresh)], $others)]];
}

function ug_compat(array $doc, array $installed, array $platform = ['freescout' => '1.8.150', 'php' => '8.2.0'])
{
    $m = Metadata::fromDocuments(['name' => 'RefreshGlobal', 'version' => '1.20.0'], $doc);

    return CC::evaluate(['name' => 'RefreshGlobal', 'id' => 'refresh-global', 'version' => '1.20.0'], $m['metadata'], $m['source'], $installed, $platform);
}

function ug_state(array $r, $id)
{
    foreach ($r['projects'] as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }

    return null;
}

const UG_COMPAT_SETTINGS = ['compat_block_incompatible' => true, 'compat_block_unknown_auto' => false, 'compat_allow_override' => true];

test('compatibility: version ranges (semver forms)', function () {
    foreach ([['1.8.0', '>=1.7.0 <2.0.0', true], ['2.0.0', '>=1.7.0 <2.0.0', false], ['1.9.1', '1.9.0 - 1.9.2', true], ['1.9.3', '>=1.9.0 <=1.9.2', false],
        ['1.9.7', '1.9.x', true], ['1.10.0', '1.9.x', false], ['1.9', '1.9.0', true], ['1.2.5', '^1.2.0', true], ['2.0.0', '^1.2.0', false],
        ['0.3.1', '^0.2.0', false], ['1.2.9', '~1.2.3', true], ['1.3.0', '~1.2.3', false], ['5.0.0', '*', true], ['1.0.0-beta', '>=1.0.0', false],
        ['1.4.0', '<1.0.0 || >=1.4.0', true], ['1.4.0', '>= 1.4', true], ['1.4.0', 'garbage', false], ['1.4.0', '', false]] as $c) {
        same($c[2], VersionRange::satisfies($c[0], $c[1]), $c[0].' in '.$c[1]);
    }
    same(false, VersionRange::isValid('>=1.0 <'));
    same('refresh-global', Metadata::kebab('RefreshGlobal'));
    same('workflow-packs', Metadata::kebab('WorkflowPacks'));
});

test('compatibility: optional project absent is OPTIONAL / NOT INSTALLED and never blocks', function () {
    $r = ug_compat(ug_rg_doc(), []);
    same(CC::NOT_INSTALLED, ug_state($r, 'refresh')['state']);
    same(CC::COMPATIBLE, $r['overall']);
    same('allowed', CC::decide($r, UG_COMPAT_SETTINGS, true)['decision']);
    same('PASS', $r['platform'][0]['result'], 'FreeScout');
    same('PASS', $r['platform'][1]['result'], 'PHP');
});

test('compatibility: required project absent or disabled is MISSING_REQUIRED and blocks', function () {
    $doc = ug_rg_doc(['required' => true, 'relationship' => 'required_dependency']);
    $r = ug_compat($doc, []);
    same(CC::MISSING_REQUIRED, ug_state($r, 'refresh')['state']);
    same(CC::MISSING_REQUIRED, $r['overall']);
    same('blocked', CC::decide($r, UG_COMPAT_SETTINGS, false)['decision']);
    $disabled = ug_compat($doc, [ug_proj('Refresh', '1.8.0', ['active' => false])]);
    same(CC::MISSING_REQUIRED, ug_state($disabled, 'refresh')['state'], 'disabled = missing');
    // old module.json "requires"
    $m = Metadata::normalize(['requires' => ['refresh']], ['requires' => ['refresh']]);
    same('required_dependency', $m['projects']['refresh']['relationship']);
});

test('compatibility: supported version and interop protocol are COMPATIBLE', function () {
    $r = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.8.0', ['interop_protocol' => '1.0.0'])]);
    $p = ug_state($r, 'refresh');
    same(CC::COMPATIBLE, $p['state']);
    same('PASS', $p['interop']);
    same('https://github.com/altmenorg/freescout-refresh', $p['repository']);
    same('SAFE TO UPDATE', CC::decide($r, UG_COMPAT_SETTINGS, false)['label']);
    // the installed project does not declare its protocol: the version decides, interop "NOT DECLARED"
    $p = ug_state(ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.8.0')]), 'refresh');
    same(CC::COMPATIBLE, $p['state']);
    same('NOT DECLARED', $p['interop']);
});

test('compatibility: explicit conflict (Refresh 1.9.1 with RefreshGlobal 1.20.0) is INCOMPATIBLE and blocks', function () {
    $r = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.9.1')]);
    $p = ug_state($r, 'refresh');
    same(CC::INCOMPATIBLE, $p['state']);
    check(strpos($p['reason'], 'RefreshGlobal 1.20.0 declares Refresh >=1.9.0 <=1.9.2 as unsupported') === 0, $p['reason']);
    $d = CC::decide($r, UG_COMPAT_SETTINGS, false);
    same('blocked', $d['decision']);
    same(true, $d['overridable'], 'manual update: "Install anyway" possible');
    same(false, CC::decide($r, UG_COMPAT_SETTINGS, true)['overridable'], 'automatic update: never overridden');
    same('allowed', CC::decide($r, ['compat_block_incompatible' => false] + UG_COMPAT_SETTINGS, false)['decision'], 'blocking rule switched off');
    // relationship "conflict": every version, or the given ranges
    $c = ug_compat(['compatibility' => ['projects' => ['old-theme' => ['relationship' => 'conflict']]]], [ug_proj('OldTheme', '3.0.0')]);
    same(CC::INCOMPATIBLE, ug_state($c, 'old-theme')['state']);
    same(CC::NOT_INSTALLED, ug_state(ug_compat(['compatibility' => ['projects' => ['old-theme' => ['relationship' => 'conflict']]]], []), 'old-theme')['state']);
});

test('compatibility: legacy range is DEGRADED with the legacy message, not blocked', function () {
    $r = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.6.2')]);
    $p = ug_state($r, 'refresh');
    same(CC::DEGRADED, $p['state']);
    same('RefreshGlobal will continue to work, but integration with Refresh will use legacy compatibility mode.', $p['reason']);
    same(CC::DEGRADED, $r['overall']);
    same('allowed', CC::decide($r, UG_COMPAT_SETTINGS, true)['decision']);
});

test('compatibility: incompatible interop protocol (legacy protocol: DEGRADED)', function () {
    $r = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.8.0', ['interop_protocol' => '2.1.0'])]);
    same(CC::INCOMPATIBLE, ug_state($r, 'refresh')['state']);
    same('FAIL', ug_state($r, 'refresh')['interop']);
    $legacy = ug_compat(ug_rg_doc(['legacy' => ['supported_versions' => '>=1.5.0 <1.7.0', 'interop_protocol' => '>=2.0.0 <3.0.0']]),
        [ug_proj('Refresh', '1.8.0', ['interop_protocol' => '2.1.0'])]);
    same(CC::DEGRADED, ug_state($legacy, 'refresh')['state']);
    same('LEGACY', ug_state($legacy, 'refresh')['interop']);
});

test('compatibility: multiple related projects, the worst one decides', function () {
    $doc = ug_rg_doc([], [
        'workflow-packs' => ['relationship' => 'optional_integration', 'supported_versions' => '>=1.10.0'],
        'another-module' => ['relationship' => 'required_dependency', 'supported_versions' => '^1.0'],
        'helper'         => ['relationship' => 'recommended_companion'],
    ]);
    $installed = [ug_proj('Refresh', '1.8.0'), ug_proj('WorkflowPacks', '1.12.0'), ug_proj('AnotherModule', '2.1.0')];
    $r = ug_compat($doc, $installed);
    same(4, count($r['projects']));
    same(CC::COMPATIBLE, ug_state($r, 'workflow-packs')['state'], 'found by its kebab-case id');
    same(CC::INCOMPATIBLE, ug_state($r, 'another-module')['state'], 'required, outside the range');
    same(CC::NOT_INSTALLED, ug_state($r, 'helper')['state']);
    same(CC::INCOMPATIBLE, $r['overall']);
    $d = CC::decide($r, UG_COMPAT_SETTINGS, false);
    same('blocked', $d['decision']);
    same(1, count($d['reasons']));
});

test('compatibility: unknown project or no metadata is UNKNOWN (blocks automatic updates only with the setting)', function () {
    $none = CC::evaluate(['name' => 'X', 'id' => 'x', 'version' => '1.0.0'], null, '', [], ['freescout' => '1.8.0', 'php' => '8.2.0']);
    same(CC::UNKNOWN, $none['overall']);
    same('NOT DECLARED', $none['platform'][0]['result']);
    same('allowed', CC::decide($none, UG_COMPAT_SETTINGS, true)['decision']);
    same('blocked', CC::decide($none, ['compat_block_unknown_auto' => true] + UG_COMPAT_SETTINGS, true)['decision']);
    same('allowed', CC::decide($none, ['compat_block_unknown_auto' => true] + UG_COMPAT_SETTINGS, false)['decision'], 'manual: allowed');
    // optional project outside its range: not declared incompatible
    $out = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '2.3.0')]);
    same(CC::UNKNOWN, ug_state($out, 'refresh')['state']);
    // no supported versions declared
    same(CC::UNKNOWN, ug_state(ug_compat(['compatibility' => ['projects' => ['refresh' => ['relationship' => 'compatibility_tested']]]], [ug_proj('Refresh', '1.8.0')]), 'refresh')['state']);
});

test('compatibility: FreeScout / PHP too old blocks, never overridable', function () {
    $r = ug_compat(ug_rg_doc(), [], ['freescout' => '1.7.9', 'php' => '8.2.0']);
    same('FAIL', $r['platform'][0]['result']);
    same(CC::INCOMPATIBLE, $r['overall']);
    $d = CC::decide($r, UG_COMPAT_SETTINGS, false, true);
    same('blocked', $d['decision']);
    same(false, $d['overridable']);
    // old keys requiredAppVersion / requiredPhpVersion
    $m = Metadata::normalize(['requiredAppVersion' => '1.8.0', 'requiredPhpVersion' => '7.4'], ['requiredAppVersion' => '1.8.0', 'requiredPhpVersion' => '7.4']);
    same('>=1.8.0', $m['freescout']);
    same('>=7.4', $m['php']);
});

test('compatibility: administrator override of an incompatible project', function () {
    $r = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.9.1')]);
    $d = CC::decide($r, UG_COMPAT_SETTINGS, false, true);
    same('allowed', $d['decision']);
    same(true, $d['overridden']);
    same('OVERRIDDEN', $d['label']);
    same('blocked', CC::decide($r, ['compat_allow_override' => false] + UG_COMPAT_SETTINGS, false, true)['decision'], 'overrides disabled');
    same('blocked', CC::decide($r, UG_COMPAT_SETTINGS, true, true)['decision'], 'automatic: no override');
});

test('compatibility: JSON metadata (interop.json first, module.json, invalid entries, identity, emergency rules)', function () {
    $dir = tmpdir('compat');
    file_put_contents($dir.'/module.json', json_encode(['name' => 'RefreshGlobal', 'version' => '1.20.0', 'detailsUrl' => 'https://github.com/00MY00/RefreshGlobal',
        'compatibility' => ['projects' => ['refresh' => ['supported_versions' => '>=1.0.0']]]]));
    $m = Metadata::fromDir($dir);
    same('module.json', $m['source']);
    same('>=1.0.0', $m['metadata']['projects']['refresh']['supported_versions']);
    file_put_contents($dir.'/interop.json', "\xEF\xBB\xBF".json_encode(ug_rg_doc() + ['x' => 1]));
    $m = Metadata::fromDir($dir);
    same('interop.json', $m['source'], 'interop.json preferred');
    same('>=1.7.0 <2.0.0', $m['metadata']['projects']['refresh']['supported_versions']);
    same('refresh-global', $m['identity']['id']);
    same('https://github.com/00MY00/RefreshGlobal', $m['identity']['repository']);
    // invalid ranges / ids are reported, not used
    $bad = Metadata::normalize(['compatibility' => ['freescout' => 'soon', 'projects' => ['Bad Id' => [], 'refresh' => ['supported_versions' => '>=x', 'relationship' => 'friend', 'repository' => 'http://insecure']]]]);
    same('', $bad['freescout']);
    same('', $bad['projects']['refresh']['supported_versions']);
    same('optional_integration', $bad['projects']['refresh']['relationship']);
    same('', $bad['projects']['refresh']['repository'], 'https only');
    same(4, count($bad['errors']));
    // emergency rule: RefreshGlobal 1.20.0 + Refresh 1.9.1 = blocked, even when the metadata says nothing
    list($md, $n) = Metadata::applyRules(null, [['module' => 'RefreshGlobal', 'versions' => '1.20.0', 'project' => 'refresh', 'name' => 'Refresh',
        'project_versions' => '1.9.1', 'state' => 'INCOMPATIBLE', 'reason' => 'blank tickets list']], ['id' => 'refresh-global'], 'RefreshGlobal', '1.20.0');
    same(1, $n);
    $r = CC::evaluate(['name' => 'RefreshGlobal', 'id' => 'refresh-global', 'version' => '1.20.0'], $md, 'UpdateGuard rules', [ug_proj('Refresh', '1.9.1')], ['freescout' => '1.8.0', 'php' => '8.2.0']);
    same(CC::INCOMPATIBLE, ug_state($r, 'refresh')['state']);
    same(true, ug_state($r, 'refresh')['builtin']);
    list(, $n) = Metadata::applyRules(null, [['module' => 'RefreshGlobal', 'versions' => '1.20.0', 'project' => 'refresh', 'project_versions' => '1.9.1']], ['id' => 'refresh-global'], 'RefreshGlobal', '1.21.0');
    same(0, $n, 'another version of the module: rule not applied');
    // installed modules found by their declared id, alias or name
    $reg = ProjectRegistry::fromModules([['name' => 'RefreshGlobal', 'alias' => 'refreshglobal', 'version' => '1.20.0', 'enabled' => true, 'path' => $dir,
        'manifest' => ['name' => 'RefreshGlobal', 'version' => '1.20.0']]]);
    same('refresh-global', $reg[0]['id']);
    same('RefreshGlobal', ProjectRegistry::find($reg, ['id' => 'refreshglobal', 'module' => ''])['module']);
    rmtree($dir);
});

test('compatibility: result stored in the update report (JSON and text)', function () {
    $r = ug_compat(ug_rg_doc(), [ug_proj('Refresh', '1.9.1')]);
    $r['decision'] = CC::decide($r, UG_COMPAT_SETTINGS, false);
    $report = \Modules\UpdateGuard\Services\ReportService::make('UG-20261020-00001', 'update', 'RefreshGlobal', '1.19.0', '1.20.0', 'test', false);
    same(null, $report['compatibility']);
    $report['compatibility'] = $r;
    \Modules\UpdateGuard\Services\ReportService::step($report, 'compatibility', 'FAIL', 'INCOMPATIBLE (package interop.json)');
    $json = json_decode(\Modules\UpdateGuard\Services\ReportService::toJson($report), true);
    same('INCOMPATIBLE', $json['compatibility']['overall']);
    same('blocked', $json['compatibility']['decision']['decision']);
    $text = \Modules\UpdateGuard\Services\ReportService::toText($report);
    foreach (['Project compatibility', 'Compatibility checks (source: interop.json)', 'FreeScout ............ PASS', 'Refresh .............. INCOMPATIBLE',
        'Installed: 1.9.1', 'Known conflicts: >=1.9.0 <=1.9.2', 'Overall compatibility:    INCOMPATIBLE', 'Result:                   BLOCKED'] as $needle) {
        check(strpos($text, $needle) !== false, 'report text contains "'.$needle.'"');
    }
    $absent = ug_compat(ug_rg_doc(), []);
    check(strpos(implode("\n", \Modules\UpdateGuard\Services\ReportService::compatibilityLines($absent)), 'Refresh .............. OPTIONAL / NOT INSTALLED') !== false, 'optional absent');
});
