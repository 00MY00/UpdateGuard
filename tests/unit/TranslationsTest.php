<?php

test('translations: en and fr have the same keys, every key used exists', function () {
    $base = __DIR__.'/../../UpdateGuard';
    $en = require $base.'/Resources/lang/en/ug.php';
    $fr = require $base.'/Resources/lang/fr/ug.php';
    same([], array_values(array_diff(array_keys($en), array_keys($fr))), 'missing in fr');
    same([], array_values(array_diff(array_keys($fr), array_keys($en))), 'missing in en');

    $used = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (substr($file->getFilename(), -4) === '.php') {
            preg_match_all('/updateguard::ug\.([a-z0-9_]+)/', file_get_contents($file->getPathname()), $m);
            $used = array_merge($used, $m[1]);
        }
    }
    // keys built at run time
    $statuses = ['queued', 'checking', 'downloading', 'verifying', 'staging', 'backing_up', 'installing', 'clearing_cache',
        'health_check', 'observing', 'successful', 'failed', 'rolling_back', 'rolled_back', 'rollback_failed', 'blocked'];
    foreach ($statuses as $s) {
        $used[] = 'job_'.$s;
    }
    foreach (['update', 'restore', 'health', 'check'] as $t) {
        $used[] = 'type_'.$t;
    }
    foreach (['before_update', 'before_restore', 'manual'] as $r) {
        $used[] = 'reason_'.$r;
    }
    foreach (['updates', 'security', 'rollback', 'retention', 'notifications', 'advanced'] as $s) {
        $used[] = 'section_'.$s;
    }
    foreach (['hourly', 'daily', 'full', 'protected'] as $c) {
        $used[] = 'choice_'.$c;
    }
    $config = (function () use ($base) {
        if (!function_exists('env')) {
            function env($k, $d = null)
            {
                return $d;
            }
        }

        return require $base.'/Config/config.php';
    })();
    foreach (array_keys($config['defaults']) as $name) {
        $used[] = 'set_'.$name;
    }
    foreach (\Modules\UpdateGuard\Services\Ui::STATES as $s) {
        $used[] = $s[1];
    }
    foreach (\Modules\UpdateGuard\Services\Ui::LEVELS as $s) {
        $used[] = $s[1];
    }
    $used = array_unique(array_filter($used, function ($k) {
        return substr($k, -1) !== '_';
    }));
    same([], array_values(array_diff($used, array_keys($en))), 'keys used but not translated');
});
