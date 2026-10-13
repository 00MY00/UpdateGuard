<?php

namespace Modules\UpdateGuard\Services;

/**
 * Global update lock: one update / restore at a time. Pure PHP.
 *
 * storage/updateguard/locks/global.lock is flock()ed for the whole operation; global.json next to it holds
 * {pid, module, date, time, update_id} (a separate file: a locked file cannot be read on every system).
 *
 * A lock is abandoned when its file is not flock()ed any more (process killed: the kernel released it), when its
 * holder is older than 3 × the timeout (hung process), or, if the lock file cannot be opened, when its PID is gone
 * or it is older than the timeout.
 */
class LockService
{
    /** @var string */
    protected $dir;

    /** @var int seconds */
    protected $timeout;

    /** @var resource|null */
    protected $handle;

    /** @var callable pid => bool (tests) */
    public $pidAlive;

    /** @var callable () => int (tests) */
    public $clock;

    public function __construct($dir, $timeoutMinutes = 60)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->timeout = max(1, (int) $timeoutMinutes) * 60;
        $this->pidAlive = function ($pid) {
            if ($pid <= 0) {
                return false;
            }
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 0);
            }

            return is_dir('/proc') ? is_dir('/proc/'.$pid) : true;
        };
        $this->clock = function () {
            return time();
        };
    }

    public function path()
    {
        return $this->dir.'/global.lock';
    }

    public function infoPath()
    {
        return $this->dir.'/global.json';
    }

    /** @return array|null content of the current lock (even abandoned) */
    public function current()
    {
        $data = is_file($this->infoPath()) ? json_decode((string) @file_get_contents($this->infoPath()), true) : null;

        return is_array($data) ? $data : null;
    }

    /** The lock exists but nobody holds it any more. */
    public function isAbandoned()
    {
        $data = $this->current();
        if ($data === null) {
            return false;
        }
        if ($this->handle) {
            return false; // ours
        }
        $h = @fopen($this->path(), 'c');
        if ($h) {
            $free = flock($h, LOCK_EX | LOCK_NB);
            if ($free) {
                flock($h, LOCK_UN);
            }
            fclose($h);
            if ($free) {
                // nobody holds the file lock: the process that wrote global.json is gone
                return true;
            }

            // held by a live process, unless it is far too old (hung process)
            return (int) ($data['time'] ?? 0) < call_user_func($this->clock) - 3 * $this->timeout;
        }
        $age = call_user_func($this->clock) - (int) ($data['time'] ?? 0);

        return !call_user_func($this->pidAlive, (int) ($data['pid'] ?? 0)) || $age > $this->timeout;
    }

    /** Removes an abandoned lock; returns its content (for the log) or null. */
    public function clearAbandoned()
    {
        if (!$this->isAbandoned()) {
            return null;
        }
        $data = $this->current();
        @unlink($this->infoPath());
        @unlink($this->path());

        return $data;
    }

    /** true when acquired; false when another operation holds it. */
    public function acquire($module, $updateId)
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('cannot create the lock folder '.$this->dir);
        }
        $this->clearAbandoned();
        if ($this->current() !== null && !$this->isAbandoned()) {
            return false;
        }
        $h = @fopen($this->path(), 'c+');
        if (!$h) {
            throw new \RuntimeException('cannot write the lock file '.$this->path());
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);

            return false;
        }
        file_put_contents($this->infoPath(), json_encode([
            'pid'       => getmypid(),
            'module'    => (string) $module,
            'date'      => date('c', call_user_func($this->clock)),
            'time'      => call_user_func($this->clock),
            'update_id' => (string) $updateId,
        ]));
        $this->handle = $h;

        return true;
    }

    public function release()
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
            @unlink($this->infoPath());
            @unlink($this->path());
        }
    }
}
