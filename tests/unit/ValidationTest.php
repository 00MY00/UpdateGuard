<?php

use Modules\UpdateGuard\Services\FallbackTestService;
use Modules\UpdateGuard\Services\LogScanner;
use Modules\UpdateGuard\Services\ReportService;

test('generic tests: HealthyTestModule passes structure and php -l', function () {
    $dir = fakemodule('HealthyTestModule', [], ['Http/routes.php' => "<?php\n", 'Resources/views/index.blade.php' => 'ok']);
    $checks = FallbackTestService::structure($dir, 'HealthyTestModule', 'healthytestmodule');
    check(!FallbackTestService::failed($checks), FallbackTestService::summary($checks));
    same('PASS', FallbackTestService::lint($dir, 'phplint')['result']);
    same('PASS', FallbackTestService::summary($checks));
});

test('generic tests: BrokenSyntaxModule fails php -l', function () {
    $dir = fakemodule('BrokenSyntaxModule', [], ['Services/Broken.php' => "<?php\nclass Broken {\n  public function x() {\n"]);
    $r = FallbackTestService::lint($dir, 'phplint');
    same('FAIL', $r['result']);
    check(strpos($r['detail'], 'Broken.php') !== false, 'file named in the detail');
    $checks = FallbackTestService::beforeInstall($dir, 'BrokenSyntaxModule', 'brokensyntaxmodule', 'phplint');
    same('FAIL', $checks['php -l']['result']);
    check(isset($checks['php -l']), 'lint runs before install');
});

test('generic tests: broken ServiceProvider / namespace / identity', function () {
    $missing = fakemodule('BrokenProviderModule', ['providers' => ['Modules\\BrokenProviderModule\\Providers\\Missing']]);
    same('FAIL', FallbackTestService::structure($missing)['ServiceProvider']['result']);

    $foreign = fakemodule('BrokenRouteModule', ['providers' => ['App\\Providers\\AppServiceProvider']]);
    same('FAIL', FallbackTestService::structure($foreign)['namespace']['result'], 'provider outside the module namespace');

    $renamed = fakemodule('HealthFailModule');
    $checks = FallbackTestService::structure($renamed, 'Refresh', 'refresh');
    same('FAIL', $checks['module.json']['result'], 'archive of another module refused');

    $dir = tmpdir('nojson');
    same('FAIL', FallbackTestService::structure($dir)['module.json']['result']);

    $files = fakemodule('FilesModule', ['files' => ['helpers.php', '../../etc/passwd']]);
    same('FAIL', FallbackTestService::structure($files)['autoload']['result']);
});

test('crash detection: HTTP 500 and fatal errors of the module after the update', function () {
    $since = strtotime('2026-10-13 18:01:00');
    $log = tmpdir('logs').'/laravel.log';
    file_put_contents($log, implode("\n", [
        '[2026-10-13 17:59:00] production.ERROR: old error in Modules/Refresh/Http/X.php',
        '[2026-10-13 18:01:12] production.ERROR: Undefined index: x {"exception":"[object] (ErrorException(code: 0): Undefined index at /var/www/html/Modules/Refresh/Http/Controllers/A.php:12)',
        '#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Routing/Router.php(1): x',
        '[2026-10-13 18:01:30] production.ERROR: Class \'Modules\\Refresh\\Missing\' not found {"exception":"[object] (Symfony\\\\Component\\\\Debug\\\\Exception\\\\FatalThrowableError(code: 0))',
        '[2026-10-13 18:02:00] production.ERROR: error in Modules/WorkflowPacks/X.php',
        '[2026-10-13 18:03:00] production.INFO: Modules/Refresh/ info only',
        '',
    ]));
    touch($log, $since + 300);
    $r = LogScanner::scan([$log], 'Refresh', $since);
    same(1, $r['http500'], 'one HTTP 500');
    same(1, $r['fatal'], 'one fatal error');
    same(2, count($r['samples']));
    $r = LogScanner::scan([$log], 'SidebarWebhook', $since);
    same(0, $r['http500'] + $r['fatal'], 'other modules not blamed');
    same(5, count(LogScanner::entries($log)));
});

test('reports: TXT / JSON, native vs generic clearly separated', function () {
    same('UG-20261013-00042', ReportService::updateId(42, strtotime('2026-10-13 12:00')));
    check(ReportService::isUpdateId('UG-20261013-00042'), 'valid id');
    check(!ReportService::isUpdateId('../../etc/passwd'), 'path refused');
    $r = ReportService::make('UG-20261013-00042', 'update', 'Refresh', '1.4.8', '1.4.9', 'Administrator', false);
    ReportService::step($r, 'checksum', 'PASS');
    ReportService::step($r, 'native_health', 'FAIL', 'exit 1');
    ReportService::step($r, 'rollback', 'SUCCESS', '1.4.8 restored');
    $r['generic']['result'] = 'PASS';
    $r['final_version'] = '1.4.8';
    $r['final_status'] = 'healthy';
    $txt = ReportService::toText($r);
    foreach (['Update Report', 'Refresh', '1.4.8 → 1.4.9', 'Administrator (manual)', 'Checksum', 'Native health check', 'Generic validation:', 'AVAILABLE — PASS', 'SUCCESS', 'HEALTHY'] as $needle) {
        check(strpos($txt, $needle) !== false, 'TXT contains '.$needle);
    }
    $json = json_decode(ReportService::toJson($r), true);
    same('updateguard-report/1', $json['format']);
    same('FAIL', $json['steps']['native_health']['result']);
    $dir = tmpdir('reports');
    ReportService::save($dir, $r);
    same('1.4.8', ReportService::load($dir, 'UG-20261013-00042')['final_version']);
    same(null, ReportService::load($dir, '../x'));
    check(strpos(ReportService::toHtml($r), '<pre>') !== false, 'HTML export');
});
