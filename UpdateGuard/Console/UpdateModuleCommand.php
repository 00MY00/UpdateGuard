<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\UpdateRunner;

class UpdateModuleCommand extends Command
{
    protected $signature = 'updateguard:update {module : module folder name, e.g. Refresh}
        {--force : also a blocked version, or the same version again}
        {--queue : only queue the update (run by the scheduler within a minute)}';

    protected $description = 'UpdateGuard: safe update of a module (SHA-256, staging, backup, health check, automatic rollback)';

    public function handle()
    {
        $module = (string) $this->argument('module');
        if (!Paths::isModuleName($module) || !is_dir(Paths::modules($module))) {
            $this->error('Module not found: Modules/'.$module);

            return 1;
        }
        if (UpdateJob::pendingFor($module)) {
            $this->error('An operation is already queued, running or observed for '.$module.'.');

            return 1;
        }
        $job = UpdateRunner::queue('update', $module, ['force' => (bool) $this->option('force'), 'triggered_by' => 'CLI']);
        if ($this->option('queue')) {
            $this->line($job->update_id.' queued.');

            return 0;
        }
        $job = (new UpdateRunner(Output::writer($this)))->process($job);
        if ($job->status === 'queued') {
            $this->comment('Another operation holds the lock: '.$job->update_id.' stays queued for the scheduler.');

            return 0;
        }
        $this->line($job->update_id.' '.$job->status.': '.$job->message);

        return in_array($job->status, ['successful', 'observing'], true) ? 0 : 1;
    }
}
