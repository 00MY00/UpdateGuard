<?php

namespace Modules\UpdateGuard\Services;

use Modules\UpdateGuard\Entities\UpdateLog;

/**
 * Log lines of one operation: table updateguard_logs (page Logs & Reports), the report, the console output and,
 * from WARNING up, FreeScout's log (setting "notify_log").
 */
class Journal
{
    const LEVELS = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

    public $updateId = '';
    public $module = '';
    public $from = '';
    public $to = '';

    /** @var array|null report being built (lines are copied into it) */
    public $report;

    /** @var callable|null console output */
    public $out;

    public function __construct($updateId = '', $module = '', $from = '', $to = '', ?callable $out = null)
    {
        $this->updateId = (string) $updateId;
        $this->module = (string) $module;
        $this->from = (string) $from;
        $this->to = (string) $to;
        $this->out = $out;
    }

    public function debug($step, $message)
    {
        $this->write('DEBUG', $step, $message);
    }

    public function info($step, $message)
    {
        $this->write('INFO', $step, $message);
    }

    public function warning($step, $message)
    {
        $this->write('WARNING', $step, $message);
    }

    public function error($step, $message)
    {
        $this->write('ERROR', $step, $message);
    }

    public function critical($step, $message)
    {
        $this->write('CRITICAL', $step, $message);
    }

    public function write($level, $step, $message, $context = null)
    {
        $message = (string) $message;
        if (is_array($this->report)) {
            $this->report['log'][] = ['time' => date('H:i:s'), 'level' => $level, 'step' => $step, 'message' => $message];
        }
        if ($this->out) {
            call_user_func($this->out, date('H:i:s').' '.str_pad($level, 9).str_pad($this->module, 16).$message, $level);
        }
        try {
            UpdateLog::create([
                'update_id'    => $this->updateId,
                'level'        => $level,
                'module'       => $this->module,
                'from_version' => $this->from,
                'to_version'   => $this->to,
                'step'         => (string) $step,
                'message'      => mb_substr($message, 0, 65000),
                'context'      => $context !== null ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            // database unreachable: the report and FreeScout's log still have the line
        }
        if (in_array($level, ['WARNING', 'ERROR', 'CRITICAL'], true) && Settings::get('notify_log')) {
            try {
                \Log::{$level === 'WARNING' ? 'warning' : ($level === 'ERROR' ? 'error' : 'critical')}(
                    '[UpdateGuard] '.$this->updateId.' '.$this->module.' '.$step.': '.$message
                );
            } catch (\Throwable $e) {
            }
        }
    }
}
