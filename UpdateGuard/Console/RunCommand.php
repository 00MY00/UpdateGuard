<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\Settings;
use Modules\UpdateGuard\Services\UpdateRunner;

/** Every minute (scheduler): abandoned locks, observations, queued jobs, automatic updates. */
class RunCommand extends Command
{
    protected $signature = 'updateguard:run {--no-auto : do not start automatic updates}';

    protected $description = 'UpdateGuard: process queued jobs and observations (scheduler)';

    public function handle()
    {
        \App\Option::set('updateguard.last_run', date('c'));
        if (!Settings::get('enabled')) {
            return 0;
        }
        (new UpdateRunner(Output::writer($this)))->tick(!$this->option('no-auto'));

        return 0;
    }
}
