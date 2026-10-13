<?php

/*
 * Unit tests of the parts of UpdateGuard that do not need FreeScout (versions, SHA-256, archive inspection,
 * safety levels, retention, lock, migrations, crash detection in logs, reports, generic structure / lint tests).
 * Plain PHP >= 7.1 with the zip and mbstring extensions, no Composer:
 *
 *   php tests/unit/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(function ($class) {
    $prefix = 'Modules\\UpdateGuard\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__.'/../../UpdateGuard/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$GLOBALS['ug_tests'] = [];
$GLOBALS['ug_assertions'] = 0;

function test($name, callable $fn)
{
    $GLOBALS['ug_tests'][$name] = $fn;
}

function check($condition, $message)
{
    $GLOBALS['ug_assertions']++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same($expected, $actual, $message = '')
{
    check($expected === $actual, ($message !== '' ? $message.' — ' : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
}

/** Fresh temporary folder, removed at the end of the run. */
function tmpdir($name)
{
    $dir = sys_get_temp_dir().'/ug-tests-'.getmypid().'/'.$name.'-'.mt_rand();
    mkdir($dir, 0777, true);

    return str_replace('\\', '/', $dir);
}

function rmtree($dir)
{
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);

        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') {
            rmtree($dir.'/'.$f);
        }
    }
    @rmdir($dir);
}

/** Zip with the given entries: name => content (null = directory), or name => ['symlink' => target]. */
function makezip(array $entries)
{
    $file = tmpdir('zip').'/a.zip';
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        if ($content === null) {
            $zip->addEmptyDir(rtrim($name, '/'));
        } elseif (is_array($content)) {
            $zip->addFromString($name, $content['symlink']);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777 << 16));
        } else {
            $zip->addFromString($name, $content);
        }
    }
    $zip->close();

    return $file;
}

/** Fake module folder (Info.md § 42: HealthyTestModule, BrokenSyntaxModule, ...). */
function fakemodule($name, array $override = [], array $files = [])
{
    $dir = tmpdir('mod').'/'.$name;
    mkdir($dir.'/Providers', 0777, true);
    $manifest = array_merge([
        'name'      => $name,
        'alias'     => strtolower($name),
        'version'   => '1.0.0',
        'providers' => ['Modules\\'.$name.'\\Providers\\'.$name.'ServiceProvider'],
    ], $override);
    file_put_contents($dir.'/module.json', json_encode($manifest));
    file_put_contents($dir.'/Providers/'.$name.'ServiceProvider.php', "<?php\n\nnamespace Modules\\".$name."\\Providers;\n\nclass ".$name."ServiceProvider\n{\n}\n");
    foreach ($files as $path => $content) {
        if (!is_dir(dirname($dir.'/'.$path))) {
            mkdir(dirname($dir.'/'.$path), 0777, true);
        }
        file_put_contents($dir.'/'.$path, $content);
    }

    return $dir;
}

/** php -l with the PHP running the tests (ArtisanRunner::lint needs FreeScout). */
function phplint($file)
{
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);

    return ['code' => $code, 'output' => implode("\n", $out)];
}

foreach (glob(__DIR__.'/*Test.php') as $file) {
    require $file;
}

$failed = 0;
foreach ($GLOBALS['ug_tests'] as $name => $fn) {
    try {
        $fn();
        echo "  PASS  $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name\n        ".$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\n";
    }
}
rmtree(sys_get_temp_dir().'/ug-tests-'.getmypid());
echo "\n".count($GLOBALS['ug_tests']).' tests, '.$GLOBALS['ug_assertions'].' assertions, '.$failed." failure(s)\n";
exit($failed ? 1 : 0);
