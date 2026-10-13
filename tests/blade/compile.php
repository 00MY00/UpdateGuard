<?php

/*
 * Compiles every Blade template of the module with Laravel's Blade compiler and runs php -l on the result
 * (catches unbalanced @if / @foreach, broken {{ }} expressions...). Needs illuminate/view (CI installs 5.5.*,
 * the version used by FreeScout):
 *
 *   composer require --working-dir=/tmp/blade illuminate/view:5.5.*
 *   php tests/blade/compile.php /tmp/blade/vendor/autoload.php
 */

$autoload = isset($argv[1]) ? $argv[1] : __DIR__.'/vendor/autoload.php';
require $autoload;

$views = realpath(__DIR__.'/../../UpdateGuard/Resources/views');
$cache = sys_get_temp_dir().'/ug-blade-'.getmypid();
@mkdir($cache, 0777, true);
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), $cache);
$failed = 0;
$count = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (substr($file->getFilename(), -10) !== '.blade.php') {
        continue;
    }
    $count++;
    $compiled = $cache.'/'.md5($file->getPathname()).'.php';
    file_put_contents($compiled, $compiler->compileString(file_get_contents($file->getPathname())));
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($compiled).' 2>&1', $out, $code);
    $name = substr($file->getPathname(), strlen($views) + 1);
    if ($code !== 0) {
        $failed++;
        echo "  FAIL  $name\n        ".implode(' ', $out)."\n";
    } else {
        echo "  PASS  $name\n";
    }
    $out = [];
}
echo "\n$count template(s), $failed failure(s)\n";
exit($failed ? 1 : 0);
