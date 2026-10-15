<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Standardized result of a security scanner provider. Pure PHP.
 *
 * Providers never decide whether an update is installed: they return one of the statuses below and the
 * SecurityPolicy decides from the administrator's settings.
 */
class ScanResult
{
    const PASS = 'PASS';
    const WARNING = 'WARNING';
    const BLOCKED = 'BLOCKED';
    const ERROR = 'ERROR';
    const DISABLED = 'DISABLED';
    const NOT_AVAILABLE = 'NOT_AVAILABLE';

    /** @var string */
    public $provider = '';

    /** @var string one of the constants */
    public $status = self::NOT_AVAILABLE;

    /** @var string LOW | MEDIUM | HIGH | CRITICAL ('' when not applicable) */
    public $level = '';

    /** @var string */
    public $message = '';

    /** @var array e.g. ['malicious' => 0, 'suspicious' => 1, 'harmless' => 12, 'undetected' => 55] */
    public $stats = [];

    /** @var array findings of a local scanner */
    public $findings = [];

    /** @var bool the hash is unknown to the provider (an upload would be needed) */
    public $unknown = false;

    /** @var bool taken from the cache (SHA-256 + provider) */
    public $cached = false;

    /** @var string link to the provider's report, if any */
    public $link = '';

    public static function make($provider, $status, $message = '', array $extra = [])
    {
        $r = new self();
        $r->provider = (string) $provider;
        $r->status = (string) $status;
        $r->message = (string) $message;
        foreach ($extra as $key => $value) {
            if (property_exists($r, $key)) {
                $r->{$key} = $value;
            }
        }

        return $r;
    }

    public function toArray()
    {
        return [
            'provider' => $this->provider,
            'status'   => $this->status,
            'level'    => $this->level,
            'message'  => $this->message,
            'stats'    => $this->stats,
            'unknown'  => $this->unknown,
            'cached'   => $this->cached,
            'link'     => $this->link,
        ];
    }

    public static function fromArray(array $a)
    {
        return self::make($a['provider'] ?? '', $a['status'] ?? self::ERROR, $a['message'] ?? '', [
            'level'   => (string) ($a['level'] ?? ''),
            'stats'   => (array) ($a['stats'] ?? []),
            'unknown' => (bool) ($a['unknown'] ?? false),
            'cached'  => (bool) ($a['cached'] ?? false),
            'link'    => (string) ($a['link'] ?? ''),
        ]);
    }

    /** A definitive answer (worth caching): not an error, not "unknown". */
    public function isDefinitive()
    {
        return in_array($this->status, [self::PASS, self::WARNING, self::BLOCKED], true) && !$this->unknown;
    }
}
