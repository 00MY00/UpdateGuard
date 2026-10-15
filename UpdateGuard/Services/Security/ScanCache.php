<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Cache of external scan results, keyed by provider + SHA-256 of the package: an identical file is not sent or looked
 * up again while the result is fresh. Only definitive answers are cached (not errors, not "unknown"). Pure PHP.
 * storage/updateguard/security/cache/<provider>-<sha256>.json
 */
class ScanCache
{
    /** @var string */
    protected $dir;

    /** @var int seconds */
    protected $lifetime;

    /** @var callable () => int */
    public $clock;

    public function __construct($dir, $lifetimeDays = 7)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->lifetime = max(0, (int) $lifetimeDays) * 86400;
        $this->clock = function () {
            return time();
        };
    }

    protected function path($provider, $sha256)
    {
        return $this->dir.'/'.preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $provider).'-'.preg_replace('/[^a-f0-9]/', '', strtolower((string) $sha256)).'.json';
    }

    /** @return ScanResult|null */
    public function get($provider, $sha256)
    {
        if ($this->lifetime === 0 || strlen((string) $sha256) !== 64) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->path($provider, $sha256)), true);
        if (!is_array($data) || !isset($data['time'], $data['result']) || $data['time'] < call_user_func($this->clock) - $this->lifetime) {
            return null;
        }
        $result = ScanResult::fromArray($data['result']);
        // "unknown to the provider" is kept 1 day only: someone may submit the file meanwhile
        if ($result->unknown && $data['time'] < call_user_func($this->clock) - self::UNKNOWN_LIFETIME) {
            return null;
        }
        $result->cached = true;

        return $result;
    }

    /** Lifetime of an "unknown hash" answer (saves lookups of the limited free APIs). */
    const UNKNOWN_LIFETIME = 86400;

    public function put($provider, $sha256, ScanResult $result)
    {
        $unknown = $result->unknown && $result->status === ScanResult::NOT_AVAILABLE;
        if ($this->lifetime === 0 || (!$result->isDefinitive() && !$unknown) || strlen((string) $sha256) !== 64) {
            return;
        }
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        @file_put_contents($this->path($provider, $sha256), json_encode(['time' => call_user_func($this->clock), 'result' => $result->toArray()]));
    }

    /** Removes expired entries; returns how many. */
    public function prune()
    {
        $n = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (filemtime($file) < call_user_func($this->clock) - max(86400, $this->lifetime)) {
                @unlink($file);
                $n++;
            }
        }

        return $n;
    }
}
