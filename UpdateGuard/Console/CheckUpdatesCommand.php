<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\UpdateRunner;

class CheckUpdatesCommand extends Command
{
    protected $signature = 'updateguard:check {module? : module folder name (default: all)}';

    protected $description = 'UpdateGuard: check the latest versions, SHA256SUMS and health commands of the installed modules';

    public function handle()
    {
        $job = UpdateRunner::queue('check', (string) ($this->argument('module') ?: '*'), ['triggered_by' => 'CLI']);
        $job = (new UpdateRunner(Output::writer($this)))->process($job);
        $this->line($job->update_id.' '.$job->status.': '.$job->message);

        return $job->status === 'successful' ? 0 : 1;
    }
}
