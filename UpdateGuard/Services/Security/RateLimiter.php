<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Request budget of an external scanner, shared by every process (scheduler, web pages, commands) through a small
 * JSON file protected by flock. Pure PHP.
 *
 * VirusTotal public (free) API: 4 lookups / minute, 500 / day, 15.5 K / month (defaults, configurable). A premium
 * account has no local limit. When the minute budget is used, a background process (scheduler, command) waits for
 * the next slot (at most $maxWait seconds); a web page never waits. When the day or month quota is used, no request
 * is sent: the provider returns ERROR "quota reached" and the security policy decides.
 */
class RateLimiter
{
    /** @var string */
    protected $file;

    /** @var array ['minute' => int, 'day' => int, 'month' => int] (0 = no limit) */
    protected $limits;

    /** @var callable () => int */
    public $clock;

    /** @var callable seconds => void */
    public $sleep;

    public function __construct($file, array $limits)
    {
        $this->file = (string) $file;
        $this->limits = array_merge(['minute' => 0, 'day' => 0, 'month' => 0], array_map('intval', $limits));
        $this->clock = function () {
            return time();
        };
        $this->sleep = function ($seconds) {
            sleep((int) $seconds);
        };
    }

    protected function read()
    {
        $data = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : null;

        return is_array($data) ? $data : [];
    }

    /** Usage now: ['minute' => n, 'day' => n, 'month' => n] with the limits. */
    public function usage()
    {
        $now = call_user_func($this->clock);
        $d = $this->read();
        $recent = array_filter((array) ($d['recent'] ?? []), function ($t) use ($now) {
            return $t > $now - 60;
        });

        return [
            'minute' => count($recent),
            'day'    => ($d['day'] ?? '') === date('Y-m-d', $now) ? (int) ($d['day_count'] ?? 0) : 0,
            'month'  => ($d['month'] ?? '') === date('Y-m', $now) ? (int) ($d['month_count'] ?? 0) : 0,
            'limits' => $this->limits,
        ];
    }

    /**
     * Takes one request from the budget.
     * @return string '' when allowed, otherwise the reason ("minute", "day", "month")
     */
    public function acquire($maxWait = 0)
    {
        $waited = 0;
        while (true) {
            $reason = $this->tryAcquire($wait);
            if ($reason !== 'minute' || $waited + $wait > $maxWait) {
                return $reason;
            }
            call_user_func($this->sleep, $wait);
            $waited += $wait;
        }
    }

    /** One attempt; $wait = seconds until a minute slot frees up. */
    protected function tryAcquire(&$wait)
    {
        $wait = 0;
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $h = @fopen($this->file, 'c+');
        if (!$h) {
            return ''; // no budget file: do not block the scanner (the provider still reports its own HTTP 429)
        }
        flock($h, LOCK_EX);
        $data = json_decode((string) stream_get_contents($h), true);
        $data = is_array($data) ? $data : [];
        $now = call_user_func($this->clock);
        $recent = array_values(array_filter((array) ($data['recent'] ?? []), function ($t) use ($now) {
            return $t > $now - 60;
        }));
        $day = date('Y-m-d', $now);
        $month = date('Y-m', $now);
        $dayCount = ($data['day'] ?? '') === $day ? (int) ($data['day_count'] ?? 0) : 0;
        $monthCount = ($data['month'] ?? '') === $month ? (int) ($data['month_count'] ?? 0) : 0;
        $reason = '';
        if ($this->limits['month'] > 0 && $monthCount >= $this->limits['month']) {
            $reason = 'month';
        } elseif ($this->limits['day'] > 0 && $dayCount >= $this->limits['day']) {
            $reason = 'day';
        } elseif ($this->limits['minute'] > 0 && count($recent) >= $this->limits['minute']) {
            $reason = 'minute';
            $wait = max(1, min($recent) + 60 - $now);
        } else {
            $recent[] = $now;
            $data = ['recent' => $recent, 'day' => $day, 'day_count' => $dayCount + 1, 'month' => $month, 'month_count' => $monthCount + 1];
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($data));
            fflush($h);
        }
        flock($h, LOCK_UN);
        fclose($h);

        return $reason;
    }

    /** HTTP client that spends from this budget before each request (throws when the budget is used). */
    public function wrap(callable $http, $maxWait = 0)
    {
        return function ($method, $url, array $options) use ($http, $maxWait) {
            $reason = $this->acquire($maxWait);
            if ($reason !== '') {
                $messages = [
                    'minute' => 'rate limit reached (requests per minute): try again in a minute',
                    'day'    => 'daily quota reached',
                    'month'  => 'monthly quota reached',
                ];
                throw new \RuntimeException($messages[$reason]);
            }

            return call_user_func($http, $method, $url, $options);
        };
    }
}
