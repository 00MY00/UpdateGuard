<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;

/** Log lines of the runner on the console, coloured by severity. */
class Output
{
    public static function writer(Command $command)
    {
        return function ($line, $level = 'INFO') use ($command) {
            if ($level === 'DEBUG' && !$command->getOutput()->isVerbose()) {
                return;
            }
            if (in_array($level, ['ERROR', 'CRITICAL'], true)) {
                $command->error($line);
            } elseif ($level === 'WARNING') {
                $command->comment($line);
            } else {
                $command->line($line);
            }
        };
    }
}
