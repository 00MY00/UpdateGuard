<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Optional VirusTotal provider (API v3), off by default. Pure PHP: the HTTP client is injected
 * (callable($method, $url, array $options) => ['status' => int, 'body' => string]); UpdateGuard passes a Guzzle client
 * with FreeScout's proxy / SSL options, the tests pass mocked responses.
 *
 * Privacy: lookupHash() only sends the SHA-256. scanFile() uploads the package: it is only called when the
 * administrator agreed for this update, or enabled "Upload unknown files automatically". Files uploaded to
 * VirusTotal are shared with its security partners.
 *
 * Classification of the malicious detections (configurable): 0 PASS, >= warning WARNING, >= high HIGH,
 * >= critical CRITICAL; status BLOCKED only from the block threshold (default 3). Suspicious detections alone give
 * WARNING. Never throws: network errors, invalid key, quota, timeout => status ERROR.
 */
class VirusTotalScanner implements SecurityScannerProviderInterface
{
    const BASE = 'https://www.virustotal.com/api/v3';
    const MAX_UPLOAD = 33554432; // 32 MB: limit of the simple upload endpoint
    const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @var string */
    protected $key;

    /** @var callable */
    protected $http;

    /** @var array */
    protected $thresholds;

    /** @var callable seconds => void (tests: no real wait) */
    public $sleep;

    /** @var int analysis polls after an upload */
    public $polls = 20;

    public function __construct($apiKey, callable $http, array $thresholds = [])
    {
        $this->key = trim((string) $apiKey);
        $this->http = $http;
        $this->thresholds = array_merge(['warning' => 1, 'high' => 3, 'critical' => 6, 'block' => 3], array_filter($thresholds, function ($v) {
            return (int) $v > 0;
        }));
        $this->sleep = function ($seconds) {
            sleep($seconds);
        };
    }

    public function getName(): string
    {
        return 'VirusTotal';
    }

    public function isConfigured(): bool
    {
        return $this->key !== '';
    }

    public function supportsUpload(): bool
    {
        return true;
    }

    protected function call($method, $path, array $options = [])
    {
        $options['headers'] = array_merge(['x-apikey' => $this->key, 'accept' => 'application/json'], $options['headers'] ?? []);

        return call_user_func($this->http, $method, self::BASE.$path, $options);
    }

    /** Message of a failed request: quota / rate limit of the local budget as is, otherwise "unreachable: ...". */
    protected static function failure(\Throwable $e)
    {
        $m = $e->getMessage();

        return (stripos($m, 'quota') !== false || stripos($m, 'rate limit') !== false) ? $m : 'unreachable: '.$m;
    }

    protected function error($message)
    {
        return ScanResult::make($this->getName(), ScanResult::ERROR, $message);
    }

    protected function httpError(array $r)
    {
        $messages = [401 => 'invalid API key', 403 => 'API key not allowed', 429 => 'quota exceeded (too many requests)'];

        return $this->error($messages[$r['status']] ?? 'HTTP '.$r['status']);
    }

    public function lookupHash(string $sha256): ScanResult
    {
        if (!$this->isConfigured()) {
            return $this->error('API key missing');
        }
        try {
            $r = $this->call('GET', '/files/'.strtolower($sha256));
        } catch (\Throwable $e) {
            return $this->error(self::failure($e));
        }
        if ($r['status'] === 404) {
            return ScanResult::make($this->getName(), ScanResult::NOT_AVAILABLE, 'file not known by VirusTotal', ['unknown' => true]);
        }
        if ($r['status'] !== 200) {
            return $this->httpError($r);
        }
        $json = json_decode((string) $r['body'], true);
        $stats = $json['data']['attributes']['last_analysis_stats'] ?? null;
        if (!is_array($stats)) {
            return $this->error('unexpected answer');
        }

        return $this->classify($stats, 'https://www.virustotal.com/gui/file/'.strtolower($sha256));
    }

    public function scanFile(string $path): ScanResult
    {
        if (!$this->isConfigured()) {
            return $this->error('API key missing');
        }
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > self::MAX_UPLOAD) {
            return $this->error('file too large for an upload ('.round($size / 1048576, 1).' MB, limit 32 MB)');
        }
        try {
            $r = $this->call('POST', '/files', ['multipart' => [['name' => 'file', 'path' => $path, 'filename' => basename($path)]]]);
            if ($r['status'] !== 200) {
                return $this->httpError($r);
            }
            $id = json_decode((string) $r['body'], true)['data']['id'] ?? '';
            if ($id === '') {
                return $this->error('unexpected answer to the upload');
            }
            for ($i = 0; $i < $this->polls; $i++) {
                $a = $this->call('GET', '/analyses/'.rawurlencode($id));
                if ($a['status'] !== 200) {
                    return $this->httpError($a);
                }
                $json = json_decode((string) $a['body'], true);
                if (($json['data']['attributes']['status'] ?? '') === 'completed') {
                    return $this->classify((array) ($json['data']['attributes']['stats'] ?? []), '');
                }
                call_user_func($this->sleep, 15);
            }
        } catch (\Throwable $e) {
            return $this->error(self::failure($e));
        }

        return $this->error('analysis not finished in time (timeout)');
    }

    /** Stats => status and level, from the configured thresholds. */
    public function classify(array $stats, $link)
    {
        $stats = [
            'malicious'  => (int) ($stats['malicious'] ?? 0),
            'suspicious' => (int) ($stats['suspicious'] ?? 0),
            'harmless'   => (int) ($stats['harmless'] ?? 0),
            'undetected' => (int) ($stats['undetected'] ?? 0),
        ];
        $m = $stats['malicious'];
        $t = $this->thresholds;
        if ($m >= $t['critical']) {
            $level = Rules::CRITICAL;
        } elseif ($m >= $t['high']) {
            $level = Rules::HIGH;
        } elseif ($m >= $t['warning'] || $stats['suspicious'] > 0) {
            $level = Rules::MEDIUM;
        } else {
            $level = Rules::LOW;
        }
        $status = $m >= $t['block'] ? ScanResult::BLOCKED : ($level === Rules::LOW ? ScanResult::PASS : ScanResult::WARNING);

        return ScanResult::make($this->getName(), $status, $m.' malicious, '.$stats['suspicious'].' suspicious', [
            'stats' => $stats, 'level' => $level, 'link' => $link,
        ]);
    }

    public function testConnection(): ConnectionResult
    {
        if (!$this->isConfigured()) {
            return new ConnectionResult(false, 'API key missing');
        }
        try {
            // a well-known hash (empty file): 200 or 404 both prove that the key works
            $r = $this->call('GET', '/files/'.self::EMPTY_SHA256);
        } catch (\Throwable $e) {
            return new ConnectionResult(false, self::failure($e));
        }
        if ($r['status'] === 200 || $r['status'] === 404) {
            return new ConnectionResult(true, 'OK');
        }

        return new ConnectionResult(false, $this->httpError($r)->message);
    }
}
