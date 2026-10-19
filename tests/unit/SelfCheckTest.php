<?php

use Modules\UpdateGuard\Services\DownloadService;
use Modules\UpdateGuard\Services\SelfCheck;
use Modules\UpdateGuard\Services\Security\ScanCache;
use Modules\UpdateGuard\Services\Security\VirusTotalScanner;

/*
 * UpdateGuard's own check: installed files against FILES.sha256 of its release, then VirusTotal on the official ZIP.
 * Downloads and VirusTotal are mocked.
 */

/** DownloadService answering from [url => body | Throwable] (no network). */
class UgFakeDownload extends DownloadService
{
    public $answers = [];

    public $asked = [];

    public function __construct(array $answers)
    {
        $this->answers = $answers;
    }

    public function text($url)
    {
        $this->asked[] = $url;
        if (!array_key_exists($url, $this->answers)) {
            throw new \RuntimeException('HTTP 404: '.$url);
        }
        if ($this->answers[$url] instanceof \Throwable) {
            throw $this->answers[$url];
        }

        return (string) $this->answers[$url];
    }
}

/** A small "installed UpdateGuard" folder and its FILES.sha256 (sha256sum output, as tools/build-release.sh writes it). */
function ug_self_install()
{
    $dir = tmpdir('self');
    $files = ['module.json' => '{"name":"UpdateGuard","version":"1.9.0"}', 'Services/Ui.php' => "<?php\n// ui\n", 'Public/js/a.js' => "var a = 1;\n"];
    $manifest = '';
    foreach ($files as $path => $body) {
        @mkdir(dirname($dir.'/'.$path), 0777, true);
        file_put_contents($dir.'/'.$path, $body);
        $manifest .= hash('sha256', $body).'  '.$path."\n";
    }

    return [$dir, $manifest];
}

function ug_self_urls($manifest, $zipHash = null)
{
    $answers = [SelfCheck::releaseUrl('1.9.0', 'FILES.sha256') => $manifest];
    if ($zipHash !== null) {
        $answers[SelfCheck::releaseUrl('1.9.0', 'SHA256SUMS')] = $zipHash.'  UpdateGuard.zip'."\n".str_repeat('b', 64)."  install.sh\n";
    }

    return new UgFakeDownload($answers);
}

test('self check: manifest parsing (sha256sum output, binary marker, unsafe paths left out)', function () {
    $a = str_repeat('a', 64);
    $m = SelfCheck::parseManifest($a."  module.json\r\n".strtoupper($a)." *Services/Ui.php\n".$a."  ./Public/x.js\n".$a."  ../etc/passwd\n".$a."  /abs\nnot a line\n");
    same(['module.json' => $a, 'Services/Ui.php' => $a, 'Public/x.js' => $a], $m);
    same('https://github.com/00MY00/UpdateGuard/releases/download/v1.9.0/FILES.sha256', SelfCheck::releaseUrl('1.9.0', 'FILES.sha256'), 'release of the INSTALLED version, never "latest"');
});

test('self check: files identical to the release are intact, VirusTotal disabled', function () {
    list($dir, $manifest) = ug_self_install();
    $r = SelfCheck::run($dir, '1.9.0', ug_self_urls($manifest));
    same('intact', $r['status']);
    same(3, $r['files']);
    same(null, $r['vt']);
    rmtree($dir);
});

test('self check: a modified, a missing and an added file are reported (never "verified")', function () {
    list($dir, $manifest) = ug_self_install();
    file_put_contents($dir.'/Services/Ui.php', "<?php\n// changed on the server\n");
    unlink($dir.'/Public/js/a.js');
    file_put_contents($dir.'/Public/shell.php', "<?php\n");
    $calls = [];
    $vt = new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(0)]], $calls));
    $r = SelfCheck::run($dir, '1.9.0', ug_self_urls($manifest, str_repeat('c', 64)), $vt);
    same('modified', $r['status']);
    same(['Services/Ui.php'], $r['modified']);
    same(['Public/js/a.js'], $r['missing']);
    same(['Public/shell.php'], $r['added']);
    same(0, count($calls), 'modified files: VirusTotal is not asked about the official package');
    rmtree($dir);
});

test('self check: VirusTotal on the official ZIP (verified, flagged, unknown) with one lookup per version', function () {
    list($dir, $manifest) = ug_self_install();
    $zip = str_repeat('c', 64);
    $cache = new ScanCache(tmpdir('self-cache'));
    $calls = [];
    $vt = new VirusTotalScanner('key', ug_http(['GET https://www.virustotal.com/api/v3/files/'.$zip => [200, ug_vt_stats(0)]], $calls));
    $r = SelfCheck::run($dir, '1.9.0', ug_self_urls($manifest, $zip), $vt, $cache);
    same('verified', $r['status']);
    same($zip, $r['zip_sha256']);
    same(1, count($calls));
    $again = SelfCheck::run($dir, '1.9.0', ug_self_urls($manifest, $zip), $vt, $cache);
    same('verified', $again['status']);
    same(1, count($calls), 'cached: no second lookup (free API quota)');

    $flagged = SelfCheck::run($dir, '1.9.0', ug_self_urls($manifest, $zip), new VirusTotalScanner('key', ug_http(['/files/' => [200, ug_vt_stats(5)]])));
    same('flagged', $flagged['status']);

    $unknown = SelfCheck::run($dir, '1.9.0', ug_self_urls($manifest, $zip), new VirusTotalScanner('key', ug_http(['/files/' => [404, '']])));
    same('intact', $unknown['status'], 'unknown to VirusTotal: still intact, the page proposes to send the official ZIP');
    same(true, $unknown['vt']['unknown']);
    rmtree($dir);
});

test('self check: release files not published (development version, network) is "unavailable", not an alert', function () {
    list($dir, $manifest) = ug_self_install();
    $r = SelfCheck::run($dir, '1.9.0', new UgFakeDownload([]));
    same('unavailable', $r['status']);
    check(strpos($r['error'], 'FILES.sha256') !== false, 'reason given');
    same('unavailable', SelfCheck::run($dir, '1.9.0', new UgFakeDownload([SelfCheck::releaseUrl('1.9.0', 'FILES.sha256') => "<html>Not Found</html>"]))['status']);
    same('unavailable', SelfCheck::run($dir, '', ug_self_urls($manifest))['status']);
    rmtree($dir);
});
