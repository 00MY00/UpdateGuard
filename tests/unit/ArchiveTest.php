<?php

use Modules\UpdateGuard\Services\ZipInspector;

$module = '{"name":"Refresh","alias":"refresh","version":"1.4.9"}';

test('archive: release layout accepted and extracted', function () use ($module) {
    $zip = makezip(['Refresh/module.json' => $module, 'Refresh/Providers/' => null, 'Refresh/Providers/P.php' => '<?php']);
    $r = ZipInspector::inspect($zip);
    check($r['ok'], implode('; ', $r['errors']));
    same('Refresh/', $r['root']);
    $target = tmpdir('stage').'/module';
    ZipInspector::extract($zip, $r['root'], $target);
    check(is_file($target.'/module.json'), 'module.json extracted');
    check(is_file($target.'/Providers/P.php'), 'sub folder extracted');
});

test('archive: GitHub branch layouts accepted', function () use ($module) {
    $r = ZipInspector::inspect(makezip(['Refresh-main/module.json' => $module, 'Refresh-main/README.md' => 'x']));
    check($r['ok'], implode('; ', $r['errors']));
    same('Refresh-main/', $r['root']);
    $r = ZipInspector::inspect(makezip(['Repo-main/README.md' => 'x', 'Repo-main/Refresh/module.json' => $module]));
    check($r['ok'], implode('; ', $r['errors']));
    same('Repo-main/Refresh/', $r['root']);
});

test('archive: path traversal, absolute paths, symlinks refused (malicious zip)', function () use ($module) {
    $cases = [
        'traversal'     => ['Refresh/module.json' => $module, 'Refresh/../../evil.php' => '<?php'],
        'absolute'      => ['Refresh/module.json' => $module, '/etc/evil' => 'x'],
        'windows drive' => ['Refresh/module.json' => $module, 'C:/evil' => 'x'],
        'symlink'       => ['Refresh/module.json' => $module, 'Refresh/link' => ['symlink' => '/etc/passwd']],
        'two tops'      => ['Refresh/module.json' => $module, 'UpdateGuard/module.json' => '{}'],
        'root file'     => ['Refresh/module.json' => $module, 'evil.php' => '<?php'],
        'no module'     => ['Refresh/README.md' => 'x'],
        'two modules'   => ['Repo/A/module.json' => $module, 'Repo/B/module.json' => $module],
    ];
    foreach ($cases as $label => $entries) {
        $r = ZipInspector::inspect(makezip($entries));
        check(!$r['ok'], $label.' must be refused');
        check($r['errors'] !== [], $label.' has a reason');
    }
});

test('archive: corrupt zip refused', function () {
    $file = tmpdir('corrupt').'/c.zip';
    file_put_contents($file, "PK\x03\x04 this is not a zip");
    $r = ZipInspector::inspect($file);
    check(!$r['ok'], 'corrupt refused');
    $truncated = makezip(['Refresh/module.json' => '{}', 'Refresh/big.txt' => str_repeat('x', 50000)]);
    file_put_contents($truncated, substr(file_get_contents($truncated), 0, 200));
    check(!ZipInspector::inspect($truncated)['ok'], 'truncated refused');
});

test('archive: entry names checked', function () {
    same('', ZipInspector::badName('Refresh/a/b.php'));
    check(ZipInspector::badName('a/../b') !== '', '..');
    check(ZipInspector::badName('..\\b') !== '', 'backslash ..');
    check(ZipInspector::badName("a\0b") !== '', 'NUL');
    check(ZipInspector::badName('\\\\server\\share') !== '', 'UNC');
    same('', ZipInspector::badName('Refresh/..hidden'), 'a name starting with .. is not traversal');
});
