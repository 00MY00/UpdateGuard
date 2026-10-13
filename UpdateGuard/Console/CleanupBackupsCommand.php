<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Services\CleanupService;

class CleanupBackupsCommand extends Command
{
    protected $signature = 'updateguard:cleanup';

    protected $description = 'UpdateGuard: apply the retention settings (backups, logs, reports, staging)';

    public function handle()
    {
        $r = CleanupService::run();
        $this->line('Deleted: '.$r['backups'].' backup(s), '.$r['logs'].' log line(s), '.$r['reports'].' report(s), '.$r['staging'].' staging folder(s).');

        return 0;
    }
}
