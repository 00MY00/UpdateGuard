<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Entities\UpdateBackup;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\BackupService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\UpdateRunner;

class RestoreModuleCommand extends Command
{
    protected $signature = 'updateguard:restore {module : module folder name}
        {backup? : backup id (default: last known-good version other than the installed one)}
        {--list : list the backups of the module}';

    protected $description = 'UpdateGuard: restore a backup of a module (the current version is backed up first)';

    public function handle()
    {
        $module = (string) $this->argument('module');
        if (!Paths::isModuleName($module)) {
            $this->error('Invalid module name.');

            return 1;
        }
        if ($this->option('list')) {
            $rows = UpdateBackup::where('module', $module)->orderBy('id', 'desc')->get()->map(function ($b) {
                return [$b->id, $b->version, (string) $b->created_at, $b->reason, $b->known_good ? 'yes' : '', round($b->size / 1048576, 1).' MB'];
            })->all();
            $this->table(['ID', 'Version', 'Date', 'Reason', 'Known good', 'Size'], $rows);

            return 0;
        }
        $installed = (string) (ModuleDiscoveryService::manifest(Paths::modules($module))['version'] ?? '');
        $backup = $this->argument('backup')
            ? UpdateBackup::where('module', $module)->where('id', (int) $this->argument('backup'))->first()
            : BackupService::lastKnownGood($module, $installed);
        if (!$backup) {
            $this->error('No backup found (see --list).');

            return 1;
        }
        if (UpdateJob::pendingFor($module)) {
            $this->error('An operation is already queued, running or observed for '.$module.'.');

            return 1;
        }
        if (!$this->confirm('Restore '.$module.' '.$backup->version.'? A backup of version '.($installed ?: '?').' will be created first.', true)) {
            return 1;
        }
        $job = UpdateRunner::queue('restore', $module, ['backup_id' => $backup->id, 'triggered_by' => 'CLI']);
        $job = (new UpdateRunner(Output::writer($this)))->process($job);
        $this->line($job->update_id.' '.$job->status.': '.$job->message);

        return $job->status === 'successful' ? 0 : 1;
    }
}
