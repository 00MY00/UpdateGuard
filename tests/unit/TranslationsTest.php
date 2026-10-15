<?php

const UG_LANGUAGES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pt-BR'];

/** ":module", ":version"... of a text, sorted */
function ug_params($text)
{
    preg_match_all('/:([a-z_]+)/', $text, $m);
    $p = array_unique($m[1]);
    sort($p);

    return $p;
}

foreach (UG_LANGUAGES as $lang) {
    test('translations: '.$lang.' complete (same keys and parameters as en, no empty text)', function () use ($lang) {
        $base = __DIR__.'/../../UpdateGuard/Resources/lang/';
        check(is_file($base.$lang.'/ug.php'), 'missing file '.$lang.'/ug.php');
        $en = require $base.'en/ug.php';
        $t = require $base.$lang.'/ug.php';
        same([], array_values(array_diff(array_keys($en), array_keys($t))), 'missing in '.$lang);
        same([], array_values(array_diff(array_keys($t), array_keys($en))), 'unknown in '.$lang);
        foreach ($en as $key => $text) {
            check(is_string($t[$key]) && trim($t[$key]) !== '', $lang.'.'.$key.' empty');
            same(ug_params($text), ug_params($t[$key]), $lang.'.'.$key.' parameters');
        }
    });
}

test('translations: every key used in the code exists', function () {
    $base = __DIR__.'/../../UpdateGuard';
    $en = require $base.'/Resources/lang/en/ug.php';

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
    foreach (['update', 'restore', 'health', 'check', 'scan'] as $t) {
        $used[] = 'type_'.$t;
    }
    foreach (['before_update', 'before_restore', 'manual'] as $r) {
        $used[] = 'reason_'.$r;
    }
    foreach (['updates', 'security', 'rollback', 'retention', 'notifications', 'advanced', 'blocking'] as $s) {
        $used[] = 'section_'.$s;
    }
    foreach (['hourly', 'daily', 'full', 'protected', 'never', 'warnings', 'always', 'warn', 'block', 'public', 'premium'] as $c) {
        $used[] = 'choice_'.$c;
    }
    $used[] = 'job_awaiting_decision';
    $used[] = 'section_virustotal';
    // Security Gate: statuses (Ui::secStatus), levels (Ui::risk), decisions of the reports
    foreach (['verified', 'pass', 'warning', 'fail', 'mismatch', 'blocked', 'error', 'notavailable', 'disabled'] as $x) {
        $used[] = 'sec_status_'.$x;
    }
    foreach (['low', 'medium', 'high', 'critical'] as $x) {
        $used[] = 'sec_level_'.$x;
    }
    foreach (['allowed', 'blocked', 'confirm', 'upload_consent', 'overridden'] as $x) {
        $used[] = 'sec_decision_'.$x;
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
