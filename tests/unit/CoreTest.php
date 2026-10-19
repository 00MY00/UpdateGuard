<?php

use Modules\UpdateGuard\Services\ChecksumService;
use Modules\UpdateGuard\Services\DownloadService;
use Modules\UpdateGuard\Services\LockService;
use Modules\UpdateGuard\Services\MigrationDiff;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\RetentionPolicy;
use Modules\UpdateGuard\Services\SafetyLevel;
use Modules\UpdateGuard\Services\VersionService;

test('versions: normalize, compare, newer', function () {
    same('1.4.8', VersionService::normalize("v1.4.8\n"));
    same('1.5.0-beta.1', VersionService::normalize('1.5.0-beta.1'));
    same('', VersionService::normalize('<html>404</html>'));
    same('', VersionService::normalize(''));
    check(VersionService::isNewer('1.4.10', '1.4.9'), '1.4.10 > 1.4.9');
    check(!VersionService::isNewer('1.4.8', '1.4.8'), 'same version is not newer');
    check(!VersionService::isNewer('garbage', '1.0.0'), 'invalid version never newer');
    same(-1, VersionService::compare('', '1.0'));
    same('1.0_x', VersionService::slug('1.0/x'));
});

test('SHA-256: SHA256SUMS parsing and comparison (invalid checksum = abort)', function () {
    $sums = str_repeat('a', 64)."  Refresh.zip\n".str_repeat('B', 64)." *module.json\r\nnot a line\n";
    $parsed = ChecksumService::parseSums($sums);
    same(str_repeat('a', 64), $parsed['Refresh.zip']);
    same(str_repeat('b', 64), $parsed['module.json'], 'binary mode marker and upper case');
    same('', ChecksumService::expectedFor($sums, 'Other.zip'));
    $file = tmpdir('sha').'/f.zip';
    file_put_contents($file, 'content');
    $hash = ChecksumService::hashFile($file);
    same(hash('sha256', 'content'), $hash);
    check(ChecksumService::matches(strtoupper($hash), $hash), 'case-insensitive match');
    check(!ChecksumService::matches(str_repeat('0', 64), $hash), 'invalid checksum refused');
    check(!ChecksumService::matches('', $hash), 'empty expected hash never matches');
});

test('SHA-256: tree fingerprint changes when a file changes', function () {
    $dir = fakemodule('TreeModule');
    $a = ChecksumService::hashTree($dir);
    same($a, ChecksumService::hashTree($dir), 'stable');
    file_put_contents($dir.'/module.json', '{"name":"TreeModule"}');
    check($a !== ChecksumService::hashTree($dir), 'modified file detected');
    same('', ChecksumService::hashTree($dir.'/missing'));
});

test('download: https only, SHA256SUMS next to the ZIP', function () {
    check(DownloadService::isAllowedUrl('https://github.com/a/b/releases/latest/download/B.zip'), 'https allowed');
    check(!DownloadService::isAllowedUrl('http://example.test/B.zip'), 'plain http refused');
    check(!DownloadService::isAllowedUrl('file:///etc/passwd'), 'file:// refused');
    check(!DownloadService::isAllowedUrl('ftp://example.test/B.zip'), 'ftp refused');
    $m = ['latestVersionZipUrl' => 'https://github.com/a/b/releases/latest/download/B.zip'];
    same('https://github.com/a/b/releases/latest/download/SHA256SUMS', DownloadService::sumsUrl($m));
    same('B.zip', DownloadService::zipName($m));
    same('https://x.test/sums', DownloadService::sumsUrl($m + ['sha256sumsUrl' => 'https://x.test/sums']));
    same('', DownloadService::sumsUrl([]));
});

test('safety levels: FULL / PROTECTED / GENERIC / UNSUPPORTED', function () {
    $base = ['version_url' => true, 'zip_url' => true];
    same(SafetyLevel::FULL, SafetyLevel::compute($base + ['sha256' => true, 'native_health' => true]));
    same(SafetyLevel::PROTECTED_LEVEL, SafetyLevel::compute($base + ['sha256' => true]));
    same(SafetyLevel::PROTECTED_LEVEL, SafetyLevel::compute($base + ['native_health' => true]));
    same(SafetyLevel::GENERIC, SafetyLevel::compute($base));
    same(SafetyLevel::UNSUPPORTED, SafetyLevel::compute(['zip_url' => true, 'sha256' => true, 'native_health' => true]));
    same(SafetyLevel::UNSUPPORTED, SafetyLevel::compute($base + ['official' => true]));
    same(SafetyLevel::UNSUPPORTED, SafetyLevel::compute($base + ['self' => true, 'sha256' => true, 'native_health' => true]), 'UpdateGuard never updates itself');
    check(SafetyLevel::allowsAutomatic('full', 'full'), 'full allowed');
    check(!SafetyLevel::allowsAutomatic('protected', 'full'), 'protected needs the setting');
    check(SafetyLevel::allowsAutomatic('protected', 'protected'), 'protected allowed by the setting');
    check(!SafetyLevel::allowsAutomatic('generic', 'protected'), 'generic never automatic');
    check(!SafetyLevel::allowsAutomatic('generic', 'generic'), 'minimum cannot be lowered to generic');
    check(!SafetyLevel::allowsAutomatic('unsupported', 'unsupported'), 'unsupported never automatic');
});

test('health command: only in the module\'s own namespace', function () {
    same('refresh:health', ModuleDiscoveryService::healthCommandName([], 'refresh'));
    same('refresh:health-check', ModuleDiscoveryService::healthCommandName(['healthCommand' => 'refresh:health-check'], 'refresh'));
    same('refresh:health', ModuleDiscoveryService::healthCommandName(['healthCommand' => 'migrate:fresh'], 'refresh'), 'foreign command refused');
    same('refresh:health', ModuleDiscoveryService::healthCommandName(['healthCommand' => 'refresh:x; rm -rf /'], 'refresh'), 'shell characters refused');
    same('refresh:health', ModuleDiscoveryService::healthCommandName(['healthCommand' => 'refreshglobal:health'], 'refresh'), 'other module with same prefix refused');
});

test('retention: keep N AND max age, never the last known-good backup', function () {
    $now = 1800000000;
    $day = 86400;
    $b = function ($id, $daysAgo, $good = false, $locked = false) use ($now, $day) {
        return ['id' => $id, 'created_at' => $now - $daysAgo * $day, 'known_good' => $good, 'locked' => $locked];
    };
    $list = [$b(1, 1), $b(2, 2), $b(3, 3), $b(4, 4), $b(5, 5)];
    same([4, 5], RetentionPolicy::select($list, 3, 0, $now), 'keep last 3');
    same([], RetentionPolicy::select($list, 0, 0, $now), 'unlimited');
    same([3, 4, 5], RetentionPolicy::select($list, 0, 2, $now), 'older than 2 days');
    $list = [$b(1, 1), $b(2, 2), $b(3, 300, true), $b(4, 400, true)];
    same([2, 4], RetentionPolicy::select($list, 1, 180, $now), 'most recent known-good kept even if old');
    $list = [$b(1, 1), $b(2, 2, false, true), $b(3, 3)];
    same([3], RetentionPolicy::select($list, 1, 0, $now), 'backup in use kept');
});

test('lock: one operation at a time, abandoned lock cleaned', function () {
    $dir = tmpdir('locks');
    $a = new LockService($dir, 60);
    check($a->acquire('Refresh', 'UG-20261013-00001'), 'first acquire');
    same('Refresh', $a->current()['module']);
    same('UG-20261013-00001', $a->current()['update_id']);
    $b = new LockService($dir, 60);
    check(!$b->acquire('Other', 'UG-20261013-00002'), 'second operation refused while the first holds the lock');
    $a->release();
    check(!is_file($dir.'/global.json'), 'released');
    check($b->acquire('Other', 'UG-20261013-00002'), 'acquired after release');
    $b->release();

    // abandoned: lock file left by a dead process
    file_put_contents($dir.'/global.json', json_encode(['pid' => 999999, 'module' => 'X', 'date' => 'old', 'time' => time() - 10, 'update_id' => 'UG-20261013-00003']));
    $c = new LockService($dir, 60);
    $c->pidAlive = function () {
        return false;
    };
    check($c->isAbandoned(), 'dead pid = abandoned');
    same('UG-20261013-00003', $c->clearAbandoned()['update_id']);
    check($c->acquire('Y', 'UG-20261013-00004'), 'acquire after cleanup');
    $c->release();

    // too old (hung but alive process)
    file_put_contents($dir.'/global.json', json_encode(['pid' => 1, 'time' => time() - 7200]));
    $d = new LockService($dir, 60);
    $d->pidAlive = function () {
        return true;
    };
    check($d->isAbandoned(), 'older than the timeout = abandoned');
    @unlink($dir.'/global.json');
});

test('migrations: new migrations disable the automatic rollback', function () {
    $old = fakemodule('MigrationTestModule', [], ['Database/Migrations/2026_01_01_000000_a.php' => '<?php']);
    $new = fakemodule('MigrationTestModule', [], [
        'Database/Migrations/2026_01_01_000000_a.php' => '<?php',
        'Database/Migrations/2026_02_01_000000_b.php' => '<?php',
    ]);
    same(['2026_02_01_000000_b.php'], MigrationDiff::added($old, $new));
    same([], MigrationDiff::added($new, $new));
    same('file-safe', MigrationDiff::rollbackSafety([], []));
    same('unknown', MigrationDiff::rollbackSafety(['b.php'], []));
    same('database-safe', MigrationDiff::rollbackSafety(['b.php'], ['updateGuard' => ['migrations' => 'backward-compatible']]));
});

test('republication: same version with another SHA-256 is reported, never silently accepted', function () {
    $a = str_repeat('a', 64);
    $b = str_repeat('b', 64);
    list($known, $r) = \Modules\UpdateGuard\Services\RepublishGuard::track([], '1.2.0', $a);
    same(['1.2.0' => $a], $known);
    same(null, $r, 'first publication');
    list($known, $r) = \Modules\UpdateGuard\Services\RepublishGuard::track($known, '1.2.0', strtoupper($a));
    same(null, $r, 'same content again');
    list($known2, $r) = \Modules\UpdateGuard\Services\RepublishGuard::track($known, '1.2.0', $b);
    same(['version' => '1.2.0', 'old' => $a, 'new' => $b], $r, 'republished');
    same($a, $known2['1.2.0'], 'the first hash stays the reference');
    list($known3, $r) = \Modules\UpdateGuard\Services\RepublishGuard::track($known2, '1.3.0', $b);
    same(null, $r, 'a new version number is not a republication');
    same($b, \Modules\UpdateGuard\Services\RepublishGuard::accept($known3, '1.2.0', $b)['1.2.0'], 'accepted after install');
    list(, $r) = \Modules\UpdateGuard\Services\RepublishGuard::track($known, '1.2.0', '');
    same(null, $r, 'no SHA256SUMS: nothing to compare');
    $many = [];
    for ($i = 0; $i < 30; $i++) {
        list($many) = \Modules\UpdateGuard\Services\RepublishGuard::track($many, '1.0.'.$i, hash('sha256', (string) $i));
    }
    same(\Modules\UpdateGuard\Services\RepublishGuard::KEEP, count($many), 'history bounded');
    check(isset($many['1.0.29']), 'most recent kept');
});

test('page: one progress bar per operation even when the frame and the page both ask for it', function () {
    same(true, \Modules\UpdateGuard\Services\Ui::firstProgress(50), 'page');
    same(false, \Modules\UpdateGuard\Services\Ui::firstProgress(50), 'frame: same operation, not shown again');
    same(true, \Modules\UpdateGuard\Services\Ui::firstProgress(51), 'another operation');
    same(false, \Modules\UpdateGuard\Services\Ui::firstProgress(0));
});

test('versions: latestVersionUrl answering plain text or a module.json (as FreeScout reads it)', function () {
    $v = '\Modules\UpdateGuard\Services\VersionService';
    same('1.6.0', $v::fromResponse("1.6.0\n"));
    same('1.6.0', $v::fromResponse('v1.6.0'));
    same('1.6.0', $v::fromResponse("\xEF\xBB\xBF{\n  \"name\": \"Refresh\",\n  \"version\": \"1.6.0\",\n  \"providers\": [\"Modules\\Refresh\\Providers\\RefreshServiceProvider\"]\n}"), 'module.json of a release');
    same('2.0.1', $v::fromResponse('{"name": "X", "version": "2.0.1", }'), 'malformed JSON: FreeScout pattern');
    same('', $v::fromResponse('<html><body>Not Found</body></html>'));
    same('', $v::fromResponse('{"name": "X"}'), 'module.json without version');
    same('', $v::fromResponse('{"version": "latest"}'));
    same('', $v::fromResponse(''));
});
